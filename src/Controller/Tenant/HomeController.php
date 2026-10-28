<?php

namespace App\Controller\Tenant;

use App\Entity\Tenant\Classroom;
use App\Entity\Tenant\School;
use App\Entity\Tenant\SchoolEvent;
use App\Entity\Tenant\Student;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class HomeController extends AbstractController
{
    private function getSchool(EntityManagerInterface $entityManager): ?School
    {
        // The tenant EntityManager is already connected to school_{subdomain}.
        return $entityManager->getRepository(School::class)->findOneBy([]);
    }

    /**
     * Convert the School entity into a plain array containing only scalar values.
     * This prevents Twig from receiving arrays/objects and triggering
     * "Array to string conversion" warnings.
     */
    private function schoolArray(?School $school): array
    {
        if (!$school) {
            return [];
        }

        $fields = [
            'name',
            'institutionType',
            // The short category tag ("Nursery", "Junior Secondary"…) that is printed
            // under the school's name on every public page. Derived, not a column —
            // listed here so the whitelist loop below resolves getTypeTag().
            'typeTag',
            'motto',
            'logoFilename',
            'address',
            'phoneNumber',
            'email',
            'website',
            'primaryColor',
            'heroTagline',
            'heroImageFilename',
            'aboutContent',
            'admissionsIntro',
            'mapEmbedUrl',
            'whatsappNumber',
            'feesSummary',
            'showStudentCount',
            'foundedYear',
            'facilities',
            'coreValues',
            'principalName',
            'principalTitle',
            'facebookUrl',
            'twitterUrl',
            'instagramUrl',
            // 🆕 Application Mode — lets the public pages link to /apply
            'applicationsOpen',
            'applicationsOpenAt',
            'applicationsCloseAt',
            'applicationInstructions',
            'applicationClassesNote',
            'applicationFeeAmount',
        ];

        $data = [];

        foreach ($fields as $field) {
            $getter = 'get' . ucfirst($field);
            $isser  = 'is' . ucfirst($field); // e.g. applicationsOpen → isApplicationsOpen()

            $value = match (true) {
                method_exists($school, $getter) => $school->$getter(),
                method_exists($school, $isser)  => $school->$isser(),
                default                         => null,
            };

            // Dates are objects; the public pages only need to print them.
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }

            $data[$field] = is_scalar($value) ? $value : null;
        }

        // Two free-text fields are lists in the eyes of the page: facilities
        // (one per line) and core values ("Title: what it means"). Parsing lives
        // here so every public template sees the same shape.
        $data['facilities_list'] = $this->lines($data['facilities'] ?? null);
        $data['core_values'] = $this->parseValues($data['coreValues'] ?? null);

        return $data;
    }

    /**
     * Split a "one per line" field into clean lines. Leading bullets are
     * stripped because schools type "- Library" as often as "Library".
     *
     * @return list<string>
     */
    private function lines(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line, " \t\n\r\0\x0B-•*·"),
                $lines),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * "Integrity: We do the right thing" → ['title' => 'Integrity', 'desc' => …].
     * A line with no separator is still a value — the title alone.
     *
     * @return list<array{title: string, desc: string}>
     */
    private function parseValues(?string $raw): array
    {
        $values = [];

        foreach ($this->lines($raw) as $line) {
            // Colon or an em/en dash separates the word from its meaning. A
            // plain hyphen is never a separator — it lives inside words.
            $parts = preg_split('/\s*[:\x{2013}\x{2014}]\s*/u', $line, 2) ?: [$line];
            $title = trim($parts[0]);
            if ($title === '') {
                continue;
            }
            $values[] = ['title' => $title, 'desc' => trim($parts[1] ?? '')];
        }

        // Until the school writes its own, the page still needs something to
        // say. These are fallbacks, not the school's words.
        return $values !== [] ? $values : [
            ['title' => 'Integrity', 'desc' => 'We do the right thing even when no one is watching.'],
            ['title' => 'Excellence', 'desc' => 'We hold every child to a standard worth reaching.'],
            ['title' => 'Respect', 'desc' => 'We honour every child, parent and member of staff.'],
            ['title' => 'Service', 'desc' => 'We raise people who are useful to their community.'],
        ];
    }

    #[Route('/', name: 'app_tenant_home', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function index(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        // If no students exist, this returns 0 — no error.
        $studentCount = $entityManager->getRepository(Student::class)->count([]);

        return $this->render('tenant/home/index.html.twig', [
            'school' => $this->schoolArray($school),
            'student_count' => $studentCount,
            'subdomain' => $subdomain,
        ]);
    }

    #[Route('/about', name: 'app_tenant_about', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function about(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        return $this->render('tenant/home/about.html.twig', [
            'school' => $this->schoolArray($school),
            'subdomain' => $subdomain,
        ]);
    }

    #[Route('/admissions', name: 'app_tenant_admissions', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function admissions(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        return $this->render('tenant/home/admissions.html.twig', [
            'school' => $this->schoolArray($school),
            'subdomain' => $subdomain,
        ]);
    }

    /**
     * The school's own classes, grouped into stages.
     *
     * This is deliberately built from `Classroom` rows — real classes the school
     * created — rather than a hard-coded "Nursery to SS3" brochure that would
     * lie about a school that only runs JSS. The stage is inferred from the
     * class name because that is what the school actually type in onboarding.
     */
    #[Route('/academics', name: 'app_tenant_academics', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function academics(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        $grouped = [];
        foreach ($entityManager->getRepository(Classroom::class)->findBy([], ['name' => 'ASC']) as $classroom) {
            $grouped[$this->stageOf((string) $classroom->getName())][] = $classroom->getName();
        }

        // Fixed order: a school is read from the bottom up.
        $stages = [];
        foreach (['Early Years', 'Primary', 'Junior Secondary', 'Senior Secondary', 'College', 'Other classes'] as $stage) {
            if (!empty($grouped[$stage])) {
                $stages[$stage] = array_values(array_unique($grouped[$stage]));
            }
        }

        return $this->render('tenant/home/academics.html.twig', [
            'school' => $this->schoolArray($school),
            'stages' => $stages,
            'subdomain' => $subdomain,
        ]);
    }

    /**
     * Upcoming events from the school calendar (`SchoolEvent`), newest first
     * from today. Past events are not hidden history a parent needs; an empty
     * calendar says so instead of showing a blank box.
     */
    #[Route('/events', name: 'app_tenant_events', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function events(EntityManagerInterface $entityManager, string $subdomain): Response
    {
        $school = $this->getSchool($entityManager);

        $events = $entityManager->getRepository(SchoolEvent::class)->createQueryBuilder('e')
            ->where('e.startDate >= :from')
            ->setParameter('from', new \DateTime('today'))
            ->orderBy('e.startDate', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->render('tenant/home/events.html.twig', [
            'school' => $this->schoolArray($school),
            'events' => $events,
            'subdomain' => $subdomain,
        ]);
    }

    /**
     * A per-school sitemap. Search engines reach the school through its own
     * subdomain, so Edus's base-domain sitemap can never list these pages.
     */
    #[Route('/sitemap.xml', name: 'app_tenant_sitemap', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function sitemap(string $subdomain): Response
    {
        $urls = [];
        foreach ([
            'app_tenant_home',
            'app_tenant_about',
            'app_tenant_academics',
            'app_tenant_admissions',
            'app_tenant_events',
            'app_tenant_contact',
            'app_tenant_apply_index',
        ] as $route) {
            $urls[] = $this->generateUrl($route, ['subdomain' => $subdomain], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $xml = $this->renderView('tenant/home/sitemap.xml.twig', [
            'urls' => $urls,
            'lastmod' => new \DateTimeImmutable('today'),
        ]);

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * Robots rules for a school subdomain: the marketing pages are fair game,
     * the portal and the application workflow are not.
     *
     * `/apply` itself is allowed and is listed in the sitemap — "apply online to
     * <school>" is a search a parent actually makes. The *trailing slash* rule is
     * what keeps crawlers out of the workflow behind it, including
     * `/apply/verify/{id}`, which shows one family's application. The two files
     * must agree: a URL in the sitemap that robots.txt disallows is a wasted
     * crawl, and search engines ignore it anyway.
     *
     * Shared report links (`/r/…`, `/p/performance…`, `/pv/…`) are tokenised
     * access to a child's marks; a token in an index is a token leaked.
     */
    #[Route('/robots.txt', name: 'app_tenant_robots', methods: ['GET'], host: '{subdomain}.%app.base_domain%')]
    public function robots(string $subdomain): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /apply/',
            'Disallow: /login',
            'Disallow: /dashboard',
            'Disallow: /parent',
            'Disallow: /onboarding',
            'Disallow: /verify/',
            'Disallow: /r/',
            'Disallow: /p/performance',
            'Disallow: /pv/',
            'Disallow: /payments/',
            '',
            'Sitemap: ' . $this->generateUrl('app_tenant_sitemap', ['subdomain' => $subdomain], UrlGeneratorInterface::ABSOLUTE_URL),
            '',
        ]);

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Which stage a class name belongs to. Checked most-specific first, because
     * "JSS 1" also contains "ss" and "Pre-Primary" contains "primary".
     */
    private function stageOf(string $name): string
    {
        $needle = mb_strtolower($name);

        return match (true) {
            str_contains($needle, 'nursery'),
            str_contains($needle, 'creche'),
            str_contains($needle, 'crèche'),
            str_contains($needle, 'kg'),
            str_contains($needle, 'kindergarten') => 'Early Years',
            str_contains($needle, 'jss'),
            str_contains($needle, 'junior') => 'Junior Secondary',
            str_contains($needle, 'ss'),
            str_contains($needle, 'senior') => 'Senior Secondary',
            str_contains($needle, 'primary'),
            str_contains($needle, 'pry') => 'Primary',
            str_contains($needle, 'nd'),
            str_contains($needle, 'hnd'),
            str_contains($needle, 'year') => 'College',
            default => 'Other classes',
        };
    }

    /**
     * The public contact page — and the only way a stranger can write to a
     * school from its website.
     *
     * Deliberate choices:
     *   - POST-redirect-GET. A refresh must never send the message twice.
     *   - CSRF token, plus a honeypot field that a person never sees. A filled
     *     honeypot gets the success page and no email.
     *   - Five messages an hour per session. Above that we say "call us"
     *     rather than silently dropping the sixth and letting a parent think
     *     we ignored them.
     *   - No `Name`/`Email` validation theatre: an enquiry needs a name, a way
     *     to reply (phone OR email — many parents have no email), and a message.
     */
    #[Route('/contact', name: 'app_tenant_contact', methods: ['GET', 'POST'], host: '{subdomain}.%app.base_domain%')]
    public function contact(
        Request $request,
        EntityManagerInterface $entityManager,
        string $subdomain,
        MailerInterface $mailer,
        LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        string $from = 'Edus <no-reply@edus.ng>',
    ): Response {
        $school = $this->getSchool($entityManager);

        $errors = [];
        $old = ['name' => '', 'phone' => '', 'email' => '', 'message' => ''];

        if ($request->isMethod('POST')) {
            $session = $request->getSession();
            $recent = array_values(array_filter(
                (array) $session->get('contact_attempts', []),
                static fn ($t): bool => is_int($t) && $t > time() - 3600
            ));

            $old = [
                'name' => trim((string) $request->request->get('name')),
                'phone' => trim((string) $request->request->get('phone')),
                'email' => trim((string) $request->request->get('email')),
                'message' => trim((string) $request->request->get('message')),
            ];

            $honeypot = trim((string) $request->request->get('website'));

            if (count($recent) >= 5) {
                $errors['form'] = 'That is several messages from this device already. Please call or message the school office instead.';
            } elseif (!$this->isCsrfTokenValid('contact', (string) $request->request->get('_token'))) {
                $errors['form'] = 'That form expired. Please try again.';
            } elseif ($honeypot !== '') {
                // A bot filled the hidden field. Show the success page; send nothing.
                return $this->redirectToRoute('app_tenant_contact', ['subdomain' => $subdomain, 'sent' => 1]);
            } else {
                if (mb_strlen($old['name']) < 2) {
                    $errors['name'] = 'Please tell us your name.';
                }
                if ($old['phone'] === '' && $old['email'] === '') {
                    $errors['phone'] = 'Add a phone number or an email address so the school can reply.';
                }
                if ($old['email'] !== '' && filter_var($old['email'], FILTER_VALIDATE_EMAIL) === false) {
                    $errors['email'] = 'That email address does not look right.';
                }
                if (mb_strlen($old['message']) < 10) {
                    $errors['message'] = 'Please write a short message (at least 10 characters).';
                }
            }

            if ($errors === []) {
                $to = $school?->getEmail();

                if ($to === null || $to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                    $errors['form'] = 'This school has not published a contact email yet. Please call or message the office instead.';
                } else {
                    try {
                        $email = (new TemplatedEmail())
                            ->from($from)
                            ->to($to)
                            ->subject(sprintf('Website enquiry from %s — %s', $old['name'], $school->getName()))
                            ->htmlTemplate('emails/contact_enquiry.html.twig')
                            ->context([
                                'school_name' => $school->getName(),
                                'sender_name' => $old['name'],
                                'sender_phone' => $old['phone'],
                                'sender_email' => $old['email'],
                                'sender_message' => $old['message'],
                                'received_at' => new \DateTimeImmutable(),
                            ]);

                        if ($old['email'] !== '') {
                            $email->replyTo(new Address($old['email'], $old['name']));
                        }

                        $mailer->send($email);

                        $recent[] = time();
                        $session->set('contact_attempts', $recent);

                        return $this->redirectToRoute('app_tenant_contact', ['subdomain' => $subdomain, 'sent' => 1]);
                    } catch (\Throwable $e) {
                        // The message is not stored anywhere, so say so plainly
                        // rather than pretending it was delivered.
                        $logger->error('Contact form: could not deliver message from {name} — {message}', [
                            'name' => $old['name'],
                            'message' => $e->getMessage(),
                        ]);
                        $errors['form'] = 'We could not send your message just now. Please call or message the school office.';
                    }
                }
            }
        }

        return $this->render('tenant/home/contact.html.twig', [
            'school' => $this->schoolArray($school),
            'subdomain' => $subdomain,
            'errors' => $errors,
            'old' => $old,
            'sent' => $request->query->getBoolean('sent'),
        ]);
    }
}