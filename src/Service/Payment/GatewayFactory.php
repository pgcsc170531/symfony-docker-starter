<?php

namespace App\Service\Payment;

/**
 * Picks the payment provider at runtime.
 *
 * Paystack when a secret key is configured, the local simulator otherwise. That
 * means a developer (or a school that has not finished Paystack onboarding) gets
 * a fully working payment journey instead of a dead button — and going live is
 * a matter of adding the key, not changing code.
 */
final class GatewayFactory
{
    public function __construct(
        private PaystackGateway $paystack,
        private FakeGateway $fake,
    ) {
    }

    public function create(): PaymentGatewayInterface
    {
        return $this->paystack->isConfigured() ? $this->paystack : $this->fake;
    }
}
