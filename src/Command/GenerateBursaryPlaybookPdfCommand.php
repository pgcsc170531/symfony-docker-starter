<?php

namespace App\Command;

use App\Service\Manual\MarkdownToHtml;
use App\Service\Pdf\A4PdfRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Builds the shareable bursary playbook.
 *
 * The source of truth is `docs/manual/BURSAR-PLAYBOOK.md`. The command converts
 * its deliberately small Markdown subset into HTML, wraps it in the A4 print
 * template and writes `docs/manual/BURSAR-PLAYBOOK.pdf`.
 *
 * It goes through A4PdfRenderer like every other printable Edus document, so the
 * paper size, font and failure mode stay identical to the school-issued PDFs.
 */
#[AsCommand(
    name: 'app:bursary-playbook:pdf',
    description: 'Build the shareable bursary playbook PDF from docs/manual/BURSAR-PLAYBOOK.md.'
)]
final class GenerateBursaryPlaybookPdfCommand extends Command
{
    public function __construct(
        private readonly MarkdownToHtml $markdown,
        private readonly A4PdfRenderer $pdf,
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('html', null, InputOption::VALUE_NONE, 'Also write an HTML preview next to the PDF.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $source = $this->projectDir . '/docs/manual/BURSAR-PLAYBOOK.md';
        $target = $this->projectDir . '/docs/manual/BURSAR-PLAYBOOK.pdf';

        if (!is_file($source)) {
            $io->error(sprintf('The playbook source was not found at %s.', $source));

            return Command::FAILURE;
        }

        $markdown = (string) file_get_contents($source);

        // The cover carries the title and metadata; the body starts at the first
        // section heading. This prevents a duplicate <h1> on page two.
        $start = strpos($markdown, "\n## ");

        if ($start !== false) {
            $markdown = substr($markdown, $start + 1);
        }

        $body = $this->markdown->convert($markdown);

        $contents = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $matches) === 1) {
                $contents[] = trim($matches[1]);
            }
        }

        $html = $this->twig->render('manual/bursary_playbook.pdf.twig', [
            'body' => $body,
            'contents' => $contents,
            'generated_at' => new \DateTimeImmutable(),
        ]);

        $pdf = $this->pdf->renderHtml($html);

        if (file_put_contents($target, $pdf) === false) {
            $io->error(sprintf('Could not write %s.', $target));

            return Command::FAILURE;
        }

        if ($input->getOption('html')) {
            $htmlTarget = $this->projectDir . '/docs/manual/BURSAR-PLAYBOOK.html';

            if (file_put_contents($htmlTarget, $html) === false) {
                $io->error(sprintf('Could not write %s.', $htmlTarget));

                return Command::FAILURE;
            }

            $io->writeln(sprintf('HTML preview: %s', $htmlTarget));
        }

        $io->success(sprintf(
            'Wrote %s (%s KB, %d sections).',
            $target,
            number_format(strlen($pdf) / 1024, 1),
            count($contents)
        ));

        return Command::SUCCESS;
    }
}
