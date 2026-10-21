<?php

namespace App\Service\Dashboard;

use App\Entity\Tenant\User;

/**
 * Who is looking at the dashboard, and what they are allowed to see there.
 *
 * The dashboard used to be one page for everybody: a teacher was shown the
 * school's wallet balance, the store keeper was shown outstanding fees, and the
 * bursar was shown result approvals. Every one of those is somebody else's job —
 * and, on a school's account, some of it is somebody else's business.
 *
 * A person can hold more than one responsibility at once (a form teacher also
 * teaches three subjects; an HOD also marks). So this is a set of capabilities
 * with one *label* — the label names the seat the person is sitting in when they
 * sign in, and the capabilities decide which panels are drawn.
 */
final class DashboardPersona
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const BURSAR = 'bursar';
    public const ADMISSION_OFFICER = 'admission_officer';
    public const STORE = 'store';
    public const HOD = 'hod';
    public const YEAR_GROUP_MASTER = 'year_group_master';
    public const FORM_TEACHER = 'form_teacher';
    public const TEACHER = 'teacher';
    public const PARENT = 'parent';
    public const STAFF = 'staff';

    public function __construct(
        /** Which seat the header names. */
        public readonly string $key,
        public readonly string $label,
        /** One line under the label — what this seat is for. */
        public readonly string $headline,
        /** The school's overseer: sees every panel on this dashboard. */
        public readonly bool $isSuperAdmin,
        /** Administrator (principal / proprietor): whole-school oversight. */
        public readonly bool $isAdmin,
        /** May see money: fees, collections, debtors, expenses. */
        public readonly bool $canSeeFinance,
        /** May review and enrol applications (administrator or admissions desk). */
        public readonly bool $canSeeAdmissions,
        /** May see inventory and store sales. */
        public readonly bool $canSeeStore,
        /** May see the teaching panels (subject sheets, approvals). */
        public readonly bool $canSeeTeaching,
        /** Holds a form class for the active term. */
        public readonly bool $isFormTeacher,
        /** Heads a department for the active session. */
        public readonly bool $isHod,
        /** Masters a year group for the active session. */
        public readonly bool $isYearGroupMaster,
        public readonly bool $isParent,
    ) {
    }

    public function is(string $key): bool
    {
        return $this->key === $key;
    }

    /** True when this account should be shown the whole-school panels. */
    public function seesWholeSchool(): bool
    {
        return $this->isAdmin || $this->isSuperAdmin;
    }

    /** A short nudge used on the dashboard header. */
    public function isOverseer(): bool
    {
        return $this->isSuperAdmin;
    }

    public static function roleConstant(string $key): string
    {
        return match ($key) {
            self::SUPER_ADMIN => User::ROLE_TENANT_SUPER_ADMIN,
            self::ADMIN => User::ROLE_ADMIN,
            self::BURSAR => 'ROLE_BURSAR',
            self::ADMISSION_OFFICER => User::ROLE_ADMISSION_OFFICER,
            self::STORE => 'ROLE_STORE',
            self::HOD => User::ROLE_HOD,
            self::TEACHER => User::ROLE_TEACHER,
            self::PARENT => 'ROLE_PARENT',
            default => 'ROLE_USER',
        };
    }
}
