<?php

namespace App\Service\Payment;

use App\Entity\Tenant\AccountCredit;
use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\VirtualAccount;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a bank transfer into a family's dedicated account into either a settled
 * invoice or an **unassigned credit**.
 *
 * The rule (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md §5): a transfer identifies
 * the *family*, and the parent's **Pay online** tap identifies the *invoice* —
 * the intent carries it. So we settle only when exactly one open intent on that
 * account matches the amount (±₦1, because humans round). Everything else is
 * held, visibly, and never auto-split across siblings.
 *
 * Idempotent twice over:
 *   • `providerReference` is unique, so a replayed webhook cannot credit twice;
 *   • `SettlementService` refuses to settle an intent that is already decided.
 */
final class VirtualAccountCreditService
{
    /** A parent who rounds the kobo is still paying the bill in front of them. */
    public const AMOUNT_TOLERANCE = 1.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettlementService $settlement,
        private readonly LedgerPoster $ledger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record the transfer. Returns null when the payload is not a transfer into a
     * dedicated account, so the caller can fall through to the normal paths.
     *
     * @param array<string, mixed> $data the webhook's `data` object
     */
    public function ingest(array $data): ?AccountCredit
    {
        $authorization = $data['authorization'] ?? [];

        if (!is_array($authorization) || (string) ($authorization['channel'] ?? '') !== 'dedicated_nuban') {
            return null;
        }

        $customerCode = (string) ($data['customer']['customer_code'] ?? $authorization['customer_code'] ?? '');
        $reference = (string) ($data['reference'] ?? $data['id'] ?? '');

        if ($customerCode === '' || $reference === '') {
            $this->logger->warning('DVA transfer without a customer code or reference; parked for review.');

            return null;
        }

        $account = $this->em->getRepository(VirtualAccount::class)->findOneBy(['paystackCustomerCode' => $customerCode]);

        if ($account === null) {
            $this->logger->warning('DVA transfer for unknown customer {customer} (reference {reference})', [
                'customer' => $customerCode,
                'reference' => $reference,
            ]);

            return null;
        }

        $existing = $this->em->getRepository(AccountCredit::class)->findOneBy(['providerReference' => $reference]);
        if ($existing !== null) {
            return $existing;
        }

        $amount = number_format(((int) ($data['amount'] ?? 0)) / 100, 2, '.', '');

        $credit = new AccountCredit();
        $credit
            ->setVirtualAccount($account)
            ->setProviderReference($reference)
            ->setAmount($amount)
            ->setSenderName($this->stringOrNull($authorization['sender_name'] ?? null))
            ->setSenderBank($this->stringOrNull($authorization['sender_bank'] ?? null))
            ->setRawPayload(json_encode($data, JSON_UNESCAPED_UNICODE) ?: null);

        $paidAt = $this->stringOrNull($data['paid_at'] ?? $data['paidAt'] ?? null);
        if ($paidAt !== null) {
            try {
                $credit->setPaidAt(new \DateTimeImmutable($paidAt));
            } catch (\Exception) {
                // Leave the received-at default; the provider's clock is not worth failing a payment over.
            }
        }

        $this->em->persist($credit);
        $account->touch();

        $this->applyToMatchingIntent($credit);

        $this->em->flush();

        /*
         * The books, in two steps (ONLINE-PAYMENT-LEDGER.md §6.2–6.3):
         * the cash arrived (unallocated), and — if it matched a bill in the same
         * breath — it was split into what the school is owed and our charge.
         * Posted after the credit has an id, which is the idempotency key.
         */
        $this->ledger->creditReceived($credit);

        if ($credit->getStatus() === AccountCredit::STATUS_APPLIED) {
            $this->ledger->creditApplied($credit);
        }

        // Paystack includes `fees` on the transaction; when it does, the cost is
        // booked now rather than waiting for the nightly sweep.
        $fees = (int) ($data['fees'] ?? 0);
        if ($fees > 0) {
            $this->ledger->postGatewayCost((int) $credit->getId(), $fees, $reference);
        }

        $this->em->flush();

        return $credit;
    }

    /**
     * Settle the one open intent on this account whose total matches the money.
     * Anything else stays unassigned — never guessed, never split.
     */
    private function applyToMatchingIntent(AccountCredit $credit): void
    {
        $account = $credit->getVirtualAccount();
        if ($account === null) {
            return;
        }

        $intents = $this->em->getRepository(PaymentIntent::class)->findBy(
            ['virtualAccount' => $account, 'status' => PaymentIntent::STATUS_PENDING],
            ['createdAt' => 'ASC'],
        );

        $amount = (float) $credit->getAmount();

        foreach ($intents as $intent) {
            if ($intent->getInvoice() === null) {
                continue;
            }

            if (abs((float) $intent->getAmount() - $amount) > self::AMOUNT_TOLERANCE) {
                continue;
            }

            if (!$this->settlement->settle($intent, $credit->getRawPayload() ?? '')) {
                return;
            }

            $payment = $intent->getPayment();
            if ($payment !== null && $intent->getInvoice() !== null) {
                $credit->markApplied($intent->getInvoice(), $payment, 'system');
            }

            return;
        }

        $this->logger->info('Transfer {reference} on {account} is an unassigned credit: no open intent matched {amount}', [
            'reference' => $credit->getProviderReference(),
            'account' => $account->getAccountNumber(),
            'amount' => $credit->getAmount(),
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
