<?php

namespace App\Controller\Tenant;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Which code is this box actually running?
 *
 * Written after a day spent proving exactly that by hand: the container mounts a
 * checkout, the workflow reported success, and the school still saw a menu that
 * the running code did not contain. Two green deploys, stale code, no way to
 * tell from outside.
 *
 * The commit is baked into the image at build time (`ARG GIT_SHA`) and read back
 * here, so after a push:
 *
 *     curl -s https://gakprimary.edus.ng/health
 *     {"ok":true,"commit":"c10e51c","environment":"prod","time":"..."}
 *
 * compared with `git rev-parse --short HEAD` is the whole answer.
 *
 * Public on purpose: it reveals a commit hash and the environment name, nothing
 * about a school. It doubles as an uptime probe that fails when the app cannot
 * boot — which is the only way /health can be wrong about "ok".
 */
final class HealthController extends AbstractController
{
    public function __construct(
        // `%env(default::APP_BUILD_SHA)%` resolves to NULL when the variable is
        // absent (any non-Docker run), so the type has to allow it.
        #[Autowire('%app.build_sha%')]
        private readonly ?string $buildSha = null,
    ) {
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $sha = trim($this->buildSha);

        return $this->json([
            'ok' => true,
            'commit' => $sha !== '' ? substr($sha, 0, 7) : 'dev',
            'environment' => $this->getParameter('kernel.environment'),
            'time' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
