<?php

namespace App\Service\Mail;

use App\Service\Settings\PlatformSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that reads its DSN from the settings screen **on every send**.
 *
 * Without this, `MAILER_DSN` is fixed when the container is built, so changing the
 * SMTP password means a deploy. With `MAILER_DSN=platform://default`, the framework
 * still resolves one transport — this one — and the actual server is chosen at send
 * time from {@see PlatformSettings} (settings screen → env → Mailpit).
 *
 * The underlying transport is cached per DSN, so a hundred receipts in a row open
 * one connection, and rotating the password simply produces a new DSN and a new
 * transport on the next message.
 */
final class PlatformMailerTransport implements TransportInterface
{
    /** @var array<string, TransportInterface> */
    private array $transports = [];

    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $dsn = $this->settings->mailDsn();

        // A mailer that does not set From would otherwise fall back to the env
        // value, and the From the operator typed on the settings screen would be
        // ignored. Set it here, once, for every message that needs it.
        if ($message instanceof Message) {
            $identity = $this->settings->mailIdentity();
            if ($message->getFrom() === []) {
                $message->from($identity['fromEmail'], $identity['fromName']);
            }
            if ($message->getReplyTo() === [] && $identity['replyTo'] !== '') {
                $message->replyTo($identity['replyTo']);
            }
        }

        try {
            $transport = $this->transports[$dsn] ??= \Symfony\Component\Mailer\Transport::fromDsn($dsn);
        } catch (\Throwable $e) {
            $this->logger->error('Could not build the mail transport from the configured DSN: {error}', [
                'error' => $e->getMessage(),
            ]);

            throw new TransportException('Mail is not configured correctly. Ask an administrator to check Settings → Email.', 0, $e);
        }

        $sent = $transport->send($message, $envelope);

        // Never log the DSN itself — it carries the password.
        $this->logger->info('Mail sent via {scheme} transport.', [
            'scheme' => strstr($dsn, '://', true) ?: 'unknown',
        ]);

        return $sent;
    }

    public function __toString(): string
    {
        return 'platform://(settings)';
    }
}
