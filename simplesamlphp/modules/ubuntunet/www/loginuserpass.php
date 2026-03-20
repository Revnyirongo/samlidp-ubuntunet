<?php
declare(strict_types=1);

if (!isset($_REQUEST['AuthState'])) {
    throw new \SimpleSAML\Error\BadRequest('Missing required AuthState query parameter.');
}

/**
 * @return array{name?:string,logo_url?:?string,custom_css?:?string,help_url?:?string}
 */
function ubuntunetTenantBranding(?string $tenantSlug): array
{
    if (!is_string($tenantSlug) || $tenantSlug === '') {
        return [];
    }

    $databaseUrl = getenv('DATABASE_URL');
    if (!is_string($databaseUrl) || $databaseUrl === '') {
        return [];
    }

    $parsed = parse_url($databaseUrl);
    if ($parsed === false || empty($parsed['host'])) {
        return [];
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
        default => null,
    };

    if ($dsn === null) {
        return [];
    }

    try {
        $pdo = new \PDO(
            $dsn,
            $user,
            $pass,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ]
        );

        $stmt = $pdo->prepare('SELECT name, logo_url, custom_login_css, technical_contact_email FROM tenants WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $tenantSlug]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return [];
        }

        return [
            'name' => $row['name'] ?? null,
            'logo_url' => ubuntunetNormalizeLogoUrl($row['logo_url'] ?? null),
            'custom_css' => $row['custom_login_css'] ?? null,
            'help_url' => !empty($row['technical_contact_email']) ? 'mailto:' . $row['technical_contact_email'] : null,
        ];
    } catch (\Throwable $e) {
        \SimpleSAML\Logger::warning('Unable to load tenant branding: ' . $e->getMessage());

        return [];
    }
}

function ubuntunetNormalizeLogoUrl(?string $logoUrl): ?string
{
    if (!is_string($logoUrl) || $logoUrl === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $logoUrl) === 1) {
        return $logoUrl;
    }

    if ($logoUrl[0] !== '/') {
        $logoUrl = '/' . $logoUrl;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? getenv('SAMLIDP_HOSTNAME') ?: 'idp.ubuntunet.net';

    return sprintf('%s://%s%s', $scheme, $host, $logoUrl);
}

$stateId = $_REQUEST['AuthState'];
$state = null;
foreach (['ubuntunet:DatabaseAuth', 'ubuntunet:UserPassAuth'] as $stageId) {
    try {
        $state = \SimpleSAML\Auth\State::loadState($stateId, $stageId);
        break;
    } catch (\SimpleSAML\Error\NoState) {
        continue;
    }
}

if (!is_array($state)) {
    throw new \SimpleSAML\Error\BadRequest('Invalid or expired AuthState.');
}

$authSourceId = $state['ubuntunet:AuthSource'] ?? null;
$source = \SimpleSAML\Auth\Source::getById($authSourceId);
if (
    !is_object($source)
    || !method_exists($source, 'login')
    || !method_exists($source, 'getTenantSlug')
) {
    throw new \SimpleSAML\Error\Exception('Auth source is not compatible with the UbuntuNet login handler.');
}

$errors = [];
$formStateId = preg_replace('#(https://[^/:]+):80/#', '$1/', $stateId) ?? $stateId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $errors[] = 'Please enter both username and password.';
    } else {
        try {
            $attributes = $source->login($username, $password);
            $state['Attributes'] = $attributes;
            \SimpleSAML\Auth\Source::completeAuth($state);
        } catch (\SimpleSAML\Error\Error $e) {
            $errors[] = match ($e->getErrorCode()) {
                'WRONGUSERPASS' => 'Incorrect username or password.',
                'NOACCESS'      => 'Your account has been disabled. Contact your IT helpdesk.',
                default         => 'Authentication failed. Please try again.',
            };
        } catch (\Throwable $e) {
            \SimpleSAML\Logger::error('ubuntunet login error: ' . $e->getMessage());
            $errors[] = 'An internal error occurred. Please try again.';
        }
    }
}

$globalConfig = \SimpleSAML\Configuration::getInstance();
$t = new \SimpleSAML\XHTML\Template($globalConfig, 'ubuntunet:loginuserpass.twig');
$t->data['formTarget']  = '/simplesaml/module.php/ubuntunet/loginuserpass.php';
$t->data['stateParams'] = ['AuthState' => $formStateId];
$t->data['errors']      = $errors;
$t->data['username']    = $_POST['username'] ?? '';
$tenantSlug = $state['ubuntunet:TenantSlug'] ?? (method_exists($source, 'getTenantSlug') ? $source->getTenantSlug() : null);
$branding = ubuntunetTenantBranding(is_string($tenantSlug) ? $tenantSlug : null);
$t->data['tenant_name'] = $branding['name'] ?? getenv('TENANT_NAME') ?: 'UbuntuNet IdP';
$t->data['logo_url']    = $branding['logo_url'] ?? getenv('TENANT_LOGO_URL') ?: null;
$t->data['custom_css']  = $branding['custom_css'] ?? getenv('TENANT_CUSTOM_CSS') ?: null;
$t->data['helpUrl']     = $branding['help_url'] ?? null;
$t->data['forgot_password_url'] = is_string($tenantSlug) && $tenantSlug !== '' ? '/tenant/' . rawurlencode($tenantSlug) . '/forgot-password' : null;
$t->send();
