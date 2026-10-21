<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\SchoolDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's own documents — rules and regulations, the fees policy, anything
 * a parent is expected to have read.
 *
 * One place to write it, three places it appears: this screen edits it, the
 * school's website publishes it at `/p/{slug}`, and the admission letter appends
 * it as an appendix. That is why the text lives here instead of inside the letter
 * template: a school changes its rules once a year, not once per applicant.
 */
#[Route('/settings/documents')]
#[IsGranted('ROLE_ADMIN')]
final class SchoolDocumentController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'app_tenant_document_index', methods: ['GET'])]
    public function index(): Response
    {
        $documents = $this->em->getRepository(SchoolDocument::class)
            ->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->render('tenant/settings/documents/index.html.twig', [
            'documents' => $documents,
        ]);
    }

    #[Route('/new', name: 'app_tenant_document_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $document = (new SchoolDocument())
            ->setCategory(SchoolDocument::CATEGORY_RULES);

        return $this->handle($request, $document, true);
    }

    #[Route('/{id}/edit', name: 'app_tenant_document_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, SchoolDocument $document): Response
    {
        return $this->handle($request, $document, false);
    }

    #[Route('/{id}/delete', name: 'app_tenant_document_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, SchoolDocument $document): Response
    {
        if (!$this->isCsrfTokenValid('delete_document_' . $document->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        $this->em->remove($document);
        $this->em->flush();

        $this->addFlash('success', 'Document deleted.');

        return $this->redirectToRoute('app_tenant_document_index');
    }

    private function handle(Request $request, SchoolDocument $document, bool $isNew): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('save_document', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid security token.');
            }

            $title = trim((string) $request->request->get('title'));
            $body = trim((string) $request->request->get('body'));

            if ($title === '' || $body === '') {
                $this->addFlash('error', 'A title and the document text are both required.');
            } else {
                $document
                    ->setTitle($title)
                    ->setBody($body)
                    ->setCategory((string) $request->request->get('category', SchoolDocument::CATEGORY_RULES))
                    ->setIsPublished($request->request->getBoolean('isPublished'))
                    ->setAttachToAdmissionLetter($request->request->getBoolean('attachToAdmissionLetter'))
                    ->setSortOrder((int) $request->request->get('sortOrder', 0))
                    ->touch();

                // The slug is what the public page and the QR/citation links use,
                // so it is generated once and then left alone.
                if ($document->getSlug() === null || $document->getSlug() === '') {
                    $document->setSlug($this->uniqueSlug(SchoolDocument::slugify($title), $document));
                }

                if ($isNew) {
                    $this->em->persist($document);
                }

                $this->em->flush();
                $this->addFlash('success', 'Document saved.');

                return $this->redirectToRoute('app_tenant_document_index');
            }
        }

        return $this->render('tenant/settings/documents/form.html.twig', [
            'document' => $document,
            'categories' => SchoolDocument::CATEGORIES,
            'isNew' => $isNew,
        ]);
    }

    private function uniqueSlug(string $base, SchoolDocument $document): string
    {
        $repository = $this->em->getRepository(SchoolDocument::class);
        $slug = $base;
        $suffix = 2;

        while (($existing = $repository->findOneBy(['slug' => $slug])) !== null && $existing->getId() !== $document->getId()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
