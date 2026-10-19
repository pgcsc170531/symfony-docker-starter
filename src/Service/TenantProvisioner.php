<?php

namespace App\Service;

use App\Entity\Tenant\GradingScale;
use App\Entity\Tenant\School as TenantSchool;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Single source of truth for bringing a new school online.
 *
 * Used by both the landlord UI (`/admin/school/new`) and the console
 * (`app:landlord:create-school`), so a school provisioned either way gets
 * exactly the same starting state:
 *
 *   1. the `school_{subdomain}` database (created only if absent)
 *   2. every tenant table, via SchemaTool (never Doctrine migrations)
 *   3. the school settings row
 *   4. the active academic session + first term
 *   5. the default Nigerian A1–F9 grading scale
 *   6. the principal's login account
 *
 * Everything is idempotent — running it twice must not duplicate rows.
 */
class TenantProvisioner
{
    /**
     * Canonical WAEC/NECO nine-point scale — identical to the fallback used by
     * `ResultReportController::gradeFor()` so seeded and unseeded schools agree.
     *
     * @var list<array{0:string,1:float,2:float,3:string,4:string}>
     *      grade, min, max, points, remark
     */
    public const DEFAULT_GRADING_SCALE = [
        ['A1', 75, 100, '1.00', 'Excellent'],
        ['B2', 70, 74, '2.00', 'Very Good'],
        ['B3', 60, 69, '3.00', 'Good'],
        ['C4', 50, 59, '4.00', 'Credit'],
        ['C5', 45, 49, '5.00', 'Credit'],
        ['C6', 40, 44, '6.00', 'Credit'],
        ['D7', 35, 39, '7.00', 'Pass'],
        ['E8', 30, 34, '8.00', 'Pass'],
        ['F9', 0, 29, '9.00', 'Fail'],
    ];

    /** Usable password for a fresh install; the principal is forced to change it later. */
    public const DEFAULT_PRINCIPAL_PASSWORD = 'password123';

    public function __construct(
        #[Autowire(env: 'LANDLORD_DATABASE_URL')]
        private string $landlordDatabaseUrl,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * `Royal Academy` / `royal-academy` -> `school_royalacademy`.
     */
    public function databaseNameFor(string $subdomain): string
    {
        return 'school_' . preg_replace('/[^a-z0-9]/', '', strtolower($subdomain));
    }

    /**
     * @return array{
     *     database:string,
     *     database_created:bool,
     *     school_created:bool,
     *     session:string,
     *     term:string,
     *     grading_scale_seeded:int,
     *     admin_created:?string
     * }
     */
    public function provision(
        string $schoolName,
        string $subdomain,
        ?int $landlordSchoolId = null,
        ?string $principalName = null,
        ?string $principalEmail = null,
        ?string $principalPassword = null,
        ?string $databaseName = null,
    ): array {
        // The landlord record owns the database name; only derive it as a fallback.
        $dbName = ($databaseName !== null && $databaseName !== '')
            ? $databaseName
            : $this->databaseNameFor($subdomain);
        $base = $this->connectionParams();

        // ── 1. The database itself ───────────────────────────────────────
        $serverConnection = DriverManager::getConnection($base);
        $schemaManager = $serverConnection->createSchemaManager();
        $databaseCreated = !in_array($dbName, $schemaManager->listDatabases(), true);

        if ($databaseCreated) {
            $schemaManager->createDatabase($dbName);
        }
        $serverConnection->close();

        // ── 2. Every tenant table ────────────────────────────────────────
        $em = $this->tenantEntityManager($dbName, $base);
        $schemaTool = new SchemaTool($em);
        $schemaTool->updateSchema($em->getMetadataFactory()->getAllMetadata(), true);

        // `messenger_messages` is a transport table, not an entity, so SchemaTool
        // never creates it — every tenant needs it for queued work (webhooks,
        // outbound mail). Forgetting it used to half-break a tenant.
        $this->ensureMessengerTable($em);

        // ── 3. School settings ───────────────────────────────────────────
        $school = $em->getRepository(TenantSchool::class)->findOneBy([]);
        $schoolCreated = $school === null;

        if ($schoolCreated) {
            $school = new TenantSchool();
            $school->setName($schoolName);
            $school->setSubdomain($subdomain);
            if ($principalEmail !== null && $principalEmail !== '') {
                $school->setEmail($principalEmail);
            }
            if ($landlordSchoolId !== null) {
                $school->setLandlordSchoolId($landlordSchoolId);
            }
            $em->persist($school);
        }

        // ── 4. Active session + first term ───────────────────────────────
        $year = (int) date('Y');
        $sessionName = $year . '/' . ($year + 1);

        $session = $em->getRepository(Session::class)->findOneBy(['name' => $sessionName]);
        if ($session === null) {
            $session = new Session();
            $session->setName($sessionName);
            $session->setIsActive(true);
            $em->persist($session);
        }

        $termName = 'First Term';
        $term = $em->getRepository(Term::class)->findOneBy(['session' => $session, 'name' => $termName]);
        if ($term === null) {
            $term = new Term();
            $term->setName($termName);
            $term->setIsActive(true);
            $term->setSession($session);
            $em->persist($term);
        }

        // ── 5. Default grading scale ─────────────────────────────────────
        $gradingScaleSeeded = $this->seedGradingScale($em);

        // ── 6. The principal's account ───────────────────────────────────
        $adminCreated = null;
        if ($principalEmail !== null && $principalEmail !== '') {
            $existing = $em->getRepository(User::class)->findOneBy(['email' => $principalEmail]);

            if ($existing === null) {
                $user = new User();
                $user->setEmail($principalEmail);
                $user->setFullName($principalName ?: $schoolName . ' Principal');
                $user->setRoles(['ROLE_ADMIN', 'ROLE_BURSAR']);
                $user->setSchool($school);
                $user->setPassword($this->passwordHasher->hashPassword(
                    $user,
                    $principalPassword ?: self::DEFAULT_PRINCIPAL_PASSWORD
                ));
                $em->persist($user);
                $adminCreated = $principalEmail;
            }
        }

        $em->flush();
        $em->getConnection()->close();

        return [
            'database' => $dbName,
            'database_created' => $databaseCreated,
            'school_created' => $schoolCreated,
            'session' => $sessionName,
            'term' => $termName,
            'grading_scale_seeded' => $gradingScaleSeeded,
            'admin_created' => $adminCreated,
        ];
    }

    /**
     * Creates the Doctrine messenger transport table when it is absent.
     */
    private function ensureMessengerTable(EntityManager $em): void
    {
        $em->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGINT AUTO_INCREMENT NOT NULL,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX IDX_75EA56E0FB7336F0 (queue_name),
                INDEX IDX_75EA56E0E3BD61CE (available_at),
                INDEX IDX_75EA56E016BA31DB (delivered_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    /**
     * Adds any missing grades from the canonical scale without touching rows the
     * school has already customised.
     */
    private function seedGradingScale(EntityManager $em): int
    {
        $existing = $em->getRepository(GradingScale::class)->findAll();
        $byGrade = [];
        foreach ($existing as $scale) {
            $byGrade[(string) $scale->getGrade()] = true;
        }

        $added = 0;
        foreach (self::DEFAULT_GRADING_SCALE as $position => [$grade, $min, $max, $points, $remark]) {
            if (isset($byGrade[$grade])) {
                continue;
            }

            $scale = new GradingScale();
            $scale->setGrade($grade);
            $scale->setMinScore($min);
            $scale->setMaxScore($max);
            $scale->setPoints($points);
            $scale->setRemark($remark);
            $scale->setSortOrder($position);
            $em->persist($scale);
            $added++;
        }

        return $added;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParams(): array
    {
        $parts = parse_url($this->landlordDatabaseUrl);
        if ($parts === false) {
            throw new \RuntimeException('LANDLORD_DATABASE_URL could not be parsed.');
        }

        return [
            'user' => $parts['user'] ?? 'root',
            'password' => $parts['pass'] ?? '',
            'host' => $parts['host'] ?? 'database',
            'port' => $parts['port'] ?? 3306,
            'driver' => 'pdo_mysql',
        ];
    }

    /**
     * @param array<string, mixed> $base
     */
    private function tenantEntityManager(string $dbName, array $base): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../Entity/Tenant'],
            isDevMode: true
        );
        // Must match the runtime naming strategy or the SchemaTool disagrees with
        // Doctrine about snake_case column names.
        $config->setNamingStrategy(new UnderscoreNamingStrategy());

        $connection = DriverManager::getConnection(array_merge($base, ['dbname' => $dbName]), $config);

        return new EntityManager($connection, $config);
    }
}
