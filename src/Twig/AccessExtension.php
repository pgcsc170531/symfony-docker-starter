<?php

namespace App\Twig;

use App\Entity\Tenant\DepartmentHead;
use App\Entity\Tenant\FormTeacher;
use App\Entity\Tenant\School;
use App\Entity\Tenant\YearGroupMaster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes assignment-based visibility helpers to templates.
 *
 * A "special academic role" (Form Teacher, HOD, Year Group Master) is not a
 * login role — it is an assignment given to a teacher. These helpers check the
 * current user's Staff profile for such assignments.
 */
class AccessExtension extends AbstractExtension
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_approval_assignment', [$this, 'hasApprovalAssignment']),
            new TwigFunction('tenant_school', [$this, 'tenantSchool']),
            new TwigFunction('school_logo_url', [$this, 'schoolLogoUrl']),
        ];
    }

    /**
     * The school's uploaded logo — or null when the file is not there any more.
     *
     * `logo_filename` outlives the file: a re-upload, a restored backup or a
     * copied database all leave a name behind that 404s, and the result is a
     * broken-image glyph on every letterhead, ID card and receipt. `asset()`
     * will happily build a URL for a file that does not exist, so the only
     * honest answer is to look at the disk.
     */
    public function schoolLogoUrl(?string $filename): ?string
    {
        $filename = trim((string) $filename);

        // A filename is not a path. Anything with a separator or a dot-dot is
        // not something this helper should turn into a URL.
        if ($filename === '' || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return null;
        }

        return is_file($this->projectDir.'/public/uploads/logos/'.$filename)
            ? '/uploads/logos/'.$filename
            : null;
    }

    /**
     * The school whose portal we are rendering — for branding the app header.
     *
     * `app.user.tenant` is only set on accounts created through the tenant
     * console, so parent logins (created by bulk imports and the application
     * flow) walk around with it null. Falling back to the single row in
     * `school_settings` means every user, on every tenant host, sees their own
     * school's name rather than the platform's.
     *
     * Returns null on landlord hosts, where the tenant database is not the one
     * in play — the caller must cope.
     */
    public function tenantSchool(): ?School
    {
        try {
            return $this->em->getRepository(School::class)->findOneBy([]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when the logged-in user is a teacher assigned as a
     * Form Teacher, HOD (Department Head) or Year Group Master.
     */
    public function hasApprovalAssignment(): bool
    {
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return false;
        }

        $user = $token->getUser();
        if (!is_object($user) || !method_exists($user, 'getStaff')) {
            return false;
        }

        $staff = $user->getStaff();
        if (!$staff) {
            return false;
        }

        if ($this->em->getRepository(FormTeacher::class)->findOneBy(['staff' => $staff])) {
            return true;
        }
        if ($this->em->getRepository(DepartmentHead::class)->findOneBy(['staff' => $staff])) {
            return true;
        }
        if ($this->em->getRepository(YearGroupMaster::class)->findOneBy(['staff' => $staff])) {
            return true;
        }

        return false;
    }
}
