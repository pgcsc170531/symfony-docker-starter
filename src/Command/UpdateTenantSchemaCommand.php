<?php

namespace App\Command;

use App\Entity\Landlord\School;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:tenant:update-schema',
    description: 'Updates the database schema for all tenants (schools)',
)]
class UpdateTenantSchemaCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,

        // ✅ FIX: We tell Symfony exactly where to find this string
        #[Autowire(env: 'LANDLORD_DATABASE_URL')]
        private string $databaseUrl
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Starting Tenant Schema Update...');

        // 1. Get all Schools
        $schools = $this->landlordEm->getRepository(School::class)->findAll();
        $io->text(sprintf('Found %d schools to update.', count($schools)));

        // 2. Prepare the Tenant Config (Look in Tenant folder)
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Entity/Tenant'],
            isDevMode: true
        );

        // Use the same underscore naming strategy as the running app so the
        // schema tool and Doctrine agree on snake_case column names.
        $config->setNamingStrategy(new UnderscoreNamingStrategy());

        // 3. Loop through every school
        foreach ($schools as $school) {
            $io->section("Updating: " . $school->getName() . " (" . $school->getSubdomain() . ")");

            try {
                // A. Parse the URL to get user/pass/host
                $parts = parse_url($this->databaseUrl);
                
                $params = [
                    'user'     => $parts['user'],
                    'password' => $parts['pass'],
                    'host'     => $parts['host'],
                    'port'     => $parts['port'] ?? 3306,
                    'driver'   => 'pdo_mysql',
                    'dbname'   => $school->getDatabaseName(),
                ];

                // B. Connect specifically to this Tenant DB
                $connection = DriverManager::getConnection($params, $config);
                $entityManager = new \Doctrine\ORM\EntityManager($connection, $config);

                // C. Run the Schema Update (handles new tables/entities)
                $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
                $schemaTool = new SchemaTool($entityManager);

                // true = Execute safely (update only, don't delete data)
                $schemaTool->updateSchema($metadata, true);

                // D. 🚀 Deterministic fallback: explicitly add the website
                //    personalisation columns...
                $this->ensureWebsiteColumns($connection, $io);

                // 🆕 Phase 1 — Exam Collection foundation tables
                $this->ensurePhaseOneTables($connection, $io);

                // 🆕 Phase 2 — Staff & Assignments
                $this->ensurePhaseTwoTables($connection, $io);

                // 🆕 Phase 3 — Grading scale, assessment scheme, class/student subjects
                $this->ensurePhaseThreeTables($connection, $io);

                // 🆕 Phase 4 — Result component scores
                $this->ensurePhaseFourTables($connection, $io);

                // 🆕 Phase 5 — Result approvals (Form Teacher / HOD / Year Group Master)
                $this->ensurePhaseFiveTables($connection, $io);

                $io->success('✅ Schema updated successfully.');
                $connection->close();

            } catch (\Exception $e) {
                $io->error('❌ Failed: ' . $e->getMessage());
            }
        }

        $io->success('🎉 All tenant databases are up to date!');
        return Command::SUCCESS;
    }

    /**
     * Adds the school website personalisation columns if they do not yet exist.
     * Idempotent: checks information_schema before each ALTER.
     */
    private function ensureWebsiteColumns(Connection $connection, SymfonyStyle $io): void
    {
        $existing = $connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'school_settings'"
        );
        $existing = array_map('strtolower', $existing);

        $columns = [
            'hero_tagline'        => 'VARCHAR(255) DEFAULT NULL',
            'hero_image_filename' => 'VARCHAR(255) DEFAULT NULL',
            'about_content'       => 'TEXT DEFAULT NULL',
            'admissions_intro'    => 'TEXT DEFAULT NULL',
            'map_embed_url'       => 'TEXT DEFAULT NULL',
            'facebook_url'        => 'VARCHAR(255) DEFAULT NULL',
            'twitter_url'         => 'VARCHAR(255) DEFAULT NULL',
            'instagram_url'       => 'VARCHAR(255) DEFAULT NULL',
        ];

        foreach ($columns as $name => $definition) {
            if (in_array($name, $existing, true)) {
                $io->text("  ✓ Column {$name} already exists.");
                continue;
            }
            $connection->executeStatement("ALTER TABLE school_settings ADD COLUMN {$name} {$definition}");
            $io->text("  + Added column {$name}");
        }
    }

    /**
     * Phase 1 — creates the exam-collection foundation tables and links.
     * Idempotent: CREATE TABLE IF NOT EXISTS + information_schema checks.
     */
    private function ensurePhaseOneTables(Connection $connection, SymfonyStyle $io): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS department (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                UNIQUE INDEX UNIQ_department_name (name),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS subject (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(20) DEFAULT NULL,
                department_id INT DEFAULT NULL,
                INDEX IDX_subject_department (department_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS year_group (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(50) NOT NULL,
                level VARCHAR(50) DEFAULT NULL,
                sort_order INT DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $this->ensureColumn($connection, 'classroom', 'year_group_id', 'INT DEFAULT NULL', $io);
        $this->ensureColumn($connection, 'classroom', 'arm', 'VARCHAR(10) DEFAULT NULL', $io);

        $this->ensureForeignKey($connection, 'subject', 'department_id', 'department', 'id', $io);
        $this->ensureForeignKey($connection, 'classroom', 'year_group_id', 'year_group', 'id', $io);

        $io->text('  ✓ Exam collection foundation tables verified.');
    }

    private function ensureColumn(Connection $connection, string $table, string $column, string $definition, SymfonyStyle $io): void
    {
        $exists = (bool) $connection->fetchOne(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column",
            ['table' => $table, 'column' => $column]
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        $io->text("  + Added column {$table}.{$column}");
    }

    private function ensureForeignKey(
        Connection $connection,
        string $table,
        string $column,
        string $refTable,
        string $refColumn,
        SymfonyStyle $io,
        string $onDelete = 'SET NULL'   // 🆕 configurable; default keeps Phase 1 behavior
    ): void {
        $exists = (bool) $connection->fetchOne(
            "SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
               AND REFERENCED_TABLE_NAME = :refTable",
            ['table' => $table, 'column' => $column, 'refTable' => $refTable]
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement(
            "ALTER TABLE {$table} ADD CONSTRAINT FK_{$table}_{$refTable} FOREIGN KEY ({$column}) REFERENCES {$refTable} ({$refColumn}) ON DELETE {$onDelete}"
        );
        $io->text("  + Added FK {$table}.{$column} → {$refTable}.{$refColumn} (ON DELETE {$onDelete})");
    }


private function ensurePhaseTwoTables(Connection $connection, SymfonyStyle $io): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS staff (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                title VARCHAR(20) DEFAULT NULL,
                gender VARCHAR(10) DEFAULT NULL,
                phone VARCHAR(20) DEFAULT NULL,
                employee_number VARCHAR(50) DEFAULT NULL,
                qualification TEXT DEFAULT NULL,
                photo_filename VARCHAR(255) DEFAULT NULL,
                UNIQUE INDEX UNIQ_staff_user (user_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS department_head (
                id INT AUTO_INCREMENT NOT NULL,
                staff_id INT NOT NULL,
                department_id INT NOT NULL,
                session_id INT NOT NULL,
                UNIQUE INDEX UNIQ_department_head (staff_id, department_id, session_id),
                INDEX IDX_department_head_staff (staff_id),
                INDEX IDX_department_head_department (department_id),
                INDEX IDX_department_head_session (session_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS subject_teacher (
                id INT AUTO_INCREMENT NOT NULL,
                staff_id INT NOT NULL,
                subject_id INT NOT NULL,
                classroom_id INT NOT NULL,
                term_id INT NOT NULL,
                UNIQUE INDEX UNIQ_subject_teacher (staff_id, subject_id, classroom_id, term_id),
                INDEX IDX_subject_teacher_staff (staff_id),
                INDEX IDX_subject_teacher_subject (subject_id),
                INDEX IDX_subject_teacher_classroom (classroom_id),
                INDEX IDX_subject_teacher_term (term_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS year_group_master (
                id INT AUTO_INCREMENT NOT NULL,
                staff_id INT NOT NULL,
                year_group_id INT NOT NULL,
                session_id INT NOT NULL,
                UNIQUE INDEX UNIQ_year_group_master (staff_id, year_group_id, session_id),
                INDEX IDX_year_group_master_staff (staff_id),
                INDEX IDX_year_group_master_year_group (year_group_id),
                INDEX IDX_year_group_master_session (session_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS form_teacher (
                id INT AUTO_INCREMENT NOT NULL,
                staff_id INT NOT NULL,
                classroom_id INT NOT NULL,
                term_id INT NOT NULL,
                UNIQUE INDEX UNIQ_form_teacher (staff_id, classroom_id, term_id),
                INDEX IDX_form_teacher_staff (staff_id),
                INDEX IDX_form_teacher_classroom (classroom_id),
                INDEX IDX_form_teacher_term (term_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $fks = [
            ['staff', 'user_id', 'user', 'id', 'RESTRICT'],
            ['department_head', 'staff_id', 'staff', 'id', 'RESTRICT'],
            ['department_head', 'department_id', 'department', 'id', 'RESTRICT'],
            ['department_head', 'session_id', 'session', 'id', 'RESTRICT'],
            ['subject_teacher', 'staff_id', 'staff', 'id', 'RESTRICT'],
            ['subject_teacher', 'subject_id', 'subject', 'id', 'RESTRICT'],
            ['subject_teacher', 'classroom_id', 'classroom', 'id', 'RESTRICT'],
            ['subject_teacher', 'term_id', 'term', 'id', 'RESTRICT'],
            ['year_group_master', 'staff_id', 'staff', 'id', 'RESTRICT'],
            ['year_group_master', 'year_group_id', 'year_group', 'id', 'RESTRICT'],
            ['year_group_master', 'session_id', 'session', 'id', 'RESTRICT'],
            ['form_teacher', 'staff_id', 'staff', 'id', 'RESTRICT'],
            ['form_teacher', 'classroom_id', 'classroom', 'id', 'RESTRICT'],
            ['form_teacher', 'term_id', 'term', 'id', 'RESTRICT'],
        ];

        foreach ($fks as [$table, $column, $refTable, $refColumn, $onDelete]) {
            $this->ensureForeignKey($connection, $table, $column, $refTable, $refColumn, $io, $onDelete);
        }

        $io->text('  ✓ Staff & assignment tables verified.');
    }

    private function ensurePhaseThreeTables(Connection $connection, SymfonyStyle $io): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS grading_scale (
                id INT AUTO_INCREMENT NOT NULL,
                grade VARCHAR(10) NOT NULL,
                min_score DOUBLE PRECISION NOT NULL,
                max_score DOUBLE PRECISION NOT NULL,
                points NUMERIC(4,2) DEFAULT NULL,
                remark VARCHAR(100) DEFAULT NULL,
                sort_order INT DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS assessment_scheme (
                id INT AUTO_INCREMENT NOT NULL,
                session_id INT NOT NULL,
                term_id INT NOT NULL,
                classroom_id INT DEFAULT NULL,
                subject_id INT DEFAULT NULL,
                ca_count INT NOT NULL DEFAULT 3,
                exam_mode VARCHAR(20) NOT NULL DEFAULT 'single',
                ca_max INT NOT NULL DEFAULT 20,
                theory_max INT NOT NULL DEFAULT 60,
                practical_max INT NOT NULL DEFAULT 40,
                INDEX IDX_assessment_scheme_session (session_id),
                INDEX IDX_assessment_scheme_term (term_id),
                INDEX IDX_assessment_scheme_classroom (classroom_id),
                INDEX IDX_assessment_scheme_subject (subject_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS class_subject (
                id INT AUTO_INCREMENT NOT NULL,
                subject_id INT NOT NULL,
                classroom_id INT NOT NULL,
                session_id INT NOT NULL,
                category VARCHAR(20) NOT NULL DEFAULT 'core',
                UNIQUE INDEX UNIQ_class_subject (subject_id, classroom_id, session_id),
                INDEX IDX_class_subject_subject (subject_id),
                INDEX IDX_class_subject_classroom (classroom_id),
                INDEX IDX_class_subject_session (session_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS student_subject (
                id INT AUTO_INCREMENT NOT NULL,
                student_id INT NOT NULL,
                class_subject_id INT NOT NULL,
                UNIQUE INDEX UNIQ_student_subject (student_id, class_subject_id),
                INDEX IDX_student_subject_student (student_id),
                INDEX IDX_student_subject_class_subject (class_subject_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $fks = [
            ['assessment_scheme', 'session_id', 'session', 'id', 'RESTRICT'],
            ['assessment_scheme', 'term_id', 'term', 'id', 'RESTRICT'],
            ['assessment_scheme', 'classroom_id', 'classroom', 'id', 'SET NULL'],
            ['assessment_scheme', 'subject_id', 'subject', 'id', 'SET NULL'],
            ['class_subject', 'subject_id', 'subject', 'id', 'RESTRICT'],
            ['class_subject', 'classroom_id', 'classroom', 'id', 'RESTRICT'],
            ['class_subject', 'session_id', 'session', 'id', 'RESTRICT'],
            ['student_subject', 'student_id', 'student', 'id', 'RESTRICT'],
            ['student_subject', 'class_subject_id', 'class_subject', 'id', 'RESTRICT'],
        ];

        foreach ($fks as [$table, $column, $refTable, $refColumn, $onDelete]) {
            $this->ensureForeignKey($connection, $table, $column, $refTable, $refColumn, $io, $onDelete);
        }

        $io->text('  ✓ Assessment setup tables verified.');
    }

    private function ensurePhaseFourTables(Connection $connection, SymfonyStyle $io): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS result_component_score (
                id INT AUTO_INCREMENT NOT NULL,
                student_id INT NOT NULL,
                class_subject_id INT NOT NULL,
                term_id INT NOT NULL,
                component VARCHAR(20) NOT NULL,
                score DECIMAL(5,2) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                entered_by_id INT DEFAULT NULL,
                created_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_result_component_score (student_id, class_subject_id, term_id, component),
                INDEX IDX_result_component_score_student (student_id),
                INDEX IDX_result_component_score_class_subject (class_subject_id),
                INDEX IDX_result_component_score_term (term_id),
                INDEX IDX_result_component_score_entered_by (entered_by_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $fks = [
            // NOT NULL columns → RESTRICT (SET NULL is invalid for these)
            ['result_component_score', 'student_id', 'student', 'id', 'RESTRICT'],
            ['result_component_score', 'class_subject_id', 'class_subject', 'id', 'RESTRICT'],
            ['result_component_score', 'term_id', 'term', 'id', 'RESTRICT'],
            // nullable → SET NULL is valid
            ['result_component_score', 'entered_by_id', 'staff', 'id', 'SET NULL'],
        ];

        foreach ($fks as [$table, $column, $refTable, $refColumn, $onDelete]) {
            $this->ensureForeignKey($connection, $table, $column, $refTable, $refColumn, $io, $onDelete);
        }

        $io->text('  ✓ Result component score table verified.');
    }

    private function ensurePhaseFiveTables(Connection $connection, SymfonyStyle $io): void
    {
        $connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS result_approval (
                id INT AUTO_INCREMENT NOT NULL,
                class_subject_id INT NOT NULL,
                term_id INT NOT NULL,
                form_teacher_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                form_teacher_id INT DEFAULT NULL,
                form_teacher_at DATETIME DEFAULT NULL,
                hod_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                hod_id INT DEFAULT NULL,
                hod_at DATETIME DEFAULT NULL,
                year_group_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                year_group_master_id INT DEFAULT NULL,
                year_group_at DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_result_approval (class_subject_id, term_id),
                INDEX IDX_result_approval_class_subject (class_subject_id),
                INDEX IDX_result_approval_term (term_id),
                INDEX IDX_result_approval_form_teacher (form_teacher_id),
                INDEX IDX_result_approval_hod (hod_id),
                INDEX IDX_result_approval_year_group_master (year_group_master_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $fks = [
            ['result_approval', 'class_subject_id', 'class_subject', 'id', 'RESTRICT'],
            ['result_approval', 'term_id', 'term', 'id', 'RESTRICT'],
            ['result_approval', 'form_teacher_id', 'staff', 'id', 'SET NULL'],
            ['result_approval', 'hod_id', 'staff', 'id', 'SET NULL'],
            ['result_approval', 'year_group_master_id', 'staff', 'id', 'SET NULL'],
        ];

        foreach ($fks as [$table, $column, $refTable, $refColumn, $onDelete]) {
            $this->ensureForeignKey($connection, $table, $column, $refTable, $refColumn, $io, $onDelete);
        }

        $io->text('  ✓ Result approval table verified.');
    }
}