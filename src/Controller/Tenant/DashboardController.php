<?php

namespace App\Controller\Tenant;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Service\Dashboard\DashboardDataProvider;
use App\Service\Dashboard\DashboardPersona;
use App\Service\Dashboard\DashboardPersonaResolver;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One route, one page per seat.
 *
 * The page used to be identical for every account: a teacher saw the school's
 * wallet and debtors, the store keeper saw outstanding fees, the bursar saw
 * result approvals. Now the controller works out *who* is signed in, loads only
 * the data that seat is entitled to, and hands the template a persona plus the
 * panels it may draw. A teacher's request never runs a fees query at all.
 *
 * The rules live in App\Service\Dashboard — see DashboardPersonaResolver for the
 * label priority and DashboardDataProvider for the figures.
 */
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_tenant_dashboard', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(
        EntityManagerInterface $em,
        ManagerRegistry $doctrine,
        DashboardDataProvider $data,
        DashboardPersonaResolver $personas,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        // A parent belongs in the parent portal. A parent who is *also* on staff
        // (a teacher with a child in the school) keeps the staff dashboard and
        // reaches the parent portal from the account menu.
        if (!$user || ($this->isGranted('ROLE_PARENT') && $user->getStaff() === null)) {
            return $this->redirectToRoute('app_parent_dashboard');
        }

        $term = $data->activeTerm();
        $session = $term?->getSession() ?? $data->activeSession();
        $staff = $user->getStaff();

        $persona = $personas->resolve($user, ...$data->assignmentFlags($staff, $term));

        $studentCount = (int) $em->getRepository(Student::class)->count([]);

        // A school with no pupils has nothing to report on. An administrator is
        // sent to the setup wizard rather than shown a dashboard of zeros. Staff
        // who are not administrators keep the dashboard — they cannot onboard.
        if ($studentCount === 0 && $persona->isAdmin) {
            return $this->redirectToRoute('app_tenant_onboarding_identity');
        }

        $view = [
            'persona' => $persona,
            'studentCount' => $studentCount,
            'school_name' => $this->schoolName($user, $em),
            'user_name' => $user->getFullName() ?? 'User',
            'currentSession' => $session,
            'currentTerm' => $term,
            'landlordSchool' => $persona->canSeeFinance ? $this->landlordSchool($doctrine, $user) : null,
        ];

        // ── Panels, gated by what the seat may see ────────────────────
        if ($persona->seesWholeSchool()) {
            $view['overview'] = $data->overview($term);
        }
        // The admissions desk sees its own queue, not the whole school's figures.
        if ($persona->canSeeAdmissions && !$persona->seesWholeSchool()) {
            $view['admissions'] = $data->admissions();
        }
        if ($persona->canSeeFinance) {
            $view['finance'] = $data->finance($term);
        }
        if ($persona->canSeeStore) {
            $view['store'] = $data->store();
        }
        if ($staff && $persona->canSeeTeaching) {
            $view['teaching'] = $data->teacherWork($staff, $term);
        }
        if ($persona->isFormTeacher) {
            $view['formClass'] = $data->formClass($staff, $term);
        }
        if ($persona->isHod) {
            $view['departments'] = $data->departmentWork($staff, $term);
        }
        if ($persona->isYearGroupMaster) {
            $view['yearGroups'] = $data->yearGroupWork($staff, $term);
        }

        $view['actions'] = $this->buildActions($persona, $view);

        return $this->render('tenant/dashboard/index.html.twig', $view);
    }

    // ──────────────────────────────────────────────────────────────────
    //  "Your work" — the one thing this seat has to do today
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $view
     * @return list<array{label: string, hint: string, url: string, tone: string}>
     */
    private function buildActions(DashboardPersona $persona, array $view): array
    {
        $actions = [];

        $teaching = $view['teaching'] ?? null;
        if ($teaching) {
            if ($teaching['returned'] > 0) {
                $actions[] = $this->action(
                    sprintf('%d result sheet%s returned for correction', $teaching['returned'], $teaching['returned'] === 1 ? '' : 's'),
                    'Fix and re-submit on the entry screen',
                    'tenant_result_entry_index',
                    'failed'
                );
            }
            if ($teaching['waiting'] > 0) {
                $actions[] = $this->action(
                    sprintf('%d subject%s without marks', $teaching['waiting'], $teaching['waiting'] === 1 ? '' : 's'),
                    'Enter or submit this term’s scores',
                    'tenant_result_entry_index',
                    'pending'
                );
            }
        }

        $waitingApprovals = 0;
        foreach ($view['departments'] ?? [] as $department) {
            $waitingApprovals += (int) ($department['waiting'] ?? 0);
        }
        foreach ($view['yearGroups'] ?? [] as $yearGroup) {
            $waitingApprovals += (int) ($yearGroup['waiting'] ?? 0);
        }
        if ($waitingApprovals > 0) {
            $actions[] = $this->action(
                sprintf('%d result sheet%s waiting for approval', $waitingApprovals, $waitingApprovals === 1 ? '' : 's'),
                'Review the submitted sheets',
                'tenant_approval_hub',
                'pending'
            );
        }

        $formClass = $view['formClass'] ?? null;
        if ($formClass) {
            if ($formClass['registersMissing'] > 0) {
                $actions[] = $this->action(
                    sprintf('%d attendance register%s not taken', $formClass['registersMissing'], $formClass['registersMissing'] === 1 ? '' : 's'),
                    $formClass['classroom']->getName() . ' — catch up on missed days',
                    'app_tenant_my_class_attendance',
                    'pending'
                );
            }
            if ($formClass['remarksOutstanding'] > 0) {
                $actions[] = $this->action(
                    sprintf('%d pupil remark%s outstanding', $formClass['remarksOutstanding'], $formClass['remarksOutstanding'] === 1 ? '' : 's'),
                    $formClass['classroom']->getName() . ' — end-of-term remarks',
                    'app_tenant_my_class_remarks',
                    'pending'
                );
            }
        }

        $overview = $view['overview'] ?? null;
        if ($overview && $overview['pendingApplications'] > 0) {
            $actions[] = $this->action(
                sprintf('%d admission application%s to review', $overview['pendingApplications'], $overview['pendingApplications'] === 1 ? '' : 's'),
                'Parents are waiting for a decision',
                'app_tenant_application_index',
                'accent'
            );
        }

        // The admissions desk gets the same nudge from its own queue.
        $admissions = $view['admissions'] ?? null;
        if ($admissions && $admissions['queue'] > 0) {
            $actions[] = $this->action(
                sprintf('%d application%s waiting for a decision', $admissions['queue'], $admissions['queue'] === 1 ? '' : 's'),
                $admissions['needsInfo'] > 0
                    ? sprintf('%d sent back to a parent for more information', $admissions['needsInfo'])
                    : 'Review, then offer a place and enrol',
                'app_tenant_application_index',
                'accent'
            );
        }

        $finance = $view['finance'] ?? null;

        // A confirmed bank transfer that nobody has approved is money the school
        // has already received but cannot spend in the books. It outranks the
        // outstanding-fees nudge.
        if ($finance && ($finance['pendingVerifications'] ?? 0) > 0) {
            $actions[] = $this->action(
                sprintf(
                    '%d payment%s awaiting verification',
                    $finance['pendingVerifications'],
                    $finance['pendingVerifications'] === 1 ? '' : 's'
                ),
                '₦' . number_format($finance['pendingVerificationTotal'], 0) . ' claimed — check the bank, then approve',
                'app_tenant_payment_verify',
                'pending'
            );
        }

        if ($finance && $finance['debtorCount'] > 0) {
            $actions[] = $this->action(
                sprintf('%d invoice%s still unpaid', $finance['debtorCount'], $finance['debtorCount'] === 1 ? '' : 's'),
                '₦' . number_format($finance['outstanding'], 0) . ' outstanding',
                'app_tenant_invoice_index',
                'accent'
            );
        }

        $store = $view['store'] ?? null;
        if ($store && $store['lowStockCount'] > 0) {
            $actions[] = $this->action(
                sprintf('%d item%s low on stock', $store['lowStockCount'], $store['lowStockCount'] === 1 ? '' : 's'),
                'Restock before the shelves are empty',
                'app_tenant_product_index',
                'accent'
            );
        }

        // An overseer's standing duty: the term is not closed until every sheet
        // has been through every approval stage.
        if ($persona->isSuperAdmin && $overview && $overview['resultPercent'] !== null && $overview['resultPercent'] < 100) {
            $actions[] = $this->action(
                sprintf('Results %d%% approved', $overview['resultPercent']),
                'The term cannot be released until every subject is signed off',
                'tenant_approval_hub',
                'accent'
            );
        }

        return $actions;
    }

    /**
     * @return array{label: string, hint: string, url: string, tone: string}
     */
    private function action(string $label, string $hint, string $route, string $tone): array
    {
        return [
            'label' => $label,
            'hint' => $hint,
            'url' => $this->generateUrl($route),
            'tone' => $tone,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  School identity / Edus wallet
    // ──────────────────────────────────────────────────────────────────

    private function schoolName(User $user, EntityManagerInterface $em): string
    {
        if ($user->getTenant()) {
            return $user->getTenant()->getName();
        }

        $school = $em->getRepository(School::class)->findOneBy([]);

        return $school?->getName() ?? 'Your School';
    }

    /**
     * The school's Edus subscription wallet lives in the landlord database, so it
     * is looked up by subdomain rather than by the tenant entity manager.
     */
    private function landlordSchool(ManagerRegistry $doctrine, User $user): ?LandlordSchool
    {
        $subdomain = $user->getSchool()?->getSubdomain();

        if (!$subdomain) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $subdomain = explode('.', $host)[0];
        }

        return $doctrine->getManager('landlord')
            ->getRepository(LandlordSchool::class)
            ->findOneBy(['subdomain' => $subdomain]);
    }
}
