<?php

namespace App\Controller\Landlord;

use App\Service\Settings\PlatformSettings;
use App\Service\Settings\SecretCipher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paystack and email settings, editable without a deploy.
 *
 * Both screens follow the same rules, and they are the reason this exists:
 *  - a secret is **write-only** — the screen shows `sk_live_…3f9c`, never the value,
 *    so it cannot be read out of the page source, the DOM or a screenshot;
 *  - clearing and replacing are separate actions, so a mistyped paste can be undone
 *    without hunting for the old key in an email;
 *  - "is this live?" is derived from the key itself, not from a checkbox;
 *  - every change is recorded (who, when) without recording the value.
 */
#[Route('/admin/settings')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class PlatformSettingsController extends AbstractController
{
    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly SecretCipher $cipher,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app.base_domain%')]
        private readonly string $baseDomain = 'edus.ng',
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default:public_domain_fallback:PUBLIC_BASE_DOMAIN)%')]
        private readonly string $publicDomain = 'edus.ng',
    ) {
    }

    // ── Payments ─────────────────────────────────────────────────────────

    #[Route('/payments', name: 'landlord_settings_payments', methods: ['GET', 'POST'])]
    public function payments(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_payments', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Reload the page and try again.');

                return $this->redirectToRoute('landlord_settings_payments');
            }

            $action = (string) $request->request->get('action');

            try {
                if ($action === 'clear_secret') {
                    $this->settings->clear(PlatformSettings::PAYSTACK_SECRET, $this->actor());
                    $this->addFlash('success', 'Paystack secret key cleared. Online payment falls back to the environment, then to transfers only.');
                } elseif ($action === 'clear_public') {
                    $this->settings->clear(PlatformSettings::PAYSTACK_PUBLIC, $this->actor());
                    $this->addFlash('success', 'Paystack public key cleared.');
                } else {
                    $secret = trim((string) $request->request->get('paystack_secret', ''));
                    $public = trim((string) $request->request->get('paystack_public', ''));

                    if ($secret !== '') {
                        $this->settings->set(PlatformSettings::PAYSTACK_SECRET, $secret, $this->actor());
                    }
                    if ($public !== '') {
                        $this->settings->set(PlatformSettings::PAYSTACK_PUBLIC, $public, $this->actor());
                    }

                    $this->addFlash('success', $secret !== '' || $public !== ''
                        ? 'Saved. The next payment uses it — no restart needed.'
                        : 'Nothing to save.');
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('landlord_settings_payments');
        }

        return $this->render('landlord/settings/payments.html.twig', [
            'mode' => $this->settings->paystackMode(),
            'secretHint' => $this->settings->hint(PlatformSettings::PAYSTACK_SECRET),
            'publicHint' => $this->settings->hint(PlatformSettings::PAYSTACK_PUBLIC),
            'secretSource' => $this->settings->source(PlatformSettings::PAYSTACK_SECRET),
            'publicSource' => $this->settings->source(PlatformSettings::PAYSTACK_PUBLIC),
            'rotatedAt' => $this->settings->rotatedAt(PlatformSettings::PAYSTACK_SECRET),
            'canEncrypt' => $this->canEncrypt(),
            // The URL that goes into the Paystack dashboard is the LIVE one, never
            // the host this box happens to be served on: pasting `<school>.localhost`
            // there would simply never reach the server.
            'webhookUrl' => sprintf(
                'https://<school>.%s%s',
                $this->publicDomain !== '' ? $this->publicDomain : $this->baseDomain,
                $this->generateUrl('app_payment_webhook_paystack')
            ),
            'webhookLocalNote' => $this->publicDomain !== $this->baseDomain
                ? sprintf('<school>.%s (this machine)', $this->baseDomain)
                : null,
            'bank' => $this->settings->bankDetails(),
        ]);
    }

    /** Proves the saved key works, before anybody closes the ticket. */
    #[Route('/payments/check', name: 'landlord_settings_payments_check', methods: ['POST'])]
    public function checkKey(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('platform_payments_check', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Reload the page and try again.');

            return $this->redirectToRoute('landlord_settings_payments');
        }

        $key = $this->settings->paystackSecretKey();
        if ($key === null) {
            $this->addFlash('error', 'No Paystack key is set, so there is nothing to check.');

            return $this->redirectToRoute('landlord_settings_payments');
        }

        try {
            $response = HttpClient::create()->request('GET', 'https://api.paystack.co/balance', [
                'headers' => ['Authorization' => 'Bearer ' . $key],
                'timeout' => 15,
            ]);
            $payload = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Could not reach Paystack: ' . $e->getMessage());

            return $this->redirectToRoute('landlord_settings_payments');
        }

        if (($payload['status'] ?? false) !== true) {
            $this->addFlash('error', 'Paystack refused the key: ' . ($payload['message'] ?? 'no reason given'));

            return $this->redirectToRoute('landlord_settings_payments');
        }

        $currency = $payload['data'][0]['currency'] ?? '';
        $balance = $payload['data'][0]['balance'] ?? 0;

        $this->addFlash('success', sprintf(
            'The key works. Paystack balance: %s %s.',
            number_format(((int) $balance) / 100, 2),
            $currency
        ));

        return $this->redirectToRoute('landlord_settings_payments');
    }

    // ── Email ────────────────────────────────────────────────────────────

    #[Route('/email', name: 'landlord_settings_email', methods: ['GET', 'POST'])]
    public function email(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('platform_email', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'That form expired. Reload the page and try again.');

                return $this->redirectToRoute('landlord_settings_email');
            }

            $action = (string) $request->request->get('action');

            try {
                if ($action === 'clear_password') {
                    $this->settings->clear(PlatformSettings::MAIL_PASSWORD, $this->actor());
                    $this->addFlash('success', 'SMTP password cleared — the environment value, if any, is used instead.');
                } else {
                    foreach ([
                        PlatformSettings::MAIL_HOST => 'mail_host',
                        PlatformSettings::MAIL_PORT => 'mail_port',
                        PlatformSettings::MAIL_USERNAME => 'mail_username',
                        PlatformSettings::MAIL_PASSWORD => 'mail_password',
                        PlatformSettings::MAIL_FROM_NAME => 'mail_from_name',
                        PlatformSettings::MAIL_FROM_EMAIL => 'mail_from_email',
                        PlatformSettings::MAIL_REPLY_TO => 'mail_reply_to',
                    ] as $key => $field) {
                        $value = trim((string) $request->request->get($field, ''));
                        // An empty password field means "keep the current one", not
                        // "delete it" — otherwise every save would wipe the secret.
                        if ($key === PlatformSettings::MAIL_PASSWORD && $value === '') {
                            continue;
                        }
                        $this->settings->set($key, $value, $this->actor());
                    }

                    $this->addFlash('success', 'Email settings saved.');
                }
            } catch (\Throwable $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('landlord_settings_email');
        }

        $identity = $this->settings->mailIdentity();

        return $this->render('landlord/settings/email.html.twig', [
            'host' => $this->settings->get(PlatformSettings::MAIL_HOST, ''),
            'port' => $this->settings->get(PlatformSettings::MAIL_PORT, ''),
            'username' => $this->settings->get(PlatformSettings::MAIL_USERNAME, ''),
            'passwordHint' => $this->settings->hint(PlatformSettings::MAIL_PASSWORD),
            'passwordSource' => $this->settings->source(PlatformSettings::MAIL_PASSWORD),
            'fromName' => $identity['fromName'],
            'fromEmail' => $identity['fromEmail'],
            'replyTo' => $identity['replyTo'],
            'hostSource' => $this->settings->source(PlatformSettings::MAIL_HOST),
            'canEncrypt' => $this->canEncrypt(),
            'rotatedAt' => $this->settings->rotatedAt(PlatformSettings::MAIL_PASSWORD),
        ]);
    }

    /** Sends one real email through the **saved** settings, to the signed-in admin. */
    #[Route('/email/test', name: 'landlord_settings_email_test', methods: ['POST'])]
    public function testEmail(Request $request, MailerInterface $mailer): Response
    {
        if (!$this->isCsrfTokenValid('platform_email_test', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Reload the page and try again.');

            return $this->redirectToRoute('landlord_settings_email');
        }

        $to = (string) $this->getUser()?->getUserIdentifier();
        if ($to === '') {
            $this->addFlash('error', 'Your account has no email address.');

            return $this->redirectToRoute('landlord_settings_email');
        }

        try {
            $mailer->send((new Email())
                ->to($to)
                ->subject('Edus — test email')
                ->text("This is a test from Admin → Settings → Email.\n\nIf you are reading it, the saved SMTP settings work."));
            $this->addFlash('success', sprintf('Test email sent to %s. Check that inbox — and the spam folder.', $to));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Sending failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('landlord_settings_email');
    }

    private function actor(): string
    {
        return (string) ($this->getUser()?->getUserIdentifier() ?? 'landlord');
    }

    /**
     * Whether a secret can be stored at all.
     *
     * False when `APP_ENCRYPTION_KEY` is missing or malformed: the screens then
     * refuse the secret and explain how to generate the key, rather than storing a
     * Paystack key in plain text because the environment was not finished.
     */
    private function canEncrypt(): bool
    {
        return $this->cipher->canEncrypt();
    }
}
