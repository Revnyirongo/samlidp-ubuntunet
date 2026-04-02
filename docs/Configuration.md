# Configuration

## Core Environment Variables

### Application

- `APP_ENV`
- `APP_SECRET`

### Hostname and routing

- `SAMLIDP_HOSTNAME`
- `SAMLIDP_HOSTNAME_REGEX`
- `TRUSTED_PROXIES`
- `TRUSTED_HOSTS`

### Database

- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `DB_HOST`
- `DB_PORT`
- `DATABASE_URL`
- `LEGACY_DATABASE_URL`

### Redis

- `REDIS_PASSWORD`
- `REDIS_URL`

### Container DNS

- `DNS_RESOLVER_PRIMARY`
- `DNS_RESOLVER_SECONDARY`

### Mail

- `MAILER_DSN`
- `MAILER_FROM_ADDRESS`
- `MAILER_FROM_NAME`

### SimpleSAMLphp

- `SSP_ADMIN_PASSWORD_HASH`
- `SSP_SECRET_SALT`

### TLS and key handling

- `VAULT_PASS`

### Federation

- `FEDERATION_METADATA_URLS`

### Optional monitoring

- `SENTRY_DSN`

## Database Configuration

The primary application database is PostgreSQL.

Typical container-local configuration:

```env
DATABASE_URL=postgresql://samlidp:strong-password@db:5432/samlidp?serverVersion=16&charset=utf8
```

External PostgreSQL can be used by updating the host, port, and credentials accordingly.

## Mail Configuration

The application uses Symfony Mailer. Example:

```env
MAILER_DSN=smtp://user:password@smtp.example.com:587?encryption=tls&auth_mode=login
MAILER_FROM_ADDRESS=noreply@example.com
MAILER_FROM_NAME="Managed Identity Service"
```

Validate outbound mail during installation. Password reset, invitation, and registration workflows depend on it.

## SimpleSAMLphp Secrets

Generate the admin password hash:

```bash
php -r "echo password_hash('your-admin-password', PASSWORD_BCRYPT), PHP_EOL;"
```

Generate the secret salt:

```bash
openssl rand -base64 32
```

## Legacy Import

If you are importing data from an older PostgreSQL deployment:

```env
LEGACY_DATABASE_URL=postgresql://user:password@host:5432/legacy_db?serverVersion=16&charset=utf8
```

Treat this as a controlled migration setting, not a permanent runtime dependency.

## Trusted Hosts

For production:

```env
TRUSTED_HOSTS='^(example\.com|.*\.example\.com)$'
```

## Federation Metadata Sources

For platform-wide federation metadata refresh:

```env
FEDERATION_METADATA_URLS=https://metadata.edugain.org/edugain-v2.xml
```

Per-tenant federation aggregate URLs are configured from the administration UI.
