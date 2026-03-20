<?php

declare(strict_types=1);

namespace SimpleSAML\Module\ubuntunet\Auth\Source;

use PDO;
use SimpleSAML\Auth\Source;
use SimpleSAML\Error\Error;

/**
 * Database-backed authentication source for tenants using local user accounts.
 * Reads users from the shared PostgreSQL `idp_users` table, scoped by tenant slug.
 *
 * Configure in authsources-<slug>.php:
 *   'idp-<slug>' => [
 *       'ubuntunet:DatabaseAuth',
 *       'tenant_slug' => '<slug>',
 *   ],
 */
class DatabaseAuth extends Source
{
    private string $tenantSlug;
    private ?PDO $pdo = null;

    public function __construct(array $info, array $config)
    {
        parent::__construct($info, $config);

        if (empty($config['tenant_slug'])) {
            throw new \SimpleSAML\Error\Exception('DatabaseAuth: missing required config option "tenant_slug".');
        }

        $this->tenantSlug = $config['tenant_slug'];
    }

    /**
     * Redirect to our login form.
     */
    public function authenticate(array &$state): void
    {
        $state['ubuntunet:AuthSource'] = $this->authId;
        $state['ubuntunet:TenantSlug'] = $this->tenantSlug;
        $id  = \SimpleSAML\Auth\State::saveState($state, 'ubuntunet:DatabaseAuth');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = '443';
        $url = \SimpleSAML\Module::getModuleURL('ubuntunet/loginuserpass.php');
        (new \SimpleSAML\Utils\HTTP())->redirectTrustedURL($url, ['AuthState' => $id]);
    }

    /**
     * Verify credentials and return attribute array.
     *
     * @throws \SimpleSAML\Error\Error on bad credentials or inactive account
     */
    public function login(string $username, string $password): array
    {
        $pdo = $this->getPdo();

        $stmt = $pdo->prepare(<<<SQL
            SELECT u.id, u.password, u.attributes, u.is_active
            FROM idp_users u
            JOIN tenants t ON t.id = u.tenant_id
            WHERE t.slug = :slug
              AND u.username = :username
            LIMIT 1
        SQL);

        $stmt->execute([
            'slug'     => $this->tenantSlug,
            'username' => $username,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // User not found — same error as wrong password (don't leak username existence)
            throw new Error('WRONGUSERPASS');
        }

        if (!(bool) $row['is_active']) {
            throw new Error('NOACCESS');
        }

        if (!password_verify($password, $row['password'])) {
            throw new Error('WRONGUSERPASS');
        }

        // Update last login timestamp (best-effort, non-fatal)
        try {
            $pdo->prepare("UPDATE idp_users SET last_login_at = NOW() WHERE id = :id")
                ->execute(['id' => $row['id']]);
        } catch (\Throwable) {}

        $attrs = json_decode($row['attributes'], true) ?? [];

        // Always ensure uid is set
        if (!isset($attrs['uid'])) {
            $attrs['uid'] = [$username];
        }

        return $attrs;
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }

    // ── Private helpers ───────────────────────────────────────

    private function getPdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $databaseUrl = getenv('DATABASE_URL');
        if (!$databaseUrl) {
            throw new \SimpleSAML\Error\Exception('DatabaseAuth: DATABASE_URL environment variable not set.');
        }

        // Parse Symfony DATABASE_URL. Support PostgreSQL and MySQL/MariaDB URLs.
        $parsed = parse_url($databaseUrl);
        if ($parsed === false || empty($parsed['host'])) {
            throw new \SimpleSAML\Error\Exception('DatabaseAuth: Cannot parse DATABASE_URL.');
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = $parsed['host'];
        $port   = $parsed['port'] ?? match ($scheme) {
            'mysql', 'mariadb' => 3306,
            default            => 5432,
        };
        $dbname = ltrim($parsed['path'] ?? '/samlidp', '/');
        $user   = $parsed['user'] ?? '';
        $pass   = $parsed['pass'] ?? '';

        parse_str($parsed['query'] ?? '', $query);

        $dsn = match ($scheme) {
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $port,
                $dbname,
                $query['charset'] ?? 'utf8mb4',
            ),
            'postgres', 'postgresql', 'pgsql', '' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $host,
                $port,
                $dbname,
            ),
            default => throw new \SimpleSAML\Error\Exception(
                sprintf('DatabaseAuth: Unsupported DATABASE_URL scheme "%s".', $scheme)
            ),
        };

        $this->pdo = new PDO(
            $dsn,
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]
        );

        return $this->pdo;
    }
}
