<?php

namespace App\Service\Subscription;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Edus's own collection account — one place that reads the environment.
 *
 * Every surface that shows where to send money (the pay page, the invoice PDF,
 * the invoice email) must show the same three values, and must show **nothing**
 * when they are not configured. Hard-coding them once caused a page to advertise
 * an account number nobody could pay into; a placeholder is worse than silence.
 */
final class BillingBankAccount
{
    public function __construct(
        #[Autowire('%app.billing.bank_name%')]
        private readonly string $name = '',
        #[Autowire('%app.billing.bank_account%')]
        private readonly string $account = '',
        #[Autowire('%app.billing.bank_account_name%')]
        private readonly string $accountName = '',
    ) {
    }

    /**
     * @return array{name: string, account: string, account_name: string}
     */
    public function toArray(): array
    {
        return [
            'name' => trim($this->name),
            'account' => trim($this->account),
            'account_name' => trim($this->accountName),
        ];
    }

    /**
     * True only when a school could actually transfer into it.
     */
    public function isConfigured(): bool
    {
        return trim($this->account) !== '';
    }
}
