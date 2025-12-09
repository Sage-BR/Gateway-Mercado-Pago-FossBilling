<?php
/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 * 
 * MODIFICADO: Suporte a Mercado Pago webhooks (JSON) + FIX headers array
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'load.php';
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

$di = include Path::join(PATH_ROOT, 'di.php');
$di['translate']();
$filesystem = new Filesystem();

// ====== CAPTURA DE HEADERS (necessário para validação MP) ======
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    // Extração manual de headers do $_SERVER
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$headerName] = $value;
        }
    }
}

// 🔥 CRÍTICO: Normaliza headers para lowercase ANTES de passar ao adapter
$headers = array_change_key_case($headers, CASE_LOWER);

// ====== DETECÇÃO DE WEBHOOK MERCADO PAGO ======
$rawInput = $filesystem->readFile('php://input');
$isMercadoPago = false;
$mpData = null;

if (!empty($rawInput)) {
    $json = json_decode($rawInput, true);
    
    // Detecta webhook do Mercado Pago pelo formato oficial
    if (json_last_error() === JSON_ERROR_NONE 
        && isset($json['type']) 
        && $json['type'] === 'payment'
        && isset($json['data']['id'])) {
        
        $isMercadoPago = true;
        $mpData = $json;
        
        // Injeta os dados do JSON no $_POST para compatibilidade com adapters
        $_POST = array_merge($_POST, $json);
        $_REQUEST = array_merge($_REQUEST, $json);
        
        error_log('[IPN] Webhook Mercado Pago detectado - Payment ID: ' . $json['data']['id']);
    }
}

// ====== CAPTURA DE IDs (original FOSSBilling) ======
$invoiceID = $_POST['invoice_id'] ?? $_GET['invoice_id'] ?? $_POST['bb_invoice_id'] ?? $_GET['bb_invoice_id'] ?? null;
$gatewayID = $_POST['gateway_id'] ?? $_GET['gateway_id'] ?? $_POST['bb_gateway_id'] ?? $_GET['bb_gateway_id'] ?? null;

// ====== MERCADO PAGO: Busca gateway_id do banco se não vier na request ======
if ($isMercadoPago && empty($gatewayID)) {
    try {
        // Busca o gateway Mercado Pago ativo (assume que existe apenas 1 ativo)
        $gateway = $di['db']->findOne('pay_gateway', 'gateway = :gateway AND enabled = 1', [':gateway' => 'MercadoPago']);
        
        if ($gateway) {
            $gatewayID = $gateway->id;
            error_log("[IPN] Gateway Mercado Pago ID obtido do banco: {$gatewayID}");
        } else {
            error_log('[IPN ERROR] Gateway Mercado Pago não encontrado ou desabilitado no banco!');
            http_response_code(500);
            exit;
        }
    } catch (Exception $e) {
        error_log('[IPN ERROR] Erro ao buscar gateway: ' . $e->getMessage());
        http_response_code(500);
        exit;
    }
}

// Atualiza $_GET para compatibilidade com código legado
$_GET['bb_invoice_id'] = $invoiceID;
$_GET['bb_gateway_id'] = $gatewayID;

// Log dos headers capturados (debug)
if ($isMercadoPago) {
    error_log('[IPN] Headers capturados (array): ' . json_encode(array_keys($headers)));
    error_log('[IPN] Tipo de $headers: ' . gettype($headers) . ' com ' . count($headers) . ' itens');
    
    // Log específico dos headers que o MP usa
    if (isset($headers['x-signature'])) {
        error_log('[IPN] ✅ X-Signature encontrado: ' . substr($headers['x-signature'], 0, 50) . '...');
    } else {
        error_log('[IPN] ❌ X-Signature NÃO encontrado');
    }
    
    if (isset($headers['x-request-id'])) {
        error_log('[IPN] ✅ X-Request-Id encontrado: ' . $headers['x-request-id']);
    } else {
        error_log('[IPN] ❌ X-Request-Id NÃO encontrado');
    }
}

// ====== MONTAGEM DO IPN ======
$ipn = [
    'skip_validation' => true,
    'invoice_id' => $invoiceID,
    'gateway_id' => $gatewayID,
    'get' => $_GET,
    'post' => $_POST,
    'server' => $_SERVER,
    'headers' => $headers, // 🔥 AGORA É UM ARRAY NORMALIZADO
    'http_raw_post_data' => $rawInput,
];

error_log('[IPN] Processando → Gateway: ' . ($gatewayID ?? 'NULL') . ' | Invoice: ' . ($invoiceID ?? 'NULL'));
error_log('[IPN] 🔥 Headers sendo enviados ao adapter: ' . json_encode(array_keys($headers)));

// ====== PROCESSAMENTO ======
try {
    $service = $di['mod_service']('invoice', 'transaction');
    $output = $service->createAndProcess($ipn);
    $res = ['result' => $output, 'error' => null];
    
    if ($isMercadoPago) {
        error_log('[IPN] ✅ Webhook Mercado Pago processado com sucesso');
    }
} catch (Exception $e) {
    error_log('[IPN ERROR] ' . $e->getMessage());
    error_log('[IPN TRACE] ' . $e->getTraceAsString());
    $res = ['result' => null, 'error' => ['message' => $e->getMessage()]];
    $output = false;
}

// ====== REDIRECIONAMENTO (retorno do cliente) ======
if (isset($_GET['redirect'], $_GET['invoice_hash']) || isset($_GET['bb_redirect'], $_GET['bb_invoice_hash'])) {
    $hash = $_GET['invoice_hash'] ?? $_GET['bb_invoice_hash'];
    $url = $di['url']->link('invoice/' . $hash);
    header("Location: $url");
    exit;
}

// ====== RESPOSTA ======
// Mercado Pago espera resposta 200 rápida
if ($isMercadoPago) {
    http_response_code(200);
}

header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-type: application/json; charset=utf-8');
echo json_encode($res);
exit;
