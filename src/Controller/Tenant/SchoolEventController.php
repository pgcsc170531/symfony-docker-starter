<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\SchoolEvent;
use App\Entity\Tenant\Guardian;
use App\Entity\Landlord\School as LandlordSchool;
use App\Service\NotificationService;
use Doctrine\Persistence\ManagerRegistry;
use App\Form\SchoolEventType;
use App\Form\SchoolEventImportType;
use App\Repository\Tenant\SchoolEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

#[Route('/events')]
#[IsGranted('ROLE_ADMIN')]
class SchoolEventController extends AbstractController
{
    #[Route('/', name: 'app_tenant_event_index', methods: ['GET'])]
    public function index(SchoolEventRepository $schoolEventRepository): Response
    {
        return $this->render('tenant/school_event/index.html.twig', [
            'events' => $schoolEventRepository->findBy([], ['startDate' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'app_tenant_event_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $schoolEvent = new SchoolEvent();
        $form = $this->createForm(SchoolEventType::class, $schoolEvent);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($schoolEvent);
            $entityManager->flush();

            $this->addFlash('success', 'Event created successfully!');

            return $this->redirectToRoute('app_tenant_event_index');
        }

        return $this->render('tenant/school_event/new.html.twig', [
            'school_event' => $schoolEvent,
            'form' => $form,
        ]);
    }
    
    #[Route('/{id}/delete', name: 'app_tenant_event_delete', methods: ['POST'])]
    public function delete(Request $request, SchoolEvent $schoolEvent, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$schoolEvent->getId(), $request->request->get('_token'))) {
            $entityManager->remove($schoolEvent);
            $entityManager->flush();
            $this->addFlash('success', 'Event deleted.');
        }

        return $this->redirectToRoute('app_tenant_event_index');
    }

    #[Route('/import', name: 'app_tenant_event_import', methods: ['GET', 'POST'])]
    public function import(Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SchoolEventImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();

            if ($file) {
                try {
                    $spreadsheet = IOFactory::load($file->getPathname());
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = $sheet->toArray();

                    $count = 0;
                    
                    // Loop through rows (Start at index 1 to skip Header row)
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        
                        // Expected Columns: 
                        // A: Title, B: Date, C: Type, D: Description, E: Is Flash?
                        $title = $row[0] ?? null;
                        $dateRaw = $row[1] ?? null;

                        if (!$title || !$dateRaw) continue; // Skip empty rows

                        $event = new SchoolEvent();
                        $event->setTitle($title);

                        // 📅 HANDLE DATE PARSING
                        try {
                            if (is_numeric($dateRaw)) {
                                // Excel stores dates as numbers (e.g. 45290)
                                $event->setStartDate(Date::excelToDateTimeObject($dateRaw));
                            } else {
                                // Text format (e.g. "2025-01-01")
                                $event->setStartDate(new \DateTime($dateRaw));
                            }
                        } catch (\Exception $e) {
                            // Fallback if date is weird
                            $event->setStartDate(new \DateTime());
                        }

                        $event->setType($row[2] ?? 'Academic');
                        $event->setDescription($row[3] ?? null);
                        
                        // Check if "Yes", "1", or "True" for Flash Notice
                        $isFlash = isset($row[4]) && (stripos($row[4], 'yes') !== false || $row[4] == 1);
                        $event->setIsFlashNotice($isFlash);

                        $em->persist($event);
                        $count++;
                    }

                    $em->flush();
                    $this->addFlash('success', "Success! Imported $count events.");
                    return $this->redirectToRoute('app_tenant_event_index');

                } catch (\Exception $e) {
                    $this->addFlash('error', 'Error reading file: ' . $e->getMessage());
                }
            }
        }

        return $this->render('tenant/school_event/import.html.twig', [
            'form' => $form->createView(),
        ]);
    }

   #[Route('/{id}/notify', name: 'app_tenant_event_notify', methods: ['POST'])]
    public function notify(
        SchoolEvent $event, 
        EntityManagerInterface $em, 
        NotificationService $notifier, 
        ManagerRegistry $doctrine
    ): Response {
        /** @var \App\Entity\Tenant\User $user */
        $user = $this->getUser();
        $tenant = $user->getTenant();

        // 1. Get Landlord Context (To pay for the SMS)
        $landlordEm = $doctrine->getManager('landlord');
        $landlordSchool = $landlordEm->getRepository(LandlordSchool::class)->findOneBy([
            'subdomain' => $tenant->getSubdomain()
        ]);

        if (!$landlordSchool) {
            $this->addFlash('error', 'Landlord account not found. Cannot send SMS.');
            return $this->redirectToRoute('app_tenant_event_index');
        }

        // 2. Fetch all Guardians with valid phone numbers
        $guardians = $em->getRepository(Guardian::class)->findAll();
        $sentCount = 0;

        // 3. Define the Custom Message Template
        // The placeholders [event_title], [date], and [desc] are swapped by the Service
        $template = "School Update: [event_title] is scheduled for [date]. Details: [desc]";

        foreach ($guardians as $guardian) {
            $phone = $guardian->getPhoneNumber();
            
            if ($phone) {
                $notifier->sendSms(
                    $landlordSchool, 
                    $phone, 
                    $template, 
                    'calendar', 
                    [
                        '[event_title]' => $event->getTitle(),
                        '[date]'        => $event->getStartDate()->format('d M, Y'),
                        '[desc]'        => $event->getDescription() ?? 'Please take note.'
                    ]
                );
                $sentCount++;
            }
        }

        $this->addFlash('success', "SMS Broadcast complete! Sent to $sentCount parents.");
        return $this->redirectToRoute('app_tenant_event_index');
    }


    #[Route('/{id}/edit', name: 'app_tenant_event_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, SchoolEvent $schoolEvent, EntityManagerInterface $entityManager): Response
    {
        // Use the same SchoolEventType you used for 'new'
        $form = $this->createForm(SchoolEventType::class, $schoolEvent);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // No need to persist() for existing entities, flush() is enough
            $entityManager->flush();

            $this->addFlash('success', 'Event updated successfully!');

            return $this->redirectToRoute('app_tenant_event_index');
        }

        return $this->render('tenant/school_event/edit.html.twig', [
            'school_event' => $schoolEvent,
            'form' => $form,
        ]);
    }
}