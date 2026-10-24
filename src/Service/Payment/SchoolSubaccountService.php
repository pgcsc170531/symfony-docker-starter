<?php

namespace App\Service\Payment;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\School as TenantSchool;
use App\Service\Tenant\TenantConnectionSwitcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates the Paystack subaccount a school's money settles into.
 *
 * This is an **operator** action, not a school one: Edus holds the one Paystack
 * account, and commercial and support responsibility for it sits with us. A school
 * only ever supplies its bank details (Settings → General → Banking details) —
 * it never sees a key, a subaccount code, or a Paystack login
 * (docs/payments/PAYMENT-ARCHITECTURE.md §1).
 *
 * The code lands in the **tenant** row (`school_settings.paystack_subaccount_code`)
 * because that is where every payment path reads it; the operator screen simply
 * has the privilege to write it there.
 */
final class SchoolSubaccountService
{
    public function __construct(
        private readonly TenantConnectionSwitcher $switcher,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        private readonly EntityManagerInterface $tenantEm,
        private readonly PaystackClient $client,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * What the operator screen needs to show: the code (when set), the bank
     * details the school has entered, and — for a live subaccount — the account
     * Paystack is actually settling into, so a divergence is visible before a
     * naira moves.
     *
     * `paystack_found` answers the question a stored code alone cannot: does the
     * *current* mode know this code? Test-mode codes are invisible to live mode,
     * so a school flipped from test to live keeps a code that resolves nowhere.
     *
     * @return array{code: ?string, bank_name: ?string, account_number: ?string, account_name: ?string, paystack_account_number: ?string, paystack_bank: ?string, paystack_found: ?bool, paystack_message: ?string}
     */
    public function details(LandlordSchool $school): array
    {
        $this->switcher->switchTo($school);

        try {
            $tenant = $this->tenantEm->getRepository(TenantSchool::class)->findOneBy([]);
            $code = $tenant?->getPaystackSubaccountCode();

            $remoteAccount = null;
            $remoteBank = null;
            $found = null;
            $message = null;

            // Placeholders from earlier testing ("ACCT_local_test") are not real
            // codes; asking Paystack about them only costs a round trip.
            if ($code !== null && preg_match('/^ACCT_[A-Za-z0-9]{8,}$/', $code) === 1) {
                $remote = $this->client->fetchSubaccount($code);
                $found = $remote['ok'];

                if ($remote['ok']) {
                    $remoteAccount = $remote['account_number'] ?? null;
                    $remoteBank = $remote['bank'] ?? null;
                } else {
                    $message = $remote['message'] ?? null;
                }
            }

            return [
                'code' => $code,
                'bank_name' => $tenant?->getBankName(),
                'account_number' => $tenant?->getAccountNumber(),
                'account_name' => $tenant?->getAccountName(),
                'paystack_account_number' => $remoteAccount,
                'paystack_bank' => $remoteBank,
                'paystack_found' => $found,
                'paystack_message' => $message,
            ];
        } finally {
            $this->switcher->restore();
        }
    }

    /**
     * Save the settlement bank details the school's money will go to.
     *
     * Normally the school types these itself (tenant Settings → General), but at
     * onboarding the operator is the one holding the account letter, and making
     * them log in as the school to unblock an operator task is friction with no
     * benefit. Both can write; whoever gets there first wins.
     *
     * @param array{bank_name: string, account_number: string, account_name: string} $details
     *
     * @return array{ok: bool, message?: string}
     */
    public function saveBankDetails(LandlordSchool $school, array $details): array
    {
        $this->switcher->switchTo($school);

        try {
            $tenant = $this->tenantEm->getRepository(TenantSchool::class)->findOneBy([]);

            if ($tenant === null) {
                return ['ok' => false, 'message' => 'This school has no settings row yet — repair its schema first.'];
            }

            if (trim($details['account_number']) === '') {
                return ['ok' => false, 'message' => 'The account number is required — it is where the school is paid.'];
            }

            $this->applyDetails($tenant, $details);
            $this->tenantEm->flush();

            return ['ok' => true];
        } finally {
            $this->switcher->restore();
        }
    }

    /**
     * Create the subaccount from the school's bank details and save the code.
     *
     * Details are written **before** Paystack is called, so a provider refusal
     * does not also throw away what the operator just typed.
     *
     * @param array{bank_name: string, account_number: string, account_name: string} $details
     *
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function create(LandlordSchool $school, array $details, string $bankCode, float $percentageCharge = 0.0): array
    {
        $bankCode = trim($bankCode);

        if ($bankCode === '') {
            return ['ok' => false, 'message' => 'Enter the settlement bank code — Paystack needs the code, not the name (058 is GTBank).'];
        }

        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'message' => 'No Paystack key is configured, so no subaccount can be created.'];
        }

        $this->switcher->switchTo($school);

        try {
            $tenant = $this->tenantEm->getRepository(TenantSchool::class)->findOneBy([]);

            if ($tenant === null) {
                return ['ok' => false, 'message' => 'This school has no settings row yet — repair its schema first.'];
            }

            $accountNumber = trim($details['account_number']);

            if ($accountNumber === '') {
                return [
                    'ok' => false,
                    'message' => 'Enter the school\'s bank account number — it is where the school is paid.',
                ];
            }

            // Keep what was typed even if Paystack refuses below.
            $this->applyDetails($tenant, $details);
            $this->tenantEm->flush();

            $result = $this->client->createSubaccount(
                (string) $school->getName(),
                $bankCode,
                $accountNumber,
                $percentageCharge
            );

            if (!$result['ok'] || empty($result['code'])) {
                return ['ok' => false, 'message' => $result['message'] ?? 'Paystack rejected the subaccount.'];
            }

            $tenant->setPaystackSubaccountCode((string) $result['code']);
            $this->tenantEm->flush();

            $this->logger->info('Subaccount {code} created for {school}', [
                'code' => $result['code'],
                'school' => $school->getSubdomain(),
            ]);

            return ['ok' => true, 'code' => (string) $result['code']];
        } finally {
            $this->switcher->restore();
        }
    }

    /**
     * @param array{bank_name: string, account_number: string, account_name: string} $details
     */
    private function applyDetails(TenantSchool $tenant, array $details): void
    {
        $bankName = trim($details['bank_name']);
        $accountName = trim($details['account_name']);

        $tenant->setBankName($bankName !== '' ? $bankName : null);
        $tenant->setAccountNumber(trim($details['account_number']));
        $tenant->setAccountName($accountName !== '' ? $accountName : null);
    }
}
