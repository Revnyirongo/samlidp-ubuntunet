# Operations

## Common Commands

### Start services

```bash
make up
```

### Stop services

```bash
make down
```

### Tail logs

```bash
make logs
make logs svc=app
make logs svc=nginx
make logs svc=simplesamlphp
```

### Open shells

```bash
make shell
make ssp-shell
make db-shell
```

## Migrations

Apply schema changes:

```bash
make migrate
```

Run this after every deployment that introduces database changes.

## Cache Maintenance

```bash
make cache-clear
```

## Metadata and Runtime Configuration

### Refresh metadata

```bash
make metadata-refresh
make metadata-refresh-tenant slug=<tenant-slug>
```

### Regenerate runtime config

```bash
make regenerate-configs
```

Regenerate runtime configuration whenever:

- tenant SAML settings change
- SP metadata is imported or updated
- federation data is refreshed
- certificates or key material change

## Administrator Management

Bootstrap or update the initial administrator:

```bash
make bootstrap-admin
```

Create an administrator manually:

```bash
make create-admin email=admin@example.org name="Full Name" role=ROLE_ADMIN
```

## Database Backup

```bash
make backup-db
```

Optional named backup:

```bash
make backup-db file=backups/backup.sql.gz
```

## Database Restore

```bash
make restore-db file=backups/backup.sql.gz
```

## Post-Deployment Checks

After every deployment, verify:

```bash
curl -I https://example.com/healthz
curl -I https://example.com/login
curl -I https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

For tenant SSO endpoints, a direct request with no SAML request usually returns a client-side error such as `400`. That confirms the endpoint is reachable and expecting a valid SAML message.

## Recommended Release Sequence

1. update source
2. build or deploy containers
3. apply migrations
4. clear or warm cache
5. regenerate runtime configuration
6. validate health, login, and metadata endpoints
