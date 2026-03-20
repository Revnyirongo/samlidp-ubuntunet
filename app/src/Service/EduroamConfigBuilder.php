<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;

final class EduroamConfigBuilder
{
    public function build(Tenant $tenant): array
    {
        $profile = $tenant->getEduroamProfile();
        $enabled = (bool) ($profile['enabled'] ?? false);
        $realm = $this->deriveRealm($tenant, $profile);
        $radiusHostname = $profile['local_radius_hostname'] ?? 'radius.' . $realm;
        $defaultEapMethod = $this->normalizeEapMethod($profile['default_eap_method'] ?? null);
        [$mode, $modeLabel, $supported, $warnings] = $this->resolveMode($tenant, $realm);

        $files = [
            'readme' => [
                'label' => 'README',
                'filename' => sprintf('eduroam-%s-readme.md', $tenant->getSlug()),
                'content' => $this->buildReadme($tenant, $realm, $radiusHostname, $defaultEapMethod, $modeLabel, $supported, $warnings),
            ],
        ];

        if ($mode === 'database') {
            $files['inner_tunnel'] = [
                'label' => 'inner-tunnel policy',
                'filename' => sprintf('eduroam-%s-inner-tunnel.conf', $tenant->getSlug()),
                'content' => $this->buildInnerTunnelConfig($realm, $mode),
            ];
            $files['backend'] = [
                'label' => 'Authentication backend',
                'filename' => sprintf('eduroam-%s-backend.conf', $tenant->getSlug()),
                'content' => $this->buildBackendConfig($tenant, $realm, $mode),
            ];
            $files['schema_postgresql'] = [
                'label' => 'PostgreSQL view',
                'filename' => sprintf('eduroam-%s-postgresql.sql', $tenant->getSlug()),
                'content' => $this->buildPostgreSqlSchema($tenant, $realm),
            ];
            $files['schema_mysql'] = [
                'label' => 'MySQL view',
                'filename' => sprintf('eduroam-%s-mysql.sql', $tenant->getSlug()),
                'content' => $this->buildMySqlSchema($tenant, $realm),
            ];
        }

        return [
            'enabled' => $enabled,
            'realm' => $realm,
            'radiusHostname' => $radiusHostname,
            'defaultEapMethod' => $defaultEapMethod,
            'backendMode' => $mode,
            'backendModeLabel' => $modeLabel,
            'supported' => $supported,
            'warnings' => $warnings,
            'files' => $files,
        ];
    }

    private function deriveRealm(Tenant $tenant, array $profile): string
    {
        $explicit = strtolower(trim((string) ($profile['realm'] ?? '')));
        if ($explicit !== '') {
            return $explicit;
        }

        $metadataProfile = $tenant->getMetadataProfile();
        foreach (['scopes', 'domain_hints'] as $key) {
            $candidate = $metadataProfile[$key][0] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim($candidate));
            }
        }

        $organizationUrl = $tenant->getOrganizationUrl();
        if (is_string($organizationUrl) && $organizationUrl !== '') {
            $host = parse_url($organizationUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        return strtolower($tenant->getTenantHostname());
    }

    private function normalizeEapMethod(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'ttls-pap' => 'ttls-pap',
            'tls' => 'tls',
            default => 'peap',
        };
    }

    /**
     * @return array{0:string,1:string,2:bool,3:list<string>}
     */
    private function resolveMode(Tenant $tenant, string $realm): array
    {
        $warnings = [];

        if ($tenant->usesDatabaseAuth()) {
            $warnings[] = 'Managed local users can support PEAP/MSCHAPv2 and TTLS/PAP after passwords are created or rotated so NT hashes are populated.';
            $warnings[] = sprintf('Use %s as the institution realm and ensure FreeRADIUS normalizes usernames to user@%s before SQL lookups.', $realm, $realm);

            return ['database', 'Managed SQL user store', true, $warnings];
        }

        if ($tenant->getAuthType() === Tenant::AUTH_LDAP) {
            $warnings[] = 'LDAP-backed tenants must be wired directly from FreeRADIUS to LDAP or Active Directory by the institution.';
            $warnings[] = 'This portal does not generate institution-specific LDAP module configuration because directory layouts and bind models vary.';

            return ['ldap', 'LDAP / Active Directory (external)', false, $warnings];
        }

        if ($tenant->getAuthType() === Tenant::AUTH_RADIUS) {
            $warnings[] = 'RADIUS-backed tenants must be wired to their upstream RADIUS service outside this portal.';

            return ['radius', 'Upstream RADIUS (external)', false, $warnings];
        }

        $warnings[] = 'SAML-proxy tenants are not suitable as an eduroam authentication source because eduroam needs a credential-verifying backend, not an interactive browser SSO flow.';

        return ['unsupported', 'Not supported for eduroam', false, $warnings];
    }

    private function buildReadme(
        Tenant $tenant,
        string $realm,
        string $radiusHostname,
        string $defaultEapMethod,
        string $modeLabel,
        bool $supported,
        array $warnings,
    ): string {
        $lines = [
            '# eduroam / FreeRADIUS Authentication Kit',
            '',
            sprintf('Tenant: %s (%s)', $tenant->getName(), $tenant->getSlug()),
            sprintf('Realm: %s', $realm),
            sprintf('Recommended RADIUS hostname: %s', $radiusHostname),
            sprintf('Managed IdP metadata: %s', $tenant->getMetadataUrl()),
            sprintf('Backend mode: %s', $modeLabel),
            sprintf('Recommended inner EAP method: %s', strtoupper($defaultEapMethod)),
            '',
            'What you need:',
            '1. A FreeRADIUS 3.x or newer server for the institution.',
            '2. A public institution realm that matches the user suffix, for example user@' . $realm . '.',
            '3. A server certificate for EAP (PEAP or TTLS) if you are not using EAP-TLS only.',
            '4. Connectivity from FreeRADIUS to the chosen backend: managed SQL, LDAP/AD, or upstream RADIUS.',
            '5. A separate switchboard or national operator service for clients.conf and roaming proxy configuration.',
            '',
            'Scope of this kit:',
            sprintf('- Requests for @%s should stay local and be authenticated by the institution FreeRADIUS server.', $realm),
            '- This portal only generates authentication-side configuration owned by the tenant backend.',
            '- Client definitions and roaming proxy configuration should come from switchboard.eduroam.africa or the national operator.',
            '- Configure suffix handling so FreeRADIUS uses the stripped user name for local backend lookups.',
            '',
            'Managed SQL layout:',
            '- Local users live in idp_users and are tied to tenants via tenant_id.',
            '- Password stores use bcrypt for web login and NT hashes for FreeRADIUS MSCHAPv2.',
            '- For local SQL mode, create the compatibility views in the generated PostgreSQL or MySQL file or adapt the custom SQL query directly.',
            '',
        ];

        if ($supported) {
            $lines[] = 'Recommended deployment sequence:';
            $lines[] = '1. Set the tenant realm in the eduroam profile in this portal.';
            $lines[] = '2. Copy the generated authentication snippets into FreeRADIUS inner-tunnel and SQL policy modules.';
            $lines[] = '3. Configure EAP with a proper certificate chain and CA trust.';
            $lines[] = '4. Obtain clients.conf and roaming proxy policy from switchboard.eduroam.africa or the national operator.';
            $lines[] = '5. Run eapol_test or radtest with a user@realm identity before joining national roaming.';
        } else {
            $lines[] = 'This tenant uses an external authentication backend.';
            $lines[] = 'The institution must manage its own FreeRADIUS-to-backend wiring outside this portal.';
        }

        if ($warnings !== []) {
            $lines[] = '';
            $lines[] = 'Warnings:';
            foreach ($warnings as $warning) {
                $lines[] = '- ' . $warning;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function buildInnerTunnelConfig(string $realm, string $mode): string
    {
        $authorize = match ($mode) {
            'database' => "    sql_managed_idp\n\n    if (&control:NT-Password) {\n        update control {\n            Auth-Type := MS-CHAP\n        }\n    }\n",
            'ldap' => "    ldap_managed_idp\n",
            'radius' => "    update control {\n        Proxy-To-Realm := \"{$realm}\"\n    }\n    return\n",
            default => "    reject\n",
        };

        $authenticate = match ($mode) {
            'database' => "    Auth-Type MS-CHAP {\n        mschap\n    }\n\n    Auth-Type PAP {\n        pap\n    }\n",
            'ldap' => "    Auth-Type LDAP {\n        ldap_managed_idp\n    }\n\n    Auth-Type PAP {\n        pap\n    }\n",
            'radius' => "    # Authentication happens on the proxied upstream RADIUS server.\n",
            default => "    Auth-Type Reject {\n        reject\n    }\n",
        };

        return <<<CONF
server inner-tunnel {
    authorize {
        filter_username
        suffix

        if (&Realm && &Realm != "{$realm}") {
            update control {
                Proxy-To-Realm := DEFAULT
            }
            return
        }

        update request {
            SQL-User-Name := "%{tolower:%{%{Stripped-User-Name}:-%{User-Name}}@{$realm}}"
        }

{$authorize}        eap
    }

    authenticate {
{$authenticate}        eap
    }
}
CONF;
    }

    private function buildBackendConfig(Tenant $tenant, string $realm, string $mode): string
    {
        return match ($mode) {
            'database' => $this->buildDatabaseBackendConfig($tenant, $realm),
            'ldap' => $this->buildLdapBackendConfig($tenant),
            'radius' => $this->buildRadiusBackendConfig($tenant),
            default => <<<TEXT
# This tenant uses a SAML proxy backend.
# eduroam cannot authenticate against a browser SSO-only backend.
# Move this tenant to LDAP, managed SQL, or upstream RADIUS first.
TEXT,
        };
    }

    private function buildDatabaseBackendConfig(Tenant $tenant, string $realm): string
    {
        $slug = $tenant->getSlug();

        return <<<CONF
sql sql_managed_idp {
    dialect = postgresql
    driver = "rlm_sql_\${dialect}"
    server = CHANGE_ME_DB_HOST
    port = 5432
    login = CHANGE_ME_DB_USER
    password = CHANGE_ME_DB_PASSWORD
    radius_db = CHANGE_ME_DB_NAME
    read_clients = no

    authorize_check_query = "SELECT u.id::text AS id, lower(CASE WHEN position('@' in u.username) > 0 THEN u.username ELSE u.username || '@{$realm}' END) AS username, 'NT-Password' AS attribute, upper(u.nt_password_hash) AS value, ':=' AS op FROM idp_users u JOIN tenants t ON t.id = u.tenant_id WHERE t.slug = '{$slug}' AND u.is_active = TRUE AND u.nt_password_hash IS NOT NULL AND lower(CASE WHEN position('@' in u.username) > 0 THEN u.username ELSE u.username || '@{$realm}' END) = lower('%{SQL-User-Name}')"
    authorize_reply_query = "SELECT u.id::text AS id, lower(CASE WHEN position('@' in u.username) > 0 THEN u.username ELSE u.username || '@{$realm}' END) AS username, 'Reply-Message' AS attribute, 'Authenticated by Managed IdP' AS value, ':=' AS op FROM idp_users u JOIN tenants t ON t.id = u.tenant_id WHERE 1 = 0"
}

# For MySQL deployments, switch dialect/driver and use the generated MySQL view file.
# FreeRADIUS should query SQL-User-Name set in inner-tunnel to user@{$realm}.
CONF;
    }

    private function buildLdapBackendConfig(Tenant $tenant): string
    {
        $config = $tenant->getLdapConfig() ?? [];
        $host = $config['host'] ?? 'ldaps://ldap.example.org:636';
        $baseDn = $config['base_dn'] ?? 'ou=people,dc=example,dc=org';
        $bindDn = $config['bind_dn'] ?? 'cn=radius-bind,dc=example,dc=org';
        $filter = $config['search_filter'] ?? '(&(objectClass=person)(uid=%{%{Stripped-User-Name}:-%{User-Name}}))';

        return <<<CONF
ldap ldap_managed_idp {
    server = "{$host}"
    identity = "{$bindDn}"
    password = "CHANGE_ME_BIND_PASSWORD"
    base_dn = "{$baseDn}"
    user {
        filter = "{$filter}"
    }
    update {
        control:Password-With-Header += 'userPassword'
        reply:Reply-Message := 'Authenticated by LDAP backend'
    }
}

# For Active Directory and PEAP/MSCHAPv2, prefer ntlm_auth / winbind integration.
# For generic LDAP, TTLS/PAP is the safest portable option.
CONF;
    }

    private function buildRadiusBackendConfig(Tenant $tenant): string
    {
        $config = $tenant->getRadiusConfig() ?? [];
        $server = $config['server'] ?? 'radius.example.org';
        $port = (int) ($config['port'] ?? 1812);

        return <<<CONF
home_server managed_upstream_radius {
    type = auth
    ipaddr = {$server}
    port = {$port}
    secret = CHANGE_ME_UPSTREAM_RADIUS_SECRET
    response_window = 20
    revive_interval = 120
    status_check = status-server
}

home_server_pool managed_upstream_radius_pool {
    type = fail-over
    home_server = managed_upstream_radius
}
CONF;
    }

    private function buildPostgreSqlSchema(Tenant $tenant, string $realm): string
    {
        $slug = $tenant->getSlug();

        return <<<SQL
CREATE SCHEMA IF NOT EXISTS freeradius_managedidp;

CREATE OR REPLACE VIEW freeradius_managedidp.radcheck_{$slug} AS
SELECT
    u.id::text AS id,
    lower(CASE WHEN position('@' in u.username) > 0 THEN u.username ELSE u.username || '@{$realm}' END) AS username,
    'NT-Password' AS attribute,
    ':=' AS op,
    upper(u.nt_password_hash) AS value
FROM idp_users u
JOIN tenants t ON t.id = u.tenant_id
WHERE t.slug = '{$slug}'
  AND u.is_active = TRUE
  AND u.nt_password_hash IS NOT NULL;
SQL;
    }

    private function buildMySqlSchema(Tenant $tenant, string $realm): string
    {
        $slug = $tenant->getSlug();

        return <<<SQL
CREATE SCHEMA IF NOT EXISTS freeradius_managedidp;

CREATE OR REPLACE VIEW freeradius_managedidp.radcheck_{$slug} AS
SELECT
    CAST(u.id AS CHAR(36)) AS id,
    LOWER(CASE WHEN INSTR(u.username, '@') > 0 THEN u.username ELSE CONCAT(u.username, '@{$realm}') END) AS username,
    'NT-Password' AS attribute,
    ':=' AS op,
    UPPER(u.nt_password_hash) AS value
FROM idp_users u
JOIN tenants t ON t.id = u.tenant_id
WHERE t.slug = '{$slug}'
  AND u.is_active = TRUE
  AND u.nt_password_hash IS NOT NULL;
SQL;
    }
}
