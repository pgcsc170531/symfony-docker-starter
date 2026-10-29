<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\YearGroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/classrooms')]
#[IsGranted('ROLE_ADMIN')]
class ClassroomController extends AbstractController
{
    #[Route('/', name: 'app_tenant_classroom_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        // 1. HANDLE SMART ADD FORM
        if ($request->isMethod('POST')) {
            $level = trim((string) $request->request->get('name'));

            if ($level === '') {
                $this->addFlash('error', 'Give the class a level name first — for example JSS 1.');

                return $this->redirectToRoute('app_tenant_classroom_index');
            }

            $yearGroup = $this->getOrCreateYearGroup($em, $level);
            $created = 0;

            /*
             * Manual entry: one class, one arm, typed exactly as the school says
             * it. The bulk box is for "JSS 1 with A, B, C"; this is for the single
             * odd arm nobody planned for — "JSS 1 Gold".
             */
            if ('manual' === $request->request->get('mode')) {
                $arm = trim((string) $request->request->get('arm'));
                $fullName = $arm === '' ? $level : $this->joinArm($level, $arm);
                $created = $this->createClassroomIfNotExists($em, $fullName, $yearGroup, $arm);

                $em->flush();

                $this->addFlash(
                    $created > 0 ? 'success' : 'info',
                    $created > 0
                        ? sprintf('%s added.', $fullName)
                        : sprintf('%s already exists — nothing was added.', $fullName)
                );

                return $this->redirectToRoute('app_tenant_classroom_index');
            }

            /*
             * Append to a level that already exists: the school added a fourth
             * arm mid-term. The level comes from the card the form sits in, so it
             * cannot be mistyped, and an existing arm is skipped rather than
             * reported as a failure.
             */
            if ('append' === $request->request->get('mode')) {
                $arms = $this->parseArms((string) $request->request->get('arm'));

                if ($arms === []) {
                    $this->addFlash('error', sprintf('Type at least one arm to add to %s.', $level));

                    return $this->redirectToRoute('app_tenant_classroom_index');
                }

                $added = [];
                foreach ($arms as $arm) {
                    $fullName = $this->joinArm($level, $arm);
                    if ($this->createClassroomIfNotExists($em, $fullName, $yearGroup, $arm) > 0) {
                        $added[] = $fullName;
                    }
                }

                $em->flush();

                $this->addFlash(
                    $added !== [] ? 'success' : 'info',
                    $added !== []
                        ? sprintf('Added %s under %s.', implode(', ', $added), $level)
                        : sprintf('Those arms already exist under %s — nothing was added.', $level)
                );

                return $this->redirectToRoute('app_tenant_classroom_index');
            }

            $arms = $this->parseArms((string) $request->request->get('arms'));

            // No arms listed means "one class, exactly as typed" — a nursery has
            // no arms, and an empty arm must never produce "JSS 1 ".
            foreach ($arms === [] ? [null] : $arms as $arm) {
                $fullName = $arm === null ? $level : $this->joinArm($level, $arm);
                $created += $this->createClassroomIfNotExists($em, $fullName, $yearGroup, $arm);
            }

            $em->flush();

            $this->addFlash(
                $created > 0 ? 'success' : 'info',
                $created > 0
                    ? sprintf('%d class%s added under %s.', $created, $created === 1 ? '' : 'es', $level)
                    : sprintf('Every class you listed under %s already exists — nothing was added.', $level)
            );

            return $this->redirectToRoute('app_tenant_classroom_index');
        }

        // 2. FETCH AND GROUP CLASSROOMS
        $classrooms = $em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']);

        /*
         * Group by the year group the class was created under, not by guessing
         * from the name. The old heuristic ("last word is one or two letters, or
         * a colour") silently gave "JSS 1 Gold" its own card the moment a school
         * called an arm something else. The year group is exact; the heuristic
         * survives only as a fallback for classes created before year groups.
         */
        $grouped = [];
        foreach ($classrooms as $classroom) {
            $group = $classroom->getYearGroup()?->getName()
                ?? $this->guessGroupKey((string) $classroom->getName());
            $grouped[$group][] = $classroom;
        }

        uksort($grouped, 'strnatcasecmp');
        foreach ($grouped as $group => $members) {
            usort(
                $members,
                static fn (Classroom $a, Classroom $b): int => strnatcasecmp((string) $a->getName(), (string) $b->getName())
            );
            $grouped[$group] = $members;
        }

        return $this->render('tenant/classroom/index.html.twig', [
            'groupedClassrooms' => $grouped,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_tenant_classroom_delete', methods: ['POST'])]
    public function delete(Request $request, Classroom $classroom, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$classroom->getId(), $request->request->get('_token'))) {
            try {
                $em->remove($classroom);
                $em->flush();
                $this->addFlash('success', 'Classroom deleted successfully.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Cannot delete this class. It probably has students or records attached.');
            }
        }

        return $this->redirectToRoute('app_tenant_classroom_index');
    }

    /**
     * Rename one class's arm — "JSS 1 A" becomes "JSS 1 Gold", "JSS 1 Blue",
     * "JSS 1 Science"… whatever the school actually calls it.
     *
     * Only the arm is editable: the level comes from the year group, so a rename
     * can never silently move a class under a different level or make two levels
     * collide. Classes created before year groups existed have no level to anchor
     * to, so those fall back to a single full-name field.
     */
    #[Route('/{id}/edit', name: 'app_tenant_classroom_edit', methods: ['POST'])]
    public function edit(Request $request, Classroom $classroom, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('edit'.$classroom->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'That change expired. Please try again.');

            return $this->redirectToRoute('app_tenant_classroom_index');
        }

        $level = $classroom->getYearGroup()?->getName();
        $arm = null;

        if ($level !== null) {
            $arm = trim((string) $request->request->get('arm'));
            $newName = $arm === '' ? $level : $this->joinArm($level, $arm);
        } else {
            $newName = trim((string) $request->request->get('name'));
        }

        if ($newName === '') {
            $this->addFlash('error', 'A class cannot have an empty name.');

            return $this->redirectToRoute('app_tenant_classroom_index');
        }

        $clash = $em->getRepository(Classroom::class)->findOneBy(['name' => $newName]);
        if ($clash !== null && $clash->getId() !== $classroom->getId()) {
            $this->addFlash('error', sprintf('%s already exists.', $newName));

            return $this->redirectToRoute('app_tenant_classroom_index');
        }

        $classroom->setName($newName);
        if ($level !== null) {
            $classroom->setArm($arm === '' ? null : $arm);
        }
        $em->flush();

        $this->addFlash('success', sprintf('Renamed to %s.', $newName));

        return $this->redirectToRoute('app_tenant_classroom_index');
    }

    /**
     * Turn the arms box into a list of arm names.
     *
     * "A, B, C" and "Gold, Silver" are equally valid — the arms are whatever the
     * school calls them. A bare number is still honoured, so a cached copy of the
     * old form ("4 arms") keeps doing what it used to.
     *
     * @return list<string>
     */
    private function parseArms(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return [];
        }

        if (preg_match('/^\d{1,2}$/', $input) === 1) {
            $count = min((int) $input, 26);

            return $count > 0 ? array_slice(range('A', 'Z'), 0, $count) : [];
        }

        $arms = array_map(trim(...), explode(',', $input));
        $arms = array_filter($arms, static fn (string $arm): bool => $arm !== '');

        return array_values(array_unique($arms));
    }

    /** "JSS 1" + "Gold" → "JSS 1 Gold", clamped to the column's 255 characters. */
    private function joinArm(string $level, string $arm): string
    {
        $room = max(0, 255 - mb_strlen($level) - 1);

        return trim($level . ' ' . mb_substr($arm, 0, $room));
    }

    /**
     * Last-resort grouping for classes that predate year groups: "JSS 1 A" →
     * "JSS 1". Only used when there is no year group to trust.
     */
    private function guessGroupKey(string $rawName): string
    {
        $parts = explode(' ', $rawName);
        if (count($parts) < 2) {
            return $rawName;
        }

        $last = (string) end($parts);
        if ((mb_strlen($last) <= 2 && !is_numeric($last))
            || in_array(mb_strtolower($last), ['gold', 'silver', 'bronze', 'blue', 'red', 'green'], true)) {
            array_pop($parts);

            return implode(' ', $parts);
        }

        return $rawName;
    }

    /** Creates the class when it is new; returns 1 when it created a row, 0 when it already existed. */
    private function createClassroomIfNotExists(
        EntityManagerInterface $em,
        string $name,
        ?YearGroup $yearGroup = null,
        ?string $arm = null,
    ): int {
        $classroom = $em->getRepository(Classroom::class)->findOneBy(['name' => $name]);

        if (!$classroom) {
            $classroom = new Classroom();
            $classroom->setName($name);
            $classroom->setArm($arm === '' ? null : $arm);
            if ($yearGroup) {
                $classroom->setYearGroup($yearGroup);
            }
            $em->persist($classroom);

            return 1;
        }

        if ($yearGroup && !$classroom->getYearGroup()) {
            $classroom->setYearGroup($yearGroup);
        }

        // Classes created before the arm column was filled in say "JSS 1 Gold" in
        // the name and nothing in the arm; adopt the arm the school just typed so
        // the record stops disagreeing with itself.
        if ($classroom->getArm() === null && $arm !== null && $arm !== '') {
            $classroom->setArm($arm);
        }

        return 0;
    }

    private function getOrCreateYearGroup(EntityManagerInterface $em, string $name): YearGroup
    {
        $yearGroup = $em->getRepository(YearGroup::class)->findOneBy(['name' => $name]);
        if (!$yearGroup) {
            $yearGroup = new YearGroup();
            $yearGroup->setName($name);
            $em->persist($yearGroup);
        }

        return $yearGroup;
    }
}