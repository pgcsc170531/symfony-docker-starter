<?php

namespace App\Command;

use App\Entity\Landlord\School;
use App\Entity\Tenant\SchoolDocument;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives every school a starting "Rules and Regulations" document.
 *
 * The admission letter promises "the rules and regulations" as an attachment, so
 * a school that has never written one would print a letter that refers to nothing.
 * This seeds a sensible Nigerian secondary-school draft — the school edits it in
 * Settings → School documents — and is idempotent: running it twice changes
 * nothing, and it never overwrites a document the school has already edited.
 */
#[AsCommand(
    name: 'app:tenant:seed-documents',
    description: 'Create the default Rules and Regulations document for every tenant'
)]
class SeedSchoolDocumentsCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        #[Autowire(env: 'LANDLORD_DATABASE_URL')]
        private readonly string $databaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('school', null, InputOption::VALUE_REQUIRED, 'Only this subdomain');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Seeding school documents');

        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Entity/Tenant'],
            isDevMode: false
        );
        $config->setNamingStrategy(new UnderscoreNamingStrategy());

        $only = $input->getOption('school');

        foreach ($this->landlordEm->getRepository(School::class)->findAll() as $school) {
            if ($only !== null && $school->getSubdomain() !== $only) {
                continue;
            }

            $parts = parse_url($this->databaseUrl);
            $connection = DriverManager::getConnection([
                'user' => $parts['user'] ?? null,
                'password' => $parts['pass'] ?? null,
                'host' => $parts['host'] ?? 'localhost',
                'port' => $parts['port'] ?? 3306,
                'driver' => 'pdo_mysql',
                'dbname' => $school->getDatabaseName(),
            ], $config);

            $em = new EntityManager($connection, $config);
            $repository = $em->getRepository(SchoolDocument::class);

            if ($repository->findOneBy(['slug' => 'rules-and-regulations']) !== null) {
                $io->text(sprintf('%s: already has a rules document — skipped.', $school->getName()));
                $connection->close();
                continue;
            }

            $em->persist(
                (new SchoolDocument())
                    ->setTitle('Rules and Regulations')
                    ->setSlug('rules-and-regulations')
                    ->setCategory(SchoolDocument::CATEGORY_RULES)
                    ->setBody($this->defaultRules())
                    ->setIsPublished(true)
                    ->setAttachToAdmissionLetter(true)
                    ->setSortOrder(1)
            );
            $em->flush();

            $io->text(sprintf('%s: rules and regulations created.', $school->getName()));
            $connection->close();
        }

        $io->success('Done. Edit the text under Settings → School documents.');

        return Command::SUCCESS;
    }

    private function defaultRules(): string
    {
        return <<<'TEXT'
These rules exist so that every pupil can learn in safety and every parent knows exactly what the school expects. They apply from the first day of the session and are read together with the school fees schedule attached to this letter.

1. RESUMPTION AND ATTENDANCE
School opens at 7:45 a.m. and closes at 2:30 p.m. A pupil who arrives after assembly is marked late and must report to the office before entering class. Absence must be explained in writing by the parent or guardian within 48 hours. Three unexplained absences in a term are reported to the parents and may lead to a review of the pupil's place.

2. UNIFORM AND APPEARANCE
The complete school uniform is worn every day: correct shirt, trousers or skirt, school belt, black shoes and the school badge. Hair must be neat and covered where the school requires it. No coloured hair, nail polish, visible tattoos, earrings for boys or jewellery beyond a simple wristwatch. Pupils who come to school incorrectly dressed are sent home to change.

3. CONDUCT
Pupils are expected to be courteous to staff, visitors and one another, both inside the school and on the way to and from it. Fighting, bullying, intimidation, theft, dishonesty, and the use of abusive or discriminatory language are serious offences and are dealt with under the school's disciplinary procedure.

4. PROHIBITED ITEMS
Mobile phones, smart watches, tablets, earphones, radios and any other electronic device are not allowed during school hours; a phone brought to school must be handed to the office in the morning and collected at the close of day. Knives, lighters, matches, alcohol, tobacco, vapes and any substance the school considers harmful are forbidden, and possession of a weapon is reported to the parents and, where necessary, to the authorities.

5. ACADEMIC WORK
Every pupil must complete and submit assignments on the dates given, bring the prescribed textbooks and writing materials to every lesson, and sit all examinations and tests. Examination malpractice of any kind — copying, bringing unauthorised material, or communicating during a test — results in the cancellation of that paper and a disciplinary review.

6. PROPERTY AND ENVIRONMENT
School property must be handled with care. Damage caused wilfully or through negligence is repaired at the cost of the pupil's parents or guardians. Litter must be placed in the bins provided, and every class keeps its own classroom clean at the end of the day.

7. HEALTH AND SAFETY
Any illness or injury must be reported to the class teacher immediately. Medicines may only be administered by the school nurse, and only on the written instruction of a parent. Pupils with a medical condition, allergy, or regular medication must inform the school in writing at the start of the session.

8. FEES
School fees are payable for the term, in full or in part, at the bursary or online through the parent portal. A pupil whose fees remain unpaid may be required to stay away from examinations until the account is cleared, and the school may withhold the term's report card. Every payment is receipted, and a receipt states what has been paid and what remains outstanding.

9. SUPERVISION AND COLLECTION
Pupils must not leave the school premises during school hours without written permission from the principal. Parents should collect their children promptly at closing time. Where a different person is collecting a child, the school must be informed in advance by the parent or guardian on record.

10. DISCIPLINE
Breaches are handled progressively: a verbal warning, a written warning copied to the parents, then suspension or, in the most serious cases, withdrawal of the pupil's place. Corporal punishment is not used. Parents or guardians will always be informed in writing before a serious sanction is applied, and they may respond to the school in writing.

11. REVIEW OF THESE RULES
The school reviews these rules at the start of each session and notifies parents of any change. A parent or guardian who wishes to comment on any rule may do so in writing to the principal at any time.
TEXT;
    }
}
