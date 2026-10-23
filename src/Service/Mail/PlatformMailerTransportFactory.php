<?php

namespace App\Service\Mail;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Registers the `platform://` scheme with Symfony Mailer.
 *
 * `MAILER_DSN=platform://default` then means "ask the settings screen which server
 * to use", instead of baking a host and password into the built container. Symfony
 * asks this factory once; {@see PlatformMailerTransport} does the real work per
 * message.
 */
final class PlatformMailerTransportFactory implements TransportFactoryInterface
{
    public function __construct(private readonly PlatformMailerTransport $transport)
    {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        return $this->transport;
    }

    public function supports(Dsn $dsn): bool
    {
        return $dsn->getScheme() === 'platform';
    }
}
