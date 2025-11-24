<?php
/**
 * Mercado Pago Checkout Pro para FOSSBilling
 * VERSÃO COM DETECÇÃO AUTOMÁTICA DE CONFIGURAÇÕES
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
            error_log('[MercadoPago] AVISO: Secret Key não configurada. Webhooks não poderão ser validados!');
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => 'Aceita pagamentos via Mercado Pago Checkout Pro (2025) com Webhooks',
            'logo'                       => ['logo' => 'mercadopago.png', 'height' => '30px'],
            'form'                       => [
                'access_token' => [
                    'text',
                    [
                        'label'       => 'Access Token (Produção ou Teste)',
                        'description' => 'Token de acesso da sua aplicação. Obtenha em: Suas Integrações > Credenciais',
                        'required'    => true,
                    ],
                ],
                'secret_key' => [
                    'text',
                    [
                        'label'       => 'Secret Key (Chave Secreta)',
                        'description' => 'Necessária para validar webhooks. Gerada em: Suas Integrações > Webhooks',
                        'required'    => false,
                    ],
                ],
                'test_mode' => [
                    'checkbox',
                    [
                        'label'       => 'Modo Teste (Sandbox)',
                        'description' => 'Use credenciais TEST para ambiente sandbox',
                        'value'       => '1',
                        'required'    => false,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        try {
            $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
            
            error_log('[MercadoPago] === INICIANDO PAGAMENTO ===');
            error_log('[MercadoPago] Invoice ID: ' . $invoice['id']);
            
            $preference = $this->createPreference($invoice);

            if (!$preference || empty($preference['init_point'])) {
                return $this->renderError($preference);
            }

            $paymentUrl = $this->config['test_mode'] 
                ? ($preference['sandbox_init_point'] ?? $preference['init_point'])
                : $preference['init_point'];

            return $this->renderPaymentButton($paymentUrl);

        } catch (Exception $e) {
            error_log('[MercadoPago] Erro ao gerar HTML: ' . $e->getMessage());
            return $this->renderError(['error' => $e->getMessage()]);
        }
    }

    private function createPreference($invoice): ?array
    {
        $url = 'https://api.mercadopago.com/checkout/preferences';
        
        $externalRef = 'INV_' . $invoice['id'];
        
        // ========== DETECÇÃO AUTOMÁTICA DE CONFIGURAÇÕES ==========
        
        // 1. EMAIL: Tenta múltiplas fontes
        $buyerEmail = $this->getValidEmail($invoice);
        
        // 2. NOME DA EMPRESA: Busca nas configurações do sistema
        $companyName = $this->getCompanyName();
        
        // 3. NOME DA FATURA: Combina empresa + número
        $invoiceTitle = $this->getInvoiceTitle($invoice, $companyName);
        
        error_log('[MercadoPago] Email usado: ' . $buyerEmail);
        error_log('[MercadoPago] Empresa: ' . $companyName);
        error_log('[MercadoPago] Título da fatura: ' . $invoiceTitle);
        
        // ========== FIM DA DETECÇÃO AUTOMÁTICA ==========
        
        $currency = strtoupper(trim($invoice['currency'] ?? 'BRL'));
        if (strlen($currency) !== 3) {
            error_log('[MercadoPago] Moeda inválida: ' . $currency . ' - Forçando BRL');
            $currency = 'BRL';
        }
        
        $totalValue = (float) $invoice['total'];
        if ($totalValue < 0.5) {
            error_log('[MercadoPago] Valor muito baixo: ' . $totalValue);
            return ['error' => 'Valor da fatura muito baixo (mínimo: R$ 0,50)'];
        }
        
        $webhookUrl = $this->di['url']->link('ipn/mercadopago');
        
        error_log('[MercadoPago] Webhook URL: ' . $webhookUrl);
        
        $payload = [
            "items" => [[
                "title"       => $this->sanitizeString($invoiceTitle),
                "description" => $this->sanitizeString($this->getInvoiceDescription($invoice)),
                "quantity"    => 1,
                "currency_id" => $currency,
                "unit_price"  => round($totalValue, 2),
            ]],
            
            "payer" => [
                "email"   => $buyerEmail,
                "name"    => $this->sanitizeString($invoice['buyer']['first_name'] ?? 'Cliente'),
                "surname" => $this->sanitizeString($invoice['buyer']['last_name'] ?? $companyName),
            ],
            
            "back_urls" => [
                "success" => $this->di['url']->link('invoice', ['id' => $invoice['hash']]) . '?status=approved',
                "pending" => $this->di['url']->link('invoice', ['id' => $invoice['hash']]) . '?status=pending',
                "failure" => $this->di['url']->link('invoice', ['id' => $invoice['hash']]) . '?status=rejected',
            ],
            
            "auto_return" => "approved",
            "notification_url" => $webhookUrl,
            "external_reference" => $externalRef,
            "statement_descriptor" => $this->sanitizeString(substr($companyName, 0, 13)), // Mercado Pago limita a 13 chars
            
            "expires" => true,
            "expiration_date_from" => date('c'),
            "expiration_date_to" => date('c', strtotime('+7 days')),
        ];

        error_log('[MercadoPago] Payload: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config['access_token'],
                'X-Idempotency-Key: ' . $externalRef . '_' . time(),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log('[MercadoPago] HTTP Code: ' . $httpCode);
        error_log('[MercadoPago] Response: ' . $result);

        if ($curlError) {
            error_log("[MercadoPago] cURL Error: {$curlError}");
            return ['error' => 'Erro de conexão com Mercado Pago'];
        }

        if ($httpCode !== 201) {
            $decoded = json_decode($result, true);
            $errorMsg = $decoded['message'] ?? $decoded['error'] ?? 'Erro desconhecido';
            
            if (isset($decoded['cause'])) {
                error_log('[MercadoPago] Causa do erro: ' . json_encode($decoded['cause']));
            }
            
            return ['error' => "API retornou erro: {$errorMsg} (HTTP {$httpCode})"];
        }

        $decoded = json_decode($result, true);
        error_log("[MercadoPago] ✅ Preferência criada: ID {$decoded['id']}");
        
        return $decoded;
    }

    /**
     * DETECÇÃO AUTOMÁTICA: Email válido com fallback inteligente
     */
    private function getValidEmail($invoice): string
    {
        // Ordem de prioridade para email
        $emailSources = [
            $invoice['buyer']['email'] ?? null,
            $invoice['client']['email'] ?? null,
        ];
        
        foreach ($emailSources as $email) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
        
        // Fallback: busca email do administrador nas configurações
        try {
            if ($this->di) {
                $systemService = $this->di['mod_service']('system');
                $companyEmail = $systemService->getParamValue('company_email');
                
                if ($companyEmail && filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
                    error_log('[MercadoPago] Usando email da empresa como fallback: ' . $companyEmail);
                    return $companyEmail;
                }
            }
        } catch (Exception $e) {
            error_log('[MercadoPago] Erro ao buscar email da empresa: ' . $e->getMessage());
        }
        
        // Último fallback: email genérico
        error_log('[MercadoPago] AVISO: Usando email genérico como último recurso');
        return 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /**
     * DETECÇÃO AUTOMÁTICA: Nome da empresa das configurações
     */
    private function getCompanyName(): string
    {
        try {
            if ($this->di) {
                $systemService = $this->di['mod_service']('system');
                
                // Tenta várias fontes
                $companyName = $systemService->getParamValue('company_name') 
                            ?? $systemService->getParamValue('company_title')
                            ?? null;
                
                if ($companyName && trim($companyName)) {
                    return trim($companyName);
                }
            }
        } catch (Exception $e) {
            error_log('[MercadoPago] Erro ao buscar nome da empresa: ' . $e->getMessage());
        }
        
        // Fallback: usa o domínio do servidor
        return ucfirst(str_replace(['www.', '.com', '.br'], '', $_SERVER['HTTP_HOST'] ?? 'Empresa'));
    }

    /**
     * DETECÇÃO AUTOMÁTICA: Título da fatura
     */
    private function getInvoiceTitle($invoice, $companyName): string
    {
        $invoiceNumber = $invoice['nr'] ?? $invoice['id'];
        return "Fatura #{$invoiceNumber} - {$companyName}";
    }

    /**
     * DETECÇÃO AUTOMÁTICA: Descrição da fatura
     */
    private function getInvoiceDescription($invoice): string
    {
        $clientName = trim(
            ($invoice['buyer']['first_name'] ?? '') . ' ' . 
            ($invoice['buyer']['last_name'] ?? '')
        );
        
        return $clientName ?: 'Cliente';
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        try {
            error_log('[MercadoPago] ========================================');
            error_log('[MercadoPago] === WEBHOOK RECEBIDO ===');
            error_log('[MercadoPago] ID: ' . $id);
            error_log('[MercadoPago] Gateway ID: ' . $gateway_id);
            error_log('[MercadoPago] Data completa: ' . json_encode($data, JSON_PRETTY_PRINT));
            error_log('[MercadoPago] ========================================');
            
            if (!empty($this->config['secret_key'])) {
                if (!$this->validateWebhookSignature($data)) {
                    error_log('[MercadoPago] ❌ Assinatura inválida! Possível fraude.');
                    return;
                }
            }

            $webhook = $data['post'] ?? $data;
            $action = $webhook['action'] ?? $webhook['type'] ?? null;
            $paymentId = $webhook['data']['id'] ?? $webhook['id'] ?? null;
            
            if (!$paymentId) {
                error_log('[MercadoPago] ❌ Webhook sem payment ID');
                return;
            }

            error_log('[MercadoPago] Action: ' . $action);
            error_log('[MercadoPago] Payment ID: ' . $paymentId);

            $payment = $this->getPaymentDetails($paymentId);
            
            if (!$payment) {
                error_log("[MercadoPago] ❌ Pagamento {$paymentId} não encontrado na API");
                return;
            }

            error_log('[MercadoPago] Status do pagamento: ' . $payment['status']);

            if ($payment['status'] !== 'approved') {
                error_log("[MercadoPago] ⏳ Pagamento ainda não aprovado: {$payment['status']}");
                return;
            }

            $externalRef = $payment['external_reference'] ?? null;
            if (!$externalRef) {
                error_log("[MercadoPago] ❌ External reference ausente");
                return;
            }

            $invoiceId = (int) preg_replace('/\D/', '', $externalRef);
            
            if (!$invoiceId) {
                error_log("[MercadoPago] ❌ External reference inválido: {$externalRef}");
                return;
            }

            error_log("[MercadoPago] 💰 Processando pagamento da fatura {$invoiceId}...");

            try {
                $invoice = $api_admin->invoice_get(['id' => $invoiceId]);
                
                if ($invoice['status'] === 'paid') {
                    error_log("[MercadoPago] ℹ️ Fatura {$invoiceId} já está paga. Ignorando webhook duplicado.");
                    return;
                }
            } catch (Exception $e) {
                error_log("[MercadoPago] ❌ Erro ao buscar fatura {$invoiceId}: " . $e->getMessage());
                return;
            }

            $api_admin->invoice_mark_as_paid([
                'id' => $invoiceId,
                'note' => "Pago via Mercado Pago (ID: {$paymentId})"
            ]);

            error_log("[MercadoPago] ✅✅✅ SUCESSO! Fatura {$invoiceId} marcada como PAGA (Payment ID: {$paymentId})");

        } catch (Exception $e) {
            error_log('[MercadoPago] ❌ ERRO CRÍTICO ao processar webhook: ' . $e->getMessage());
            error_log('[MercadoPago] Stack trace: ' . $e->getTraceAsString());
        }
    }

    private function validateWebhookSignature($data): bool
    {
        if (empty($this->config['secret_key'])) {
            error_log('[MercadoPago] ⚠️ Webhook aceito SEM validação (configure Secret Key!)');
            return true;
        }

        $headers = $data['headers'] ?? getallheaders() ?? [];
        $headers = array_change_key_case($headers, CASE_LOWER);
        
        $xSignature = $headers['x-signature'] ?? null;
        $xRequestId = $headers['x-request-id'] ?? null;

        if (!$xSignature || !$xRequestId) {
            error_log('[MercadoPago] ⚠️ Headers de assinatura ausentes');
            return false;
        }

        preg_match('/ts=(\d+),v1=([a-f0-9]+)/', $xSignature, $matches);
        
        if (count($matches) !== 3) {
            error_log('[MercadoPago] ❌ Formato de assinatura inválido');
            return false;
        }

        [$_, $timestamp, $hash] = $matches;
        $paymentId = $data['post']['data']['id'] ?? $data['data']['id'] ?? '';
        $manifest = implode(';', [$paymentId, $xRequestId, $timestamp]);
        $expectedHash = hash_hmac('sha256', $manifest, $this->config['secret_key']);
        $isValid = hash_equals($expectedHash, $hash);
        
        error_log('[MercadoPago] Validação de assinatura: ' . ($isValid ? '✅ OK' : '❌ FALHOU'));
        
        return $isValid;
    }

    private function getPaymentDetails($paymentId): ?array
    {
        $url = "https://api.mercadopago.com/v1/payments/{$paymentId}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->config['access_token']
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("[MercadoPago] ❌ Erro ao buscar pagamento: HTTP {$httpCode}");
            return null;
        }

        return json_decode($result, true);
    }

    private function sanitizeString(?string $str): string
    {
        if (!$str) return '';
        $str = preg_replace('/[^\p{L}\p{N}\s\-_.]/u', '', $str);
        return trim(substr($str, 0, 200));
    }

    private function renderError($preference): string
    {
        $errorMsg = $preference['error'] ?? 'Erro ao processar pagamento';
        
        return '
        <div class="alert alert-danger" style="padding:15px; margin:20px 0; border:1px solid #dc3545; border-radius:5px;">
            <h4>❌ Erro no Mercado Pago</h4>
            <p><strong>' . htmlspecialchars($errorMsg) . '</strong></p>
            <p><small>Verifique os logs em <code>data/log/application.log</code></small></p>
        </div>';
    }

    private function renderPaymentButton($url): string
    {
        return '
        <div style="text-align:center; padding:20px;">
            <button onclick="redirectToPayment()" class="btn btn-primary btn-lg" style="
                background: #009EE3;
                border: none;
                padding: 15px 40px;
                font-size: 18px;
                border-radius: 6px;
                cursor: pointer;
            ">
                <span style="font-weight:600;">💳 Pagar com Mercado Pago</span>
            </button>
            
            <script>
                const paymentUrl = ' . json_encode($url) . ';
                
                function redirectToPayment() {
                    window.location.href = paymentUrl;
                }
                
                setTimeout(redirectToPayment, 2000);
            </script>
            
            <p style="color:#666; margin-top:15px;">
                🔒 Pagamento seguro<br>
                <small>Redirecionando em <span id="countdown">2</span> segundos...</small>
            </p>
            
            <script>
                let seconds = 2;
                setInterval(() => {
                    const el = document.getElementById("countdown");
                    if (el) el.textContent = --seconds;
                }, 1000);
            </script>
        </div>';
    }
}
