<?php

namespace FOSSBilling\Core\Payment;

if (!interface_exists('FOSSBilling\\Core\\Payment\\JsonWebhookAuthenticatorInterface', false)) {
    /**
     * Compatibility shim for FOSSBilling <= 0.8.x. FOSSBilling 0.9+ / main provides this interface
     * and invokes authenticateJsonWebhook() synchronously before persisting or acknowledging a JSON webhook.
     */
    interface JsonWebhookAuthenticatorInterface
    {
        public function authenticateJsonWebhook(array $data): void;
    }
}
