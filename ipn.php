<?php
/**
 * Copyright 2022-2025 FOSSBilling
 * Copyright 2011-2021 BoxBilling, Inc.
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 * Json By 4teambr.com
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'load.php';
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

$di = include Path::join(PATH_ROOT, 'di.php');
$di['translate']();
$filesystem = new Filesystem();

// ============================================================================
// PATCH PARA MERCADO PAGO (e outros gateways que enviam JSON)
// ============================================================================

$rawInput = $filesystem->readFile('php://input');

// Detecta se é webhook do Mercado Pago pelo gateway_id
$gatewayID = $_POST['gateway_id'] ?? $_GET['gateway_id'] ?? $_POST['bb_gateway_id'] ?? $_GET['bb_gateway_id'] ?? null;

if ($gatewayID) {
    error_log('[IPN] ========================================');
    error_log('[IPN] Gateway ID recebido: ' . $gatewayID);
    error_log('[IPN] GET: ' . json_encode($_GET));
    error_log('[IPN] Raw input: ' . substr($rawInput, 0, 500)); // Primeiros 500 chars
    
    // Se o body não está vazio, tenta parsear JSON
    if (!empty($rawInput) && empty($_POST)) {
        $jsonData = json_decode($rawInput, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            error_log('[IPN] ✅ JSON parseado com sucesso');
            error_log('[IPN] JSON data: ' . json_encode($jsonData, JSON_PRETTY_PRINT));
            
            // Verifica se é estrutura do Mercado Pago
            if (isset($jsonData['data']['id']) && (isset($jsonData['action']) || isset($jsonData['type']))) {
                error_log('[IPN] 🎯 Detectado webhook do Mercado Pago');
                
                try {
                    // Busca o gateway pelo ID
                    $paymentGatewayService = $di['mod_service']('Invoice', 'PaymentGateway');
                    $gateway = $paymentGatewayService->getPaymentGatewayById($gatewayID);
                    
                    if (!$gateway) {
                        error_log('[IPN] ❌ Gateway ID ' . $gatewayID . ' não encontrado');
                        http_response_code(404);
                        echo json_encode(['result' => null, 'error' => ['message' => 'Gateway not found']]);
                        exit;
                    }
                    
                    error_log('[IPN] ✅ Gateway encontrado: ' . $gateway['gateway'] . ' (ID: ' . $gateway['id'] . ')');
                    
                    // Carrega o adapter do gateway
                    $adapter = $paymentGatewayService->getPaymentAdapter($gateway);
                    
                    // Prepara dados para o processamento
                    $ipnData = [
                        'get'     => $_GET,
                        'post'    => $jsonData,
                        'headers' => getallheaders(),
                    ];
                    
                    // Processa o webhook
                    $adapter->processTransaction(
                        $di['api_admin'],
                        $gateway['id'],
                        $ipnData,
                        $gateway['id']
                    );
                    
                    error_log('[IPN] ✅ Processamento concluído com sucesso');
                    http_response_code(200);
                    echo json_encode(['result' => 'OK', 'error' => null]);
                    exit;
                    
                } catch (Exception $e) {
                    error_log('[IPN] ❌ Erro ao processar: ' . $e->getMessage());
                    error_log('[IPN] Stack trace: ' . $e->getTraceAsString());
                    http_response_code(500);
                    echo json_encode(['result' => null, 'error' => ['message' => $e->getMessage()]]);
                    exit;
                }
            } else {
                error_log('[IPN] ℹ️ JSON detectado mas não é do Mercado Pago');
            }
        } else {
            error_log('[IPN] ⚠️ Erro ao parsear JSON: ' . json_last_error_msg());
        }
    }
    error_log('[IPN] ========================================');
}

$invoiceID = $_POST['invoice_id'] ?? $_GET['invoice_id'] ?? $_POST['bb_invoice_id'] ?? $_GET['bb_invoice_id'] ?? null;

// Se não tem gateway_id ainda, tenta pegar novamente
if (!$gatewayID) {
    $gatewayID = $_POST['gateway_id'] ?? $_GET['gateway_id'] ?? $_POST['bb_gateway_id'] ?? $_GET['bb_gateway_id'] ?? null;
}

$_GET['bb_invoice_id'] = $invoiceID;
$_GET['bb_gateway_id'] = $gatewayID;

$ipn = [
    'skip_validation' => true,
    'invoice_id' => $invoiceID,
    'gateway_id' => $gatewayID,
    'get' => $_GET,
    'post' => $_POST,
    'server' => $_SERVER,
    'http_raw_post_data' => $rawInput,
];

try {
    $service = $di['mod_service']('invoice', 'transaction');
    $output = $service->createAndProcess($ipn);
    $res = ['result' => $output, 'error' => null];
} catch (Exception $e) {
    $res = ['result' => null, 'error' => ['message' => $e->getMessage()]];
    $output = false;
}

// redirect to invoice if gateways requires
if (isset($_GET['redirect'], $_GET['invoice_hash']) || isset($_GET['bb_redirect'], $_GET['bb_invoice_hash'])) {
    $hash = $_GET['invoice_hash'] ?? $_GET['bb_invoice_hash'];
    $url = $di['url']->link('invoice/' . $hash);
    header("Location: $url");
    exit;
}

header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-type: application/json; charset=utf-8');
echo json_encode($res);
exit;
