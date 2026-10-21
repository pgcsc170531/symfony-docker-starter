<?php

namespace App\Service\Dashboard;

use App\Entity\Tenant\User;

/**
 * Turns an account into a {@see DashboardPersona}.
 *
 * Deliberately pure: it reads the account's stored roles and the three
 * assignment flags it is handed, and does no database work. That makes the
 * "which dashboard does this person get?" rule unit-testable without a kernel —
 * and it is the rule most likely to be got wrong, because role hierarchy makes
 * `isGranted('ROLE_BURSAR')` true for an administrator.
 *
 * Order matters. ROLE_ADMIN inherits BURSAR + STORE + TEACHER, so an
 * administrator would otherwise be labelled "Bursar". The label therefore reads
 * the account's *stored* roles; the capability flags read what they inherit.
 */
final class DashboardPersonaResolver
{
    /**
     * @param bool $hasFormClass      form teacher for the active term
     * @param bool $isDepartmentHead  department head for the active session
     * @param bool $isYearGroupMaster year group master for the active session
     */
    public function resolve(
        User $user,
        bool $hasFormClass = false,
        bool $isDepartmentHead = false,
        bool $isYearGroupMaster = false,
    ): DashboardPersona {
        $stored = $user->getRoles();

        $has = static fn (string $role): bool => in_array($role, $stored, true);

        $isSuperAdmin = $has(User::ROLE_TENANT_SUPER_ADMIN);
        $isAdmin = $isSuperAdmin || $has(User::ROLE_ADMIN);

        // What the account may see, through the hierarchy.
        $canSeeFinance = $isAdmin || $has('ROLE_BURSAR');
        $canSeeStore = $isAdmin || $has('ROLE_STORE');
        $isAdmissionsOfficer = $has(User::ROLE_ADMISSION_OFFICER);
        $canSeeAdmissions = $isAdmin || $isAdmissionsOfficer;
        $isTeacher = $isAdmin || $has(User::ROLE_TEACHER) || $has(User::ROLE_HOD);
        $isParent = $has('ROLE_PARENT') && !$isTeacher && !$isAdmin;

        // Which seat to name. First match wins.
        [$key, $label, $headline] = match (true) {
            $isSuperAdmin => [
                DashboardPersona::SUPER_ADMIN,
                'Super Admin',
                'Overseer — every part of this school, end to end',
            ],
            $has(User::ROLE_ADMIN) => [
                DashboardPersona::ADMIN,
                'Administrator',
                'Whole-school oversight: enrolment, fees, results and staff',
            ],
            $has('ROLE_BURSAR') => [
                DashboardPersona::BURSAR,
                'Bursar',
                'Fees, collections, expenses and the school wallet',
            ],
            // The admissions desk sits directly under the administrator: it is
            // the second, and usually the more regular, hand on the register.
            $isAdmissionsOfficer => [
                DashboardPersona::ADMISSION_OFFICER,
                'Admission Officer',
                'New intakes: applications to review, offers and enrolment',
            ],
            $has('ROLE_STORE') => [
                DashboardPersona::STORE,
                'Store Keeper',
                'Inventory, prices and store sales',
            ],
            $isDepartmentHead || $has(User::ROLE_HOD) => [
                DashboardPersona::HOD,
                'Head of Department',
                'Your department’s subjects and result approvals',
            ],
            $isYearGroupMaster => [
                DashboardPersona::YEAR_GROUP_MASTER,
                'Year Group Master',
                'Results and approvals across your year group',
            ],
            $hasFormClass => [
                DashboardPersona::FORM_TEACHER,
                'Form Teacher',
                'Your class’s attendance, behaviour and remarks',
            ],
            $isTeacher => [
                DashboardPersona::TEACHER,
                'Subject Teacher',
                'Your subjects, marks and submissions',
            ],
            $isParent => [
                DashboardPersona::PARENT,
                'Parent',
                'Your child’s applications, results and fees',
            ],
            default => [
                DashboardPersona::STAFF,
                'Staff',
                'This school’s portal',
            ],
        };

        return new DashboardPersona(
            key: $key,
            label: $label,
            headline: $headline,
            isSuperAdmin: $isSuperAdmin,
            isAdmin: $isAdmin,
            canSeeFinance: $canSeeFinance,
            canSeeAdmissions: $canSeeAdmissions,
            canSeeStore: $canSeeStore,
            canSeeTeaching: $isTeacher,
            isFormTeacher: $hasFormClass,
            isHod: $isDepartmentHead || $has(User::ROLE_HOD),
            isYearGroupMaster: $isYearGroupMaster,
            isParent: $isParent,
        );
    }
}
