<?php

namespace App\Service\Payment;

use App\Entity\Tenant\PaymentIntent;

/**
 * What a gateway tells us back. One shape for every provider, so the controllers
 * and the settlement service never branch on "which gateway is this".
 */
final class GatewayResult
{
    /**
     * @param array<string, mixed> $raw the provider's own payload, kept for audit
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $status,
        public readonly ?string $authorizationUrl = null,
        public readonly ?string $accessCode = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $channel = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {
    }

    /** The parent must now be sent to the gateway's page. */
    public static function pending(
        string $authorizationUrl,
        ?string $accessCode = null,
        ?string $providerReference = null,
        array $raw = [],
    ): self {
        return new self(
            ok: true,
            status: PaymentIntent::STATUS_PENDING,
            authorizationUrl: $authorizationUrl,
            accessCode: $accessCode,
            providerReference: $providerReference,
            raw: $raw,
        );
    }

    /** The gateway confirms the money is in. */
    public static function paid(
        string $providerReference,
        ?string $channel = null,
        ?string $error = null,
        array $raw = [],
    ): self {
        return new self(
            ok: true,
            status: PaymentIntent::STATUS_PAID,
            providerReference: $providerReference,
            channel: $channel,
            error: $error,
            raw: $raw,
        );
    }

    /** Declined, abandoned, or the gateway said no. */
    public static function failed(string $error, array $raw = []): self
    {
        return new self(ok: false, status: PaymentIntent::STATUS_FAILED, error: $error, raw: $raw);
    }

    /** Still pending at the gateway — the parent has not finished. */
    public static function stillPending(?string $providerReference = null, array $raw = []): self
    {
        return new self(
            ok: true,
            status: PaymentIntent::STATUS_PENDING,
            providerReference: $providerReference,
            raw: $raw,
        );
    }

    public function isPaid(): bool
    {
        return $this->ok && $this->status === PaymentIntent::STATUS_PAID;
    }
}
