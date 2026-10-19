<?php

namespace App\Service\Payment;

use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\School;

/**
 * Everything the application needs from a payment provider.
 *
 * Building against this interface is what let us finish the whole parent-payment
 * journey — intent, pay screen, settlement, receipt, webhook — before Paystack's
 * open questions were answered. Swapping the provider is a container change.
 */
interface PaymentGatewayInterface
{
    /** 'paystack' | 'fake' — stored on the intent so we know who handled it. */
    public function name(): string;

    /** False when keys/config are missing; the UI hides online payment then. */
    public function isConfigured(): bool;

    /**
     * Start a payment attempt.
     *
     * For a hosted-checkout provider this returns a URL to redirect to. It must
     * never mark anything as paid — only `verify()` or a webhook can do that.
     */
    public function initialize(PaymentIntent $intent, School $school): GatewayResult;

    /**
     * Ask the provider what actually happened.
     *
     * Always called on the way back from the provider: the parent's browser is
     * not a trustworthy source of truth about money.
     */
    public function verify(PaymentIntent $intent): GatewayResult;

    /**
     * Validate a webhook and return its payload, or null when the signature does
     * not check out.
     *
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>|null
     */
    public function parseWebhook(string $rawBody, array $headers): ?array;
}
