# 💳 Mercado Pago Gateway for FOSSBilling

[![FOSSBilling](https://img.shields.io/badge/FOSSBilling-0.6+-blue)](https://fossbilling.org)
[![Mercado Pago API](https://img.shields.io/badge/Mercado%20Pago-API%20v1-00b1ea)](https://www.mercadopago.com.br/developers)
[![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache%202.0-green)](LICENSE)

Complete **Mercado Pago Checkout Pro** payment gateway for FOSSBilling with webhook support, HMAC signature validation, and automatic URL generation.

Donate: http://url.4teambr.com/paypal

## ✨ Features

- ✅ **Checkout Pro** - Secure redirect to Mercado Pago payment page
- ✅ **Automatic Webhooks** - Real-time payment notifications with HMAC validation
- ✅ **Dynamic URLs** - Automatically detects installation domain
- ✅ **Sandbox Mode** - Full test environment support
- ✅ **Detailed Logging** - Debug mode for troubleshooting
- ✅ **BRL Support** - Optimized for Brazilian Real
- ✅ **Email Validation** - Robust buyer data validation
- ✅ **Duplicate Prevention** - Avoids processing same payment twice

## 📋 Requirements

- **FOSSBilling** 0.6.0 or higher
- **PHP** 8.0 or higher
- **SSL Certificate** (HTTPS required for webhooks)
- **Mercado Pago Account** ([Sign up here](https://www.mercadopago.com.br))

## 🚀 Installation

### 1. Copy Files

```bash
# Navigate to FOSSBilling root directory
cd /path/to/fossbilling

# Copy the payment adapter
cp MercadoPago.php library/Payment/Adapter/MercadoPago.php

# Update ipn.php (backup original first)
cp ipn.php ipn.php.backup
cp ipn.php.new ipn.php
```

### 2. Get Mercado Pago Credentials

1. Access [Mercado Pago Developers](https://www.mercadopago.com.br/developers)
2. Go to **Your Integrations** > **Credentials**
3. Copy your **Access Token** (Production or Test)
4. Go to **Webhooks** and generate a **Secret Key**

### 3. Configure in FOSSBilling

1. Login to FOSSBilling admin panel
2. Go to **System** > **Payment Gateways**
3. Click **New Payment Gateway**
4. Select **Mercado Pago**
5. Fill in:
   - **Access Token**: Your production or test token
   - **Secret Key**: Your webhook secret key (optional but recommended)
   - **Test Mode**: Check if using test credentials
6. Save configuration

### 4. Configure Webhook in Mercado Pago

1. Go to [Mercado Pago Developers](https://www.mercadopago.com.br/developers)
2. Navigate to **Your Integrations** > **Webhooks**
3. Add new webhook:
   - **URL**: `https://yourdomain.com/ipn.php?gateway_id=X` 
     - Replace `X` with your gateway ID (find in FOSSBilling admin)
   - **Events**: Select **Payments**
4. Save webhook

**How to find gateway_id:**
- Go to FOSSBilling admin > Payment Gateways
- Click on Mercado Pago gateway
- Check the URL: `...gateway/1` → gateway_id is `1`

## 📁 File Structure

```
fossbilling/
├── library/
│   └── Payment/
│       └── Adapter/
│           └── MercadoPago.php    # Payment adapter
└── ipn.php                         # Webhook receiver (modified)
```

## 🧪 Testing

### Test Mode

1. Get **TEST credentials** from Mercado Pago
2. Enable **Test Mode** in gateway configuration
3. Use [test cards](https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/additional-content/test-cards)

### Verify Logs

Check logs at: `data/log/application.log`

Expected logs for successful payment:
```
[MercadoPago] Initiating payment for invoice #41
[MercadoPago] Generated webhook URL: https://yourdomain.com/ipn.php?gateway_id=6
[MercadoPago] Preference created successfully: 211834134-xxx
[IPN] Gateway ID received: 6
[IPN] Detected Mercado Pago webhook
[MercadoPago] Payment status: approved
[MercadoPago] ✅ Invoice 41 marked as paid (Payment ID: 134469763517)
```

## 🔧 Configuration Options

| Option | Description | Required |
|--------|-------------|----------|
| **Access Token** | Production or Test token from Mercado Pago | Yes |
| **Secret Key** | Webhook secret for signature validation | Recommended |
| **Test Mode** | Use sandbox environment | No |

## 🔐 Security

- ✅ **HMAC Signature Validation** - Verifies webhook authenticity
- ✅ **HTTPS Required** - Secure data transmission
- ✅ **Duplicate Detection** - Prevents double payments
- ✅ **Email Validation** - Sanitizes buyer data

## 🐛 Troubleshooting

### Payment not being marked as paid

1. Check webhook URL is correct in Mercado Pago dashboard
2. Verify logs in `data/log/application.log`
3. Ensure `gateway_id` matches your configuration
4. Test webhook manually:
   ```bash
   curl -X POST https://yourdomain.com/ipn.php?gateway_id=6 \
     -H "Content-Type: application/json" \
     -d '{"action":"payment.created","data":{"id":"123456"}}'
   ```

### Email validation errors

If you see `Invalid email` errors:
- Client registered with invalid email format
- Gateway uses fallback email to prevent payment failure
- Update client email in FOSSBilling admin
- Add email validation to registration form

### Webhook signature validation fails

1. Verify **Secret Key** is correctly configured
2. Check Mercado Pago dashboard webhook settings
3. Temporarily disable validation (not recommended for production):
   - Leave Secret Key empty in gateway configuration

## 📝 Customization

### Adjust Expiration Time

Edit in `MercadoPago.php`:
```php
"expiration_date_to" => date('c', strtotime('+3 days')), // 3 days instead of 7
```

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch
3. Test thoroughly
4. Submit a pull request

## 📄 License

Apache License 2.0 - See [LICENSE](LICENSE) file for details

## 🙏 Credits

Developed by [4TeamBR](https://4teambr.com)

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/Sage-BR/Gateway-Mercado-Pago-FossBilling/issues)
- **FOSSBilling Docs**: [FOSSBilling Documentation](https://fossbilling.org/docs)
- **Mercado Pago Docs**: [MP Developers](https://www.mercadopago.com.br/developers)

## 🔗 Useful Links

- [Mercado Pago API Reference](https://www.mercadopago.com.br/developers/pt/reference)
- [FOSSBilling GitHub](https://github.com/FOSSBilling/FOSSBilling)
- [Test Cards](https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/additional-content/test-cards)

---

**⭐ If this gateway helped you, please star the repository!**
