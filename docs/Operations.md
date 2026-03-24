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

```bash
make migrate
```

Run this after every deployment that introduces schema changes.

## Cache Maintenance

```bash
make cache-clear
```

## Metadata and Configuration

### Refresh metadata

```bash
make metadata-refresh
make metadata-refresh-tenant slug=<tenant-slug>
```

### Regenerate runtime config

```bash
make regenerate-configs
```

Run configuration regeneration whenever:

- tenant settings change in ways that affect SAML runtime
- SP metadata is updated
- certificates or key material change

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

## Bootstrap and Administrator Management

Create or update the initial administrator:

```bash
make bootstrap-admin
```

Create an administrator manually:

```bash
make create-admin email=admin@example.org name="Full Name" role=ROLE_ADMIN
```

## Deployment Checks

After every deployment, validate:

```bash
curl -I https://example.com/healthz
curl -I https://example.com/login
curl -I https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

For tenant SSO endpoint health, a direct request with no SAML request should usually return a client error such as `400`, which indicates the endpoint is reachable and expects a valid SAML message.

## Legacy Data Import

The platform includes a legacy import command path in the application source for one-time migration from older PostgreSQL-backed installations. Use this only against a validated migration plan and take a full backup first.

## Release Discipline

Recommended release sequence:

1. update source
2. build containers
3. apply migrations
4. clear and warm cache
5. regenerate SAML configuration
6. run health and metadata checks
