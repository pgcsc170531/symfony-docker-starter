<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\AccountCredit;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Invoice;
use App\Entity\Tenant\VirtualAccount;
use App\Service\Payment\AccountCreditApplicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Money that arrived on the family's account but could not be tied to a bill —
 * a wrong amount, an expired figure, or two bills added together.
 *
 * The parent sees it and says which bill it was; nothing is ever guessed across
 * siblings (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md §5.5).
 */
#[Route('/parent/credits')]
#[IsGranted('ROLE_PARENT')]
final class ParentCreditController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountCreditApplicationService $applicator,
    ) {
    }

    #[Route('', name: 'app_parent_credits', methods: ['GET'])]
    public function index(): Response
    {
        $guardian = $this->guardian();
        $account = $this->account($guardian);

        return $this->render('tenant/parent/credits.html.twig', [
            'credits' => $account !== null
                ? $this->em->getRepository(AccountCredit::class)->findBy(
                    ['virtualAccount' => $account, 'status' => AccountCredit::STATUS_UNASSIGNED],
                    ['paidAt' => 'DESC'],
                )
                : [],
            'openInvoices' => $this->openInvoices($guardian),
        ]);
    }

    #[Route('/{id}/apply', name: 'app_parent_credit_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(AccountCredit $credit, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('apply_credit_' . $credit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $guardian = $this->guardian();
        $account = $this->account($guardian);

        if ($credit->getVirtualAccount() !== $account) {
            throw $this->createAccessDeniedException('That payment is not yours.');
        }

        $invoice = $this->em->getRepository(Invoice::class)->find((int) $request->request->get('invoice_id'));

        if ($invoice === null || $invoice->getStudent() === null || !$guardian->getStudents()->contains($invoice->getStudent())) {
            $this->addFlash('error', 'Choose a bill that belongs to your child.');

            return $this->redirectToRoute('app_parent_credits');
        }

        $result = $this->applicator->apply($credit, $invoice, 'parent');

        $this->addFlash(
            $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? 'Applied ₦' . number_format((float) $credit->getAmount(), 2) . ' to ' . $invoice->getInvoiceNumber() . '.'
                : ($result['message'] ?? 'We could not apply that payment.')
        );

        return $this->redirectToRoute('app_parent_credits');
    }

    private function guardian(): Guardian
    {
        $user = $this->getUser();
        $guardian = $user !== null
            ? $this->em->getRepository(Guardian::class)->findOneBy(['user' => $user])
            : null;

        if ($guardian === null) {
            throw $this->createAccessDeniedException('No parent record for this login.');
        }

        return $guardian;
    }

    private function account(?Guardian $guardian): ?VirtualAccount
    {
        if ($guardian === null) {
            return null;
        }

        return $this->em->getRepository(VirtualAccount::class)->findOneBy(['guardian' => $guardian]);
    }

    /** @return Invoice[] */
    private function openInvoices(Guardian $guardian): array
    {
        $invoices = $this->em->getRepository(Invoice::class)->findBy(
            ['student' => $guardian->getStudents()->toArray()],
            ['createdAt' => 'DESC'],
        );

        return array_values(array_filter(
            $invoices,
            static fn (Invoice $invoice): bool => (float) $invoice->getBalanceDue() > 0
        ));
    }
}
