<?php

namespace App\Service\Payment;

use App\Service\Settings\PlatformSettings;
use Psr\Log\LoggerInterface;
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
 * **The key comes from `PlatformSettings`** — the landlord settings screen first,
 * then the environment — which is the same source `PaystackGateway` uses. It used
 * to read `%paystack_secret_key%` directly, and that split the app in two: a key
 * pasted into the admin screen drove card checkout and webhook verification while
 * dedicated accounts, subaccounts and payouts kept using a stale environment key.
 *
 * Same rule as everywhere else: the callback is a hint, `verify()` is the proof.
 */
final class PaystackClient
{
    private const BASE_URL = 'https://api.paystack.co';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private PlatformSettings $settings,
    ) {
    }

    /** Empty when no key is configured anywhere. */
    private function secretKey(): string
    {
        return trim((string) ($this->settings->paystackSecretKey() ?? ''));
    }

    public function isConfigured(): bool
    {
        return $this->secretKey() !== '';
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

        if ($signature === null || $this->secretKey() === '') {
            return null;
        }

        if (!hash_equals(hash_hmac('sha512', $rawBody, $this->secretKey()), $signature)) {
            $this->logger->warning('Subscription Paystack webhook rejected: bad signature.');

            return null;
        }

        $payload = json_decode($rawBody, true);

        return is_array($payload) ? $payload : null;
    }

    // ── Dedicated virtual accounts ──────────────────────────────────────────
    //
    // Backend-only; a parent never calls these. Three steps, in Paystack's own
    // order (docs read 23 Oct 2026): create the customer, validate them (only
    // for the Betting / Financial services / General services categories), then
    // create the account on the school's subaccount.

    /**
     * Create the customer that will own the account.
     *
     * Paystack keys customers on **email**, and the same parent appears in every
     * school of a group — so a rejected duplicate is not an error, it means the
     * customer already exists and we should reuse the code.
     *
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function createCustomer(string $email, string $firstName, string $lastName, string $phone): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Online payment is not configured yet.'];
        }

        try {
            $body = $this->httpClient->request('POST', self::BASE_URL . '/customer', [
                'headers' => $this->headers(),
                'json' => [
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                ],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack createCustomer failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'We could not reach the payment provider. Please try again.'];
        }

        $code = (string) ($body['data']['customer_code'] ?? '');

        if (($body['status'] ?? false) === true && $code !== '') {
            return ['ok' => true, 'code' => $code];
        }

        // Likely "Customer already exists" — look the existing record up.
        $existing = $this->fetchCustomer($email);
        if ($existing['ok'] && ($existing['code'] ?? '') !== '') {
            return ['ok' => true, 'code' => $existing['code']];
        }

        $message = (string) ($body['message'] ?? 'The payment provider rejected this customer.');
        $this->logger->warning('Paystack createCustomer rejected: {message}', ['message' => $message]);

        return ['ok' => false, 'message' => $message];
    }

    /**
     * Validate the payer's identity: BVN + a bank account in their own name.
     *
     * Paystack answers 202 — the outcome arrives by webhook. Only required when
     * our merchant category demands it; the caller decides whether to ask.
     *
     * @return array{ok: bool, message?: string}
     */
    public function validateCustomer(
        string $customerCode,
        string $bvn,
        string $bankCode,
        string $accountNumber,
        string $firstName,
        string $lastName,
    ): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Online payment is not configured yet.'];
        }

        try {
            $body = $this->httpClient->request(
                'POST',
                self::BASE_URL . '/customer/' . rawurlencode($customerCode) . '/identification',
                [
                    'headers' => $this->headers(),
                    'json' => [
                        'country' => 'NG',
                        'type' => 'bank_account',
                        'account_number' => $accountNumber,
                        'bvn' => $bvn,
                        'bank_code' => $bankCode,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ],
                ]
            )->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack validateCustomer failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'We could not reach the payment provider. Please try again.'];
        }

        $ok = ($body['status'] ?? false) === true;

        if (!$ok) {
            $this->logger->warning('Paystack validateCustomer rejected: {message}', [
                'message' => (string) ($body['message'] ?? 'unknown'),
            ]);
        }

        return $ok ? ['ok' => true] : ['ok' => false, 'message' => (string) ($body['message'] ?? 'Identity check failed.')];
    }

    /**
     * Create a dedicated virtual account.
     *
     * **No split is attached, deliberately.** A transfer into this account lands
     * in Edus's Paystack balance in full, and the school's fee is paid out by us
     * afterwards (`app:payments:payout`). The alternative — splitting to the
     * school's subaccount — cannot keep a **flat** ₦650: Paystack's DVA endpoints
     * accept `subaccount`/`split_code` only, never `transaction_charge`, and a
     * percentage share of a varying total is not a fixed amount
     * (docs/payments/ONLINE-PAYMENT-LEDGER.md §6, §7).
     *
     * @return array{ok: bool, account_number?: string, bank_name?: string, account_name?: string, message?: string}
     */
    public function createDedicatedAccount(string $customerCode, string $preferredBank, ?string $subaccountCode = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Online payment is not configured yet.'];
        }

        $payload = [
            'customer' => $customerCode,
            'preferred_bank' => $preferredBank,
        ];

        // Kept as an explicit opt-in for a school that later wants direct
        // settlement; the default path settles to the platform.
        if ($subaccountCode !== null && $subaccountCode !== '') {
            $payload['subaccount'] = $subaccountCode;
        }

        try {
            $body = $this->httpClient->request('POST', self::BASE_URL . '/dedicated_account', [
                'headers' => $this->headers(),
                'json' => $payload,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack createDedicatedAccount failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'We could not reach the payment provider. Please try again.'];
        }

        $account = $body['data']['dedicated_account'] ?? $body['data'] ?? [];
        $number = (string) ($account['account_number'] ?? '');

        if (($body['status'] ?? false) === true && $number !== '') {
            return [
                'ok' => true,
                'account_number' => $number,
                'bank_name' => (string) ($account['bank']['name'] ?? ''),
                'account_name' => (string) ($account['account_name'] ?? ''),
            ];
        }

        $message = (string) ($body['message'] ?? 'The payment provider did not issue an account.');
        $this->logger->warning('Paystack createDedicatedAccount rejected: {message}', ['message' => $message]);

        return ['ok' => false, 'message' => $message];
    }

    /**
     * Fetch a customer (and their virtual account, when they have one).
     *
     * @return array{ok: bool, code?: string, account_number?: string, bank_name?: string, account_name?: string}
     */
    public function fetchCustomer(string $emailOrCode): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false];
        }

        try {
            $body = $this->httpClient->request(
                'GET',
                self::BASE_URL . '/customer/' . rawurlencode($emailOrCode),
                ['headers' => $this->headers()]
            )->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack fetchCustomer failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false];
        }

        if (($body['status'] ?? false) !== true) {
            return ['ok' => false];
        }

        $data = $body['data'] ?? [];
        $account = $data['dedicated_account'] ?? [];

        return [
            'ok' => true,
            'code' => (string) ($data['customer_code'] ?? ''),
            'account_number' => (string) ($account['account_number'] ?? ''),
            'bank_name' => (string) ($account['bank']['name'] ?? ''),
            'account_name' => (string) ($account['account_name'] ?? ''),
        ];
    }

    /**
     * Remove the split from an account that was issued before we stopped splitting.
     *
     * Without this, an older NUBAN keeps paying the school the parent's ₦650 as
     * well as the fee — the exact leak this change exists to close.
     *
     * @return array{ok: bool, message?: string}
     */
    public function removeDedicatedSplit(string $accountNumber): array
    {
        if (!$this->isConfigured() || trim($accountNumber) === '') {
            return ['ok' => false, 'message' => 'Nothing to update.'];
        }

        try {
            $body = $this->httpClient->request('DELETE', self::BASE_URL . '/dedicated_account/split', [
                'headers' => $this->headers(),
                'json' => ['account_number' => trim($accountNumber)],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack removeDedicatedSplit failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'We could not reach the payment provider.'];
        }

        $ok = ($body['status'] ?? false) === true || ($body['status'] ?? '') === 'success';

        if (!$ok) {
            $this->logger->warning('Paystack removeDedicatedSplit rejected: {message}', [
                'message' => (string) ($body['message'] ?? 'unknown'),
            ]);
        }

        return ['ok' => $ok, 'message' => (string) ($body['message'] ?? '')];
    }

    /**
     * Register the school's bank account as a transfer recipient (RCP_…).
     *
     * Paystack validates the account against the bank before creating it, which
     * is exactly the check we want once: get the account wrong here and every
     * payout afterwards is wrong. Cached on the school row afterwards.
     *
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function createTransferRecipient(string $name, string $bankCode, string $accountNumber): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'No Paystack key is configured.'];
        }

        if ($bankCode === '' || $accountNumber === '') {
            return ['ok' => false, 'message' => 'The school needs a bank code and account number before it can be paid.'];
        }

        try {
            $body = $this->httpClient->request('POST', self::BASE_URL . '/transferrecipient', [
                'headers' => $this->headers(),
                'json' => [
                    'type' => 'nuban',
                    'name' => $name,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'currency' => 'NGN',
                ],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack createTransferRecipient failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'We could not reach Paystack to register the school account.'];
        }

        $code = (string) ($body['data']['recipient_code'] ?? '');

        if (($body['status'] ?? false) === true && $code !== '') {
            return ['ok' => true, 'code' => $code];
        }

        $message = (string) ($body['message'] ?? 'Paystack rejected that bank account.');
        $this->logger->warning('Paystack createTransferRecipient rejected: {message}', ['message' => $message]);

        return ['ok' => false, 'message' => $message];
    }

    /**
     * Send money to a recipient, from the Paystack balance.
     *
     * The `reference` is the payout's own reference, so a retry after a timeout
     * cannot pay twice — Paystack rejects a duplicate reference.
     *
     * @return array{ok: bool, transferCode?: string, status?: string, message?: string}
     */
    public function initiateTransfer(string $recipientCode, int $amountMinor, string $reason, string $reference): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'No Paystack key is configured.'];
        }

        if ($amountMinor <= 0) {
            return ['ok' => false, 'message' => 'Nothing to transfer.'];
        }

        try {
            $body = $this->httpClient->request('POST', self::BASE_URL . '/transfer', [
                'headers' => $this->headers(),
                'json' => [
                    'source' => 'balance',
                    'amount' => $amountMinor,
                    'recipient' => $recipientCode,
                    'reason' => $reason,
                    'reference' => $reference,
                    'currency' => 'NGN',
                ],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack initiateTransfer failed: {message}', ['message' => $e->getMessage()]);

            // Not marked failed by the caller: an unknown outcome must never be
            // retried blindly — Paystack's reference check protects us, but the
            // operator has to look.
            return ['ok' => false, 'message' => 'We could not reach Paystack; the transfer status is unknown.'];
        }

        if (($body['status'] ?? false) !== true) {
            $message = (string) ($body['message'] ?? 'Paystack refused the transfer.');
            $this->logger->warning('Paystack initiateTransfer rejected: {message}', ['message' => $message]);

            return ['ok' => false, 'message' => $message];
        }

        return [
            'ok' => true,
            'transferCode' => (string) ($body['data']['transfer_code'] ?? ''),
            'status' => (string) ($body['data']['status'] ?? 'pending'),
        ];
    }

    /**
     * Create the subaccount a school's money settles into.
     *
     * Edus runs one Paystack account; a subaccount is only a **record** holding
     * the school's settlement bank and our split — the school never signs up for
     * or logs into Paystack (PAYMENT-ARCHITECTURE.md §1).
     *
     * Paystack wants the bank **code**, not the name (058 = GTBank), so the
     * operator picks it from Paystack's bank list.
     *
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function createSubaccount(
        string $businessName,
        string $bankCode,
        string $accountNumber,
        float $percentageCharge = 0.0,
    ): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'No Paystack key is configured, so no subaccount can be created.'];
        }

        try {
            $body = $this->httpClient->request('POST', self::BASE_URL . '/subaccount', [
                'headers' => $this->headers(),
                'json' => [
                    'business_name' => $businessName,
                    'settlement_bank' => $bankCode,
                    'account_number' => $accountNumber,
                    'percentage_charge' => $percentageCharge,
                ],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Paystack createSubaccount failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'We could not reach Paystack. Please try again.'];
        }

        $code = (string) ($body['data']['subaccount_code'] ?? '');

        if (($body['status'] ?? false) === true && $code !== '') {
            return ['ok' => true, 'code' => $code];
        }

        $message = (string) ($body['message'] ?? 'Paystack rejected this subaccount.');
        $this->logger->warning('Paystack createSubaccount rejected: {message}', ['message' => $message]);

        return ['ok' => false, 'message' => $message];
    }

    /**
     * Read a subaccount back from Paystack.
     *
     * Used by the operator screen to answer one question: *is the account the
     * school has on file still the account Paystack settles into?* A school may
     * change its bank details in its own settings, and Paystack will happily keep
     * paying the old account until we replace the subaccount — this is how that
     * divergence becomes visible instead of silent.
     *
     * @return array{ok: bool, account_number?: ?string, bank?: ?string, active?: bool, message?: string}
     */
    public function fetchSubaccount(string $subaccountCode): array
    {
        if (!$this->isConfigured() || trim($subaccountCode) === '') {
            return ['ok' => false, 'message' => 'No subaccount to read.'];
        }

        try {
            $body = $this->httpClient->request(
                'GET',
                self::BASE_URL . '/subaccount/' . rawurlencode(trim($subaccountCode)),
                ['headers' => $this->headers(), 'timeout' => 5]
            )->toArray(false);
        } catch (\Throwable $e) {
            // A slow Paystack must never break the operator's screen.
            $this->logger->warning('Paystack fetchSubaccount failed: {message}', ['message' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'Paystack could not be reached.'];
        }

        if (($body['status'] ?? false) !== true) {
            return ['ok' => false, 'message' => (string) ($body['message'] ?? 'Subaccount not found.')];
        }

        $data = $body['data'] ?? [];

        return [
            'ok' => true,
            'account_number' => isset($data['account_number']) ? (string) $data['account_number'] : null,
            'bank' => isset($data['settlement_bank']) ? (string) $data['settlement_bank'] : null,
            'active' => (bool) ($data['active'] ?? false),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->secretKey(),
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
