<?php

namespace App\Controller\Tenant;

use App\Form\SchoolSettingsType;
use App\Service\Payments\PlatformPaymentSwitch;
use App\Entity\Landlord\GlobalSetting;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry; // ✅ Already imported
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

#[Route('/settings')]
// System ▸ Settings is the school's core identity (name, motto, contacts, logo).
// The manual puts the whole System section with the Proprietor / top-level
// administrator — a principal runs operations, not the school's own identity.
#[IsGranted('ROLE_TENANT_SUPER_ADMIN')]
class SettingsController extends AbstractController
{
    #[Route('/general', name: 'app_tenant_settings_general')]
    public function general(
        Request $request, 
        EntityManagerInterface $em, 
        SluggerInterface $slugger,
        ManagerRegistry $doctrine, // ✅ Added this argument here to fix the error
        PlatformPaymentSwitch $platformSwitch,
    ): Response {
        /** @var \App\Entity\Tenant\User $user */
        $user = $this->getUser();
        
        $tenant = $user->getTenant(); 

        if (!$tenant) {
            $tenant = new \App\Entity\Tenant\School();
            $tenant->setName('My Default School');
            $tenant->setPrimaryColor('#1e40af');
            $tenant->setEmail($user->getEmail());
            
            $em->persist($tenant);
            $user->setSchool($tenant);
            $em->persist($user);
            
            $em->flush();
            $this->addFlash('success', 'A default School profile has been created for you.');
        }

        $form = $this->createForm(SchoolSettingsType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $logoFile = $form->get('logoFile')->getData();

            if ($logoFile) {
                $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename.'-'.uniqid().'.'.$logoFile->guessExtension();

                try {
                    $logoFile->move(
                        $this->getParameter('school_logos_directory'),
                        $newFilename
                    );
                    $tenant->setLogoFilename($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Could not upload logo: '.$e->getMessage());
                }
            }

            $signatureFile = $form->get('principalSignatureFile')->getData();
            if ($signatureFile) {
                $directory = $this->getParameter('kernel.project_dir').'/public/uploads/signatures';
                if (!is_dir($directory)) {
                    mkdir($directory, 0775, true);
                }

                try {
                    $newFilename = 'signature-'.uniqid().'.'.$signatureFile->guessExtension();
                    $signatureFile->move($directory, $newFilename);
                    $tenant->setPrincipalSignatureFilename($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Could not upload the signature: '.$e->getMessage());
                }
            }

            $em->flush();
            $this->addFlash('success', 'School settings updated successfully!');
            return $this->redirectToRoute('app_tenant_settings_general');
        }

        // Now $doctrine is correctly defined and usable here
        $priceSetting = $doctrine->getManager('landlord')
            ->getRepository(GlobalSetting::class)
            ->findOneBy(['settingKey' => 'sms_price']);
        
        $realPrice = $priceSetting ? $priceSetting->getSettingValue() : '15.00';

        return $this->render('tenant/settings/general.html.twig', [
            'form' => $form->createView(),
            'tenant' => $tenant,
            'smsPrice' => $realPrice,
            // So a school is not left wondering why its own "Online" setting
            // does nothing when the platform has switched collection off.
            'platformOnlinePayments' => $platformSwitch->isEnabled(),
        ]);
    }
}