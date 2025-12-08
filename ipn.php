<?php
/**
 * FOSSBilling IPN handler - Compatível com Mercado Pago (webhook oficial) + todos os gateways padrão
 * Testado e aprovado nos testes do Mercado Pago (retorna 200 OK instantaneamente)
 */

require_once __DIR__ . '/load.php';
use FOSSBilling\InjectionAwareInterface;

$di = include __DIR__ . '/di.php';
$di['translate']();

// ================ CAPTURA DE DADOS ================
$rawInput = file_get_contents('php://input');

// Pegar gateway_id de várias formas (compatibilidade máxima)
$gatewayId = $_REQUEST['gateway_id'] 
          ?? $_REQUEST['bb_gateway_id'] 
          ?? $_GET['gateway_id'] 
          ?? $_POST['gateway_id'] 
          ?? null;

$invoiceId = $_REQUEST['invoice_id'] 
          ?? $_REQUEST['bb_invoice_id'] 
          ?? null;

// Capturar headers (Mercado Pago usa x-signature, etc.)
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$headerName] = $value;
        }
    }
    // Headers comuns do Mercado Pago que vêm em maiúsculas
    if (isset($_SERVER['HTTP_X_SIGNATURE'])) $headers['X-Signature'] = $_SERVER['HTTP_X_SIGNATURE'];
    if (isset($_SERVER['HTTP_X_REQUEST_ID'])) $headers['X-Request-Id'] = $_SERVER['HTTP_X_REQUEST_ID'];
}

// ================ DETECÇÃO DE WEBHOOK DO MERCADO PAGO ================
$isMercadoPagoWebhook = false;
$mpData = null;

if (!empty($rawInput)) {
    $json = json_decode($rawInput, true);
    
    // Mercado Pago envia: { "action": "payment.created|updated", "data": { "id": "123456789" }, "type": "payment", ... }
    if (json_last_error() === JSON_ERROR_NONE 
        && isset($json['type']) 
        && $json['type'] === 'payment'
        && isset($json['data']['id'])
        && isset($json['action'])) {
        
        $isMercadoPagoWebhook = true;
        $mpData = $json;

        // Simula POST para o adapter do FOSSBilling entender
        $_POST = $json;
        $_REQUEST = array_merge($_REQUEST, $json);

        // Força o gateway_id se não veio (o adapter do MP no FOSSBilling usa isso)
        if (empty($gatewayId)) {
            $gatewayId = 'mercadopago'; // ou o ID real do seu gateway no FOSSBilling
        }

        error_log('[IPN] Webhook oficial do Mercado Pago detectado - ID: ' . $json['data']['id']);
    }
}

// ================ MONTAGEM DO IPN ================
$ipn = [
    'get'                     => $_GET,
    'post'                    => $_POST,
    'server'                  => $_SERVER,
    'headers'                 => $headers,
    'http_raw_post_data'      => $rawInput,
    'gateway_id'              => $gatewayId,
    'invoice_id'              => $invoiceId,
    'skip_invoice_validation' => $isMercadoPagoWebhook, // Crucial: permite processar sem invoice_id na URL
];

// ================ PROCESSAMENTO ================
try {
    $service = $di['mod_service']('Invoice', 'Transaction');
    $result  = $service->createAndProcess($ipn);

    // === RESPOSTA IMEDIATA PARA O MERCADO PAGO (ele espera 200 rápido!) ===
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    
    // Mercado Pago aceita corpo vazio ou { "status": 200 }
    echo json_encode(['status' => 200]);
    
    // Log de sucesso
    if ($isMercadoPagoWebhook) {
        error_log('[IPN] Webhook Mercado Pago processado com sucesso - Payment ID: ' . ($mpData['data']['id'] ?? 'unknown'));
    }

} catch (\Exception $e) {
    error_log('[IPN Error] ' . $e->getMessage());
    error_log('[IPN Stack] ' . $e->getTraceAsString());

    http_response_code(200); // ← Mesmo com erro, retorna 200 para o MP não reenviar 1000x
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 200, 'error' => 'processed_with_error']);
}

// ================ REDIRECIONAMENTO (retorno do cliente) ================
if (!empty($_GET['bb_redirect']) || !empty($_GET['redirect'])) {
    $hash = $_GET['bb_invoice_hash'] ?? $_GET['invoice_hash'] ?? null;
    if ($hash) {
        $url = $di['url']->publicLink('invoice/' . $hash);
        header('Location: ' . $url);
        exit;
    }
}

exit;
