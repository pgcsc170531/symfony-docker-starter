<?php

namespace App\Controller\Tenant;

use App\Entity\Landlord\School as LandlordSchool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DebugWalletController extends AbstractController
{
    #[Route('/debug-fetch', name: 'debug_fetch')]
    public function index(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        EntityManagerInterface $landlordEm
    ): Response
    {
        // 1. Who am I? (From Tenant Session)
        $tenantUser = $this->getUser();
        $tenantSchool = $tenantUser->getSchool();
        $myId = $tenantSchool->getId();

        // 2. What does Doctrine find?
        $schoolRepo = $landlordEm->getRepository(LandlordSchool::class);
        $entityFound = $schoolRepo->find($myId);

        // 3. What does Raw SQL find? (Bypassing Doctrine)
        $conn = $landlordEm->getConnection();
        $sql = 'SELECT id, name, wallet_balance FROM school WHERE id = :id';
        $rawFound = $conn->executeQuery($sql, ['id' => $myId])->fetchAssociative();

        // 4. Check the FIRST row in the table (To test your theory)
        $firstRow = $conn->executeQuery('SELECT id, name, wallet_balance FROM school LIMIT 1')->fetchAssociative();

        dd([
            '🕵️ WHO AM I?' => [
                'Tenant School ID' => $myId,
                'Tenant School Name' => $tenantSchool->getName(),
            ],
            '🐘 DOCTRINE FETCH' => [
                'Did it find a match?' => $entityFound ? 'YES' : 'NO',
                'ID Found' => $entityFound ? $entityFound->getId() : 'N/A',
                'Wallet Balance' => $entityFound ? $entityFound->getWalletBalance() : 'N/A',
            ],
            '💾 RAW SQL FETCH (The Truth)' => [
                'Did it find a row?' => $rawFound ? 'YES' : 'NO',
                'ID Found' => $rawFound['id'] ?? 'N/A',
                'Wallet Balance' => $rawFound['wallet_balance'] ?? 'N/A',
            ],
            '🥇 THE "FIRST ROW" THEORY' => [
                'ID of First Row' => $firstRow['id'],
                'Wallet of First Row' => $firstRow['wallet_balance'],
                'Is this me?' => ($firstRow['id'] == $myId) ? 'YES' : 'NO',
            ]
        ]);
    }
}