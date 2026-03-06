<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\GlobalSetting;
use App\Form\Landlord\GlobalSettingType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings', name: 'landlord_settings_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class GlobalSettingsController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        $settings = $doctrine
            ->getManager('landlord')
            ->getRepository(GlobalSetting::class)
            ->findAll();

        return $this->render('landlord/settings/index.html.twig', [
            'settings' => $settings,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, GlobalSetting $setting, ManagerRegistry $doctrine): Response
    {
        $form = $this->createForm(GlobalSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager('landlord')->flush();
            $this->addFlash('success', 'Global setting updated.');

            return $this->redirectToRoute('landlord_settings_index');
        }

        return $this->render('landlord/settings/edit.html.twig', [
            'setting' => $setting,
            'form' => $form,
        ]);
    }
}