#!/bin/bash
set -euo pipefail

SSP_DIR=/var/simplesamlphp/vendor/simplesamlphp/simplesamlphp
CERT_DIR=${SSP_DIR}/cert
CONFIG_DIR=${SSP_DIR}/config
METADATA_DIR=${SSP_DIR}/metadata

export APACHE_RUN_USER=app
export APACHE_RUN_GROUP=app

TRUSTED_HOST_REGEX=$(printf '%s' "${SAMLIDP_HOSTNAME}" | sed 's/[.[\*^$()+?{|]/\\&/g')

# ── Decrypt wildcard certificate private key ─────────────────
echo "[entrypoint] Decrypting wildcard certificate..."
if [ -f /var/credentials/wildcard_certificate.key.enc ]; then
    openssl aes256 -md sha256 -a -d \
        -k "${VAULT_PASS}" \
        -in /var/credentials/wildcard_certificate.key.enc \
        -out "${CERT_DIR}/wildcard_certificate.key" 2>/dev/null
    chmod 600 "${CERT_DIR}/wildcard_certificate.key"
    cp /var/credentials/wildcard_certificate.crt "${CERT_DIR}/wildcard_certificate.crt"
    echo "[entrypoint] Certificate decrypted successfully."
elif [ -f /var/credentials/wildcard_certificate.key ]; then
    cp /var/credentials/wildcard_certificate.key "${CERT_DIR}/wildcard_certificate.key"
    cp /var/credentials/wildcard_certificate.crt "${CERT_DIR}/wildcard_certificate.crt"
    chmod 600 "${CERT_DIR}/wildcard_certificate.key"
    echo "[entrypoint] Plain certificate copied."
else
    echo "[entrypoint] WARNING: No wildcard certificate found, generating self-signed..."
    openssl req -newkey rsa:4096 -new -x509 -days 3652 -nodes \
        -subj "/CN=${SAMLIDP_HOSTNAME}" \
        -out "${CERT_DIR}/wildcard_certificate.crt" \
        -keyout "${CERT_DIR}/wildcard_certificate.key"
    chmod 600 "${CERT_DIR}/wildcard_certificate.key"
fi

# ── Generate SSP config/config.php ───────────────────────────
echo "[entrypoint] Writing SimpleSAMLphp config..."
cat > "${CONFIG_DIR}/config.php" << EOF
<?php
\$sspHost = \$_SERVER['HTTP_X_FORWARDED_HOST'] ?? \$_SERVER['HTTP_HOST'] ?? getenv('SAMLIDP_HOSTNAME') ?: 'example.com';
\$sspHost = preg_replace('/:80$/', '', (string) \$sspHost);
\$sspProto = \$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
\$sspProto = strtolower(trim(explode(',', (string) \$sspProto)[0] ?: 'https'));
\$sspBase = sprintf('%s://%s/simplesaml/', \$sspProto, \$sspHost);

\$config = [
    'baseurlpath'       => \$sspBase,
    'certdir'           => '${CERT_DIR}/',
    'loggingdir'        => '/tmp/',
    'datadir'           => '/tmp/ssp-data/',
    'tempdir'           => '/tmp/ssp-temp/',

    'technicalcontact_name'  => 'UbuntuNet IdP Admins',
    'technicalcontact_email' => 'idp-admin@example.com',

    'timezone'       => 'Africa/Nairobi',
    'secretsalt'     => '${SSP_SECRET_SALT}',
    'auth.adminpassword' => '${SSP_ADMIN_PASSWORD_HASH}',

    'admin.protectindexpage'  => true,
    'admin.protectmetadata'   => false,

    'showerrors'    => false,
    'errorreporting' => false,
    'debug'         => ['saml' => false, 'backtraces' => false, 'validatexml' => false],

    'session.duration'               => 8 * 60 * 60,
    'session.datastore.timeout'      => 4 * 60 * 60,
    'session.state.timeout'          => 60 * 60,
    'session.cookie.name'            => 'SimpleSAMLSessionID',
    'session.cookie.lifetime'        => 0,
    'session.cookie.path'            => '/',
    'session.cookie.domain'          => null,
    'session.cookie.secure'          => true,
    'session.cookie.samesite'        => 'None',
    'session.phpsession.cookiename'  => 'SimpleSAML',
    'session.phpsession.savepath'    => null,
    'session.phpsession.httponly'    => true,
    'session.rememberme.enable'      => false,

    // Single-container deployments are simpler and more reliable with PHP-backed sessions.
    'store.type' => 'phpsession',

    'enable.saml20-idp' => true,
    'enable.adfs-idp'   => false,

    'metadata.sources' => [
        ['type' => 'flatfile'],
    ],

    'metadata.sign.enable'    => true,
    'metadata.sign.algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',

    'proxy.allow_unverified_ssl_certs' => false,

    'language.available' => ['en'],
    'language.rtl'       => [],
    'language.default'   => 'en',

    'theme.use' => 'default',

    'module.enable' => [
        'admin'      => true,
        'core'       => true,
        'saml'       => true,
        'ldap'       => true,
        'statistics' => true,
        'ubuntunet'  => true,
    ],

    'logging.level'   => SimpleSAML\Logger::NOTICE,
    'logging.handler' => 'errorlog',

    'statistics.out' => [['class' => 'core:Log']],

    // Security hardening
    'metadata.validate.signature' => true,

    'trusted.url.domains' => ['^([a-z0-9-]+\\.)?${TRUSTED_HOST_REGEX}$'],
    'trusted.url.regex'   => true,

    'enable.http_post' => false,

    'assertion.allowed_clock_skew' => 180,
];
EOF

# ── Ensure data dirs exist ────────────────────────────────────
mkdir -p /tmp/ssp-data /tmp/ssp-temp
mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2
chown -R app:app "${CERT_DIR}" "${CONFIG_DIR}" "${METADATA_DIR}" /tmp/ssp-data /tmp/ssp-temp /var/run/apache2 /var/lock/apache2 /var/log/apache2

echo "[entrypoint] SimpleSAMLphp configured. Starting Apache..."
exec "$@"
