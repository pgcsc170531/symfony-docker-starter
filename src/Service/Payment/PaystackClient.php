<?php

namespace App\Service\Payment;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A small, entity-free Paystack client.
 *
 * `PaystackGateway` is bound to a parent's invoice (PaymentIntent, split to the
 * school's subaccount). Subscription money is different: it is Edus's own
 * revenue, it goes to Edus's account with no split, and the "invoice" lives in
 * the landlord database. Sharing the entity-bound gateway would mean faking a
 * tenant invoice, so this client exists instead.
 *
 * Same rule as everywhere else: the callback is a hint, `verify()` is the proof.
 */
final class PaystackClient
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire('%paystack_secret_key%')]
        private string $secretKey = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->secretKey) !== '';
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{ok: bool, url?: string, reference?: string, message?: string}
     */
    public function initialize(
        string $email,
        int $amountMinorUnits,
        string $reference,
        string $callbackUrl,
        array $metadata = [],
    ): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Online payment is not configured yet.'];
        }

        try {
            $body = $this->httpClient->request('POST', self::BASE_URL . '/transaction/initialize', [
                'headers' => $this->headers(),
                'json' => [
                    'email' => $email,
                    'amount' => $amountMinorUnits,
                    'currency' => 'NGN',
                    'reference' => $reference,
                    'callback_url' => $callbackUrl,
                    'metadata' => $metadata,
                ],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Subscription Paystack initialize failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'We could not reach the payment provider. Please try again.'];
        }

        if (($body['status'] ?? false) !== true || empty($body['data']['authorization_url'])) {
            $message = (string) ($body['message'] ?? 'The payment provider rejected this request.');
            $this->logger->warning('Subscription Paystack initialize rejected: {message}', ['message' => $message]);

            return ['ok' => false, 'message' => $message];
        }

        return [
            'ok' => true,
            'url' => (string) $body['data']['authorization_url'],
            'reference' => (string) ($body['data']['reference'] ?? $reference),
        ];
    }

    /**
     * @return array{status: string, amount: int, channel: ?string, reference: ?string}
     *         status: success | failed | pending | unknown
     */
    public function verify(string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'unknown', 'amount' => 0, 'channel' => null, 'reference' => $reference];
        }

        try {
            $body = $this->httpClient->request(
                'GET',
                self::BASE_URL . '/transaction/verify/' . rawurlencode($reference),
                ['headers' => $this->headers()]
            )->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Subscription Paystack verify failed: {message}', ['message' => $e->getMessage()]);

            return ['status' => 'unknown', 'amount' => 0, 'channel' => null, 'reference' => $reference];
        }

        $data = $body['data'] ?? [];
        $status = (string) ($data['status'] ?? '');

        return [
            'status' => match ($status) {
                'success' => 'success',
                'failed', 'reversed' => 'failed',
                'abandoned' => 'pending',
                default => 'unknown',
            },
            'amount' => (int) ($data['amount'] ?? 0),
            'channel' => isset($data['channel']) ? (string) $data['channel'] : null,
            'reference' => isset($data['reference']) ? (string) $data['reference'] : $reference,
        ];
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>|null null means "not authentic"
     */
    public function parseWebhook(string $rawBody, array $headers): ?array
    {
        $signature = $this->header($headers, 'x-paystack-signature');
        if ($signature === null || trim($this->secretKey) === '') {
            return null;
        }

        if (!hash_equals(hash_hmac('sha512', $rawBody, $this->secretKey), $signature)) {
            $this->logger->warning('Subscription Paystack webhook rejected: bad signature.');

            return null;
        }

        $payload = json_decode($rawBody, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . trim($this->secretKey),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower(str_replace('_', '-', (string) $key)) === $name) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return null;
    }
}
