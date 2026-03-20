<?php

declare(strict_types=1);

namespace SimpleSAML\Module\ubuntunet\Auth\Source;

use SimpleSAML\Auth\State;
use SimpleSAML\Module;
use SimpleSAML\Module\ldap\Auth\Source\Ldap as BaseLdap;
use SimpleSAML\Utils\HTTP;

class Ldap extends BaseLdap
{
    private string $tenantSlug;

    public function __construct(array $info, array $config)
    {
        parent::__construct($info, $config);

        if (empty($config['tenant_slug'])) {
            throw new \SimpleSAML\Error\Exception('Ldap: missing required config option "tenant_slug".');
        }

        $this->tenantSlug = (string) $config['tenant_slug'];
    }

    public function authenticate(array &$state): void
    {
        $state['ubuntunet:AuthSource'] = $this->authId;
        $state['ubuntunet:TenantSlug'] = $this->tenantSlug;
        $id = State::saveState($state, 'ubuntunet:UserPassAuth');
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = '443';
        $url = Module::getModuleURL('ubuntunet/loginuserpass.php');
        (new HTTP())->redirectTrustedURL($url, ['AuthState' => $id]);
    }

    public function login(string $username, #[\SensitiveParameter] string $password): array
    {
        return parent::login($username, $password);
    }

    public function getTenantSlug(): string
    {
        return $this->tenantSlug;
    }
}
