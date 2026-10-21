<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Guardian;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Student;
use App\Service\Admission\AdmissionLetterPdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admission letter — one document, two doors.
 *
 * It used to live inside {@see AdmissionController}, the administrator's admissions
 * surface. Printing a letter that has **already been issued** is not a decision, so
 * it gets its own controller with its own rules:
 *
 *   • staff (bursar or administrator) print it from the learner's record;
 *   • a parent prints **their own child's** letter at any time, for as long as the
 *     child is on the roll.
 *
 * The document is a **PDF** (App\Service\Admission\AdmissionLetterPdfRenderer), not
 * a browser print view, so the same letter leaves Chrome, Safari, Edge and an
 * Android phone with the same margins, the same embedded fonts and the same single
 * A4 page. `?format=html` renders the identical context for on-screen proof;
 * `?download=1` forces a download instead of an inline view.
 */
final class AdmissionLetterController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/admission/student/{id}/print-letter', name: 'app_tenant_admission_print', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('ROLE_BURSAR') or is_granted('ROLE_ADMIN')"))]
    public function staff(Student $student, Request $request, AdmissionLetterPdfRenderer $renderer): Response
    {
        return $this->serve($student, $request, $renderer);
    }

    #[Route('/parent/student/{id}/admission-letter', name: 'app_parent_admission_letter', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_PARENT')]
    public function parent(Student $student, Request $request, AdmissionLetterPdfRenderer $renderer): Response
    {
        $guardian = $this->em->getRepository(Guardian::class)->findOneBy(['user' => $this->getUser()]);

        if (!$guardian || !$guardian->getStudents()->contains($student)) {
            throw $this->createAccessDeniedException('This admission letter belongs to another family.');
        }

        return $this->serve($student, $request, $renderer);
    }

    private function serve(Student $student, Request $request, AdmissionLetterPdfRenderer $renderer): Response
    {
        $school = $this->em->getRepository(School::class)->findOneBy([]);

        if (!$school instanceof School) {
            throw $this->createNotFoundException('This school has not been configured yet.');
        }

        $context = $renderer->context(
            $student,
            $school,
            (string) ($request->attributes->get('subdomain') ?: explode('.', $request->getHost())[0])
        );

        // Screen proof, same context as the PDF — never a second document.
        if ($request->query->get('format') === 'html') {
            return $this->render('tenant/admission/letter.pdf.twig', $context);
        }

        $pdf = $renderer->render($context);

        $slug = preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $context['reference']) ?? 'admission-letter';
        $filename = sprintf(
            'admission-letter-%s-%s.pdf',
            trim($slug, '-'),
            (new \DateTimeImmutable())->format('Y-m-d')
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($pdf),
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $request->query->getBoolean('download') ? 'attachment' : 'inline',
                $filename
            ),
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ]);
    }
}
