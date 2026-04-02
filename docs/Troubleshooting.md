# Troubleshooting

## Start With Reachability

Run:

```bash
curl -I https://example.com/healthz
curl -I https://example.com/login
curl -I https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

## Common Problems

### `502 Bad Gateway`

Typical causes:

- `app` container unavailable
- `simplesamlphp` container unavailable
- proxy upstream resolution failure
- container recreation without proxy refresh

Check:

```bash
docker compose ps
docker compose logs --tail 200 nginx
docker compose logs --tail 200 app
docker compose logs --tail 200 simplesamlphp
```

### `500 Internal Server Error`

Check:

- recent application logs
- request path
- request ID if available
- migration state
- cache state

Useful commands:

```bash
docker compose exec -T -u 1000:1000 app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T -u 1000:1000 app php bin/console cache:clear
docker compose exec -T -u 1000:1000 app php bin/console cache:warmup
```

### `Metadata not found`

This usually means the SP does not exist in active generated runtime metadata.

Verify:

1. the SP exists in the application database
2. the SP is approved
3. the SP has a valid ACS URL
4. metadata refresh completed successfully
5. runtime configuration was regenerated

### Tenant metadata endpoint fails

Check:

- tenant exists and is active
- generated hosted metadata exists
- tenant hostname resolves to the correct server
- certificate and proxy routing are valid

### Password reset or invitation email not delivered

Check:

- `MAILER_DSN`
- sender address and domain
- SMTP authentication
- outbound network reachability
- mail provider logs

Test:

```bash
docker compose exec -T -u 1000:1000 app php bin/console app:mail:test user@example.org
```

### Logout errors

Common causes:

- stale logout state reused from an old browser session
- missing or malformed SP logout endpoint
- reverse proxy base URL mismatch

Always reproduce with a fresh login and logout cycle before treating an old logout URL as a current defect.

## Logs

Useful sources:

- `docker compose logs app`
- `docker compose logs nginx`
- `docker compose logs simplesamlphp`
- Symfony logs inside the app container
- SimpleSAMLphp logs inside the runtime container

## Request IDs

Where request IDs are available, use them to correlate:

- the browser-visible error page
- reverse proxy logs
- application logs
- SimpleSAMLphp logs

## Recommended Triage Sequence

1. reproduce the issue in a fresh browser session
2. decide whether the issue is platform, tenant, or SP-specific
3. validate health and metadata endpoints
4. inspect logs
5. regenerate runtime configuration if metadata-related
6. clear cache if application behavior looks stale
