<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\School;
use App\Entity\Tenant\SchoolDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A published school document, on the school's own website.
 *
 * This is the page an admission letter points at when it says "the rules and
 * regulations" — public, quotable and always the current version, instead of a
 * photocopy stapled to a letter three years ago.
 */
#[Route('/p')]
final class PublicDocumentController extends AbstractController
{
    #[Route('/{slug}', name: 'app_tenant_public_document', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(string $slug, EntityManagerInterface $em): Response
    {
        $document = $em->getRepository(SchoolDocument::class)->findOneBy([
            'slug' => $slug,
            'isPublished' => true,
        ]);

        if ($document === null) {
            throw $this->createNotFoundException('That document is not published.');
        }

        return $this->render('tenant/home/document.html.twig', [
            'school' => $em->getRepository(School::class)->findOneBy([]),
            'document' => $document,
            'documents' => $em->getRepository(SchoolDocument::class)->findBy(
                ['isPublished' => true],
                ['sortOrder' => 'ASC', 'id' => 'ASC']
            ),
        ]);
    }
}
