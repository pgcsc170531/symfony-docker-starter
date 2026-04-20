<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Term;
use App\Form\Wizard\ClassStructureType;
use App\Form\Wizard\FeeItemsType;
use App\Form\Wizard\OnboardingCalendarType;
use App\Form\Wizard\OnboardingIdentityType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/onboarding')]
#[IsGranted('ROLE_ADMIN')]
class OnboardingController extends AbstractController
{
    // ==========================================
    // STEP 1: IDENTITY (School Name & Logo)
    // ==========================================
    #[Route('/identity', name: 'app_tenant_onboarding_identity')]
    public function step1(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $school = $em->getRepository(School::class)->find(1);
        
        if (!$school) {
            $school = new School();
            /** @var \App\Entity\Tenant\User $user */
            $user = $this->getUser();
            if ($user) $school->setEmail($user->getEmail());
        }

        $form = $this->createForm(OnboardingIdentityType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $logoFile = $form->get('logoFile')->getData();
            if ($logoFile) {
                $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$logoFile->guessExtension();
                try {
                    $logoFile->move($this->getParameter('school_logos_directory'), $newFilename);
                    $school->setLogoFilename($newFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Logo upload failed');
                }
            }

            $em->persist($school);
            $em->flush();
            $this->addFlash('success', 'School Profile Saved!');
            return $this->redirectToRoute('app_tenant_onboarding_structure');
        }

        return $this->render('tenant/onboarding/step1_identity.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // ==========================================
    // STEP 2: CLASS STRUCTURE (JSS 1-3)
    // ==========================================
    #[Route('/structure', name: 'app_tenant_onboarding_structure')]
    public function step2(Request $request, EntityManagerInterface $em): Response
    {
        $existingClassesCount = $em->getRepository(Classroom::class)->count([]);
        // 🟢 1. Check for students (The Ultimate Safety Net)
        $studentCount = $em->getRepository(\App\Entity\Tenant\Student::class)->count([]); 
        
        $school = $em->getRepository(School::class)->find(1);
        if (!$school) return $this->redirectToRoute('app_tenant_onboarding_identity');

        $type = $school->getInstitutionType() ?? 'secondary';
        
        $suggestions = match($type) {
            'primary' => ['Nursery 1', 'Nursery 2', 'KG 1', 'KG 2', 'Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6'],
            'tertiary' => ['ND 1', 'ND 2', 'HND 1', 'HND 2', 'Year 1', 'Year 2', 'Year 3', 'Year 4'],
            default => ['JSS 1', 'JSS 2', 'JSS 3', 'SS 1', 'SS 2', 'SS 3'],
        };

        $form = $this->createForm(ClassStructureType::class, [
            'suggestions' => $suggestions
        ]);
        
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 🟢 2. Check WHICH button the user clicked
            $action = $request->request->get('action');

            // CASE A: User clicked "Keep Existing" -> Skip generation entirely
            if ($action === 'keep') {
                $this->addFlash('info', 'Kept existing classroom structure.');
                return $this->redirectToRoute('app_tenant_onboarding_calendar');
            }

            // CASE B: User clicked "Wipe & Regenerate"
            if ($action === 'regenerate') {
                if ($studentCount > 0) {
                    $this->addFlash('error', 'Cannot regenerate classes because students are already enrolled.');
                    return $this->redirectToRoute('app_tenant_onboarding_structure');
                }

                // It is safe to wipe. Delete all existing classes.
                $oldClasses = $em->getRepository(Classroom::class)->findAll();
                foreach ($oldClasses as $oldClass) {
                    $em->remove($oldClass);
                }
                $em->flush(); // Commit the deletion
            }

            // CASE C: Proceed with Generation (First time, or after wipe)
            $data = $form->getData();
            $classes = $data['selectedClasses'];
            $arms = $data['arms'] ? array_map('trim', explode(',', $data['arms'])) : [];
            
            $count = 0;
            foreach ($classes as $className) {
                if (empty($arms)) {
                    $this->createClass($em, $className);
                    $count++;
                } else {
                    foreach ($arms as $arm) {
                        if (empty($arm)) continue; 
                        $this->createClass($em, "$className $arm");
                        $count++;
                    }
                }
            }

            $em->flush();
            
            // Flash message depends on what they did
            if ($action === 'regenerate') {
                $this->addFlash('success', "Fresh start! $count new classrooms generated.");
            } else {
                $this->addFlash('success', "Success! $count classrooms generated.");
            }
            
            return $this->redirectToRoute('app_tenant_onboarding_calendar');
        }

        return $this->render('tenant/onboarding/step2_structure.html.twig', [
            'form' => $form->createView(),
            'schoolType' => $type,
            'existingClassesCount' => $existingClassesCount,
            'studentCount' => $studentCount // Pass to view for the warning UI
        ]);
    }

    // ==========================================
    // STEP 3: CALENDAR (Session & Term)
    // ==========================================
    #[Route('/calendar', name: 'app_tenant_onboarding_calendar')]
    public function step3(Request $request, EntityManagerInterface $em): Response
    {
        $school = $em->getRepository(School::class)->find(1);
        if (!$school) return $this->redirectToRoute('app_tenant_onboarding_identity');

        $type = $school->getInstitutionType();
        
        // 1. TRY TO LOAD EXISTING ACTIVE TERM
        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        $session = $term ? $term->getSession() : null;

        // 2. PREPARE DEFAULTS IF NOTHING EXISTS
        $defaultData = [
            'sessionName' => $session ? $session->getName() : date('Y') . '/' . (date('Y') + 1),
            'termName'    => $term ? $term->getName() : (($type === 'tertiary') ? 'First Semester' : '1st Term'),
            'startDate'   => $term ? $term->getStartDate() : new \DateTime('now'),
            'endDate'     => $term ? $term->getEndDate() : (new \DateTime('now'))->modify('+3 months'),
        ];

        $form = $this->createForm(OnboardingCalendarType::class, $defaultData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // A. Update or Create Session
            if (!$session) {
                $session = new Session();
                $session->setIsActive(true);
                // Rough estimate for session dates based on the term
                $session->setStartDate(new \DateTime('first day of September this year'));
                $session->setEndDate(new \DateTime('last day of July next year'));
            }
            $session->setName($data['sessionName']);
            $em->persist($session);

            // B. Update or Create Term
            if (!$term) {
                $term = new Term();
                $term->setSession($session);
                $term->setIsActive(true);
            }
            $term->setName($data['termName']);
            $term->setStartDate($data['startDate']);
            $term->setEndDate($data['endDate']);
            $em->persist($term);

            $em->flush();
            $this->addFlash('success', 'Calendar updated! Now define your Fee Items.');
            return $this->redirectToRoute('app_tenant_onboarding_fee_items'); 
        }

        return $this->render('tenant/onboarding/step3_calendar.html.twig', [
            'form' => $form->createView(),
            'schoolType' => $type
        ]);
    }
    // ==========================================
    // STEP 4: FEE ITEMS (Full Edit/Delete Support)
    // ==========================================
    // STEP 4: FEE ITEMS (Strict Update Logic)
    #[Route('/fees/items', name: 'app_tenant_onboarding_fee_items')]
    public function step4(Request $request, EntityManagerInterface $em): Response
    {
        // 1. Load Everything currently in the Database
        $existingItems = $em->getRepository(FeeItem::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
        
        // Map existing items by Name so we can check if they exist easily
        $existingMap = [];
        foreach ($existingItems as $item) {
            $existingMap[$item->getName()] = $item;
        }

        // 2. Define Defaults (The "Standard" list)
        $defaults = [
            ['Tuition / School Fees', 'TERM', 'ALL', false],
            ['Exam / Assessment Fee', 'TERM', 'ALL', false],
            ['PTA Levy', 'SESSION', 'ALL', false],
            ['Medical / First Aid', 'TERM', 'ALL', false],
            ['Sports / Inter-house', 'SESSION', 'ALL', false],
            ['Lesson / Extra Class', 'TERM', 'ALL', false],
            ['ICT / Computer Fee', 'SESSION', 'ALL', false],
            ['Admission / Form Fee', 'ONETIME', 'NEW', false],
            ['Uniform / Wears', 'ONETIME', 'NEW', true], 
            ['Books / Materials', 'ONETIME', 'NEW', true], 
            ['ID Card', 'ONETIME', 'NEW', false],
        ];

        // 3. Build the Form Data
        $formData = ['items' => []];

        // A. Process DEFAULTS
        foreach ($defaults as $def) {
            $name = $def[0];
            
            // 🟢 CRITICAL CHECK: Does this item already exist in the DB?
            if (isset($existingMap[$name])) {
                $dbItem = $existingMap[$name];
                
                // TRUST THE DATABASE! Use the values you saved previously.
                $freq = $dbItem->getFrequency();
                $target = ($freq === 'ONETIME') ? 'NEW' : 'ALL';

                $formData['items'][] = [
                    'id' => $dbItem->getId(),
                    'isSelected' => true,
                    'name' => $dbItem->getName(),
                    'frequency' => $freq,     // Use DB Value
                    'target' => $target,      // Use DB Value
                    'isOptional' => $dbItem->isOptional(), // Use DB Value
                    'position' => $dbItem->getPosition()
                ];
                
                // Remove from map so we don't add it twice
                unset($existingMap[$name]);
            } else {
                // If it's NOT in the DB, only then use the hardcoded Default
                $formData['items'][] = [
                    'id' => null,
                    'isSelected' => in_array($name, ['Tuition / School Fees', 'PTA Levy']),
                    'name' => $name,
                    'frequency' => $def[1],
                    'target' => $def[2],
                    'isOptional' => $def[3],
                    'position' => 0
                ];
            }
        }

        // B. Process CUSTOM ITEMS (Anything else left in the DB)
        foreach ($existingMap as $customItem) {
            $freq = $customItem->getFrequency();
            $target = ($freq === 'ONETIME') ? 'NEW' : 'ALL';
            
            $formData['items'][] = [
                'id' => $customItem->getId(),
                'isSelected' => true,
                'name' => $customItem->getName(),
                'frequency' => $freq,
                'target' => $target,
                'isOptional' => $customItem->isOptional(),
                'position' => $customItem->getPosition()
            ];
        }

        // 4. Handle Form Submission
        $form = $this->createForm(FeeItemsType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $submittedIds = [];

            foreach ($data['items'] as $row) {
                // Only save checked or named items
                if (($row['isSelected'] === true || !empty($row['name'])) && !empty($row['name'])) {
                    
                    $freq = $row['frequency'];
                    
                    // Logic: Auto-correct Frequency based on Target
                    if ($row['target'] === 'NEW') {
                        $freq = 'ONETIME';
                    } elseif ($row['target'] === 'ALL' && $freq === 'ONETIME') {
                        $freq = 'TERM'; // Default to Term if they switch back to All
                    }

                    // Save or Update
                    $savedId = $this->saveOrUpdateFeeItem(
                        $em, 
                        $row['id'], 
                        $row['name'], 
                        $freq, 
                        $row['isOptional'], 
                        (int)$row['position']
                    );
                    
                    if ($savedId) $submittedIds[] = $savedId;
                }
            }

            // 5. Delete removed items
            foreach ($existingItems as $existing) {
                if (!in_array($existing->getId(), $submittedIds)) {
                    // Delete linked prices first
                    $structures = $em->getRepository(FeeStructure::class)->findBy(['feeItem' => $existing]);
                    foreach ($structures as $s) $em->remove($s);
                    
                    $em->remove($existing);
                }
            }

            $em->flush();
            $this->addFlash('success', 'Fee Configuration Updated!');
            return $this->redirectToRoute('app_tenant_onboarding_fee_prices');
        }

        return $this->render('tenant/onboarding/step4_fee_items.html.twig', [
            'form' => $form->createView()
        ]);
    }

    // ==========================================
    // STEP 5: FEE PRICING (Matrix with Safety Split)
    // ==========================================
   // STEP 5: FEE PRICING (Now Pre-fills Existing Prices!)
    #[Route('/fees/prices', name: 'app_tenant_onboarding_fee_prices')]
    public function step5(Request $request, EntityManagerInterface $em, \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        // 🧹 SAFETY CLEANUP
        $em->createQuery("DELETE FROM App\Entity\Tenant\FeeItem f WHERE f.name IS NULL OR f.name = ''")->execute();

        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if (!$term) return $this->redirectToRoute('app_tenant_onboarding_calendar');

        $classrooms = $em->getRepository(Classroom::class)->findAll();
        // Load items respecting drag-and-drop order
        $allFeeItems = $em->getRepository(FeeItem::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);

        // 1. SMART GROUPING (JSS 1A, JSS 1B -> JSS 1)
        $levels = [];
        foreach ($classrooms as $class) {
            $name = $class->getName();
            $parts = explode(' ', $name);
            if (count($parts) > 1) {
                $last = end($parts);
                if ((strlen($last) <= 2 && !is_numeric($last)) || in_array(strtolower($last), ['gold', 'silver', 'bronze', 'blue', 'red', 'green'])) {
                    array_pop($parts);
                    $levelName = implode(' ', $parts);
                } else {
                    $levelName = $name;
                }
            } else {
                $levelName = $name;
            }
            $levels[$levelName][] = $class;
        }
        ksort($levels, SORT_NATURAL);

        // 🟢 2. PRE-FETCH EXISTING PRICES
        // We need to look up prices by [ItemID][LevelName]
        $existingPrices = [];
        $structures = $em->getRepository(FeeStructure::class)->findBy(['term' => $term]);

        foreach ($structures as $fs) {
            $itemId = $fs->getFeeItem()->getId();
            $className = $fs->getClassroom()->getName();
            
            // Reverse-Engineer the Level Name from Class Name
            $levelName = $className; // Default
            foreach (array_keys($levels) as $lvl) {
                if (str_starts_with($className, $lvl)) {
                    $levelName = $lvl;
                    break;
                }
            }
            
            // Store the amount (overwrite is fine, all arms should have same price)
            $existingPrices[$itemId][$levelName] = $fs->getAmount();
        }

        // 3. SEPARATE LISTS
        $recurringItems = array_filter($allFeeItems, fn($i) => $i->getFrequency() !== 'ONETIME');
        $oneTimeItems   = array_filter($allFeeItems, fn($i) => $i->getFrequency() === 'ONETIME');

        
        // 🟢 1. CAPTURE THE RETURN TICKET (From URL or Previous Submit)
       $returnTo = $request->query->get('return_to') ?? $request->request->get('return_to');

            // 4. HANDLE SAVE (ROBUST VERSION)
            if ($request->isMethod('POST')) {
                $token = $request->request->get('_token');
                if (!$csrfTokenManager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('fee_matrix', $token))) {
                    $this->addFlash('error', 'Security token expired. Please try again.');
                    return $this->redirectToRoute('app_tenant_onboarding_fee_prices');
                }
    
                $submittedFees = $request->request->all('fees'); 
                
                foreach ($allFeeItems as $item) {
                    foreach ($levels as $levelName => $subClasses) {
                        // Get the price entered for this Level (e.g., JSS 1)
                        $amount = $submittedFees[$item->getId()][$levelName] ?? null;
                        
                        // We loop through sub-classrooms (JSS 1A, JSS 1B...)
                        foreach ($subClasses as $class) {
                            
                            // 🔍 SEARCH: Find ALL existing entries for this specific combo
                            $existingEntries = $em->getRepository(FeeStructure::class)->findBy([
                                'term' => $term,
                                'classroom' => $class,
                                'feeItem' => $item
                            ]);

                            if ($amount !== null && $amount !== '') {
                                // CASE A: WE HAVE A PRICE TO SAVE
                                
                                if (count($existingEntries) > 0) {
                                    // Update the FIRST one found
                                    $structure = $existingEntries[0];
                                    $structure->setAmount($amount);
                                    $em->persist($structure);

                                    // 🧹 CLEANUP: If there are accidental duplicates (index 1, 2, 3...), DELETE THEM
                                    // This fixes the "Adding instead of Replacing" bug
                                    for ($i = 1; $i < count($existingEntries); $i++) {
                                        $em->remove($existingEntries[$i]);
                                    }
                                } else {
                                    // Create New
                                    $structure = new FeeStructure();
                                    $structure->setTerm($term);
                                    $structure->setClassroom($class);
                                    $structure->setFeeItem($item);
                                    $structure->setAmount($amount);
                                    $em->persist($structure);
                                }

                            } else {
                                // CASE B: INPUT IS EMPTY -> DELETE THE FEE
                                // If user cleared the box, remove the price entirely
                                foreach ($existingEntries as $entry) {
                                    $em->remove($entry);
                                }
                            }
                        }
                    }
                }
                
                $em->flush();
                $this->addFlash('success', 'Fees updated successfully!');
                
                if ($returnTo === 'schedule') {
                return $this->redirectToRoute('app_tenant_fee_schedule');
            }
                // If editing, go to schedule. If onboarding, go to summary.
                return $this->redirectToRoute('app_tenant_onboarding_summary'); 
            }

        return $this->render('tenant/onboarding/step5_fee_matrix.html.twig', [
            'term' => $term,
            'levels' => array_keys($levels),
            'recurringItems' => $recurringItems,
            'oneTimeItems' => $oneTimeItems,
            'existingPrices' => $existingPrices,
            'returnTo' => $returnTo // 🟢 Pass data to view
        ]);
    }

    // ==========================================
    // HELPER FUNCTIONS
    // ==========================================

    private function saveOrUpdateFeeItem(EntityManagerInterface $em, ?int $id, string $name, string $frequency, bool $isOptional, ?int $position): ?int
    {
        $item = null;
        if ($id) {
            $item = $em->getRepository(FeeItem::class)->find($id);
        }

        // Fallback: Check by name if no ID
        if (!$item) {
            $item = $em->getRepository(FeeItem::class)->findOneBy(['name' => $name]);
        }

        if (!$item) {
            $item = new FeeItem();
            $item->setCreatedAt(new \DateTimeImmutable());
        }

        $item->setName($name);
        $item->setFrequency($frequency);
        $item->setIsOptional($isOptional);
        $item->setPosition($position);
        
        $em->persist($item);
        $em->flush(); // Flush here to get ID for tracking
        
        return $item->getId();
    }
    
    private function createClass(EntityManagerInterface $em, string $name): void
    {
        $exists = $em->getRepository(Classroom::class)->findOneBy(['name' => $name]);
        if (!$exists) {
            $classroom = new Classroom();
            $classroom->setName($name);
            $em->persist($classroom);
        }
    }

    // ==========================================
    // STEP 6: SUMMARY & PRINTABLE REPORT
    // ==========================================
   
    #[Route('/summary', name: 'app_tenant_onboarding_summary')]
    public function step6(Request $request, EntityManagerInterface $em): Response
    {
        // 🟢 1. HANDLE CONFIRMATION (The new logic)
        if ($request->isMethod('POST')) {
            $this->addFlash('success', 'Fee Structure Confirmed! Now, let\'s populate your school.');
            
            // Redirect to the Quick Enroll Wizard (The Grand Finale)
            return $this->redirectToRoute('app_tenant_quick_enroll', ['wizard' => 'true']);
        }

        // ... (Existing logic below remains unchanged) ...

        $school = $em->getRepository(School::class)->find(1);
        
        // 1. Get Active Term
        $term = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        if (!$term) {
            // Fallback if no active term, grab the latest one
            $term = $em->getRepository(Term::class)->findOneBy([], ['id' => 'DESC']);
        }
        
        // 🟢 CHECK IF FIRST TERM (To hide session fees if needed)
        $termName = strtolower($term ? $term->getName() : '');
        $isFirstTerm = (str_contains($termName, '1st') || str_contains($termName, 'first') || str_starts_with($termName, '1'));

        // 2. FETCH FEES
        $queryBuilder = $em->getRepository(FeeStructure::class)->createQueryBuilder('fs')
            ->join('fs.feeItem', 'fi')
            ->join('fs.classroom', 'c')
            ->where('fs.term = :term')
            ->setParameter('term', $term)
            ->orderBy('fi.position', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        // Filter out Session fees if not 1st term
        if (!$isFirstTerm) {
            $queryBuilder->andWhere("fi.frequency != 'SESSION'");
        }

        $structures = $queryBuilder->getQuery()->getResult();

        // 3. GROUP LEVELS
        $classrooms = $em->getRepository(Classroom::class)->findAll();
        $levels = [];
        foreach ($classrooms as $class) {
            $name = $class->getName();
            $parts = explode(' ', $name);
            if (count($parts) > 1) {
                $last = end($parts);
                if ((strlen($last) <= 2 && !is_numeric($last)) || in_array(strtolower($last), ['gold', 'silver', 'bronze', 'blue', 'red', 'green'])) {
                    array_pop($parts);
                    $levelName = implode(' ', $parts);
                } else {
                    $levelName = $name;
                }
            } else {
                $levelName = $name;
            }
            $levels[$levelName][] = $class;
        }
        ksort($levels, SORT_NATURAL);

        // 4. CALCULATE TOTALS (WITH DUPLICATE PROTECTION)
        $matrix = [];
        $totals = []; 

        // Initialize totals
        foreach (array_keys($levels) as $lvl) {
            $totals[$lvl] = ['recurring' => 0, 'onetime' => 0];
        }

        // 🛡️ DUPLICATE BLOCKER: Keeps track of what we have already added
        $processedItems = []; // Format: "ItemID_LevelName"

        /** @var FeeStructure $fs */
        foreach ($structures as $fs) {
            $itemName = $fs->getFeeItem()->getName();
            $itemId   = $fs->getFeeItem()->getId();
            $freq     = $fs->getFeeItem()->getFrequency();
            $isOneTime = ($freq === 'ONETIME');
            
            // Map Class -> Level
            $cName = $fs->getClassroom()->getName();
            $levelName = $cName; 
            foreach (array_keys($levels) as $lvl) {
                if (str_starts_with($cName, $lvl)) {
                    $levelName = $lvl;
                    break;
                }
            }

            // A. Fill the Matrix (Table Body) - This is safe to overwrite
            $matrix[$itemName][$levelName] = $fs->getAmount();

            // B. Calculate Totals (Footer) - NEEDS PROTECTION
            // Create a unique key for this Fee Item + Level
            $uniqueKey = $itemId . '_' . $levelName;

            // 🛡️ ONLY ADD TO TOTAL IF NOT PROCESSED YET
            if (!isset($processedItems[$uniqueKey])) {
                if ($isOneTime) {
                    $totals[$levelName]['onetime'] += $fs->getAmount();
                } else {
                    $totals[$levelName]['recurring'] += $fs->getAmount();
                }
                
                // Mark as processed so JSS 1B, 1C don't get added again
                $processedItems[$uniqueKey] = true; 
            }
        }
        
        // 5. PREPARE ITEM LISTS
        $feeItems = $em->getRepository(FeeItem::class)->findBy([], ['position' => 'ASC']);
        
        $recurringItems = array_filter($feeItems, function($i) use ($isFirstTerm) {
            return $i->getFrequency() !== 'ONETIME' && ($isFirstTerm || $i->getFrequency() !== 'SESSION');
        });

        $oneTimeItems = array_filter($feeItems, fn($i) => $i->getFrequency() === 'ONETIME');

        return $this->render('tenant/onboarding/step6_summary.html.twig', [
            'school' => $school,
            'term' => $term,
            'levels' => array_keys($levels),
            'recurringItems' => $recurringItems,
            'oneTimeItems' => $oneTimeItems,
            'matrix' => $matrix,
            'totals' => $totals,
            'isFirstTerm' => $isFirstTerm
        ]);
    }
}