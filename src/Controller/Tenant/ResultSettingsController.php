<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\AssessmentScheme;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Term;
use App\Form\AssessmentSchemeType;
use App\Service\Results\AssessmentSchemeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's marking scheme: how many CAs, each out of what, and what the
 * examination carries.
 *
 * These maxima were hard-coded in `AssessmentSchemeResolver` (CA out of 20, exam
 * out of 60, practical out of 40) and printed in the settings help text, so a
 * school that marks CA out of 10 or runs a 70-mark exam could not be served. The
 * `assessment_scheme` table already existed to hold the answer — nothing ever
 * wrote to it. This screen does.
 *
 * One save writes the **school-wide** row (no classroom, no subject) for every
 * term of the active session, which is the third tier of the resolver's chain:
 * subject-specific → class-wide → school-wide. A class or subject that needs its
 * own split keeps it, and the screen says how many such overrides exist so the
 * principal is never surprised by a term that ignores what they just typed.
 */
#[Route('/results/settings')]
#[IsGranted('ROLE_ADMIN')]
class ResultSettingsController extends AbstractController
{
    #[Route('', name: 'app_tenant_result_settings', methods: ['GET', 'POST'])]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        AssessmentSchemeResolver $resolver,
    ): Response {
        $session = $em->getRepository(Session::class)->findOneBy(['isActive' => true]);
        $terms = $session instanceof Session
            ? $em->getRepository(Term::class)->findBy(['session' => $session], ['id' => 'ASC'])
            : [];

        $repo = $em->getRepository(AssessmentScheme::class);

        // Whatever the school is using today: its own row if one exists, otherwise
        // the built-in fallback, so the first visit is filled in rather than blank.
        $existing = null;
        foreach ($terms as $term) {
            $existing = $repo->findOneBy(['term' => $term, 'classroom' => null, 'subject' => null]);
            if ($existing instanceof AssessmentScheme) {
                break;
            }
        }

        $scheme = $existing instanceof AssessmentScheme
            ? [
                'caCount' => in_array($existing->getCaCount(), [AssessmentScheme::CA_COUNT_TWO, AssessmentScheme::CA_COUNT_THREE], true)
                    ? (int) $existing->getCaCount()
                    : $resolver->defaultCaCount(),
                'caMax' => $existing->getCaMax() ?? 20,
                'examMode' => $existing->getExamMode() ?? AssessmentScheme::EXAM_MODE_SINGLE,
                'theoryMax' => $existing->getTheoryMax() ?? 60,
                'practicalMax' => $existing->getPracticalMax() ?? 40,
            ]
            : [
                'caCount' => $resolver->defaultCaCount(),
                'caMax' => 20,
                'examMode' => AssessmentScheme::EXAM_MODE_SINGLE,
                'theoryMax' => 60,
                'practicalMax' => 40,
            ];

        $form = $this->createForm(AssessmentSchemeType::class, $scheme);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{caCount: int, caMax: int, examMode: string, theoryMax: int, practicalMax: int} $values */
            $values = $form->getData();

            foreach ($terms as $term) {
                $row = $repo->findOneBy(['term' => $term, 'classroom' => null, 'subject' => null])
                    ?? (new AssessmentScheme())->setSession($session)->setTerm($term);

                $row->setCaCount((int) $values['caCount'])
                    ->setCaMax((int) $values['caMax'])
                    ->setExamMode((string) $values['examMode'])
                    ->setTheoryMax((int) $values['theoryMax'])
                    ->setPracticalMax((int) ($values['practicalMax'] ?? 0));

                $em->persist($row);
            }

            // The general setting is what a blank score sheet and any un-configured
            // class fall back to; leaving them disagreeing is how two screens end up
            // printing different columns for the same subject.
            $school = $em->getRepository(School::class)->findOneBy([]);
            if ($school instanceof School) {
                $school->setCaCount((int) $values['caCount']);
            }

            $em->flush();

            $this->addFlash('success', 'Marking scheme saved for this session.');
            return $this->redirectToRoute('app_tenant_result_settings');
        }

        // Class- or subject-specific rows win over the school-wide one; say how many
        // exist rather than pretending the save applied to everything.
        $overrides = 0;
        if ($session instanceof Session) {
            $overrides = (int) $repo->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->andWhere('a.session = :session')
                ->andWhere('a.classroom IS NOT NULL OR a.subject IS NOT NULL')
                ->setParameter('session', $session)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $this->render('tenant/results/settings.html.twig', [
            'form' => $form->createView(),
            'session' => $session,
            'terms' => $terms,
            'overrides' => $overrides,
            'saved' => $existing instanceof AssessmentScheme,
        ]);
    }
}
