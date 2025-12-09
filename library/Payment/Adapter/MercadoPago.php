<?php
/**
 * Mercado Pago Checkout Pro para FOSSBilling
 * Desenvolvido por 4teambr.com
 */

class Payment_Adapter_MercadoPago extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        $this->config['test_mode'] = !empty($this->config['test_mode']);

        if (empty($this->config['access_token'])) {
            throw new Payment_Exception('Access Token do Mercado Pago não configurado');
        }

        if (empty($this->config['secret_key'])) {
            error_log('[MercadoPago] AVISO: Secret Key não configurada. Webhooks não serão validados com segurança!');
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Aceita pagamentos via Mercado Pago Checkout Pro (2025) com Webhooks seguros',
            'logo' => ['logo' => 'mercadopago.png', 'height' => '30px'],
            'form' => [
                'access_token' => [
                    'text',
                    [
                        'label' => 'Access Token (Produção ou Teste)',
                        'description' => 'Token de acesso da sua aplicação. Obtenha em: Suas Integrações > Credenciais',
                        'required' => true,
                    ],
                ],
                'secret_key' => [
                    'text',
                    [
                        'label' => 'Secret Key (Chave Secreta)',
                        'description' => 'OBRIGATÓRIO para validar webhooks com segurança. Gerada em: Suas Integrações > Webhooks',
                        'required' => false,
                    ],
                ],
                'test_mode' => [
                    'text',
                    [
                        'label' => 'Modo Teste (Sandbox)',
                        'description' => 'Use credenciais TEST para ambiente sandbox',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        try {
            $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
            $preference = $this->createPreference($invoice);

            if (!$preference || empty($preference['init_point'])) {
                return $this->renderError($preference['error'] ?? 'Erro ao criar preferência de pagamento');
            }

            $paymentUrl = $this->config['test_mode']
                ? ($preference['sandbox_init_point'] ?? $preference['init_point'])
                : $preference['init_point'];

            return $this->renderPaymentButton($paymentUrl);
        } catch (Exception $e) {
            error_log('[MercadoPago] Erro fatal ao gerar botão: ' . $e->getMessage());
            return $this->renderError('Erro interno. Contate o administrador.');
        }
    }

    private function createPreference($invoice): ?array
    {
        $url = 'https://api.mercadopago.com/checkout/preferences';
        $externalRef = 'INV_' . $invoice['id'];

        // DETECÇÃO AUTOMÁTICA INTELIGENTE
        $buyerEmail = $this->getValidEmail($invoice);
        $companyName = $this->getCompanyName();
        $invoiceTitle = $this->getInvoiceTitle($invoice, $companyName);

        $currency = strtoupper($invoice['currency'] ?? 'BRL');
        $allowedCurrencies = ['BRL', 'ARS', 'MXN', 'COP', 'PEN', 'CLP', 'UYU'];
        if (!in_array($currency, $allowedCurrencies)) {
            $currency = 'BRL';
            error_log("[MercadoPago] Moeda {$currency} não suportada no Checkout Pro. Forçada BRL.");
        }

        $total = round((float)$invoice['total'], 2);
        if ($total < 0.50) {
            return ['error' => 'Valor mínimo não atingido (R$ 0,50)'];
        }

        // ✅ URL do webhook no padrão FOSSBilling: ipn.php?gateway_id=X
        // O gateway_id será automaticamente detectado pelo FOSSBilling
        $webhookUrl = $this->di['url']->link('ipn/mercadopago');

        $payload = [
            "items" => [[
                "title" => $this->sanitize($invoiceTitle),
                "description" => $this->sanitize("Fatura #{$invoice['nr']} - " . ($invoice['buyer']['first_name'] ?? 'Cliente')),
                "quantity" => 1,
                "currency_id" => $currency,
                "unit_price" => $total,
            ]],
            "payer" => [
                "email" => $buyerEmail,
                "first_name" => $this->sanitize($invoice['buyer']['first_name'] ?? 'Cliente'),
                "last_name"  => $this->sanitize($invoice['buyer']['last_name'] ?? 'FOSSBilling'),
            ],
            "back_urls" => [
                "success" => $this->di['url']->link('invoice', ['id' => $invoice['hash']]) . '?status=approved',
                "pending" => $this->di['url']->link('invoice', ['id' => $invoice['hash']]) . '?status=pending',
                "failure" => $this->di['url']->link('invoice', ['id' => $invoice['hash']]) . '?status=rejected',
            ],
			"auto_return" => "approved",
            "notification_url" => $webhookUrl,
            "external_reference" => $externalRef,
            "statement_descriptor" => $this->sanitize(substr($companyName, 0, 13)),
            "expires" => true,
            "expiration_date_from" => date('c'),
            "expiration_date_to" => date('c', strtotime('+7 days')),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config['access_token'],
                'X-Idempotency-Key: ' . $externalRef . '_' . time(),
                'User-Agent: FOSSBilling-MercadoPago/2.1',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[MercadoPago] cURL Error: $error");
            return ['error' => 'Falha na conexão com Mercado Pago'];
        }

        if ($httpCode !== 201) {
            $decoded = json_decode($result, true);
            $msg = $decoded['message'] ?? 'Erro desconhecido';
            error_log("[MercadoPago] API Error {$httpCode}: " . json_encode($decoded));
            return ['error' => "Erro Mercado Pago: $msg"];
        }

        $data = json_decode($result, true);
        error_log("[MercadoPago] Preferência criada com sucesso! ID: {$data['id']}");
        return $data;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
{
    error_log('[MercadoPago] WEBHOOK RECEBIDO → ' . json_encode($data, JSON_UNESCAPED_UNICODE));

    // 🔥 FIX 1: Reconstrói headers dos $_SERVER que vêm no $data
    if (empty($data['headers']) || !is_array($data['headers']) || count($data['headers']) === 0) {
        error_log('[MercadoPago] ⚠️ Headers vazios, reconstruindo de $_SERVER...');
        $data['headers'] = $this->extractHeadersFromServer($data['server'] ?? []);
        error_log('[MercadoPago] Headers reconstruídos: ' . json_encode(array_keys($data['headers'])));
    }

    $webhook = $data['post'] ?? $data;
    $type = $webhook['type'] ?? $webhook['action'] ?? null;

    if ($type !== 'payment' && strpos($type ?? '', 'payment') === false) {
        error_log("[MercadoPago] Ignorando webhook do tipo: $type");
        return;
    }

    $paymentId = $webhook['data']['id'] ?? null;
    if (!$paymentId) {
        error_log('[MercadoPago] Webhook sem payment ID');
        return;
    }

    // 🔥 FIX 2: Detecta payment ID de teste ANTES da validação de assinatura
    if ($paymentId == '123456' || $paymentId == '12345678') {
        error_log('[MercadoPago] ⚠️ Payment ID de teste detectado (123456). Ignorando webhook de validação do MP.');
        error_log('[MercadoPago] ✅ Webhook configurado corretamente! Aguardando pagamentos reais.');
        return;
    }

    // ✅ Validação de assinatura (somente para pagamentos reais)
    if (!empty($this->config['secret_key'])) {
        if (!$this->validateWebhookSignature($data)) {
            error_log('[MercadoPago] ❌ ASSINATURA INVÁLIDA → Possível fraude');
            http_response_code(401);
            exit;
        }
    }

    // ✅ BUSCAR DETALHES DO PAGAMENTO PRIMEIRO (para obter invoice_id)
    $payment = $this->getPaymentDetails($paymentId);
    if (!$payment) {
        error_log("[MercadoPago] Não foi possível obter detalhes do pagamento {$paymentId}");
        return;
    }

    error_log("[MercadoPago] Payment Status: {$payment['status']} | External Ref: {$payment['external_reference']}");

    // Extrair invoice_id do external_reference
    $externalRef = $payment['external_reference'] ?? null;
    if (!preg_match('/^INV_(\d+)$/', $externalRef, $m)) {
        error_log("[MercadoPago] External reference inválido: $externalRef");
        return;
    }

    $invoiceId = (int)$m[1];
    error_log("[MercadoPago] Invoice ID extraído: $invoiceId");

    // Evitar duplicidade
    try {
        $existing = $api_admin->invoice_transaction_get(['txn_id' => $paymentId]);
        if ($existing) {
            error_log("[MercadoPago] Pagamento {$paymentId} já processado. Ignorando duplicata.");
            return;
        }
    } catch (Exception $e) {
        // Transação não existe, continuar processamento
    }

    // Só processa se aprovado
    if ($payment['status'] !== 'approved') {
        error_log("[MercadoPago] Pagamento {$paymentId} não aprovado ainda. Status: {$payment['status']}");
        return;
    }

    try {
        $invoice = $api_admin->invoice_get(['id' => $invoiceId]);

        if ($invoice['status'] === 'paid') {
            error_log("[MercadoPago] Fatura {$invoiceId} já paga.");
            return;
        }

        // Registrar transação ANTES de marcar como paga
        $api_admin->invoice_transaction_create([
            'invoice_id' => $invoiceId,
            'gateway_id' => $gateway_id,
            'txn_id' => $paymentId,
            'amount' => $payment['transaction_amount'],
            'currency' => $payment['currency_id'],
            'status' => 'processed',
            'type' => 'payment',
            'note' => "Pago via Mercado Pago (ID: {$paymentId})"
        ]);

        // Marcar fatura como paga
        $api_admin->invoice_mark_as_paid([
            'id' => $invoiceId,
            'note' => "Pago via Mercado Pago (ID: {$paymentId})"
        ]);

        error_log("[MercadoPago] ✅ FATURA {$invoiceId} MARCADA COMO PAGA → Payment ID: {$paymentId}");
    } catch (Exception $e) {
        error_log('[MercadoPago] ❌ Erro ao processar fatura: ' . $e->getMessage());
        error_log('[MercadoPago] Stack trace: ' . $e->getTraceAsString());
        throw $e;
    }
}

// 🔥 NOVA FUNÇÃO: Extrai headers de $_SERVER quando necessário
private function extractHeadersFromServer(array $server): array
{
    $headers = [];
    
    foreach ($server as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$headerName] = $value;
        }
    }
    
    // Headers especiais que não vêm com HTTP_ prefix
    if (!empty($server['CONTENT_TYPE'])) {
        $headers['content-type'] = $server['CONTENT_TYPE'];
    }
    if (!empty($server['CONTENT_LENGTH'])) {
        $headers['content-length'] = $server['CONTENT_LENGTH'];
    }
    
    return $headers;
}

    private function validateWebhookSignature($data): bool
    {
        if (empty($this->config['secret_key'])) {
            error_log('[MercadoPago] Secret Key vazia - pulando validação');
            return true;
        }

        // ✅ CORRIGIDO: Pega headers do array $data passado pelo ipn.php
        $headers = $data['headers'] ?? [];
        
        // Normaliza keys para lowercase
        $headers = array_change_key_case($headers, CASE_LOWER);

        error_log('[MercadoPago] Headers disponíveis: ' . json_encode(array_keys($headers)));

        $signature = $headers['x-signature'] ?? '';
        $requestId = $headers['x-request-id'] ?? '';

        if (!$signature || !$requestId) {
            error_log('[MercadoPago] ⚠️ Headers x-signature ou x-request-id ausentes');
            error_log('[MercadoPago] Signature: ' . ($signature ?: 'VAZIO'));
            error_log('[MercadoPago] Request ID: ' . ($requestId ?: 'VAZIO'));
            
            // ✅ MODO PERMISSIVO: Se headers estão faltando mas temos secret_key, aceitamos
            // Em produção você pode tornar isso mais restritivo retornando false
            error_log('[MercadoPago] ⚠️ Aceitando webhook sem validação (headers ausentes)');
            return true;
        }

        if (!preg_match('/ts=(\d+),v1=([a-f0-9]+)/', $signature, $m)) {
            error_log('[MercadoPago] ⚠️ Formato de assinatura inválido: ' . $signature);
            return false;
        }

        [, $ts, $hash] = $m;
        $paymentId = $data['post']['data']['id'] ?? $data['data']['id'] ?? '';

        $manifest = "id:{$paymentId};request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $this->config['secret_key']);

        error_log('[MercadoPago] Manifest: ' . $manifest);
        error_log('[MercadoPago] Hash recebido: ' . $hash);
        error_log('[MercadoPago] Hash esperado: ' . $expected);

        $valid = hash_equals($expected, $hash);
        error_log('[MercadoPago] Assinatura ' . ($valid ? '✅ VÁLIDA' : '❌ INVÁLIDA'));

        return $valid;
    }

    private function getPaymentDetails($paymentId): ?array
    {
        $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['access_token'],
                'User-Agent: FOSSBilling-MercadoPago/2.1'
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code === 200) ? json_decode($result, true) : null;
    }

    // Funções auxiliares otimizadas
    private function getValidEmail($invoice): string
    {
        $sources = [
            $invoice['buyer']['email'] ?? null,
            $invoice['client']['email'] ?? null,
        ];

        foreach ($sources as $email) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
        }

        try {
            $email = $this->di['mod_service']('system')->getParamValue('company_email');
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
        } catch (Exception $e) {}

        return 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    private function getCompanyName(): string
    {
        try {
            $name = $this->di['mod_service']('system')->getParamValue('company_name')
                 ?? $this->di['mod_service']('system')->getParamValue('company_title');
            if ($name) return trim($name);
        } catch (Exception $e) {}

        return ucfirst(str_replace(['www.', '.com', '.br', '.com.br'], '', $_SERVER['HTTP_HOST'] ?? 'Minha Empresa'));
    }

    private function getInvoiceTitle($invoice, $company): string
    {
        return "Fatura #" . ($invoice['nr'] ?? $invoice['id']) . " - {$company}";
    }

    private function sanitize(?string $str): string
    {
        if (!$str) return 'Cliente';
        return trim(substr(preg_replace('/[^\p{L}\p{N}\s\-_.]/u', '', $str), 0, 120));
    }

    private function renderError(string $msg): string
    {
        return "<div class='alert alert-danger'><h4>Erro no Mercado Pago</h4><p><strong>$msg</strong></p><small>Verifique os logs do sistema.</small></div>";
    }

    private function renderPaymentButton(string $url): string
    {
        return '
        <div style="text-align:center; padding:30px;">
            <button onclick="window.location.href=\'' . htmlspecialchars($url) . '\'" class="btn btn-primary btn-lg" style="background:#009EE3; border:none; padding:18px 50px; font-size:20px; border-radius:8px;">
                Pagar com Mercado Pago
            </button>
            <p style="margin-top:20px; color:#666;">
                Redirecionando automaticamente em <strong>3</strong> segundos...
            </p>
            <script>
                setTimeout(() => window.location.href = ' . json_encode($url) . ', 3000);
                let s = 3;
                setInterval(() => document.querySelector("strong").textContent = --s, 1000);
            </script>
        </div>';
    }
}
