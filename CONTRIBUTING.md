# Contributing

## Scope

This repository contains the UbuntuNet managed multitenant SAML IdP platform.

## Local Development

1. Copy `.env.example` to `.env`.
2. Provide local credentials and secrets.
3. Build and start the stack:

```bash
docker compose build
docker compose up -d
```

## Before Opening a Pull Request

Run the relevant checks:

```bash
docker compose exec -T -u 1000:1000 app php bin/console cache:clear
docker compose exec -T -u 1000:1000 app php bin/console cache:warmup
docker compose exec -T -u 1000:1000 app php bin/console doctrine:migrations:migrate --no-interaction
```

If the dev dependencies are available, also run:

```bash
cd app
php bin/phpunit
```

## Pull Request Expectations

- keep changes scoped
- include tests where practical
- update docs for behavior or deployment changes
- avoid committing secrets, certificates, or generated runtime artifacts

## Security

Use [SECURITY.md](SECURITY.md) for vulnerability reporting.
