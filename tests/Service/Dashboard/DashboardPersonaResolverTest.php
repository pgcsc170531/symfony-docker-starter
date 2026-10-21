<?php

namespace App\Tests\Service\Dashboard;

use App\Entity\Tenant\User;
use App\Service\Dashboard\DashboardPersona;
use App\Service\Dashboard\DashboardPersonaResolver;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's "who am I?" rule.
 *
 * These assertions are mostly about ONE trap: ROLE_ADMIN inherits ROLE_BURSAR,
 * ROLE_STORE and ROLE_TEACHER through security.yaml, so an administrator answers
 * `isGranted('ROLE_BURSAR')` with true. If the label were decided with
 * `isGranted`, every principal would be told they are the bursar.
 */
final class DashboardPersonaResolverTest extends TestCase
{
    private DashboardPersonaResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DashboardPersonaResolver();
    }

    private function user(string ...$roles): User
    {
        $user = new User();
        $user->setEmail('someone@school.test');
        $user->setRoles($roles);

        return $user;
    }

    public function test_administrator_is_labelled_admin_even_though_it_inherits_bursar(): void
    {
        $persona = $this->resolver->resolve($this->user(User::ROLE_ADMIN));

        self::assertSame(DashboardPersona::ADMIN, $persona->key);
        self::assertSame('Administrator', $persona->label);
        self::assertTrue($persona->isAdmin);
        self::assertTrue($persona->seesWholeSchool());
        self::assertTrue($persona->canSeeFinance);
        self::assertTrue($persona->canSeeStore);
        self::assertTrue($persona->canSeeTeaching);
        self::assertFalse($persona->isSuperAdmin);
    }

    public function test_bursar_sees_money_but_not_the_store_or_results(): void
    {
        $persona = $this->resolver->resolve($this->user('ROLE_BURSAR'));

        self::assertSame(DashboardPersona::BURSAR, $persona->key);
        self::assertSame('Bursar', $persona->label);
        self::assertTrue($persona->canSeeFinance);
        self::assertFalse($persona->canSeeStore);
        self::assertFalse($persona->canSeeTeaching);
        self::assertFalse($persona->seesWholeSchool());
    }

    public function test_store_keeper_sees_inventory_and_no_money(): void
    {
        $persona = $this->resolver->resolve($this->user('ROLE_STORE'));

        self::assertSame(DashboardPersona::STORE, $persona->key);
        self::assertSame('Store Keeper', $persona->label);
        self::assertTrue($persona->canSeeStore);
        self::assertFalse($persona->canSeeFinance);
        self::assertFalse($persona->canSeeTeaching);
    }

    public function test_subject_teacher_never_sees_finance(): void
    {
        $persona = $this->resolver->resolve($this->user(User::ROLE_TEACHER));

        self::assertSame(DashboardPersona::TEACHER, $persona->key);
        self::assertSame('Subject Teacher', $persona->label);
        self::assertTrue($persona->canSeeTeaching);
        self::assertFalse($persona->canSeeFinance);
        self::assertFalse($persona->seesWholeSchool());
    }

    public function test_a_teacher_with_a_class_is_labelled_form_teacher(): void
    {
        $persona = $this->resolver->resolve(
            $this->user(User::ROLE_TEACHER),
            hasFormClass: true
        );

        self::assertSame(DashboardPersona::FORM_TEACHER, $persona->key);
        self::assertSame('Form Teacher', $persona->label);
        self::assertTrue($persona->isFormTeacher);
        self::assertFalse($persona->isHod);
    }

    public function test_a_department_head_outranks_a_form_class(): void
    {
        $persona = $this->resolver->resolve(
            $this->user(User::ROLE_TEACHER),
            hasFormClass: true,
            isDepartmentHead: true
        );

        self::assertSame(DashboardPersona::HOD, $persona->key);
        self::assertSame('Head of Department', $persona->label);
        // Still a form teacher — the seat is named after the senior job, but the
        // class panel must still be drawn.
        self::assertTrue($persona->isFormTeacher);
    }

    public function test_a_year_group_master_is_named_as_such(): void
    {
        $persona = $this->resolver->resolve(
            $this->user(User::ROLE_TEACHER),
            isYearGroupMaster: true
        );

        self::assertSame(DashboardPersona::YEAR_GROUP_MASTER, $persona->key);
        self::assertSame('Year Group Master', $persona->label);
        self::assertTrue($persona->isYearGroupMaster);
    }

    public function test_the_school_overseer_sees_everything(): void
    {
        $persona = $this->resolver->resolve($this->user(User::ROLE_TENANT_SUPER_ADMIN));

        self::assertSame(DashboardPersona::SUPER_ADMIN, $persona->key);
        self::assertSame('Super Admin', $persona->label);
        self::assertTrue($persona->isSuperAdmin);
        self::assertTrue($persona->seesWholeSchool());
        self::assertTrue($persona->canSeeFinance);
        self::assertTrue($persona->canSeeStore);
        self::assertTrue($persona->canSeeTeaching);
    }

    public function test_a_parent_gets_the_parent_seat_and_no_staff_panels(): void
    {
        $persona = $this->resolver->resolve($this->user('ROLE_PARENT'));

        self::assertSame(DashboardPersona::PARENT, $persona->key);
        self::assertTrue($persona->isParent);
        self::assertFalse($persona->canSeeFinance);
        self::assertFalse($persona->canSeeStore);
        self::assertFalse($persona->canSeeTeaching);
    }

    public function test_an_account_with_no_job_title_is_staff_and_sees_nothing_sensitive(): void
    {
        $persona = $this->resolver->resolve($this->user());

        self::assertSame(DashboardPersona::STAFF, $persona->key);
        self::assertFalse($persona->seesWholeSchool());
        self::assertFalse($persona->canSeeFinance);
        self::assertFalse($persona->canSeeStore);
        self::assertFalse($persona->canSeeTeaching);
    }
}
