<?php

namespace App\Tests\Service\Results;

use App\Entity\Tenant\AssessmentScheme;
use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\School;
use App\Entity\Tenant\Session;
use App\Entity\Tenant\Term;
use App\Service\Results\AssessmentSchemeResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * The marking scheme belongs to the school, not to the code.
 *
 * These maxima were hard-coded (CA out of 20, exam out of 60, practical out of 40),
 * so a school that marks CA out of 15 and runs a 50-mark exam beside a 20-mark
 * practical could not be configured at all — its report card printed a total out
 * of 120 whatever it did. The resolver must read the school's own row first and
 * only invent numbers when the school has never saved any.
 */
final class AssessmentSchemeResolverTest extends TestCase
{
    /**
     * @param list<AssessmentScheme> $schemes rows the repository will return
     */
    private function resolver(?School $school = null, array $schemes = []): AssessmentSchemeResolver
    {
        $schemeRepo = $this->createMock(EntityRepository::class);
        $schemeRepo->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($schemes): ?AssessmentScheme {
                foreach ($schemes as $scheme) {
                    $matches = true;
                    foreach ($criteria as $field => $value) {
                        $matches = $matches && match ($field) {
                            'term' => $scheme->getTerm() === $value,
                            'classroom' => $scheme->getClassroom() === $value,
                            'subject' => $scheme->getSubject() === $value,
                            default => false,
                        };
                    }
                    if ($matches) {
                        return $scheme;
                    }
                }

                return null;
            }
        );

        $schoolRepo = $this->createMock(EntityRepository::class);
        $schoolRepo->method('findOneBy')->willReturn($school);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => match ($class) {
                AssessmentScheme::class => $schemeRepo,
                School::class => $schoolRepo,
                default => $this->createMock(EntityRepository::class),
            }
        );

        return new AssessmentSchemeResolver($em);
    }

    private function term(): Term
    {
        return (new Term())->setSession((new Session())->setName('2026/2027'));
    }

    public function testASchoolThatNeverSavedAnythingFallsBackToTheUsualScheme(): void
    {
        $scheme = $this->resolver()->defaults();

        self::assertSame(3, $scheme['ca_count']);
        self::assertSame(20, $scheme['ca_max']);
        self::assertSame(60, $scheme['theory_max']);
    }

    public function testTheNumberOfCasComesFromTheSchoolSetting(): void
    {
        $school = (new School())->setName('Demo')->setCaCount(AssessmentScheme::CA_COUNT_TWO);

        self::assertSame(2, $this->resolver($school)->defaults()['ca_count']);
    }

    public function testTheSchoolsOwnMaximaAreUsedForAClassWithNoSchemeOfItsOwn(): void
    {
        $term = $this->term();

        $saved = (new AssessmentScheme())
            ->setSession($term->getSession())
            ->setTerm($term)
            ->setCaCount(2)
            ->setCaMax(15)
            ->setExamMode(AssessmentScheme::EXAM_MODE_DUAL)
            ->setTheoryMax(50)
            ->setPracticalMax(20);

        $scheme = $this->resolver(null, [$saved])->forClassroom(new Classroom(), $term);

        self::assertSame(2, $scheme['ca_count']);
        self::assertSame(15, $scheme['ca_max']);
        self::assertSame(50, $scheme['theory_max']);
        self::assertSame(20, $scheme['practical_max']);
        self::assertSame(AssessmentScheme::EXAM_MODE_DUAL, $scheme['exam_mode']);
    }

    public function testATotalsOutOfWhateverTheSchoolSaved(): void
    {
        $resolver = $this->resolver();

        $dual = [
            'ca_count' => 2,
            'exam_mode' => AssessmentScheme::EXAM_MODE_DUAL,
            'ca_max' => 15,
            'theory_max' => 50,
            'practical_max' => 20,
        ];

        // 2 × 15 + 50 + 20
        self::assertSame(100.0, $resolver->maximum($dual));

        // A single-paper scheme ignores the practical entirely.
        $single = $dual;
        $single['exam_mode'] = AssessmentScheme::EXAM_MODE_SINGLE;
        self::assertSame(80.0, $resolver->maximum($single));
    }

    public function testOnlyTheComponentsOfTheSchemeAreCounted(): void
    {
        $resolver = $this->resolver();

        $twoCas = [
            'ca_count' => 2,
            'exam_mode' => AssessmentScheme::EXAM_MODE_SINGLE,
            'ca_max' => 15,
            'theory_max' => 70,
            'practical_max' => 0,
        ];

        self::assertSame(['ca1', 'ca2', 'theory'], $resolver->components($twoCas));

        // A mark left over from a dropped CA 3 must not be added in.
        self::assertSame(
            62.0,
            $resolver->total($twoCas, ['ca1' => 10, 'ca2' => 12, 'ca3' => 15, 'theory' => 40])
        );
    }

    public function testTheColumnsCarryTheSchoolsMaxima(): void
    {
        $resolver = $this->resolver();

        $columns = $resolver->columns([
            'ca_count' => 3,
            'exam_mode' => AssessmentScheme::EXAM_MODE_DUAL,
            'ca_max' => 10,
            'theory_max' => 60,
            'practical_max' => 30,
        ]);

        self::assertSame(
            [
                ['component' => 'ca1', 'label' => 'CA 1', 'max' => 10],
                ['component' => 'ca2', 'label' => 'CA 2', 'max' => 10],
                ['component' => 'ca3', 'label' => 'CA 3', 'max' => 10],
                ['component' => 'theory', 'label' => 'Exams', 'max' => 60],
                ['component' => 'practical', 'label' => 'Practical', 'max' => 30],
            ],
            $columns
        );
    }
}
