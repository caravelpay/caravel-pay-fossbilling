<?php

namespace CaravelPay\FossBilling;

use CaravelPay\Amount;
use CaravelPay\Checkout;
use CaravelPay\Kit\Invoice;
use CaravelPay\Kit\Ledger;

/**
 * FOSSBilling side of the payment flow, through the admin API: invoice_get, client_balance_add_funds
 * + invoice_pay_with_credits (how FOSSBilling's own gateways pay invoices), invoice_update notes and
 * invoice_transaction_update.
 */
final class FossLedger implements Ledger
{
    /** @var object */
    private $api;
    /** @var object|null */
    private $logger;
    /** @var int */
    private $transactionId;

    public function __construct($api_admin, $logger = null, int $transactionId = 0)
    {
        $this->api = $api_admin;
        $this->logger = $logger;
        $this->transactionId = $transactionId;
    }

    public function invoice(string $ref): ?Invoice
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $ref)) {
            return null;
        }
        try {
            $invoice = $this->api->invoice_get(array('id' => (int) $ref));
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($invoice)) {
            return null;
        }
        $platform = isset($invoice['status']) ? (string) $invoice['status'] : '';
        $map = array('unpaid' => Invoice::UNPAID, 'paid' => Invoice::PAID, 'canceled' => Invoice::CANCELLED, 'cancelled' => Invoice::CANCELLED);
        try {
            $total = Amount::normalize(is_string($invoice['total']) ? $invoice['total'] : (float) $invoice['total']);
        } catch (\Throwable $e) {
            $total = '0.00';
        }
        $currency = strtoupper(isset($invoice['currency']) ? (string) $invoice['currency'] : '');
        $email = isset($invoice['buyer']['email']) && is_string($invoice['buyer']['email']) ? $invoice['buyer']['email'] : null;
        return new Invoice($ref, isset($map[$platform]) ? $map[$platform] : Invoice::OTHER, $total, $currency, 'USD' === $currency ? $total : '', $email, $platform);
    }

    public function recordPayment(Invoice $invoice, array $checkout, string $txHash, string $nativeAmount, string $fee): bool
    {
        $raw = $this->api->invoice_get(array('id' => (int) $invoice->ref));
        $clientId = isset($raw['client_id']) ? (int) $raw['client_id'] : (isset($raw['client']['id']) ? (int) $raw['client']['id'] : 0);
        if ($clientId < 1) {
            $this->log('error', 'Invoice ' . $invoice->ref . ' has no client; the Caravel Pay payment was not recorded.');
            return false;
        }
        // client_balance.rel_id is VARCHAR(20): reference the transaction/invoice id here and keep the full tx hash in description and txn_id.
        $relId = $this->transactionId > 0 ? (string) $this->transactionId : $invoice->ref;
        if (strlen($relId) > 20) {
            $relId = substr($relId, 0, 20);
        }
        // Funds first: if this throws, nothing was added and the webhook is retried.
        $this->api->client_balance_add_funds(array(
            'id' => $clientId,
            'amount' => $nativeAmount,
            'description' => 'Caravel Pay payment ' . $txHash,
            'type' => 'transaction',
            'rel_id' => $relId,
        ));
        $this->updateTransaction(array('invoice_id' => (int) $invoice->ref, 'txn_id' => $txHash, 'txn_status' => 'paid', 'amount' => $nativeAmount, 'currency' => $invoice->nativeCurrency, 'status' => 'processed'));
        $paid = false;
        try {
            $this->api->invoice_pay_with_credits(array('id' => (int) $invoice->ref));
            $after = $this->api->invoice_get(array('id' => (int) $invoice->ref));
            $paid = is_array($after) && isset($after['status']) && 'paid' === $after['status'];
        } catch (\Throwable $e) {
            $paid = false;
        }
        $payout = array();
        foreach (Checkout::payouts($checkout) as $item) {
            $payout[] = $item['amount'] . ' ' . $item['asset'] . ', ' . Checkout::explorerUrl($item['tx_hash']);
        }
        if ($paid) {
            $this->note($invoice->ref, 'Caravel Pay payment confirmed (checkout ' . $checkout['id'] . '). Payout: ' . implode('; ', $payout) . '.');
        } else {
            // The funds are on the client's balance: never add them twice, ask the admin to apply them.
            $amountLabel = '' !== $invoice->nativeCurrency ? $nativeAmount . ' ' . $invoice->nativeCurrency : '$' . $nativeAmount;
            $this->note($invoice->ref, 'Caravel Pay added ' . $amountLabel . ' to the client\'s credit balance (checkout ' . $checkout['id'] . '), but it could not be applied to this invoice automatically. Apply the credit to the invoice.');
            $this->log('error', 'Invoice ' . $invoice->ref . ': payment credited to the client but not applied to the invoice.');
        }
        return true;
    }

    public function flag(Invoice $invoice, string $status, string $message): void
    {
        $this->log('expired' === $status ? 'info' : 'warning', 'Invoice ' . $invoice->ref . ': ' . $message);
        if ('expired' !== $status) {
            $this->note($invoice->ref, $message);
        }
    }

    public function log(string $level, string $message, array $context = array()): void
    {
        if (!is_object($this->logger)) {
            return;
        }
        try {
            $this->logger->{'error' === $level ? 'err' : 'info'}('Caravel Pay: ' . $message);
        } catch (\Throwable $e) {
            // Logging must never break a payment.
        }
    }

    /** @param array<string, mixed> $fields */
    public function updateTransaction(array $fields): void
    {
        if ($this->transactionId < 1) {
            return;
        }
        try {
            $this->api->invoice_transaction_update(array('id' => $this->transactionId) + $fields);
        } catch (\Throwable $e) {
            $this->log('error', 'Could not update transaction ' . $this->transactionId . '.');
        }
    }

    private function note(string $ref, string $message): void
    {
        try {
            $invoice = $this->api->invoice_get(array('id' => (int) $ref));
            $notes = is_array($invoice) && isset($invoice['notes']) ? trim((string) $invoice['notes']) : '';
            $line = gmdate('Y-m-d H:i') . ' UTC ' . $message;
            $this->api->invoice_update(array('id' => (int) $ref, 'notes' => '' === $notes ? $line : $notes . "\n" . $line));
        } catch (\Throwable $e) {
            $this->log('error', 'Could not add a note to invoice ' . $ref . '.');
        }
    }
}
