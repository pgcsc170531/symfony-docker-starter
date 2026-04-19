<?php

namespace App\Service;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Landlord\WalletTransaction;
use App\Entity\Landlord\GlobalSetting;
use App\Entity\Tenant\School as TenantSchool; 
use App\Entity\Tenant\NotificationLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class NotificationService
{
    public function __construct(
        private HttpClientInterface $client,
        
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,
        
        #[Autowire(service: 'doctrine.orm.default_entity_manager')] 
        private EntityManagerInterface $tenantEm,
        
        #[Autowire(env: 'TERMII_API_KEY')]
        private string $termiiApiKey,
        
        #[Autowire(env: 'TERMII_SENDER_ID')]
        private string $termiiSenderId
    ) {}

    /**
     * @param string $eventType Options: 'enrollment', 'fees', 'calendar'
     */
    public function sendSms(
        TenantSchool $school, // 🟢 FIX: Changed from LandlordSchool to TenantSchool
        string $recipient, 
        string $template, 
        string $eventType = 'enrollment', 
        array $placeholders = []
    ): void {
        // 1. DYNAMIC REPLACEMENT
        $message = strtr($template, $placeholders);

        // 2. CHECK TENANT TOGGLES FIRST (Efficiency)
        // 🟢 Optimized: We use the passed $school directly instead of querying the DB again
        $shouldSend = match($eventType) {
            'enrollment' => $school->isSmsOnEnrollment() ?? true,
            'fees'       => $school->isSmsOnFeePayment() ?? true,
            'calendar'   => $school->isSmsOnCalendarEvent() ?? true,
            default      => false
        };

        if (!$shouldSend) {
            return; 
        }

        // 3. FETCH PRICE & WALLET (Landlord DB)
        // 🟢 Look up the Landlord record using the ID to check the main billing wallet
        $landlordSchool = $this->landlordEm->getRepository(LandlordSchool::class)->find($school->getId());
        $priceSetting = $this->landlordEm->getRepository(GlobalSetting::class)
            ->findOneBy(['settingKey' => 'sms_price']);
        
        $smsCost = $priceSetting ? (float)$priceSetting->getSettingValue() : 15.00;

        if (!$landlordSchool) {
            return;
        }

        $currentBalance = (float) $landlordSchool->getWalletBalance();

        // 4. WALLET CHECK
        if ($currentBalance < $smsCost) {
            $this->logTransaction('SMS', $recipient, $message, 'FAILED_LOW_BALANCE', '0.00');
            return; 
        }

        // 5. THE ACTION (Termii API)
        try {
            $response = $this->client->request('POST', 'https://api.ng.termii.com/api/sms/send', [
                'json' => [
                    'to' => $recipient,
                    'from' => $this->termiiSenderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => 'generic',
                    'api_key' => $this->termiiApiKey,
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);

            if ($statusCode === 200 && isset($content['message_id'])) {
                
                // 6. DEDUCT THE MONEY (Landlord DB)
                $newBalance = $currentBalance - $smsCost;
                $formattedBalance = number_format($newBalance, 2, '.', '');
                $landlordSchool->setWalletBalance($formattedBalance);
                
                // 7. SAVE RECEIPT (Landlord DB)
                $transaction = new WalletTransaction();
                $transaction->setSchool($landlordSchool)
                    ->setType('DEBIT')
                    ->setAmount(number_format($smsCost, 2, '.', ''))
                    ->setBalanceAfter($formattedBalance)
                    ->setDescription(strtoupper($eventType) . " SMS to " . $recipient)
                    ->setReference("SMS-" . $content['message_id']);

                $this->landlordEm->persist($transaction);
                $this->landlordEm->flush(); 

                // 8. LOG HISTORY (Tenant DB)
                $this->logTransaction('SMS', $recipient, $message, 'SENT', (string) $smsCost);
                
            } else {
                $this->logTransaction('SMS', $recipient, $message, 'FAILED_API_ERROR', '0.00');
            }

        } catch (\Exception $e) {
             $this->logTransaction('SMS', $recipient, $message, 'FAILED_EXCEPTION', '0.00');
        }
    }

    private function logTransaction(string $channel, string $recipient, string $message, string $status, string $cost): void
    {
        $log = new NotificationLog();
        $log->setChannel($channel)
            ->setRecipient($recipient)
            ->setMessage($message)
            ->setStatus($status)
            ->setCost($cost);

        $this->tenantEm->persist($log);
        $this->tenantEm->flush();
    }
}