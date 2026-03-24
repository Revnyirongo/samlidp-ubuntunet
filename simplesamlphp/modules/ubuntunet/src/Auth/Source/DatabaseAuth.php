<?php

declare(strict_types=1);

namespace SimpleSAML\Module\ubuntunet\Auth\Source;

use PDO;
use SimpleSAML\Auth\Source;
use SimpleSAML\Error\Error;

/**
 * Database-backed authentication source for tenants using local user accounts.
 * Reads users from the shared `idp_users` table, scoped by tenant slug.
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
        $id = \SimpleSAML\Auth\State::saveState($state, 'ubuntunet:DatabaseAuth');
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
        $row = $this->findUserRow($pdo, $username);

        if ($row === false) {
            throw new Error('WRONGUSERPASS');
        }

        if (!(bool) $row['is_active']) {
            throw new Error('NOACCESS');
        }

        $passwordValid = password_verify($password, $row['password']);
        $legacySalt = is_string($row['legacy_salt'] ?? null) ? $row['legacy_salt'] : null;

        if (!$passwordValid && $legacySalt !== null && $legacySalt !== '') {
            $passwordValid = $this->verifyLegacySaltedHash((string) $row['password'], $password, $legacySalt);
            if ($passwordValid) {
                $ntHash = is_string($row['nt_password_hash'] ?? null) && $row['nt_password_hash'] !== ''
                    ? (string) $row['nt_password_hash']
                    : $this->computeNtPasswordHash($password);

                try {
                    $pdo->prepare('UPDATE idp_users SET password = :password, legacy_salt = NULL, nt_password_hash = :nt_hash WHERE id = :id')
                        ->execute([
                            'id' => $row['id'],
                            'password' => password_hash($password, PASSWORD_BCRYPT),
                            'nt_hash' => $ntHash,
                        ]);
                } catch (\Throwable) {
                }
            }
        }

        if (!$passwordValid) {
            throw new Error('WRONGUSERPASS');
        }

        try {
            $pdo->prepare('UPDATE idp_users SET last_login_at = NOW() WHERE id = :id')
                ->execute(['id' => $row['id']]);
        } catch (\Throwable) {
        }

        $attrs = json_decode($row['attributes'], true) ?? [];

        if (!isset($attrs['uid']) || !is_array($attrs['uid']) || ($attrs['uid'][0] ?? '') === '') {
            $attrs['uid'] = [$username];
        }

        if (!isset($attrs['eduPersonPrincipalName']) || !is_array($attrs['eduPersonPrincipalName']) || ($attrs['eduPersonPrincipalName'][0] ?? '') === '') {
            $mail = is_array($attrs['mail'] ?? null) ? trim((string) ($attrs['mail'][0] ?? '')) : '';
            $uid = trim((string) ($attrs['uid'][0] ?? $username));
            $attrs['eduPersonPrincipalName'] = [$mail !== '' ? $mail : $uid];
        }

        if (!isset($attrs['urn:oid:1.3.6.1.4.1.5923.1.1.1.6']) || !is_array($attrs['urn:oid:1.3.6.1.4.1.5923.1.1.1.6']) || ($attrs['urn:oid:1.3.6.1.4.1.5923.1.1.1.6'][0] ?? '') === '') {
            $attrs['urn:oid:1.3.6.1.4.1.5923.1.1.1.6'] = $attrs['eduPersonPrincipalName'];
        }

        return $attrs;
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }

    private function getPdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $databaseUrl = getenv('DATABASE_URL');
        if (!$databaseUrl) {
            throw new \SimpleSAML\Error\Exception('DatabaseAuth: DATABASE_URL environment variable not set.');
        }

        $parsed = parse_url($databaseUrl);
        if ($parsed === false || empty($parsed['host'])) {
            throw new \SimpleSAML\Error\Exception('DatabaseAuth: Cannot parse DATABASE_URL.');
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        $host = $parsed['host'];
        $port = $parsed['port'] ?? match ($scheme) {
            'mysql', 'mariadb' => 3306,
            default => 5432,
        };
        $dbname = ltrim($parsed['path'] ?? '/samlidp', '/');
        $user = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';

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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );

        return $this->pdo;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function findUserRow(PDO $pdo, string $identifier): array|false
    {
        $stmt = $pdo->prepare(<<<SQL
            SELECT u.id, u.username, u.password, u.legacy_salt, u.nt_password_hash, u.attributes, u.is_active
            FROM idp_users u
            JOIN tenants t ON t.id = u.tenant_id
            WHERE t.slug = :slug
              AND u.username = :username
            LIMIT 1
        SQL);

        $stmt->execute([
            'slug' => $this->tenantSlug,
            'username' => $identifier,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }

        $normalized = strtolower(trim($identifier));
        if ($normalized === '') {
            return false;
        }

        $fallbackStmt = $pdo->prepare(<<<SQL
            SELECT u.id, u.username, u.password, u.legacy_salt, u.nt_password_hash, u.attributes, u.is_active
            FROM idp_users u
            JOIN tenants t ON t.id = u.tenant_id
            WHERE t.slug = :slug
        SQL);
        $fallbackStmt->execute(['slug' => $this->tenantSlug]);

        while ($candidate = $fallbackStmt->fetch(PDO::FETCH_ASSOC)) {
            $attrs = json_decode((string) ($candidate['attributes'] ?? '{}'), true);
            if (!is_array($attrs)) {
                $attrs = [];
            }

            $aliases = array_filter([
                strtolower((string) ($candidate['username'] ?? '')),
                strtolower((string) (($attrs['mail'][0] ?? null) ?: '')),
                strtolower((string) (($attrs['eduPersonPrincipalName'][0] ?? null) ?: '')),
                strtolower((string) (($attrs['uid'][0] ?? null) ?: '')),
            ]);

            if (in_array($normalized, $aliases, true)) {
                return $candidate;
            }
        }

        return false;
    }

    private function verifyLegacySaltedHash(string $encoded, string $plainPassword, string $salt): bool
    {
        $digest = openssl_digest($plainPassword . $salt, 'sha512', true);
        if ($digest === false) {
            return false;
        }

        return hash_equals($encoded, base64_encode($digest . $salt));
    }

    private function computeNtPasswordHash(string $password): ?string
    {
        try {
            $utf16le = iconv('UTF-8', 'UTF-16LE', $password);
            if ($utf16le === false) {
                return null;
            }

            return strtoupper(hash('md4', $utf16le));
        } catch (\Throwable) {
            return null;
        }
    }
}
