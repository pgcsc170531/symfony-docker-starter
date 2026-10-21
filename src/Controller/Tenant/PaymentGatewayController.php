<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\PaymentIntent;
use App\Entity\Tenant\School;
use App\Service\Payment\FakeGateway;
use App\Service\Payment\PaymentGatewayInterface;
use App\Service\Payment\SettlementService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The endpoints the payment provider talks to — plus a local simulator.
 *
 * None of these trust their caller. The webhook proves itself with an HMAC
 * signature, the callback only *asks* the gateway what happened, and the
 * simulator exists only while no real provider is configured.
 *
 * All three are reachable without a session on purpose (see security.yaml §2b).
 */
#[Route('/payments')]
class PaymentGatewayController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PaymentGatewayInterface $gateway,
        private SettlementService $settlement,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Where the parent's browser lands after paying.
     *
     * This is a *hint*, not proof: it triggers a server-side verify, and the
     * webhook will settle it anyway if the parent closed the tab on the way back.
     */
    #[Route('/callback', name: 'app_payment_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        // Paystack sends `reference`; the simulator sends `reference` too.
        $reference = (string) ($request->query->get('reference') ?: $request->query->get('trxref') ?: '');

        $intent = $reference !== ''
            ? $this->em->getRepository(PaymentIntent::class)->findOneBy(['reference' => $reference])
            : null;

        if ($intent === null) {
            $this->addFlash('error', 'We could not match that payment to an invoice.');

            return $this->redirectToRoute('app_parent_dashboard');
        }

        if ($intent->isSettleable()) {
            try {
                $result = $this->gateway->verify($intent);

                if ($result->isPaid()) {
                    $this->settlement->settle($intent, json_encode($result->raw) ?: '');
                    if (!$this->em->isOpen()) {
                        return $this->redirectToRoute('app_parent_payment_status', ['reference' => $intent->getReference()]);
                    }
                } elseif ($result->status === PaymentIntent::STATUS_FAILED) {
                    $intent->setStatus(PaymentIntent::STATUS_FAILED);
                    $intent->setRawPayload(json_encode($result->raw) ?: null);
                }
            } catch (\Throwable $e) {
                // Never show a parent a stack trace over money. The status page
                // will retry, and the webhook is the backstop.
                $this->logger->error('Callback verification failed for {reference}: {message}', [
                    'reference' => $intent->getReference(),
                    'message' => $e->getMessage(),
                ]);
            }

            $this->em->flush();
        }

        return $this->redirectToRoute('app_parent_payment_status', ['reference' => $intent->getReference()]);
    }

    /**
     * The provider's server-to-server notification.
     *
     * Contract (docs/payments/PAYMENT-FLOW-DESIGN.md §8): verify the signature in
     * constant time before parsing anything → answer 200 fast → settle
     * idempotently → keep the raw payload.
     */
    #[Route('/webhook/paystack', name: 'app_payment_webhook_paystack', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = $this->gateway->parseWebhook($rawBody, $request->headers->all());

        if ($payload === null) {
            // Bad signature, or no gateway configured. 400 makes Paystack retry
            // nothing but tells an attacker we are not interested.
            $this->logger->warning('Rejected a payment webhook (bad signature or no gateway).');

            return new JsonResponse(['status' => 'ignored'], Response::HTTP_BAD_REQUEST);
        }

        $event = (string) ($payload['event'] ?? '');
        $data = $payload['data'] ?? [];
        $reference = (string) ($data['reference'] ?? '');

        $this->logger->info('Payment webhook received: {event} / {reference}', [
            'event' => $event,
            'reference' => $reference,
        ]);

        // Acknowledge immediately — slow work here causes provider retry storms.
        if ($reference === '') {
            return new JsonResponse(['status' => 'ok']);
        }

        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy(['reference' => $reference]);

        if ($intent === null) {
            $this->logger->warning('Payment webhook for unknown reference {reference}', ['reference' => $reference]);

            return new JsonResponse(['status' => 'ok']);
        }

        if (!in_array($event, ['charge.success', 'bank.transfer.rejected'], true) ||
            !$intent->isSettleable()) {
            return new JsonResponse(['status' => 'ok', 'already' => $intent->getStatus()]);
        }

        try {
            if ($event === 'charge.success') {
                $intent->setProviderReference((string) ($data['reference'] ?? $intent->getProviderReference()));
                $intent->setChannel($data['channel'] ?? null);

                // Re-verify server-side and settle. Never trust the payload's
                // amount on its own — verify() re-checks it against the intent.
                $result = $this->gateway->verify($intent);

                if ($result->isPaid()) {
                    $this->settlement->settle($intent, $rawBody);
                    if (!$this->em->isOpen()) {
                        return new JsonResponse(['status' => 'ok']);
                    }
                } else {
                    $this->logger->warning(
                        'Webhook said success but verification did not confirm {reference}',
                        ['reference' => $reference]
                    );
                }
            } else {
                // Wrong amount / rejected transfer: the money never arrived.
                $intent->setStatus(PaymentIntent::STATUS_FAILED);
                $intent->setRawPayload($rawBody);
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            // Log and still return 200: the provider will not retry a failure we
            // can fix ourselves, and the callback path will re-verify anyway.
            $this->logger->error('Webhook handling failed for {reference}: {message}', [
                'reference' => $reference,
                'message' => $e->getMessage(),
            ]);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * The local stand-in for Paystack's checkout page — only live while no real
     * key is configured. It lets the whole journey be walked end to end without
     * moving real money, including the failure path.
     */
    #[Route('/fake-checkout/{reference}', name: 'app_payment_fake_checkout', methods: ['GET', 'POST'])]
    public function fakeCheckout(string $reference, Request $request): Response
    {
        if ($this->gateway->name() !== PaymentIntent::PROVIDER_FAKE) {
            throw $this->createNotFoundException('The simulated checkout is not available.');
        }

        $intent = $this->em->getRepository(PaymentIntent::class)->findOneBy(['reference' => $reference]);
        if ($intent === null) {
            throw $this->createNotFoundException('That payment could not be found.');
        }

        if ($request->isMethod('POST')) {
            $outcome = (string) $request->request->get('outcome');
            $intent->setProviderReference(
                $outcome === 'decline' ? FakeGateway::DECLINE_MARKER : FakeGateway::CONFIRM_MARKER
            );
            $intent->setChannel('fake');
            $this->em->flush();

            return $this->redirectToRoute('app_payment_callback', ['reference' => $intent->getReference()]);
        }

        return $this->render('tenant/payments/fake_checkout.html.twig', [
            'intent' => $intent,
            'invoice' => $intent->getInvoice(),
            'student' => $intent->getStudent(),
            'school' => $this->em->getRepository(School::class)->findOneBy([]),
        ]);
    }
}
