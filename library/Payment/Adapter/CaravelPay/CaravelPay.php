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
if (!interface_exists('FOSSBilling\\Core\\Payment\\JsonWebhookAuthenticatorInterface')) {
    require_once __DIR__ . '/src/JsonWebhookAuthenticatorInterface.php';
}

use CaravelPay\Currency;
use CaravelPay\Exception\SignatureVerificationException;
use CaravelPay\FeeMode;
use CaravelPay\Kit\Gateway;
use CaravelPay\Kit\PdoStorage;
use CaravelPay\Webhook;
use CaravelPay\FossBilling\FossLedger;

class Payment_Adapter_CaravelPay implements \FOSSBilling\Core\Payment\JsonWebhookAuthenticatorInterface
{
    const VERSION = '1.2.2';
    const AUTH_CACHE_KEY = 'fb_webhook_auth';
    const AUTH_CACHE_TTL = 86400;
    const AUTH_CACHE_MAX = 100;

    /** @var array<string, array{merchant: string, timestamp: string, event: string, expires_at: int}> */
    private static $authenticatedWebhooks = array();

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
     * Synchronous HMAC verification called by FOSSBilling (main / 0.9+) before persisting the
     * transaction or flushing an early 200 OK response under PHP-FPM.
     */
    public function authenticateJsonWebhook(array $data): void
    {
        $server = isset($data['server']) && is_array($data['server']) ? $data['server'] : array();
        $method = isset($server['REQUEST_METHOD']) ? strtoupper((string) $server['REQUEST_METHOD']) : 'POST';
        if ('POST' !== $method) {
            $this->rejectWebhook(405, 'method_not_allowed');
        }
        $urls = $this->webhookUrls($server);
        if (!$urls) {
            $this->rejectWebhook(503, 'no_callback_url');
        }
        $storage = $this->storage();
        $now = $this->now();
        $secrets = $this->webhookSecrets($storage, $now);
        if (!$secrets) {
            $this->rejectWebhook(503, 'not_connected');
        }
        $body = isset($data['http_raw_post_data']) ? (string) $data['http_raw_post_data'] : '';
        $headers = Webhook::headersFromServer($server);
        try {
            $event = Webhook::verify($body, $headers, $secrets, $urls, array('now' => $now));
        } catch (SignatureVerificationException $e) {
            $this->rejectWebhook($e->getHttpStatus(), $e->getReason());
            return;
        }
        $this->rememberAuthenticatedHeaders($storage, $headers, $body, $event->merchantId, $event->id, $now);
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
            $urls = $this->webhookUrls($server);
            $body = isset($data['http_raw_post_data']) ? (string) $data['http_raw_post_data'] : '';
            $headers = $this->restoreAuthenticatedHeaders($this->storage(), Webhook::headersFromServer($server), $body, $this->now());
            $result = $urls ? $this->gateway($ledger)->handleWebhook($body, $headers, $urls) : array('status' => 503, 'result' => 'no_callback_url');
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
            $this->rejectWebhook($result['status'], $result['result']);
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
        return new Gateway($this->storage(), $ledger, $config);
    }

    private function storage()
    {
        $crypt = $this->service('crypt');
        $encrypt = is_object($crypt) && method_exists($crypt, 'encrypt') ? function ($v) use ($crypt) { return (string) $crypt->encrypt($v); } : null;
        $decrypt = is_object($crypt) && method_exists($crypt, 'decrypt') ? function ($v) use ($crypt) { return (string) $crypt->decrypt($v); } : null;
        return new PdoStorage($this->service('pdo'), 'caravelpay_', $encrypt, $decrypt);
    }

    private function now()
    {
        return isset($this->config['caravel_clock']) && is_callable($this->config['caravel_clock'])
            ? (int) call_user_func($this->config['caravel_clock'])
            : time();
    }

    /** @return string[] */
    private function webhookUrls(array $server)
    {
        $urls = array();
        if (!empty($this->config['notify_url'])) {
            $urls[] = (string) $this->config['notify_url'];
        }
        if (!empty($server['REQUEST_URI']) && '/' === substr((string) $server['REQUEST_URI'], 0, 1)) {
            $urls[] = (string) $server['REQUEST_URI'];
        }
        return $urls;
    }

    /** @return array<string, string> */
    private function webhookSecrets(PdoStorage $storage, $now)
    {
        $secrets = array();
        foreach ($storage->credentials() as $row) {
            $merchant = (string) $row['merchant_id'];
            if ('current' === $row['status']
                || (int) $row['retired_at'] >= $now - Gateway::KEEP_RETIRED_SECONDS
                || $storage->latestCheckoutAt($merchant) >= $now - Gateway::KEEP_RETIRED_SECONDS) {
                $secrets[$merchant] = (string) $row['secret'];
            }
        }
        return $secrets;
    }

    private function rejectWebhook($status, $reason)
    {
        if (!headers_sent()) {
            http_response_code((int) $status);
        }
        $message = 'Caravel Pay webhook not processed: ' . $reason;
        throw class_exists('Payment_Exception') ? new \Payment_Exception($message) : new \RuntimeException($message);
    }

    private static function webhookAuthKey($signature, $body)
    {
        return hash('sha256', strtolower(trim((string) $signature)) . "\n" . $body);
    }

    private function rememberAuthenticatedHeaders(PdoStorage $storage, array $headers, $body, $merchantId, $eventId, $now)
    {
        $signature = isset($headers['caravel-signature']) ? (string) $headers['caravel-signature'] : '';
        $timestamp = isset($headers['caravel-timestamp']) ? (string) $headers['caravel-timestamp'] : '';
        if ('' === $signature || '' === $timestamp) {
            return;
        }
        $key = self::webhookAuthKey($signature, $body);
        $entry = array(
            'merchant' => (string) $merchantId,
            'timestamp' => $timestamp,
            'event' => (string) $eventId,
            'expires_at' => (int) $now + self::AUTH_CACHE_TTL,
        );
        self::$authenticatedWebhooks[$key] = $entry;
        if (count(self::$authenticatedWebhooks) > self::AUTH_CACHE_MAX) {
            self::$authenticatedWebhooks = array_slice(self::$authenticatedWebhooks, -self::AUTH_CACHE_MAX, null, true);
        }
        $lock = null;
        try {
            $lock = $storage->lock(self::AUTH_CACHE_KEY, 5, (int) $now);
            $raw = $storage->setting(self::AUTH_CACHE_KEY);
            $map = is_string($raw) ? json_decode($raw, true) : array();
            if (!is_array($map)) {
                $map = array();
            }
            foreach ($map as $k => $v) {
                if (!is_array($v) || !isset($v['expires_at']) || (int) $v['expires_at'] < $now) {
                    unset($map[$k]);
                }
            }
            $map[$key] = $entry;
            if (count($map) > self::AUTH_CACHE_MAX) {
                $map = array_slice($map, -self::AUTH_CACHE_MAX, null, true);
            }
            $storage->saveSetting(self::AUTH_CACHE_KEY, (string) json_encode($map));
        } catch (\Throwable $e) {
            // In-process cache still handles same-request processTransaction().
        } finally {
            if (null !== $lock) {
                $storage->unlock(self::AUTH_CACHE_KEY, $lock);
            }
        }
    }

    /**
     * Restore Caravel-Merchant / Caravel-Timestamp / Caravel-Event when FOSSBilling main strips
     * non-signature HTTP_* headers from stored IPN payloads after authenticateJsonWebhook() succeeded.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function restoreAuthenticatedHeaders(PdoStorage $storage, array $headers, $body, $now)
    {
        if (!isset($headers['caravel-signature']) || '' === $headers['caravel-signature']) {
            return $headers;
        }
        if (isset($headers['caravel-merchant']) && '' !== $headers['caravel-merchant']
            && isset($headers['caravel-timestamp']) && '' !== $headers['caravel-timestamp']) {
            return $headers;
        }
        $key = self::webhookAuthKey($headers['caravel-signature'], $body);
        $entry = isset(self::$authenticatedWebhooks[$key]) ? self::$authenticatedWebhooks[$key] : null;
        if (null === $entry) {
            try {
                $raw = $storage->setting(self::AUTH_CACHE_KEY);
                $map = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($map) && isset($map[$key]) && is_array($map[$key])) {
                    $entry = $map[$key];
                }
            } catch (\Throwable $e) {
                $entry = null;
            }
        }
        if (!is_array($entry) || !isset($entry['expires_at']) || (int) $entry['expires_at'] < $now) {
            return $headers;
        }
        if ((!isset($headers['caravel-merchant']) || '' === $headers['caravel-merchant'])
            && isset($entry['merchant']) && is_string($entry['merchant'])) {
            $headers['caravel-merchant'] = $entry['merchant'];
        }
        if ((!isset($headers['caravel-timestamp']) || '' === $headers['caravel-timestamp'])
            && isset($entry['timestamp']) && is_string($entry['timestamp'])) {
            $headers['caravel-timestamp'] = $entry['timestamp'];
        }
        if ((!isset($headers['caravel-event']) || '' === $headers['caravel-event'])
            && isset($entry['event']) && is_string($entry['event'])) {
            $headers['caravel-event'] = $entry['event'];
        }
        return $headers;
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
