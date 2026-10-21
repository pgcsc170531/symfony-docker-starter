<?php

namespace App\Service\Payment;

use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\School;
use App\Service\Payments\PlatformPaymentSwitch;

/**
 * The single answer to "may this school take money online right now?".
 *
 * Four conditions, and every one of them must hold before a "Pay online" button
 * is drawn:
 *
 *   1. the platform operator has not switched online collection off;
 *   2. the school has opted in (`payment_method` = online or both);
 *   3. a gateway is configured (a real key, or the simulator);
 *   4. a real provider has somewhere for the money to land (the subaccount).
 *
 * This lived as two private methods in two controllers, which is how a button and
 * an endpoint end up disagreeing about whether a payment is possible. One class,
 * one answer.
 */
final class OnlinePaymentAvailability
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private PlatformPaymentSwitch $platformSwitch,
    ) {
    }

    public function isAvailable(?School $school): bool
    {
        if ($school === null || !$this->platformSwitch->isEnabled()) {
            return false;
        }

        if (!$school->acceptsOnlinePayment() || !$this->gateway->isConfigured()) {
            return false;
        }

        if ($this->gateway->name() === PaymentIntent::PROVIDER_PAYSTACK) {
            return trim((string) $school->getPaystackSubaccountCode()) !== '';
        }

        return true;
    }
}
