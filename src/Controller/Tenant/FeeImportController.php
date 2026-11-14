<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\FeeImportBatch;
use App\Entity\Tenant\Term;
use App\Form\FeeImportUploadType;
use App\Service\Finance\FeeImportService;
use App\Service\Subjects\PupilSubjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The bursary's opening-balance import.
 *
 * Upload → preview → commit is deliberately three steps. The preview is the
 * control that makes a 1,200-row file safe: it shows the bill, the money already
 * collected and the outstanding figure before a single `Payment` exists.
 */
#[Route('/finance/fees/import')]
#[IsGranted('ROLE_BURSAR')]
final class FeeImportController extends AbstractController
{
    public function __construct(
        private readonly FeeImportService $imports,
        private readonly PupilSubjects $pupils,
    ) {
    }

    #[Route('', name: 'app_tenant_fee_import_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $activeTerm = $em->getRepository(Term::class)->findOneBy(['isActive' => true]);
        $form = $this->createForm(FeeImportUploadType::class, ['term' => $activeTerm]);
        $form->handleRequest($request);

        $csvErrors = [];

        if ($form->isSubmitted() && $form->isValid()) {
            set_time_limit(0);
            ini_set('memory_limit', '512M');

            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $form->get('csvFile')->getData();
            /** @var Term $term */
            $term = $form->get('term')->getData();

            $parsed = $this->imports->parse($file->getPathname(), $term);
            $csvErrors = $parsed['errors'];

            if ($csvErrors === [] && $parsed['rows'] !== []) {
                $preview = $this->imports->preview($parsed['rows'], $term);
                $preview['repaired'] = $parsed['repaired'];

                $created = $this->imports->createBatch(
                    $term,
                    hash_file('sha256', $file->getPathname()),
                    $file->getClientOriginalName(),
                    $this->getUser()?->getUserIdentifier() ?? 'system',
                    $preview
                );

                if ($preview['error_count'] > 0) {
                    $this->addFlash('warning', sprintf(
                        '%d row%s could not be matched and will be skipped. The preview names every one.',
                        $preview['error_count'],
                        $preview['error_count'] === 1 ? '' : 's'
                    ));
                }

                if ($parsed['repaired'] > 0) {
                    $this->addFlash('warning', sprintf(
                        '%d value%s were not saved as UTF-8 by Excel and had to be repaired. Save as “CSV UTF-8” next time.',
                        $parsed['repaired'],
                        $parsed['repaired'] === 1 ? '' : 's'
                    ));
                }

                if ($created['already_posted']) {
                    $this->addFlash('warning', 'This file was already posted for this term. Open the batch below; reverse it before importing it again.');
                } elseif ($created['reused']) {
                    $this->addFlash('info', 'This exact file was already previewed for this term. The preview has been refreshed — nothing has been posted.');
                }

                return $this->redirectToRoute('app_tenant_fee_import_batch', [
                    'batch' => $created['batch']->getId(),
                ]);
            }

            if ($csvErrors === []) {
                $csvErrors = ['The file has no data rows.'];
            }
        }

        return $this->render('tenant/fee/import/index.html.twig', [
            'form' => $form->createView(),
            'csv_errors' => $csvErrors,
            'classrooms' => $em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']),
            'active_term' => $activeTerm,
            'recent_batches' => $em->getRepository(FeeImportBatch::class)->findBy([], ['id' => 'DESC'], 10),
        ]);
    }

    #[Route('/{batch}', name: 'app_tenant_fee_import_batch', requirements: ['batch' => '\d+'], methods: ['GET'])]
    public function batch(FeeImportBatch $batch): Response
    {
        $rows = $batch->getPayload();
        $errors = 0;
        $warnings = 0;
        $valid = 0;

        foreach ($rows as $row) {
            if (($row['errors'] ?? []) !== []) {
                ++$errors;
            } else {
                ++$valid;
            }

            if (($row['warnings'] ?? []) !== []) {
                ++$warnings;
            }
        }

        return $this->render('tenant/fee/import/batch.html.twig', [
            'batch' => $batch,
            'rows' => $rows,
            'valid_count' => $valid,
            'error_count' => $errors,
            'warning_count' => $warnings,
            'other_posted_batch' => $batch->isPreview()
                ? $this->imports->postedBatchForTerm($batch->getTerm(), $batch)
                : null,
        ]);
    }

    #[Route('/{batch}/commit', name: 'app_tenant_fee_import_commit', requirements: ['batch' => '\d+'], methods: ['POST'])]
    public function commit(FeeImportBatch $batch, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('commit_fee_import_' . $batch->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        try {
            $result = $this->imports->commit(
                $batch,
                $this->getUser()?->getUserIdentifier() ?? 'system'
            );
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Nothing was posted: ' . $e->getMessage());

            return $this->redirectToRoute('app_tenant_fee_import_batch', ['batch' => $batch->getId()]);
        }

        if ($result['error'] !== null) {
            $this->addFlash('error', $result['error']);
        } else {
            $this->addFlash('success', sprintf(
                'Posted %d row%s. ₦%s due, ₦%s already collected, ₦%s outstanding.%s',
                $result['posted'],
                $result['posted'] === 1 ? '' : 's',
                number_format($result['total_due'], 2),
                number_format($result['total_paid'], 2),
                number_format($result['outstanding'], 2),
                $result['skipped'] > 0 ? sprintf(' %d row%s skipped.', $result['skipped'], $result['skipped'] === 1 ? '' : 's') : ''
            ));
        }

        return $this->redirectToRoute('app_tenant_fee_import_batch', ['batch' => $batch->getId()]);
    }

    #[Route('/{batch}/reverse', name: 'app_tenant_fee_import_reverse', requirements: ['batch' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TENANT_SUPER_ADMIN')]
    public function reverse(FeeImportBatch $batch, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('reverse_fee_import_' . $batch->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        try {
            $result = $this->imports->reverse(
                $batch,
                $this->getUser()?->getUserIdentifier() ?? 'system'
            );
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Nothing was reversed: ' . $e->getMessage());

            return $this->redirectToRoute('app_tenant_fee_import_batch', ['batch' => $batch->getId()]);
        }

        if ($result['error'] !== null) {
            $this->addFlash('error', $result['error']);
        } else {
            $this->addFlash('success', sprintf(
                'Batch #%d reversed: %d payment%s and %d credit%s removed. The invoices are back to their previous state.',
                $batch->getId(),
                $result['payments'],
                $result['payments'] === 1 ? '' : 's',
                $result['advances'],
                $result['advances'] === 1 ? '' : 's'
            ));
        }

        return $this->redirectToRoute('app_tenant_fee_import_batch', ['batch' => $batch->getId()]);
    }

    #[Route('/{batch}/rows.csv', name: 'app_tenant_fee_import_rows_csv', requirements: ['batch' => '\d+'], methods: ['GET'])]
    public function rowsCsv(FeeImportBatch $batch): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($batch): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Row',
                'Admission Number',
                'Pupil',
                'Class',
                'Student Type',
                'Due',
                'Amount Paid',
                'Outstanding',
                'Invoice Number',
                'Status',
                'Warnings',
                'Errors',
            ]);

            foreach ($batch->getPayload() as $row) {
                fputcsv($handle, [
                    $row['row'] ?? '',
                    $row['admission_number'] ?? '',
                    $row['student_name'] ?? '',
                    $row['class_name'] ?? '',
                    $row['student_type'] ?? '',
                    number_format((float) ($row['due'] ?? 0), 2, '.', ''),
                    number_format((float) ($row['amount_paid'] ?? 0), 2, '.', ''),
                    number_format((float) ($row['outstanding'] ?? 0), 2, '.', ''),
                    $row['invoice_number'] ?? '',
                    $batch->isRolledBack()
                        ? 'Reversed'
                        : (($row['errors'] ?? []) !== []
                            ? 'Skipped'
                            : (($row['posted'] ?? false) ? 'Posted' : 'Preview')),
                    implode(' | ', $row['warnings'] ?? []),
                    implode(' | ', $row['errors'] ?? []),
                ]);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="fee-import-%d-rows.csv"', (int) $batch->getId())
        );

        return $response;
    }

    #[Route('/template/download', name: 'app_tenant_fee_import_template', methods: ['GET'])]
    public function template(Request $request, EntityManagerInterface $em): StreamedResponse
    {
        $classroom = $em->getRepository(Classroom::class)->find((int) $request->query->get('classroom'));

        $students = $classroom instanceof Classroom
            ? $this->pupils->roster($classroom)
            : [];

        $response = new StreamedResponse(function () use ($classroom, $students): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Admission Number',
                'First Name',
                'Middle Name',
                'Surname',
                'Class Name',
                'Student Type',
                'Amount Paid',
                'Payment Date',
                'Payment Method',
                'Payment Reference',
                'Note',
            ]);

            foreach ($students as $student) {
                fputcsv($handle, [
                    $student->getAdmissionNumber() ?? '',
                    $student->getFirstName() ?? '',
                    $student->getMiddleName() ?? '',
                    $student->getLastName() ?? '',
                    $classroom?->getName() ?? '',
                    $student->getStudentType(),
                    '',
                    '',
                    '',
                    '',
                    '',
                ]);
            }

            fclose($handle);
        });

        $slug = $classroom instanceof Classroom
            ? preg_replace('/[^A-Za-z0-9]+/', '-', $classroom->getName() ?? 'class')
            : 'blank';

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="fees-import-%s.csv"', trim((string) $slug, '-') ?: 'class')
        );

        return $response;
    }
}
