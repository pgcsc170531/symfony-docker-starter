<?php

namespace App\Service\Settings;

use App\Entity\Landlord\GlobalSetting;
use App\Entity\Landlord\PlatformSettingHistory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The one place platform configuration is read and written.
 *
 * Precedence, always: **database → environment → built-in default.**
 *
 *  - The database is what the Super Admin edits, so a key can be rotated without
 *    shipping code.
 *  - The environment is the break-glass: if the database is being restored, or the
 *    row was never created, the server's `PAYSTACK_SECRET_KEY` / `MAILER_DSN`
 *    still boots a working app. Nothing breaks because a setting is missing.
 *  - The default is what a fresh clone gets (`smtp://mailer:1025`, no Paystack key
 *    → the local payment simulator).
 *
 * Secrets are encrypted at rest by {@see SecretCipher} and are only ever returned
 * as plaintext to the code that must use them — never to a template.
 */
final class PlatformSettings
{
    // ── Payment ──────────────────────────────────────────────────────────
    public const PAYSTACK_SECRET = 'paystack_secret_key';
    public const PAYSTACK_PUBLIC = 'paystack_public_key';

    // ── Mail ─────────────────────────────────────────────────────────────
    public const MAIL_HOST = 'mail_host';
    public const MAIL_PORT = 'mail_port';
    public const MAIL_USERNAME = 'mail_username';
    public const MAIL_PASSWORD = 'mail_password';
    public const MAIL_FROM_NAME = 'mail_from_name';
    public const MAIL_FROM_EMAIL = 'mail_from_email';
    public const MAIL_REPLY_TO = 'mail_reply_to';

    // ── Money ────────────────────────────────────────────────────────────
    public const BANK_NAME = 'bank_name';
    public const BANK_ACCOUNT = 'bank_account';
    public const BANK_ACCOUNT_NAME = 'bank_account_name';

    /** @var array<string, bool> which keys are stored encrypted */
    private const SECRET_KEYS = [
        self::PAYSTACK_SECRET => true,
        self::MAIL_PASSWORD => true,
    ];

    /** @var array<string, mixed>|null per-request memo so one request reads once */
    private ?array $memo = null;

    public function __construct(
        #[Autowire(service: 'doctrine.orm.landlord_entity_manager')]
        private readonly EntityManagerInterface $landlordEm,
        private readonly SecretCipher $cipher,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(default::PAYSTACK_SECRET_KEY)%')]
        #[\SensitiveParameter]
        private readonly ?string $envPaystackSecret = null,
        #[Autowire('%env(default::PAYSTACK_PUBLIC_KEY)%')]
        private readonly ?string $envPaystackPublic = null,
        #[Autowire('%env(default::BREVO_SMTP_LOGIN)%')]
        #[\SensitiveParameter]
        private readonly ?string $envSmtpLogin = null,
        #[Autowire('%env(default::BREVO_SMTP_KEY)%')]
        #[\SensitiveParameter]
        private readonly ?string $envSmtpKey = null,
        #[Autowire('%env(default::MAILER_FROM)%')]
        private readonly ?string $envMailFrom = null,
        #[Autowire('%env(default::MAILER_REPLY_TO)%')]
        private readonly ?string $envMailReplyTo = null,
        #[Autowire('%env(default::MAILER_FALLBACK_DSN)%')]
        #[\SensitiveParameter]
        private readonly ?string $envMailerDsn = null,
        #[Autowire('%env(default::EDUS_BANK_NAME)%')]
        private readonly ?string $envBankName = null,
        #[Autowire('%env(default::EDUS_BANK_ACCOUNT)%')]
        private readonly ?string $envBankAccount = null,
        #[Autowire('%env(default::EDUS_BANK_ACCOUNT_NAME)%')]
        private readonly ?string $envBankAccountName = null,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────
    //  Reading
    // ──────────────────────────────────────────────────────────────────

    /** A plain (non-secret) setting. */
    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->row($key);
        $value = $row ? (string) ($row['value'] ?? '') : '';

        if ($value !== '') {
            return $row['secret'] ? $this->cipher->decrypt($value) : $value;
        }

        return $this->env($key) ?? $default;
    }

    /** Where the effective value comes from — shown on the settings screens. */
    public function source(string $key): string
    {
        $row = $this->row($key);
        if ($row && trim((string) ($row['value'] ?? '')) !== '') {
            return 'database';
        }

        return ($this->env($key) ?? '') !== '' ? 'environment' : 'default';
    }

    /** True when a secret is set at all — without revealing it. */
    public function isSet(string $key): bool
    {
        return trim((string) ($this->get($key) ?? '')) !== '';
    }

    /** `sk_live_…3f9c`, or null. Safe for a template. */
    public function hint(string $key): ?string
    {
        return $this->cipher->hint($this->get($key));
    }

    public function paystackSecretKey(): ?string
    {
        $key = trim((string) $this->get(self::PAYSTACK_SECRET, ''));

        return $key === '' ? null : $key;
    }

    public function paystackPublicKey(): ?string
    {
        $key = trim((string) $this->get(self::PAYSTACK_PUBLIC, ''));

        return $key === '' ? null : $key;
    }

    /**
     * Live vs test, decided by the key itself — never by a checkbox somebody can
     * forget to flip after a restore.
     */
    public function paystackMode(): string
    {
        $key = $this->paystackSecretKey();

        if ($key === null) {
            return 'none';
        }

        return str_starts_with($key, 'sk_live_') ? 'live' : 'test';
    }

    /**
     * The DSN the mailer should use right now.
     *
     * A DSN built from the settings screen wins; otherwise the Brevo pair in the
     * environment; otherwise `MAILER_DSN` (which a fresh clone points at Mailpit).
     */
    public function mailDsn(): string
    {
        $host = trim((string) $this->get(self::MAIL_HOST, '') ?? '');
        $username = trim((string) $this->get(self::MAIL_USERNAME, '') ?? '');
        $password = (string) ($this->get(self::MAIL_PASSWORD, '') ?? '');
        $port = (int) ($this->get(self::MAIL_PORT, '') ?: 587);

        if ($host !== '') {
            $scheme = $port === 465 ? 'smtps' : 'smtp';
            $auth = $username !== ''
                ? rawurlencode($username) . ':' . rawurlencode($password) . '@'
                : '';

            return sprintf('%s://%s%s:%d', $scheme, $auth, $host, $port);
        }

        $login = trim((string) $this->envSmtpLogin);
        $key = trim((string) $this->envSmtpKey);
        if ($login !== '' && $key !== '') {
            return sprintf('smtp://%s:%s@smtp-relay.brevo.com:587', rawurlencode($login), rawurlencode($key));
        }

        $dsn = trim((string) $this->envMailerDsn);

        return $dsn !== '' ? $dsn : 'smtp://mailer:1025';
    }

    /** @return array{fromName: string, fromEmail: string, replyTo: string} */
    public function mailIdentity(): array
    {
        $name = trim((string) $this->get(self::MAIL_FROM_NAME, '') ?? '');
        $email = trim((string) $this->get(self::MAIL_FROM_EMAIL, '') ?? '');

        if ($email === '') {
            [$name, $email] = $this->parseAddress((string) $this->envMailFrom, $name);
        }

        $replyTo = trim((string) $this->get(self::MAIL_REPLY_TO, '') ?? '');
        if ($replyTo === '') {
            $replyTo = trim((string) $this->envMailReplyTo);
        }

        return [
            'fromName' => $name !== '' ? $name : 'Edus',
            'fromEmail' => $email !== '' ? $email : 'no-reply@edus.ng',
            'replyTo' => $replyTo,
        ];
    }

    /** @return array{name: string, account: string, accountName: string, configured: bool} */
    public function bankDetails(): array
    {
        $name = trim((string) ($this->get(self::BANK_NAME, $this->envBankName) ?? ''));
        $account = trim((string) ($this->get(self::BANK_ACCOUNT, $this->envBankAccount) ?? ''));
        $accountName = trim((string) ($this->get(self::BANK_ACCOUNT_NAME, $this->envBankAccountName) ?? ''));

        return [
            'name' => $name,
            'account' => $account,
            'accountName' => $accountName,
            'configured' => $name !== '' && $account !== '',
        ];
    }

    /** When a key was last set or cleared, from the history table. */
    public function rotatedAt(string $key): ?\DateTimeImmutable
    {
        try {
            $row = $this->landlordEm->createQueryBuilder()
                ->select('MAX(h.changedAt)')
                ->from(PlatformSettingHistory::class, 'h')
                ->andWhere('h.settingKey = :key')
                ->andWhere('h.action = :set')
                ->setParameter('key', $key)
                ->setParameter('set', PlatformSettingHistory::ACTION_SET)
                ->getQuery()
                ->getSingleScalarResult();
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        /** @var \DateTimeImmutable|string $row */
        return $row instanceof \DateTimeImmutable ? $row : new \DateTimeImmutable((string) $row);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Writing
    // ──────────────────────────────────────────────────────────────────

    /**
     * Save a setting. Secrets are encrypted here — the caller hands over plaintext
     * and it never reaches the database as such.
     *
     * @throws \RuntimeException when the value is a secret and no encryption key is configured
     */
    public function set(string $key, #[\SensitiveParameter] string $value, ?string $actor = null): void
    {
        $value = trim($value);
        $secret = self::SECRET_KEYS[$key] ?? false;

        $setting = $this->landlordEm->getRepository(GlobalSetting::class)->findOneBy(['settingKey' => $key])
            ?? (new GlobalSetting())->setSettingKey($key);

        if ($value === '') {
            $this->clear($key, $actor);

            return;
        }

        if ($secret) {
            if (!$this->cipher->canEncrypt()) {
                throw new \RuntimeException(
                    'APP_ENCRYPTION_KEY is not set, so secrets cannot be saved. Generate one with '
                    . '"php bin/console app:platform:make-key" and put it in the server environment (or .env.local).'
                );
            }
            $value = $this->cipher->encrypt($value);
        }

        $setting->setSettingValue($value)
            ->setIsSecret($secret)
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setUpdatedBy($actor);

        $this->landlordEm->persist($setting);
        $this->history($key, PlatformSettingHistory::ACTION_SET, $actor);
        $this->landlordEm->flush();

        $this->invalidate();
    }

    public function clear(string $key, ?string $actor = null): void
    {
        $setting = $this->landlordEm->getRepository(GlobalSetting::class)->findOneBy(['settingKey' => $key]);
        if ($setting === null) {
            return;
        }

        $setting->setSettingValue('')
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setUpdatedBy($actor);
        $this->history($key, PlatformSettingHistory::ACTION_CLEARED, $actor);
        $this->landlordEm->flush();

        $this->invalidate();
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────────────────────────

    /** @return array{value: ?string, secret: bool}|null */
    private function row(string $key): ?array
    {
        $map = $this->map();

        return $map[$key] ?? null;
    }

    /** @return array<string, array{value: ?string, secret: bool}> */
    private function map(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            // A version counter rather than a TTL: a saved key must take effect on
            // the very next request, not "within a minute".
            $version = $this->cache->get('platform_settings_version', static fn (ItemInterface $item): int => 1);

            $this->memo = $this->cache->get(
                'platform_settings_map_v' . $version,
                function (ItemInterface $item): array {
                    $item->expiresAfter(3600);
                    $out = [];
                    foreach ($this->landlordEm->getRepository(GlobalSetting::class)->findAll() as $setting) {
                        $out[(string) $setting->getSettingKey()] = [
                            'value' => $setting->getSettingValue(),
                            'secret' => $setting->isSecret(),
                        ];
                    }

                    return $out;
                }
            );
        } catch (\Throwable $e) {
            // No landlord database (unit tests, a restore in progress): the
            // environment still answers, which is the whole point of the fallback.
            $this->logger->debug('Platform settings unavailable, falling back to environment: {error}', [
                'error' => $e->getMessage(),
            ]);
            $this->memo = [];
        }

        return $this->memo;
    }

    private function invalidate(): void
    {
        $this->memo = null;

        try {
            $this->cache->delete('platform_settings_version');
        } catch (\Throwable) {
            // cache unavailable — the next request simply reads the database
        }
    }

    private function history(string $key, string $action, ?string $actor): void
    {
        $this->landlordEm->persist(
            (new PlatformSettingHistory())
                ->setSettingKey($key)
                ->setAction($action)
                ->setActor($actor)
        );
    }

    private function env(string $key): ?string
    {
        $value = match ($key) {
            self::PAYSTACK_SECRET => $this->envPaystackSecret,
            self::PAYSTACK_PUBLIC => $this->envPaystackPublic,
            self::MAIL_USERNAME => $this->envSmtpLogin,
            self::MAIL_PASSWORD => $this->envSmtpKey,
            self::MAIL_FROM_EMAIL, self::MAIL_FROM_NAME => $this->envMailFrom,
            self::MAIL_REPLY_TO => $this->envMailReplyTo,
            self::BANK_NAME => $this->envBankName,
            self::BANK_ACCOUNT => $this->envBankAccount,
            self::BANK_ACCOUNT_NAME => $this->envBankAccountName,
            default => null,
        };

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array{0: string, 1: string} name, email */
    private function parseAddress(string $value, string $fallbackName = ''): array
    {
        if (preg_match('/^\s*(?:"?([^"<]*)"?\s*)?<([^>]+)>\s*$/', $value, $m) === 1) {
            return [trim($m[1]) !== '' ? trim($m[1]) : $fallbackName, trim($m[2])];
        }

        return [$fallbackName, trim($value)];
    }
}
