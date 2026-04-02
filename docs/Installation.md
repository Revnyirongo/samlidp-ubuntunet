# Installation

## Prerequisites

- Docker Engine 25 or newer
- Docker Compose V2
- PostgreSQL 16 compatible database
- Redis
- public DNS for the main hostname
- wildcard DNS for tenant subdomains
- TLS certificate covering the hostname strategy you will use

Recommended minimum host profile:

- 2 vCPU
- 4 GB RAM
- 20 GB storage

## Hostname Model

Set a primary platform hostname such as:

```text
example.com
```

Tenant IdPs are then exposed on:

```text
<tenant-slug>.example.com
```

## Prepare The Host

Clone the repository and create the local runtime directories:

```bash
git clone https://github.com/Revnyirongo/samlidp-ubuntunet.git
cd samlidp-ubuntunet
cp .env.example .env
mkdir -p conf/credentials backups
```

## Configure The Environment

Edit `.env` and set at least:

- `APP_SECRET`
- `SAMLIDP_HOSTNAME`
- `SAMLIDP_HOSTNAME_REGEX`
- `DATABASE_URL`
- `REDIS_URL`
- `SSP_ADMIN_PASSWORD_HASH`
- `SSP_SECRET_SALT`
- `MAILER_DSN`
- `MAILER_FROM_ADDRESS`
- `MAILER_FROM_NAME`

If you are migrating from a previous PostgreSQL-based installation, also set:

- `LEGACY_DATABASE_URL`

## Certificates

Place certificate files under `conf/credentials/`.

For a wildcard deployment, provide coverage for:

- `example.com`
- `*.example.com`

## First Deployment

Run:

```bash
make deploy-first
```

This performs:

- image build
- service startup
- database migration
- initial administrator bootstrap
- SimpleSAMLphp configuration generation

## Initial Sign-In

After deployment, sign in at:

```text
https://example.com/login
```

Rotate the bootstrap administrator password immediately after first access.

## Update Deployment

For subsequent releases:

```bash
make deploy-update
```

If your deployment copies source to the server before rebuild, make sure the server directory contains the intended release before you rebuild the containers.

## Post-Install Validation

Run at minimum:

```bash
curl -I https://example.com/healthz
curl -I https://example.com/login
curl -I https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

Expected results:

- health endpoint: `200`
- login page: `200`
- tenant metadata endpoint: `200`
