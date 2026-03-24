# Configuration

## Primary Environment Variables

### Core application

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

PostgreSQL is the primary application database.

Typical container-local configuration:

```env
DATABASE_URL=postgresql://samlidp:strong-password@db:5432/samlidp?serverVersion=16&charset=utf8
```

External PostgreSQL can also be used by setting the host and credentials accordingly.

## Mail Configuration

The platform uses Symfony Mailer. Example:

```env
MAILER_DSN=smtp://user:password@smtp.example.com:587?encryption=tls&auth_mode=login
MAILER_FROM_ADDRESS=noreply@example.com
MAILER_FROM_NAME="Managed Identity Service"
```

If mail is disabled or misconfigured, registration and reset workflows can accept requests while email delivery fails. Operators should validate outbound mail during installation.

## SimpleSAMLphp Secrets

Generate the admin password hash:

```bash
php -r "echo password_hash('your-admin-password', PASSWORD_BCRYPT), PHP_EOL;"
```

Generate the secret salt:

```bash
openssl rand -base64 32
```

## Legacy Import Configuration

If migrating from an older PostgreSQL deployment, set:

```env
LEGACY_DATABASE_URL=postgresql://user:password@host:5432/legacy_db?serverVersion=16&charset=utf8
```

This value is used for one-time migration into the current schema.

## Trusted Hosts

For production, align trusted hosts with the main host and tenant wildcard:

```env
TRUSTED_HOSTS='^(example\.com|.*\.example\.com)$'
```

## Federation Metadata Sources

If you want platform-wide refresh from one or more metadata aggregates:

```env
FEDERATION_METADATA_URLS=https://metadata.edugain.org/edugain-v2.xml
```

Per-tenant aggregate URLs are configured from the tenant administration UI.
