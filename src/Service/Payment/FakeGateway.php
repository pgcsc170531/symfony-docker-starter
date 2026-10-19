<?php

namespace App\Service\Payment;

use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\School;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A stand-in gateway that runs the entire journey locally.
 *
 * Why it exists: the parent payment flow — intent, pay screen, callback,
 * verification, settlement, receipt — is a lot of moving parts, and none of them
 * should be first exercised against real money. This gateway lets us click
 * through the whole thing, and it is also what an unconfigured school falls back
 * to, so the app never advertises a pay button that cannot work.
 *
 * It is NOT a stub that pretends to work: it goes through the same
 * initialize → redirect → callback → verify path as the real one, and it can
 * simulate both success and decline.
 */
final class FakeGateway implements PaymentGatewayInterface
{
    /** Marker written by the simulated checkout page when the parent "pays". */
    public const CONFIRM_MARKER = 'FAKE-OK';

    /** Marker for the simulated "bank declined" path. */
    public const DECLINE_MARKER = 'FAKE-NO';

    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    public function name(): string
    {
        return PaymentIntent::PROVIDER_FAKE;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initialize(PaymentIntent $intent, School $school): GatewayResult
    {
        $url = $this->urlGenerator->generate(
            'app_payment_fake_checkout',
            ['reference' => $intent->getReference()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return GatewayResult::pending(
            authorizationUrl: $url,
            accessCode: 'FAKE-' . $intent->getReference(),
            providerReference: 'FAKE-' . $intent->getReference(),
            raw: ['simulated' => true],
        );
    }

    public function verify(PaymentIntent $intent): GatewayResult
    {
        return match ($intent->getProviderReference()) {
            self::CONFIRM_MARKER => GatewayResult::paid(
                providerReference: self::CONFIRM_MARKER,
                channel: 'fake',
                raw: ['simulated' => true, 'outcome' => 'paid'],
            ),
            self::DECLINE_MARKER => GatewayResult::failed('Simulated decline (fake gateway).'),
            default => GatewayResult::stillPending($intent->getProviderReference()),
        };
    }

    /**
     * The simulator deliberately accepts NO webhooks.
     *
     * It settles through the callback, which runs a server-side `verify()`. If
     * this returned a payload, the webhook endpoint — which is public by design —
     * would accept an unsigned POST from anyone and mark invoices as paid.
     * Returning null makes the controller answer 400, exactly as it would for a
     * forged Paystack signature.
     */
    public function parseWebhook(string $rawBody, array $headers): ?array
    {
        return null;
    }
}
