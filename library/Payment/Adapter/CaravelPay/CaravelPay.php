<?php
/**
 * Caravel Pay for FOSSBilling: card, Apple Pay, Google Pay and USDC payments, paid out to your own
 * Polygon wallet, minus the 2% Caravel fee.
 *
 * Install: put this CaravelPay/ folder in library/Payment/Adapter/, then add the gateway under
 * System > Payment gateways and paste your payout wallet.
 *
 * Only this file may sit at the top of the folder: FOSSBilling lists every top-level .php file in an
 * adapter folder as a gateway, so the rest of the code lives in src/ and lib/.
 */

require_once __DIR__ . '/lib/caravel-php/autoload.php';
require_once __DIR__ . '/lib/caravel-kit/autoload.php';
require_once __DIR__ . '/src/FossLedger.php';

use CaravelPay\Currency;
use CaravelPay\FeeMode;
use CaravelPay\Kit\Gateway;
use CaravelPay\Kit\PdoStorage;
use CaravelPay\Webhook;
use CaravelPay\FossBilling\FossLedger;

class Payment_Adapter_CaravelPay
{
    const VERSION = '1.2.1';

    /** @var array<string, mixed> */
    private $config;
    /** @var mixed FOSSBilling's dependency container. */
    protected $di;

    /**
     * @param array<string, mixed> $config Saved settings plus FOSSBilling's URLs (notify_url, thankyou_url, invoice_url…).
     */
    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : array();
    }

    public function setDi($di)
    {
        $this->di = $di;
    }

    public function getDi()
    {
        return $this->di;
    }

    public static function getConfig()
    {
        return array(
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Card, Apple Pay, Google Pay and USDC. Each payment goes to your Polygon wallet, minus the 2% Caravel fee. Paste your wallet and save: FOSSBilling connects to Caravel Pay on the first payment. Invoices in US dollars are charged as they are; invoices in EUR, GBP and other supported currencies are converted to US dollars when the client pays.',
            'form' => array(
                'payout_address' => array('text', array(
                    'label' => 'Payout wallet (Polygon). Every payout is sent here, minus the 2% Caravel fee. A new wallet gets new API keys.',
                    'required' => true,
                )),
                'contact_email' => array('text', array(
                    'label' => 'Contact email (optional). For operational notices from Caravel Pay; never used to log in.',
                    'required' => false,
                )),
                'fee_mode' => array('select', array(
                    'label' => 'Who pays the fees',
                    'description' => FeeMode::help(),
                    'multiOptions' => FeeMode::labels(),
                    'required' => false,
                )),
                'fx_buffer' => array('text', array(
                    'label' => Currency::bufferLabel() . '. ' . Currency::bufferHelp(),
                    'required' => false,
                )),
            ),
        );
    }

    /**
     * Shown after the client chooses Caravel Pay for an invoice: create or resume the checkout and
     * send the client to it.
     */
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $ledger = new FossLedger($api_admin, $this->service('logger'));
        $invoice = $ledger->invoice((string) $invoice_id);
        if (null === $invoice) {
            return '<p>' . self::e('This invoice was not found.') . '</p>';
        }
        if (\CaravelPay\Kit\Invoice::PAID === $invoice->status) {
            return '<p>' . self::e('This invoice is paid. Thank you!') . '</p>';
        }
        $return = $this->url('thankyou_url', 'return_url');
        $cancel = $this->url('invoice_url', 'cancel_url');
        $gateway = $this->gateway($ledger);
        // US dollars are charged as they are; another supported currency is converted by Caravel Pay at checkout.
        if (null === $gateway->chargeCurrency($invoice)) {
            return '<p>' . self::e('This invoice\'s currency can\'t be paid with Caravel Pay.') . '</p>';
        }
        $currencyNotice = $gateway->buyerNotice($invoice);
        $result = $gateway->startPayment($invoice, $return, $cancel);
        if (!isset($result['redirect'])) {
            return '<p>' . self::e($result['message']) . '</p>';
        }
        $url = $result['redirect'];
        // When fees are passed to the buyer, say so next to the button (the hosted page repeats it).
        $notice = ('' !== $currencyNotice ? '<p class="caravelpay-currency">' . self::e($currencyNotice) . '</p>' : '')
            . ('' !== $gateway->feeNotice() ? '<p class="caravelpay-fees">' . self::e($gateway->feeNotice()) . '</p>' : '');
        return $notice . '<p><a class="btn btn-primary" href="' . self::e($url) . '">' . self::e('Continue to the secure checkout') . '</a></p>'
            . '<script>window.location.href = ' . json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    }

    /**
     * ipn.php → here. Verify, re-fetch, apply. FOSSBilling answers 200 even when this throws, so
     * failures set the HTTP status themselves to make Caravel Pay retry.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $data = is_array($data) ? $data : array();
        $server = isset($data['server']) && is_array($data['server']) ? $data['server'] : array();
        $method = isset($server['REQUEST_METHOD']) ? strtoupper((string) $server['REQUEST_METHOD']) : 'POST';
        $ledger = new FossLedger($api_admin, $this->service('logger'), (int) $id);
        if ('POST' !== $method) {
            $result = array('status' => 405, 'result' => 'method_not_allowed');
        } else {
            $urls = array();
            if (!empty($this->config['notify_url'])) {
                $urls[] = (string) $this->config['notify_url'];
            }
            if (!empty($server['REQUEST_URI']) && '/' === substr((string) $server['REQUEST_URI'], 0, 1)) {
                $urls[] = (string) $server['REQUEST_URI'];
            }
            $body = isset($data['http_raw_post_data']) ? (string) $data['http_raw_post_data'] : '';
            $result = $urls ? $this->gateway($ledger)->handleWebhook($body, Webhook::headersFromServer($server), $urls) : array('status' => 503, 'result' => 'no_callback_url');
        }
        $ok = $result['status'] >= 200 && $result['status'] < 300;
        $ledger->updateTransaction(array(
            'type' => 'Caravel Pay webhook',
            'status' => $ok ? 'processed' : 'error',
            'txn_status' => (string) $result['result'],
            'error' => $ok ? '' : (string) $result['result'],
            'error_code' => $ok ? '' : (string) $result['status'],
        ));
        if (!$ok) {
            if (!headers_sent()) {
                http_response_code($result['status']);
            }
            $message = 'Caravel Pay webhook not processed: ' . $result['result'];
            throw class_exists('Payment_Exception') ? new \Payment_Exception($message) : new \RuntimeException($message);
        }
        return true;
    }

    /** The kit gateway for these settings. */
    public function gateway(FossLedger $ledger)
    {
        $system = defined('SYSTEM_URL') ? (string) constant('SYSTEM_URL') : self::origin(isset($this->config['notify_url']) ? (string) $this->config['notify_url'] : '');
        $config = array(
            'platform' => 'fossbilling',
            'platform_name' => 'FOSSBilling',
            'version' => self::VERSION,
            'payout_address' => isset($this->config['payout_address']) ? trim((string) $this->config['payout_address']) : '',
            'email' => isset($this->config['contact_email']) ? trim((string) $this->config['contact_email']) : '',
            // Who pays the fees: the saved select value; anything else (or nothing saved yet) is the default.
            'fee_mode' => isset($this->config['fee_mode']) ? (string) $this->config['fee_mode'] : FeeMode::SELLER,
            'fx_buffer' => isset($this->config['fx_buffer']) ? (string) $this->config['fx_buffer'] : '0',
            'site_url' => rtrim($system, '/') . '/',
            'callback_url' => isset($this->config['notify_url']) && '' !== (string) $this->config['notify_url'] ? (string) $this->config['notify_url'] : rtrim($system, '/') . '/ipn.php',
        );
        if (defined('CARAVEL_PAY_API_BASE')) {
            $config['api_base'] = (string) constant('CARAVEL_PAY_API_BASE');
        }
        foreach (array('caravel_transport' => 'transport', 'caravel_clock' => 'clock') as $key => $option) {
            if (isset($this->config[$key])) {
                $config[$option] = $this->config[$key]; // Tests only: never part of the saved settings form.
            }
        }
        $crypt = $this->service('crypt');
        $encrypt = is_object($crypt) && method_exists($crypt, 'encrypt') ? function ($v) use ($crypt) { return (string) $crypt->encrypt($v); } : null;
        $decrypt = is_object($crypt) && method_exists($crypt, 'decrypt') ? function ($v) use ($crypt) { return (string) $crypt->decrypt($v); } : null;
        return new Gateway(new PdoStorage($this->service('pdo'), 'caravelpay_', $encrypt, $decrypt), $ledger, $config);
    }

    private function url($primary, $fallback)
    {
        foreach (array($primary, $fallback) as $key) {
            if (!empty($this->config[$key])) {
                return (string) $this->config[$key];
            }
        }
        return '';
    }

    private function service($name)
    {
        if (null === $this->di) {
            return null;
        }
        try {
            return isset($this->di[$name]) ? $this->di[$name] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function origin($url)
    {
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/' : '';
    }

    private static function e($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
