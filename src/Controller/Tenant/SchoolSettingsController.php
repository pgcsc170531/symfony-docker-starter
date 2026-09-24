<?php


namespace App\Controller\Tenant;

use App\Entity\Tenant\School;
use App\Form\Tenant\SchoolWebsiteType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SchoolSettingsController extends AbstractController
{
    #[Route('/dashboard/settings', name: 'app_tenant_school_settings', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $school = $em->getRepository(School::class)->findOneBy([]);

        if (!$school) {
            $school = new School();
            $school->setName('My School');
            $em->persist($school);
            $em->flush();
        }

        $form = $this->createForm(SchoolWebsiteType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $projectDir = $this->getParameter('kernel.project_dir');

            /** @var UploadedFile|null $logoFile */
            $logoFile = $form->get('logoFile')->getData();
            if ($logoFile) {
                $directory = $projectDir . '/public/uploads/logos';
                $this->ensureDirectory($directory);
                $filename = 'logo-' . uniqid() . '.' . $logoFile->guessExtension();
                $logoFile->move($directory, $filename);
                $school->setLogoFilename($filename);
            }

            /** @var UploadedFile|null $heroFile */
            $heroFile = $form->get('heroImageFile')->getData();
            if ($heroFile) {
                $directory = $projectDir . '/public/uploads/hero';
                $this->ensureDirectory($directory);
                $filename = 'hero-' . uniqid() . '.' . $heroFile->guessExtension();
                $heroFile->move($directory, $filename);
                $school->setHeroImageFilename($filename);
            }

            $em->flush();
            $this->addFlash('success', 'School website settings saved successfully.');

            return $this->redirectToRoute('app_tenant_school_settings');
        }

        return $this->render('tenant/dashboard/settings.html.twig', [
            'school' => $school,
            'form' => $form->createView(),
        ]);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}