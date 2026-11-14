<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\FamilyLinkNotice;
use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\Student;
use App\Entity\Tenant\User;
use App\Form\GuardianContactType;
use App\Service\Phone\PhoneNumber;
use App\Service\Students\FamilyLinker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The family behind the children: contact details, who belongs to it, and the
 * notices the parent has answered.
 *
 * This is where a phone number is corrected and where an uncle or guardian is
 * given a child that was registered under somebody else. Both change which
 * parent sees which pupil, so both are recorded as a {@see FamilyLinkNotice}
 * for the parent to confirm or dispute.
 */
#[Route('/parents')]
#[IsGranted('ROLE_BURSAR')]
final class GuardianController extends AbstractController
{
    #[Route('/notices', name: 'app_tenant_parent_notices', methods: ['GET'])]
    public function notices(Request $request, EntityManagerInterface $em): Response
    {
        $status = (string) $request->query->get('status', FamilyLinkNotice::STATUS_REPORTED);

        $counts = ['pending' => 0, 'confirmed' => 0, 'reported' => 0];
        foreach ($em->getRepository(FamilyLinkNotice::class)->createQueryBuilder('n')
            ->select('n.status AS status', 'COUNT(n.id) AS total')
            ->groupBy('n.status')
            ->getQuery()
            ->getArrayResult() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        $counts['all'] = array_sum($counts);

        $qb = $em->getRepository(FamilyLinkNotice::class)->createQueryBuilder('n')
            ->join('n.guardian', 'g')->addSelect('g')
            ->join('n.student', 's')->addSelect('s')
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(200);

        if (in_array($status, [FamilyLinkNotice::STATUS_PENDING, FamilyLinkNotice::STATUS_CONFIRMED, FamilyLinkNotice::STATUS_REPORTED], true)) {
            $qb->andWhere('n.status = :status')->setParameter('status', $status);
        }

        return $this->render('tenant/parent/notices.html.twig', [
            'notices' => $qb->getQuery()->getResult(),
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    #[Route('/{id}', name: 'app_tenant_parent_family', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function family(Guardian $guardian, Request $request, EntityManagerInterface $em): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $results = [];

        if ($q !== '') {
            $qb = $em->getRepository(Student::class)->createQueryBuilder('s')
                ->leftJoin('s.guardian', 'g')->addSelect('g')
                // A pupil already here is not a search result — they are already
                // in the list above, and offering them again would be a no-op.
                ->andWhere('s.guardian IS NULL OR s.guardian != :guardian')
                ->setParameter('guardian', $guardian)
                ->orderBy('s.lastName', 'ASC')
                ->addOrderBy('s.firstName', 'ASC')
                ->setMaxResults(50);

            $qb->andWhere('s.firstName LIKE :q OR s.middleName LIKE :q OR s.lastName LIKE :q OR s.admissionNumber LIKE :q')
                ->setParameter('q', '%' . $q . '%');

            $results = $qb->getQuery()->getResult();
        }

        return $this->render('tenant/parent/family.html.twig', [
            'guardian' => $guardian,
            'q' => $q,
            'results' => $results,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tenant_parent_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Guardian $guardian,
        Request $request,
        EntityManagerInterface $em,
        FamilyLinker $families,
    ): Response {
        $originalPhone = (string) $guardian->getPhoneNumber();
        $form = $this->createForm(GuardianContactType::class, $guardian);
        $form->handleRequest($request);

        $mergePreview = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $newPhone = PhoneNumber::national((string) $guardian->getPhoneNumber());

            if ($newPhone === '') {
                $form->get('phoneNumber')->addError(new FormError(
                    'Enter a phone number. It is how the school reaches this family and how siblings are matched.'
                ));
            } else {
                // Store one shape, always: 8039658200. The same rule the
                // importer and the login provider use.
                $guardian->setPhoneNumber($newPhone);

                $alternate = PhoneNumber::national((string) $guardian->getAlternatePhoneNumber());
                $guardian->setAlternatePhoneNumber($alternate !== '' ? $alternate : null);

                $others = $families->guardiansWithPhone($newPhone, $guardian);
                $candidates = [];

                foreach ($others as $other) {
                    $matches = $families->familyMatches($guardian, $other);

                    if ($matches['labels'] !== []) {
                        $candidates[] = ['guardian' => $other, 'matches' => $matches];
                    }
                }

                if ($others !== [] && $candidates === []) {
                    // Shared phone, no shared name. This is exactly the case the
                    // rule exists to stop: two families using one handset.
                    $form->get('phoneNumber')->addError(new FormError(
                        $families->noNameMatchMessage($guardian, $others)
                    ));
                } elseif (count($candidates) > 1) {
                    $form->get('phoneNumber')->addError(new FormError(
                        'That number is held by more than one parent record and more than one of them shares a child\'s name. '
                        . 'Nothing was changed. Use “Add a child” on the correct family so the right pupils are moved.'
                    ));
                } elseif (count($candidates) === 1) {
                    $target = $candidates[0]['guardian'];
                    $blocked = $families->mergeBlockedReason($guardian, $target);

                    if ($blocked !== null) {
                        $form->get('phoneNumber')->addError(new FormError($blocked));
                    } elseif (!$form->get('confirmMerge')->getData()) {
                        // First pass: show the evidence and ask for a tick. The
                        // change is held in memory only — nothing is flushed.
                        $mergePreview = [
                            'target' => $target,
                            'matches' => $candidates[0]['matches'],
                            'sourceChildren' => $guardian->getStudents()->toArray(),
                        ];
                    } else {
                        $targetName = $target->getFullName() ?: 'the other parent';
                        $result = $families->merge(
                            $guardian,
                            $target,
                            $this->getUser()?->getUserIdentifier()
                        );

                        $this->addFlash('success', sprintf(
                            'Merged into %s\'s family: %s moved across. The other parent record was %s and the family login was kept.',
                            $targetName,
                            $result['moved'] === [] ? 'no pupils' : implode(', ', $result['moved']),
                            $result['sourceRemoved'] ? 'removed' : 'kept (it has a bank account to preserve)'
                        ));

                        return $this->redirectToRoute('app_tenant_parent_family', ['id' => $target->getId()]);
                    }
                } else {
                    // No other record holds this number: an ordinary save.
                    $loginError = $this->emailConflict($em, $guardian)
                        ?? $families->moveLoginToPhone($guardian, $originalPhone, $newPhone);

                    if ($loginError !== null) {
                        $form->get('phoneNumber')->addError(new FormError($loginError));
                    } else {
                        $em->flush();

                        $this->addFlash('success', sprintf(
                            'Contact details saved for %s.%s',
                            $guardian->getFullName() ?: 'this parent',
                            $originalPhone !== $newPhone
                                ? ' The number changed from ' . ($originalPhone ?: 'none') . ' to ' . $newPhone . '.'
                                : ''
                        ));

                        return $this->redirectToRoute('app_tenant_parent_family', ['id' => $guardian->getId()]);
                    }
                }
            }
        }

        return $this->render('tenant/parent/edit.html.twig', [
            'form' => $form->createView(),
            'guardian' => $guardian,
            'original_phone' => $originalPhone,
            'merge_preview' => $mergePreview,
        ]);
    }

    #[Route('/{id}/children', name: 'app_tenant_parent_children_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addChildren(Guardian $guardian, Request $request, FamilyLinker $families): Response
    {
        if (!$this->isCsrfTokenValid('link_children_' . $guardian->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $ids = $request->request->all('student_ids');

        if (!is_array($ids) || $ids === []) {
            $this->addFlash('error', 'Select at least one pupil to add to this family.');

            return $this->redirectToRoute('app_tenant_parent_family', ['id' => $guardian->getId()]);
        }

        $result = $families->linkStudents($guardian, $ids, $this->getUser()?->getUserIdentifier());

        if ($result['linked'] !== []) {
            $this->addFlash('success', sprintf(
                '%s added to %s\'s family. The parent will see a notice in their portal and can confirm it or report a mistake.',
                implode(', ', $result['linked']),
                $guardian->getFullName() ?: 'this parent'
            ));
        }

        if ($result['skipped'] !== []) {
            $this->addFlash('warning', sprintf(
                '%s already belonged to this family — no change was made for %s.',
                implode(', ', $result['skipped']),
                count($result['skipped']) === 1 ? 'that pupil' : 'those pupils'
            ));
        }

        return $this->redirectToRoute('app_tenant_parent_family', ['id' => $guardian->getId()]);
    }

    /** A clear sentence instead of a raw unique-constraint 500. */
    private function emailConflict(EntityManagerInterface $em, Guardian $guardian): ?string
    {
        $email = trim((string) $guardian->getEmail());

        if ($email === '') {
            return null;
        }

        $otherGuardian = $em->getRepository(Guardian::class)->findOneBy(['email' => $email]);
        if ($otherGuardian !== null && $otherGuardian->getId() !== $guardian->getId()) {
            return sprintf(
                'That email address already belongs to %s. Use a different one, or merge the two parent records from the family page.',
                $otherGuardian->getFullName() ?: 'another parent'
            );
        }

        $otherUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($otherUser !== null && $otherUser->getId() !== $guardian->getUser()?->getId()) {
            return 'That email address is already used by another login. Use a different one.';
        }

        return null;
    }
}
