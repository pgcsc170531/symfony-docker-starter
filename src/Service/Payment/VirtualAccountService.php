<?php

namespace App\Service\Payment;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Landlord\VirtualAccountIndex;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use App\Entity\Tenant\VirtualAccount;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Issues a family's dedicated virtual account — once, with consent.
 *
 * Rules this class enforces (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md):
 *   • **Express consent** is required by Paystack itself, so `$consentAt` is
 *     mandatory and stored. No silent accounts.
 *   • **One account per guardian** per school — the row is unique on guardian.
 *   • **No split.** A transfer lands in Edus's Paystack balance in full; the
 *     school's fee is paid out from there, so the parent's flat ₦650 charge can
 *     be kept exactly (ONLINE-PAYMENT-LEDGER.md §6, §7). A split would pay the
 *     school the charge too.
 *   • The BVN is used **once** and never stored; only the last three digits are
 *     kept for support calls (§4.5).
 *
 * Validation with a BVN is only required for some Paystack business categories.
 * When a parent does not supply one, the account is created without validation —
 * Paystack rejects it with a clear message if our category demands it, and that
 * message is shown to the parent rather than swallowed.
 */
final class VirtualAccountService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaystackClient $client,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        #[Autowire('%env(default::PAYSTACK_DVA_BANK)%')]
        private readonly ?string $preferredBank = null,
    ) {
    }

    /** Online transfer accounts need a configured gateway and a school subaccount. */
    public function isAvailable(School $school): bool
    {
        return $this->client->isConfigured()
            && (string) $school->getPaystackSubaccountCode() !== '';
    }

    public function forGuardian(Guardian $guardian): ?VirtualAccount
    {
        return $this->em->getRepository(VirtualAccount::class)->findOneBy(['guardian' => $guardian]);
    }

    /**
     * Create the family's account, or return the one they already have.
     *
     * @param array{bvn?: string|null, bank_code?: string|null, account_number?: string|null} $identity
     *
     * @return array{ok: bool, account?: VirtualAccount, message?: string}
     */
    public function ensureFor(
        Guardian $guardian,
        School $school,
        \DateTimeImmutable $consentAt,
        array $identity = [],
    ): array {
        // Only an account we still consider open may be reused. A row marked
        // closed/dormant means the number at the bank is gone (the operator closed
        // it, or it was removed at Paystack) — handing it back would send a parent
        // to a dead NUBAN, so a replacement is issued below.
        $existing = $this->forGuardian($guardian);
        if ($existing !== null && $existing->getAccountNumber() !== null && $existing->isActive()) {
            return ['ok' => true, 'account' => $existing];
        }

        if (!$this->isAvailable($school)) {
            return ['ok' => false, 'message' => 'Online transfer accounts are not available for this school yet.'];
        }

        $email = trim((string) $guardian->getEmail());
        $phone = trim((string) $guardian->getPhoneNumber());
        $name = trim((string) $guardian->getFullName());

        if ($email === '' || $name === '') {
            return ['ok' => false, 'message' => 'Add the parent\'s name and email before creating an account.'];
        }

        if ($phone === '') {
            return ['ok' => false, 'message' => 'Add the parent\'s phone number before creating an account — the bank needs it.'];
        }

        [$firstName, $lastName] = $this->splitName($name);

        $customer = $this->client->createCustomer($email, $firstName, $lastName, $phone);
        if (!$customer['ok'] || empty($customer['code'])) {
            return ['ok' => false, 'message' => $customer['message'] ?? 'The payment provider rejected this parent.'];
        }

        $customerCode = (string) $customer['code'];

        $validatedAt = null;
        $bvnMasked = null;

        $bvn = trim((string) ($identity['bvn'] ?? ''));
        if ($bvn !== '') {
            $bankCode = trim((string) ($identity['bank_code'] ?? ''));
            $accountNumber = trim((string) ($identity['account_number'] ?? ''));

            if ($bankCode === '' || $accountNumber === '') {
                return ['ok' => false, 'message' => 'The identity check also needs your bank and account number.'];
            }

            $validated = $this->client->validateCustomer($customerCode, $bvn, $bankCode, $accountNumber, $firstName, $lastName);
            if (!$validated['ok']) {
                return ['ok' => false, 'message' => $validated['message'] ?? 'The identity check failed.'];
            }

            $validatedAt = new \DateTimeImmutable();
            $bvnMasked = '***' . substr($bvn, -3);
        }

        // No subaccount: a transfer into this account lands in Edus's balance in
        // full, and the school's fee is paid out afterwards. Splitting here would
        // hand the school the parent's ₦650 as well — see the class docblock of
        // PaystackClient::createDedicatedAccount().
        $created = $this->client->createDedicatedAccount(
            $customerCode,
            $this->preferredBank ?: 'wema-bank'
        );

        if (!$created['ok'] || empty($created['account_number'])) {
            return ['ok' => false, 'message' => $created['message'] ?? 'The payment provider did not issue an account.'];
        }

        $account = $existing ?? new VirtualAccount();
        $account
            ->setGuardian($guardian)
            ->setPaystackCustomerCode($customerCode)
            ->setAccountNumber((string) $created['account_number'])
            ->setBankName($created['bank_name'] ?? null)
            ->setAccountName($created['account_name'] ?? null)
            ->setSubaccountCode(null)
            ->setStatus(VirtualAccount::STATUS_ACTIVE)
            ->setConsentAt($consentAt)
            ->setIdentityValidatedAt($validatedAt)
            ->setBvnMasked($bvnMasked);

        $this->em->persist($account);
        $this->em->flush();

        /*
         * Paystack's webhook arrives on the platform domain with no school host,
         * so the landlord needs to know which tenant this customer belongs to.
         * The tenant row stays the source of truth; this is routing only.
         */
        $this->indexForLandlord($school, $customerCode, (string) $account->getAccountNumber());

        $this->logger->info('Virtual account issued for guardian {guardian} ({account})', [
            'guardian' => $guardian->getId(),
            'account' => $account->getAccountNumber(),
        ]);

        return ['ok' => true, 'account' => $account];
    }

    /**
     * Teach the platform which tenant owns this customer code (§11: one webhook
     * URL for the whole account). Failures here must not fail the account —
     * the tenant row already exists and can be reconciled later.
     */
    private function indexForLandlord(School $school, string $customerCode, string $accountNumber): void
    {
        $landlordSchool = $this->landlordEm->getRepository(LandlordSchool::class)->findOneBy([
            'subdomain' => $school->getSubdomain(),
        ]);

        if ($landlordSchool === null) {
            $this->logger->warning('No landlord school for subdomain {subdomain}; webhook routing not indexed.', [
                'subdomain' => $school->getSubdomain(),
            ]);

            return;
        }

        $index = $this->landlordEm->getRepository(VirtualAccountIndex::class)->findOneBy([
            'customerCode' => $customerCode,
        ]) ?? new VirtualAccountIndex();

        $index
            ->setSchool($landlordSchool)
            ->setCustomerCode($customerCode)
            ->setAccountNumber($accountNumber);

        $this->landlordEm->persist($index);
        $this->landlordEm->flush();
    }

    /**
     * Paystack wants a first and last name; a Nigerian guardian record often
     * carries three or four. First token + last token is the least surprising.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', $fullName) ?: [];

        if (count($parts) === 1) {
            return [$parts[0], $parts[0]];
        }

        return [$parts[0], $parts[count($parts) - 1]];
    }
}
