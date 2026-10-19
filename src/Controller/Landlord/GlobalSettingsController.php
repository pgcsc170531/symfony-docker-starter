<?php

namespace App\Controller\Landlord;

use App\Entity\Landlord\GlobalSetting;
use App\Form\Landlord\GlobalSettingType;
use App\Service\Payments\PlatformPaymentSwitch;
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
    public function __construct(private readonly PlatformPaymentSwitch $switch)
    {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(ManagerRegistry $doctrine): Response
    {
        $settings = $doctrine
            ->getManager('landlord')
            ->getRepository(GlobalSetting::class)
            ->findAll();

        return $this->render('landlord/settings/index.html.twig', [
            'settings' => $settings,
            'onlinePaymentsEnabled' => $this->switch->isEnabled(),
        ]);
    }

    /**
     * One switch for the whole platform.
     *
     * A school chooses offline / online / both for itself; this is the layer
     * above it, so an operator can stop online collection everywhere at once
     * without touching 200 tenant databases.
     */
    #[Route('/online-payments', name: 'online_payments', methods: ['POST'])]
    public function onlinePayments(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('platform_online_payments', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That form expired. Try again.');
            return $this->redirectToRoute('landlord_settings_index');
        }

        if ($request->request->getBoolean('enabled')) {
            $this->switch->enable();
            $this->addFlash('success', 'Online payments are enabled for every school that has opted in.');
        } else {
            $this->switch->disable();
            $this->addFlash('warning', 'Online payments are off platform-wide. Schools keep their own setting and get it back when you switch it on.');
        }

        return $this->redirectToRoute('landlord_settings_index');
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