<?php

namespace App\Command;

use App\Entity\Landlord\School as LandlordSchool;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\ClassSubject;
use App\Entity\Tenant\Department;
use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FeeItem;
use App\Entity\Tenant\FeeStructure;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Staff;
use App\Entity\Tenant\Subject;
use App\Entity\Tenant\SubjectTeacher;
use App\Entity\Tenant\Term;
use App\Entity\Tenant\User;
use App\Entity\Tenant\YearGroup;
use App\Entity\Tenant\YearGroupMaster;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Builds a complete, realistic school in a tenant database so the whole product
 * can be walked end to end: registration → invoice → payment → result entry →
 * approval chain → report.
 *
 * ═══ WHY A COMMAND AND NOT A FIXTURE ═══
 * A school is not a list of rows, it is a set of relationships that must exist in
 * the right order and must not be duplicated. This command is IDEMPOTENT: run it
 * ten times and you still get 3 departments, 9 classes and 10 subjects. That makes
 * it safe to re-run after a failed demo, which a fixture load is not.
 *
 * ═══ THE SHAPE IT BUILDS ═══
 *   3 departments · 10 subjects · 3 year groups · 9 classes (JSS 1A … JSS 3C)
 *   1 bursar, 1 store keeper, 9 teachers (one per class, as form teacher AND
 *   subject teacher for that class's subjects), 3 department heads, 3 year group
 *   masters — so the approval chain Form Teacher → HOD → Year Group Master has a
 *   different person at each step.
 *   4 fee items, priced per class for the active term.
 *
 * Student records are deliberately NOT created here: the point of the demo is to
 * watch them arrive through the public application form and the bulk importer.
 */
#[AsCommand(
    name: 'app:demo:seed-school',
    description: 'Seed a complete demo school (structure, staff and fees) into a tenant database',
)]
class SeedDemoSchoolCommand extends Command
{
    private const DEFAULT_PASSWORD = 'password123';

    /** Departments, and the subjects that belong to them. */
    private const DEPARTMENTS = [
        'Sciences' => [
            'Mathematics'          => 'MTH',
            'Basic Science'        => 'BSC',
            'Computer Studies'     => 'CMP',
            'Agricultural Science' => 'AGR',
        ],
        'Languages' => [
            'English Language' => 'ENG',
            'Hausa Language'   => 'HAU',
        ],
        'Arts & Humanities' => [
            'Social Studies'   => 'SST',
            'Civic Education'  => 'CVE',
            'Business Studies' => 'BUS',
            'Islamic Studies'  => 'ISL',
        ],
    ];

    private const YEAR_GROUPS = ['JSS 1' => 1, 'JSS 2' => 2, 'JSS 3' => 3];
    private const ARMS = ['A', 'B', 'C'];

    /**
     * One teacher per class. Named so the demo reads like a real staff list, and
     * so every person in the approval chain is a different person.
     * [full name, email, title, gender, form teacher of, HOD of, YGM of]
     */
    private const TEACHERS = [
        ['Adamu Bello',      'adamu.bello@gakonline.ng',      'Mr.',  'Male',   'JSS 1A', 'Sciences',          'JSS 1'],
        ['Zainab Yusuf',     'zainab.yusuf@gakonline.ng',     'Mrs.', 'Female', 'JSS 1B', 'Languages',         null],
        ['Musa Sani',        'musa.sani@gakonline.ng',        'Mr.',  'Male',   'JSS 1C', 'Arts & Humanities', 'JSS 2'],
        ['Hauwa Umar',       'hauwa.umar@gakonline.ng',       'Mrs.', 'Female', 'JSS 2A', null,                'JSS 3'],
        ['Suleiman Garba',   'suleiman.garba@gakonline.ng',   'Mr.',  'Male',   'JSS 2B', null,                null],
        ['Grace Okonkwo',    'grace.okonkwo@gakonline.ng',    'Ms.',  'Female', 'JSS 2C', null,                null],
        ['Ibrahim Danjuma',  'ibrahim.danjuma@gakonline.ng',  'Mr.',  'Male',   'JSS 3A', null,                null],
        ['Fatima Abubakar',  'fatima.abubakar@gakonline.ng',  'Mrs.', 'Female', 'JSS 3B', null,                null],
        ['Emeka Okafor',     'emeka.okafor@gakonline.ng',     'Mr.',  'Male',   'JSS 3C', null,                null],
    ];

    /** Fee items, and the per-year-group price in naira (JSS 1 / 2 / 3). */
    private const FEE_ITEMS = [
        ['Tuition Fee',          false, [40000, 45000, 50000]],
        ['Development Levy',     false, [5000, 5000, 5000]],
        ['Books & Stationery',   false, [7500, 7500, 7500]],
        ['Uniform & Sports Wear', true, [12000, 12000, 12000]],
    ];

    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private EntityManagerInterface $landlordEm,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $tenantConnection,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subdomain', InputArgument::OPTIONAL, 'Tenant subdomain', 'gakonline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $subdomain = (string) $input->getArgument('subdomain');

        $this->switchToTenant($subdomain);

        $session = $this->em->getRepository(Session::class)->findOneBy(['isActive' => true])
            ?? $this->em->getRepository(Session::class)->findOneBy([]);
        $term = $this->em->getRepository(Term::class)->findOneBy(['session' => $session], ['id' => 'ASC']);
        $school = $this->em->getRepository(School::class)->findOneBy([]);

        if (!$session || !$term || !$school) {
            $io->error("Tenant '$subdomain' has no session/term/school row. Provision it first.");

            return Command::FAILURE;
        }

        $io->title(sprintf('Seeding %s — %s / %s', $subdomain, $session->getName(), $term->getName()));

        // 1. Departments + subjects -------------------------------------------
        $departments = [];
        $subjects = [];
        foreach (self::DEPARTMENTS as $deptName => $deptSubjects) {
            $department = $this->em->getRepository(Department::class)->findOneBy(['name' => $deptName]);
            if (!$department) {
                $department = (new Department())->setName($deptName);
                $this->em->persist($department);
            }
            $departments[$deptName] = $department;

            foreach ($deptSubjects as $subjectName => $code) {
                $subject = $this->em->getRepository(Subject::class)->findOneBy(['code' => $code]);
                if (!$subject) {
                    $subject = (new Subject())->setName($subjectName)->setCode($code);
                    $this->em->persist($subject);
                }
                $subject->setDepartment($department);
                $subjects[$subjectName] = $subject;
            }
        }
        $this->em->flush();
        $io->writeln(sprintf('  departments: %d · subjects: %d', count($departments), count($subjects)));

        // 2. Year groups + classes ---------------------------------------------
        $yearGroups = [];
        foreach (self::YEAR_GROUPS as $name => $order) {
            $yg = $this->em->getRepository(YearGroup::class)->findOneBy(['name' => $name]);
            if (!$yg) {
                $yg = (new YearGroup())->setName($name);
                $this->em->persist($yg);
            }
            $yg->setLevel($name)->setSortOrder($order);
            $yearGroups[$name] = $yg;
        }
        $this->em->flush();

        /** @var array<string, Classroom> $classrooms keyed by "JSS 1A" */
        $classrooms = [];
        foreach (array_keys(self::YEAR_GROUPS) as $ygName) {
            foreach (self::ARMS as $arm) {
                $className = $ygName . $arm;           // matches demo_students_360.csv
                $room = $this->em->getRepository(Classroom::class)->findOneBy(['name' => $className]);
                if (!$room) {
                    $room = (new Classroom())->setName($className);
                    $this->em->persist($room);
                }
                $room->setArm($arm)->setYearGroup($yearGroups[$ygName]);
                $classrooms[$className] = $room;
            }
        }
        $this->em->flush();
        $io->writeln(sprintf('  year groups: %d · classes: %d', count($yearGroups), count($classrooms)));

        // 3. Every class studies every subject, for this session ---------------
        $links = 0;
        foreach ($classrooms as $room) {
            foreach ($subjects as $subject) {
                $existing = $this->em->getRepository(ClassSubject::class)
                    ->findOneBy(['classroom' => $room, 'subject' => $subject, 'session' => $session]);
                if ($existing) {
                    continue;
                }
                $this->em->persist(
                    (new ClassSubject())->setClassroom($room)->setSubject($subject)->setSession($session)
                );
                $links++;
            }
        }
        $this->em->flush();
        $io->writeln(sprintf('  class-subject links added: %d', $links));

        // 4. Bursar + store keeper ---------------------------------------------
        $this->ensureStaff($school, 'Bursar', 'Ngozi', 'Eze', 'bursar@gakonline.ng', 'Mrs.', 'Female', ['ROLE_BURSAR'], 'BR-001');
        $this->ensureStaff($school, 'Store', 'Kabir', 'Lawal', 'store@gakonline.ng', 'Mr.', 'Male', ['ROLE_STORE'], 'ST-001');

        // 5. Teachers, with their assignments ----------------------------------
        /** @var array<string, Staff> $staffByClass */
        $staffByClass = [];
        foreach (self::TEACHERS as [$fullName, $email, $title, $gender, $formOf, $hodOf, $ygmOf]) {
            [$first, $last] = array_pad(explode(' ', $fullName, 2), 2, '');
            $staff = $this->ensureStaff($school, $fullName, $first, $last, $email, $title, $gender, ['ROLE_TEACHER'], null);
            $staffByClass[$formOf] = $staff;

            // Form teacher of one class.
            $room = $classrooms[$formOf];
            $exists = $this->em->getRepository(FormTeacher::class)
                ->findOneBy(['classroom' => $room, 'term' => $term]);
            if (!$exists) {
                $this->em->persist((new FormTeacher())->setStaff($staff)->setClassroom($room)->setTerm($term));
            }

            // Subject teacher for every subject that class studies — so result
            // entry for that class has a named owner for each subject.
            foreach ($subjects as $subject) {
                $has = $this->em->getRepository(SubjectTeacher::class)
                    ->findOneBy(['subject' => $subject, 'classroom' => $room, 'term' => $term]);
                if (!$has) {
                    $this->em->persist(
                        (new SubjectTeacher())->setStaff($staff)->setSubject($subject)
                            ->setClassroom($room)->setTerm($term)
                    );
                }
            }

            if ($hodOf) {
                $has = $this->em->getRepository(DepartmentHead::class)
                    ->findOneBy(['department' => $departments[$hodOf], 'session' => $session]);
                if (!$has) {
                    $this->em->persist(
                        (new DepartmentHead())->setStaff($staff)->setDepartment($departments[$hodOf])->setSession($session)
                    );
                }
            }

            if ($ygmOf) {
                $has = $this->em->getRepository(YearGroupMaster::class)
                    ->findOneBy(['yearGroup' => $yearGroups[$ygmOf], 'session' => $session]);
                if (!$has) {
                    $this->em->persist(
                        (new YearGroupMaster())->setStaff($staff)->setYearGroup($yearGroups[$ygmOf])->setSession($session)
                    );
                }
            }
        }
        $this->em->flush();
        $io->writeln(sprintf('  staff: %d (incl. bursar + store)', count(self::TEACHERS) + 2));

        // 5b. Any department left without a head (the demo school has exactly
        //     three teachers carrying them, but re-running with fewer should not
        //     leave a hole in the approval chain).
        foreach ($departments as $name => $department) {
            $has = $this->em->getRepository(DepartmentHead::class)
                ->findOneBy(['department' => $department, 'session' => $session]);
            if (!$has) {
                $this->em->persist(
                    (new DepartmentHead())->setStaff(reset($staffByClass))
                        ->setDepartment($department)->setSession($session)
                );
            }
        }
        $this->em->flush();

        // 6. Fee items + a price per class for this term ------------------------
        $priced = 0;
        foreach (self::FEE_ITEMS as $position => [$itemName, $optional, $prices]) {
            $item = $this->em->getRepository(FeeItem::class)->findOneBy(['name' => $itemName]);
            if (!$item) {
                $item = (new FeeItem())->setName($itemName);
                $this->em->persist($item);
            }
            $item->setIsOptional($optional)->setPosition($position)->setFrequency('TERM');

            foreach ($classrooms as $className => $room) {
                $index = max(0, min(2, (int) substr($room->getYearGroup()->getName(), -1) - 1));
                $exists = $this->em->getRepository(FeeStructure::class)
                    ->findOneBy(['classroom' => $room, 'term' => $term, 'feeItem' => $item]);
                if ($exists) {
                    continue;
                }
                $this->em->persist(
                    (new FeeStructure())->setFeeItem($item)->setClassroom($room)->setTerm($term)
                        ->setAmount((string) $prices[$index])
                );
                $priced++;
            }
        }
        $this->em->flush();
        $io->writeln(sprintf('  fee items: %d · prices added: %d', count(self::FEE_ITEMS), $priced));

        $io->success('Demo school ready. Log in as the principal, or as any teacher below.');
        $io->table(
            ['Role', 'Email', 'Password'],
            [
                ['Principal (admin)', 'admin@gakonline.ng', self::DEFAULT_PASSWORD],
                ['Bursar', 'bursar@gakonline.ng', self::DEFAULT_PASSWORD],
                ['Store keeper', 'store@gakonline.ng', self::DEFAULT_PASSWORD],
                ['Form teacher + HOD Sciences + YGM JSS 1', 'adamu.bello@gakonline.ng', self::DEFAULT_PASSWORD],
                ['Form teacher JSS 1B + HOD Languages', 'zainab.yusuf@gakonline.ng', self::DEFAULT_PASSWORD],
            ]
        );

        return Command::SUCCESS;
    }

    /** Point the shared tenant connection at one school's database. */
    private function switchToTenant(string $subdomain): void
    {
        $school = $this->landlordEm->getRepository(LandlordSchool::class)->findOneBy(['subdomain' => $subdomain]);
        if (!$school) {
            throw new \RuntimeException("No landlord school with subdomain '$subdomain'.");
        }

        $this->tenantConnection->close();
        $params = $this->tenantConnection->getParams();
        $params['dbname'] = $school->getDatabaseName();

        $property = (new \ReflectionObject($this->tenantConnection))->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($this->tenantConnection, $params);
    }

    /** @param list<string> $roles */
    private function ensureStaff(
        School $school,
        string $fullName,
        string $firstName,
        string $lastName,
        string $email,
        string $title,
        string $gender,
        array $roles,
        ?string $employeeNumber,
    ): Staff {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $user = (new User())->setEmail($email);
            $this->em->persist($user);
        }
        $user->setFullName($fullName)
            ->setRoles($roles)
            ->setPassword($this->hasher->hashPassword($user, self::DEFAULT_PASSWORD));
        if (method_exists($user, 'setSchool')) {
            $user->setSchool($school);
        }

        $staff = $this->em->getRepository(Staff::class)->findOneBy(['user' => $user]);
        if (!$staff) {
            $staff = (new Staff())->setUser($user);
            $this->em->persist($staff);
        }
        $staff->setTitle($title)->setGender($gender);
        if ($employeeNumber !== null) {
            $staff->setEmployeeNumber($employeeNumber);
        }

        return $staff;
    }
}
