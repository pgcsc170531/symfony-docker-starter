<?php

namespace App\Service\Subscription;

use App\Entity\Landlord\RegistrationInvite;
use App\Entity\Landlord\School;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Creates and resolves the one-click "register your term" invitations.
 *
 * The clear token is returned ONCE (to be put in the email); only its hash is
 * stored. Resolution is a hash lookup, so a stolen database row cannot be turned
 * back into a working link.
 */
final class RegistrationInviteService
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * @return array{invite: RegistrationInvite, token: string}
     */
    public function create(School $school, ?string $createdBy = null): array
    {
        $token = bin2hex(random_bytes(32));

        $invite = (new RegistrationInvite())
            ->setSchool($school)
            ->setTokenHash(RegistrationInvite::hash($token))
            ->setCreatedBy($createdBy);

        $em = $this->doctrine->getManager('landlord');
        $em->persist($invite);
        $em->flush();

        return ['invite' => $invite, 'token' => $token];
    }

    public function findValid(string $token): ?RegistrationInvite
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $invite = $this->doctrine->getManager('landlord')
            ->getRepository(RegistrationInvite::class)
            ->findOneBy(['tokenHash' => RegistrationInvite::hash($token)]);

        return $invite !== null && $invite->isValid() ? $invite : null;
    }

    public function markUsed(RegistrationInvite $invite): void
    {
        $invite->setUsedAt(new \DateTimeImmutable());
        $this->doctrine->getManager('landlord')->flush();
    }
}
