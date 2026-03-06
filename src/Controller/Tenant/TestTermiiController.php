<?php

namespace App\Controller\Tenant;

use App\Event\NotificationEvent;
use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\School as TenantSchool; // 👈 Aliased to avoid naming conflicts
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestTermiiController extends AbstractController
{
    #[Route('/test-termii', name: 'test_termii')]
    public function index(
        EventDispatcherInterface $dispatcher,
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.orm.default_entity_manager')]
        EntityManagerInterface $tenantEm
    ): Response
   {
        // 1. Get the current logged-in user and their Tenant School record
        $user = $this->getUser();
        $tenantSchoolRecord = $user->getSchool(); // This is the record from Tenant DB

        // 2. CRITICAL FIX: Use the 'landlord_school_id' field instead of the local ID
        // This should return 13 instead of 1.
        $realLandlordId = $tenantSchoolRecord->getLandlordSchoolId();

        if (!$realLandlordId) {
            return new Response("❌ Error: landlord_school_id is missing in your tenant school settings.", 400);
        }

        // 3. Fetch the "Banker" record from Landlord DB using the correct ID (13)
        $landlordSchool = $landlordEm->getRepository(LandlordSchool::class)->find($realLandlordId);

        if (!$landlordSchool) {
            return new Response("❌ Critical Error: Could not find School with ID {$realLandlordId} in Landlord DB.", 500);
        }

        // 4. Fetch the Principal Phone from the TENANT database (same as before)
        $settingsRecords = $tenantEm->getRepository(TenantSchool::class)->findAll();
        if (empty($settingsRecords)) {
            return new Response("❌ Error: No school_settings record found in the Tenant database.", 400);
        }
        
        $tenantSchool = $settingsRecords[0];
        $principalPhone = $tenantSchool->getPhoneNumber(); 

        if (empty($principalPhone)) {
            return new Response("❌ Error: The phone_number is empty in school_settings.", 400);
        }

        $termiiPhoneNumber = $this->formatPhoneForTermii($principalPhone);

        // 5. Fire the Event with the correct Landlord School (ID 13)
        $dispatcher->dispatch(new NotificationEvent(
            $landlordSchool,
            $termiiPhoneNumber, 
            '🔔 Hello, your Edus Ng automated notification system is online!',
            'SMS'
        ), NotificationEvent::NAME);

        return new Response("✅ Notification dispatched to {$termiiPhoneNumber} using Landlord School ID {$realLandlordId}!");
    }

    private function formatPhoneForTermii(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0') && strlen($phone) === 11) {
            $phone = '234' . substr($phone, 1);
        }

        return $phone;
    }
}