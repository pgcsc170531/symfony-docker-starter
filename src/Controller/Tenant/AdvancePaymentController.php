<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Payment;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\StudentAdvance;
use App\Service\Finance\AdvancePaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Advance payments — money a family has paid before the bills exist.
 *
 * Bursary only. A teacher cannot see or record this, for the same reason a
 * teacher cannot see fees: it is the office's ledger, not the classroom's.
 */
#[Route('/finance/advance')]
#[IsGranted('ROLE_BURSAR')]
class AdvancePaymentController extends AbstractController
{
    public function __construct(
        private readonly AdvancePaymentService $advances,
    ) {
    }

    #[Route('/', name: 'app_tenant_advance_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            return $this->record($request, $em);
        }

        $advances = $em->getRepository(StudentAdvance::class)->findBy([], ['id' => 'DESC']);

        $rows = [];
        $totalPaid = 0.0;
        $totalLeft = 0.0;

        foreach ($advances as $advance) {
            $left = $this->advances->balance($advance);
            $used = (float) $advance->getAmount() - $left;

            $totalPaid += (float) $advance->getAmount();
            $totalLeft += $advance->isClosed() ? 0.0 : $left;

            $rows[] = [
                'advance' => $advance,
                'left' => $left,
                'used' => $used,
            ];
        }

        $search = (string) $request->query->get('q', '');
        $studentId = (int) $request->query->get('student', 0);
        $student = $studentId > 0 ? $em->getRepository(Student::class)->find($studentId) : null;

        return $this->render('tenant/finance/advance/index.html.twig', [
            'rows' => $rows,
            'totalPaid' => $totalPaid,
            'totalLeft' => $totalLeft,
            'methods' => StudentAdvance::METHODS,
            'search' => $search,
            'found' => $this->searchStudents($em, $search),
            'student' => $student,
        ]);
    }

    /** Record new money paid ahead. */
    private function record(Request $request, EntityManagerInterface $em): Response
    {
        $studentId = (int) $request->request->get('student_id');
        $student = $em->getRepository(Student::class)->find($studentId);

        if (!$student instanceof Student) {
            $this->addFlash('error', 'Choose the pupil this advance belongs to.');

            return $this->redirectToRoute('app_tenant_advance_index');
        }

        $amount = (float) preg_replace('/[^0-9.]/', '', (string) $request->request->get('amount', '0'));
        if ($amount <= 0) {
            $this->addFlash('error', 'Enter the amount received.');

            return $this->redirectToRoute('app_tenant_advance_index');
        }

        $receivedOn = (string) $request->request->get('received_on', '');
        try {
            $received = $receivedOn !== '' ? new \DateTimeImmutable($receivedOn) : new \DateTimeImmutable();
        } catch (\Exception) {
            $received = new \DateTimeImmutable();
        }

        $advance = (new StudentAdvance())
            ->setStudent($student)
            ->setAmount(number_format($amount, 2, '.', ''))
            ->setReference($this->advances->nextReference())
            ->setMethod((string) $request->request->get('method', StudentAdvance::METHOD_CASH))
            ->setReceivedOn($received)
            ->setCoversNote($this->clean($request->request->get('covers_note')))
            ->setRemark($this->clean($request->request->get('remark')))
            ->setCreatedBy($this->getUser()?->getUserIdentifier());

        $em->persist($advance);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Advance %s recorded for %s — ₦%s. Any invoice raised for this pupil will be paid from it automatically.',
            $advance->getReference(),
            $student->getFullName(),
            number_format($amount, 2)
        ));

        return $this->redirectToRoute('app_tenant_advance_index');
    }

    /** Stop an advance drawing on new invoices (refunded, or the family has left). */
    #[Route('/{id}/close', name: 'app_tenant_advance_close', methods: ['POST'])]
    public function close(StudentAdvance $advance, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('advance_close_' . $advance->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }

        $advance->setIsClosed(!$advance->isClosed());
        $em->flush();

        $this->addFlash('success', $advance->isClosed()
            ? sprintf('Advance %s closed. Its remaining credit will no longer pay new invoices.', $advance->getReference())
            : sprintf('Advance %s reopened.', $advance->getReference()));

        return $this->redirectToRoute('app_tenant_advance_index');
    }

    /** Everything drawn against one advance — the paper trail. */
    #[Route('/{id}', name: 'app_tenant_advance_show', methods: ['GET'])]
    public function show(StudentAdvance $advance, EntityManagerInterface $em): Response
    {
        $draws = $em->getRepository(Payment::class)->findBy(
            ['advance' => $advance],
            ['id' => 'ASC']
        );

        return $this->render('tenant/finance/advance/show.html.twig', [
            'advance' => $advance,
            'draws' => $draws,
            'left' => $this->advances->balance($advance),
        ]);
    }

    /** @return Student[] */
    private function searchStudents(EntityManagerInterface $em, string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return $em->getRepository(Student::class)->createQueryBuilder('s')
            ->andWhere('LOWER(s.firstName) LIKE :q OR LOWER(s.lastName) LIKE :q OR LOWER(s.admissionNumber) LIKE :q')
            ->setParameter('q', '%' . mb_strtolower($q) . '%')
            ->orderBy('s.lastName', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
