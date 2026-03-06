<?php

namespace App\Controller\Tenant;

use App\Event\NotificationEvent;
use App\Entity\Landlord\School as LandlordSchool; // 👈 Alias for clarity
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire; // 👈 Needed for injection
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class TestNotificationController extends AbstractController
{
    #[Route('/test-notifications', name: 'test_notifications')]
    public function index(
        EventDispatcherInterface $dispatcher,
        // 🟢 INJECT THE LANDLORD EM TO FIND THE WALLET
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm
    ): Response
    {
        $user = $this->getUser();
        
        // 1. Get the Tenant School (The one you are logged into)
        $tenantSchool = $user->getSchool(); // Returns App\Entity\Tenant\School

        if (!$tenantSchool) {
            return new Response('Error: You are not linked to a school.', 400);
        }

        // 2. 🔄 FIND THE MATCHING LANDLORD SCHOOL ( The one with the Wallet )
        // We assume they share the same ID.
        $landlordSchool = $landlordEm->getRepository(LandlordSchool::class)->find($tenantSchool->getId());
        
        if (!$landlordSchool) {
            return new Response('Error: Could not find the Master School record (Wallet) for this tenant.', 500);
        }

        // 3. Define Your Test Contact
        $myPhone = '2348031819670'; // ⚠️ Replace with your real number
        $myEmail = 'ibraheemrumah@gmail.com';

        // --- FIRE CHANNEL 1: SMS ---
        $dispatcher->dispatch(new NotificationEvent(
            $landlordSchool, // 👈 Now passing the CORRECT Entity
            $myPhone, 
            '🔔 EDUS SMS Test: System Online.', 
            'SMS'
        ), NotificationEvent::NAME);

        // --- FIRE CHANNEL 2: WhatsApp ---
        $dispatcher->dispatch(new NotificationEvent(
            $landlordSchool, // 👈 Correct Entity
            $myPhone, 
            '🔔 EDUS WhatsApp Test: System Online.', 
            'WHATSAPP'
        ), NotificationEvent::NAME);

        // --- FIRE CHANNEL 3: Email ---
        $dispatcher->dispatch(new NotificationEvent(
            $landlordSchool, // 👈 Correct Entity
            $myEmail, 
            '<h1>System Online</h1><p>This is a test from Amazon SES.</p>', 
            'EMAIL', 
            'EDUS Live Test'
        ), NotificationEvent::NAME);

        return new Response('🚀 Commands Sent! Check your phone and email.');
    }
}