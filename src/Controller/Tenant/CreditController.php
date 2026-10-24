<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\AccountCredit;
use App\Entity\Tenant\Invoice;
use App\Service\Payment\AccountCreditApplicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The bursar's view of money that arrived on a family's dedicated account but
 * could not be matched to a bill.
 *
 * The parent can only ever place their **own** money; this screen exists for the
 * cases they cannot resolve — a rounded amount, a transfer from an uncle who used
 * the wrong family's number, or a parent with no portal login. Placing it is a
 * deliberate act with a reason, never an automatic guess
 * (docs/payments/VIRTUAL-ACCOUNTS-DESIGN.md §5.5, §5.6).
 */
#[Route('/finance/credits')]
#[IsGranted('ROLE_BURSAR')]
final class CreditController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountCreditApplicationService $applicator,
    ) {
    }

    #[Route('', name: 'app_finance_credits', methods: ['GET'])]
    public function index(): Response
    {
        $credits = $this->em->getRepository(AccountCredit::class)->findBy(
            ['status' => AccountCredit::STATUS_UNASSIGNED],
            ['paidAt' => 'DESC'],
        );

        $total = 0.0;
        foreach ($credits as $credit) {
            $total += (float) $credit->getAmount();
        }

        return $this->render('tenant/finance/credits.html.twig', [
            'credits' => $credits,
            'total' => $total,
        ]);
    }

    /** Place one transfer on the bill the bursar names. */
    #[Route('/{id}/apply', name: 'app_finance_credit_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(AccountCredit $credit, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('apply_credit_' . $credit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $number = trim((string) $request->request->get('invoice_number', ''));
        $invoice = $number !== ''
            ? $this->em->getRepository(Invoice::class)->findOneBy(['invoiceNumber' => $number])
            : null;

        if ($invoice === null) {
            $this->addFlash('error', sprintf('No invoice numbered “%s” in this school.', $number !== '' ? $number : '—'));

            return $this->redirectToRoute('app_finance_credits');
        }

        $result = $this->applicator->apply($credit, $invoice, 'bursar');

        $this->addFlash(
            $result['ok'] ? 'success' : 'error',
            $result['ok']
                ? sprintf(
                    '₦%s applied to %s. The parent sees it on their own statement.',
                    number_format((float) $credit->getAmount(), 2),
                    $invoice->getInvoiceNumber()
                )
                : ($result['message'] ?? 'We could not apply that payment.')
        );

        return $this->redirectToRoute('app_finance_credits');
    }

    /**
     * Give up on placing it: mark it refunded and let the operator return the
     * money at the gateway. No automatic refunds — that is a second, deliberate
     * action with a bank in it.
     */
    #[Route('/{id}/refund', name: 'app_finance_credit_refund', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refund(AccountCredit $credit, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('refund_credit_' . $credit->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        if (!$credit->isUnassigned()) {
            $this->addFlash('error', 'That money has already been placed.');

            return $this->redirectToRoute('app_finance_credits');
        }

        $credit->markRefunded('bursar');
        $this->em->flush();

        $this->addFlash('info', 'Marked for refund. Refund the transfer at Paystack, then the row stays here as the record.');

        return $this->redirectToRoute('app_finance_credits');
    }
}
