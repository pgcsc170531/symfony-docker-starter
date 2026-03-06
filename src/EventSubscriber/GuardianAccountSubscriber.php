<?php

namespace App\EventSubscriber;

use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use Doctrine\Bundle\DoctrineBundle\EventSubscriber\EventSubscriberInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class GuardianAccountSubscriber implements EventSubscriberInterface
{
    private $hasher;

    public function __construct(UserPasswordHasherInterface $hasher)
    {
        $this->hasher = $hasher;
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        // 1. Only listen to Student entities
        if (!$entity instanceof Student) {
            return;
        }

        // 2. Get the Guardian Relationship
        // (This was the missing link in your previous code)
        $guardian = $entity->getGuardian();

        // 3. Safety Check: Stop if no guardian or no email
        if (!$guardian || !$guardian->getEmail()) {
            return;
        }

        $em = $args->getObjectManager();
        $userRepo = $em->getRepository(User::class);

        // 4. Check if Account Already Exists
        if ($userRepo->findOneBy(['email' => $guardian->getEmail()])) {
            return;
        }

        // 5. Auto-create Parent Account
        $user = new User();
        $user->setEmail($guardian->getEmail());
        
        // Handle Name: Try to get Full Name, or fallback
        $name = 'Parent';
        if (method_exists($guardian, 'getFullName')) {
            $name = $guardian->getFullName();
        } elseif (method_exists($guardian, 'getFirstName')) {
            $name = $guardian->getFirstName() . ' ' . $guardian->getLastName();
        }
        $user->setFullName($name);
        
        $user->setRoles(['ROLE_PARENT']);
        
        // 6. Handle Password (Phone or default)
        $rawPassword = '123456';
        if (method_exists($guardian, 'getPhone')) {
            $rawPassword = $guardian->getPhone() ?? '123456';
        } elseif (method_exists($guardian, 'getPhoneNumber')) {
            $rawPassword = $guardian->getPhoneNumber() ?? '123456';
        }

        $user->setPassword($this->hasher->hashPassword($user, $rawPassword));

        // 7. Save the new User
        $em->persist($user);
        $em->flush(); 
    }
}