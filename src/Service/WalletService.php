<?php

namespace App\Service;

use App\Entity\Landlord\School;
use App\Entity\Landlord\WalletTransaction;
use Doctrine\ORM\EntityManagerInterface;
// 🟢 1. IMPORT THIS
use Symfony\Component\DependencyInjection\Attribute\Autowire; 

class WalletService
{
    public function __construct(
        // 🟢 2. FORCE LANDLORD CONNECTION
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $em
    ) {}

    /**
     * 🟢 MAIN METHOD: Add Money/Credits to a School
     */
    public function addCredit(School $school, float $amount, string $description, ?string $reference = null): void
    {
        if ($amount <= 0) return;

        // 1. Calculate New Balance
        $currentBalance = (float) $school->getWalletBalance();
        $newBalance = $currentBalance + $amount;

        // 2. Update School Entity
        $school->setWalletBalance((string) $newBalance);

        // 3. Create Ledger Entry (The Receipt)
        $transaction = new WalletTransaction();
        $transaction->setSchool($school);
        $transaction->setType('CREDIT');
        $transaction->setAmount((string) $amount);
        $transaction->setBalanceAfter((string) $newBalance);
        $transaction->setDescription($description);
        
        // 🟢 3. FIX TYPO HERE (Was getReference, must be setReference)
        $transaction->setReference($reference);

        $this->em->persist($transaction);
        $this->em->persist($school);
        
        // 🟢 4. FLUSH to save changes immediately
        $this->em->flush();
    }

    /**
     * 🔴 MAIN METHOD: Remove Money/Credits (e.g., Sending SMS)
     */
    public function debit(School $school, float $amount, string $description): bool
    {
        $currentBalance = (float) $school->getWalletBalance();

        if ($currentBalance < $amount) {
            return false; // Insufficient funds
        }

        $newBalance = $currentBalance - $amount;
        $school->setWalletBalance((string) $newBalance);

        $transaction = new WalletTransaction();
        $transaction->setSchool($school);
        $transaction->setType('DEBIT');
        $transaction->setAmount((string) $amount);
        $transaction->setBalanceAfter((string) $newBalance);
        $transaction->setDescription($description);

        $this->em->persist($transaction);
        $this->em->persist($school);
        
        $this->em->flush();
        
        return true;
    }
}