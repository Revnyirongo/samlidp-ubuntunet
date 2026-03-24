# Installation

## Prerequisites

- Docker Engine 25 or newer
- Docker Compose V2
- PostgreSQL 16 compatible database
- Redis
- public DNS for the primary hostname
- wildcard DNS for tenant subdomains
- TLS certificate for the deployment hostname strategy

Recommended minimum host profile:

- 4 GB RAM
- 2 vCPU
- 20 GB storage

## Hostname Model

Set a primary platform hostname such as:

```text
example.com
```

Tenant IdPs are then exposed as:

```text
<tenant-slug>.example.com
```

## Clone and Prepare

```bash
git clone https://github.com/Revnyirongo/samlidp-ubuntunet.git
cd samlidp-ubuntunet
cp .env.example .env
mkdir -p conf/credentials
```

## Configure Environment

Edit `.env` and provide at least:

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

## Certificates

Place certificate material under `conf/credentials/`.

The deployment expects certificate material suitable for:

- the main platform hostname
- tenant hostnames

For wildcard-style deployments, provide coverage for both:

- `example.com`
- `*.example.com`

## First Deployment

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

After deployment:

```text
https://example.com/login
```

If you use the bootstrap variables from `.env`, rotate the bootstrap password immediately after first sign-in.

## Updating an Existing Deployment

```bash
make deploy-update
```

For source-based deployments where the code is copied to the server before build, ensure the server directory contains the latest source before rebuilding.

## Deployment Validation

At minimum verify:

```bash
curl -I https://example.com/healthz
curl -I https://example.com/login
curl -I https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

Expected results:

- health endpoint: `200`
- login page: `200`
- tenant metadata: `200`
