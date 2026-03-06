<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
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
            $name = trim($request->request->get('name')); // e.g. "JSS 1"
            $arms = (int) $request->request->get('arms'); // e.g. 4
            
            if ($name) {
                $suffixes = range('A', 'Z'); // A, B, C...
                $count = 0;

                // If user selected arms (1-10), generate JSS 1A, JSS 1B...
                if ($arms > 0) {
                    for ($i = 0; $i < $arms; $i++) {
                        // Safety check for > 26 arms
                        $suffix = $suffixes[$i] ?? $i;
                        $fullName = $name . ' ' . $suffix;
                        
                        $this->createClassroomIfNotExists($em, $fullName);
                        $count++;
                    }
                } else {
                    // No arms selected (e.g. "Creche"), just save the name as is
                    $this->createClassroomIfNotExists($em, $name);
                    $count++;
                }

                $em->flush();
                $this->addFlash('success', "Success! Created $count classroom(s) for '$name'.");
                return $this->redirectToRoute('app_tenant_classroom_index');
            }
        }

        // 2. FETCH AND GROUP CLASSROOMS (For nice display)
        $classrooms = $em->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']);
        
        // Grouping Logic (e.g. "JSS 1" => ["JSS 1A", "JSS 1B"])
        $grouped = [];
        foreach ($classrooms as $c) {
            $rawName = $c->getName();
            $parts = explode(' ', $rawName);
            
            // Heuristic: If last part is single letter or specific color, group by prefix
            if (count($parts) > 1) {
                $last = end($parts);
                if ((strlen($last) <= 2 && !is_numeric($last)) || in_array(strtolower($last), ['gold', 'silver', 'bronze', 'blue', 'red', 'green'])) {
                    array_pop($parts);
                    $groupKey = implode(' ', $parts);
                } else {
                    $groupKey = $rawName;
                }
            } else {
                $groupKey = $rawName;
            }
            $grouped[$groupKey][] = $c;
        }
        ksort($grouped, SORT_NATURAL);

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

    // Helper to prevent duplicates
    private function createClassroomIfNotExists(EntityManagerInterface $em, string $name): void
    {
        $exists = $em->getRepository(Classroom::class)->findOneBy(['name' => $name]);
        if (!$exists) {
            $c = new Classroom();
            $c->setName($name);
            $em->persist($c);
        }
    }
}