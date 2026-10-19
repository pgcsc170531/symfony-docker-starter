<?php

namespace App\Service\Payment;

use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\School;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Paystack, via hosted checkout.
 *
 * Flow: initialize → send the parent to `authorization_url` → Paystack takes the
 * payment (card / bank transfer / USSD, their choice) → the parent comes back to
 * our callback → we **verify server-side** → settle.
 *
 * The callback is a hint, never proof: `verify()` is the only thing allowed to
 * declare a payment successful, and the webhook is the safety net for parents
 * who close the tab before returning.
 *
 * ⚠️ Two things to confirm against the live account before going live:
 *   1. the split on a school's subaccount (see `splitParameters()`), and
 *   2. that the account's pricing for this channel matches the 1% assumption in
 *      `ProcessingFeeCalculator` — the ₦500 parent-paid cap depends on it.
 */
final class PaystackGateway implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire('%paystack_secret_key%')]
        private string $secretKey = '',
    ) {
    }

    public function name(): string
    {
        return PaymentIntent::PROVIDER_PAYSTACK;
    }

    public function isConfigured(): bool
    {
        return trim($this->secretKey) !== '';
    }

    public function initialize(PaymentIntent $intent, School $school): GatewayResult
    {
        $invoice = $intent->getInvoice();
        $guardian = $invoice?->getStudent()?->getGuardian();

        // Paystack wants an email; fall back to the school's own address so a
        // parent without one can still pay.
        $email = $guardian?->getEmail();
        if ($email === null || trim($email) === '') {
            $email = (string) $school->getEmail();
        }

        if ($email === '') {
            return GatewayResult::failed('No email address is available for the receipt.');
        }

        $payload = [
            'email' => $email,
            'amount' => $intent->getAmountInMinorUnits(),
            'currency' => $intent->getCurrency(),
            'reference' => $intent->getReference(),
            'callback_url' => $this->urlGenerator->generate(
                'app_payment_callback',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            'metadata' => [
                'invoice_number' => $invoice?->getInvoiceNumber(),
                'student' => $invoice?->getStudent()?->getFullName(),
                'school' => $school->getName(),
                'intent_reference' => $intent->getReference(),
            ],
        ] + $this->splitParameters($school);

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . '/transaction/initialize', [
                'headers' => $this->headers(),
                'json' => $payload,
            ]);

            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack initialize failed: {message}', ['message' => $e->getMessage()]);

            return GatewayResult::failed('We could not reach the payment provider. Please try again.');
        }

        if (($body['status'] ?? false) !== true) {
            $message = (string) ($body['message'] ?? 'The payment provider rejected this request.');
            $this->logger->warning('Paystack initialize rejected: {message}', ['message' => $message]);

            return GatewayResult::failed($message, $body);
        }

        $data = $body['data'] ?? [];

        if (empty($data['authorization_url'])) {
            return GatewayResult::failed('The payment provider did not return a checkout link.', $body);
        }

        return GatewayResult::pending(
            authorizationUrl: (string) $data['authorization_url'],
            accessCode: $data['access_code'] ?? null,
            providerReference: $data['reference'] ?? $intent->getReference(),
            raw: $body,
        );
    }

    public function verify(PaymentIntent $intent): GatewayResult
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                self::BASE_URL . '/transaction/verify/' . rawurlencode((string) $intent->getReference()),
                ['headers' => $this->headers()]
            );

            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack verify failed: {message}', ['message' => $e->getMessage()]);

            return GatewayResult::failed('We could not confirm the payment. Please try again.');
        }

        $data = $body['data'] ?? [];
        $status = (string) ($data['status'] ?? '');

        if ($status === 'success') {
            // Never trust the amount alone — the gateway is the source of truth
            // for money, but a mismatch means we should not silently settle.
            $paidMinor = (int) ($data['amount'] ?? 0);
            if ($paidMinor !== $intent->getAmountInMinorUnits()) {
                $this->logger->error(
                    'Paystack amount mismatch on {reference}: expected {expected}, got {got}',
                    [
                        'reference' => $intent->getReference(),
                        'expected' => $intent->getAmountInMinorUnits(),
                        'got' => $paidMinor,
                    ]
                );

                return GatewayResult::failed('The amount paid does not match the invoice.', $body);
            }

            return GatewayResult::paid(
                providerReference: (string) ($data['reference'] ?? $intent->getReference()),
                channel: $data['channel'] ?? null,
                raw: $body,
            );
        }

        if (in_array($status, ['failed', 'reversed'], true)) {
            return GatewayResult::failed((string) ($data['gateway_response'] ?? 'Payment failed.'), $body);
        }

        return GatewayResult::stillPending((string) ($data['reference'] ?? $intent->getReference()), $body);
    }

    public function parseWebhook(string $rawBody, array $headers): ?array
    {
        $signature = $this->header($headers, 'x-paystack-signature');
        if ($signature === null || trim($this->secretKey) === '') {
            return null;
        }

        $expected = hash_hmac('sha512', $rawBody, $this->secretKey);

        // Constant-time compare, and it happens BEFORE the body is parsed.
        if (!hash_equals($expected, $signature)) {
            $this->logger->warning('Paystack webhook rejected: bad signature.');

            return null;
        }

        $payload = json_decode($rawBody, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Route the money to the school's subaccount.
     *
     * `bearer: account` means Edus absorbs Paystack's fee out of the processing
     * fee the parent paid, so the school nets its full invoice.
     *
     * ⚠️ The precise split (percentage vs flat `transaction_charge`) is set on
     * the subaccount in the Paystack dashboard and must be confirmed for each
     * school before go-live. Everything above this line is unaffected by that
     * decision, which is exactly why it is isolated here.
     *
     * @return array<string, string>
     */
    private function splitParameters(School $school): array
    {
        $subaccount = trim((string) $school->getPaystackSubaccountCode());

        if ($subaccount === '') {
            return [];
        }

        return [
            'subaccount' => $subaccount,
            'bearer' => 'account',
        ];
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
     * Header lookup that does not care how the web server cased or prefixed it.
     *
     * @param array<string, mixed> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            $normalised = strtolower(str_replace('_', '-', (string) $key));
            if ($normalised === $name) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return null;
    }
}
