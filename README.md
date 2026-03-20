# UbuntuNet Multitenant SAML IdP

Production-grade multitenant SAML 2.0 identity provider software for the research and education community, with support for broader federation and managed identity deployments.

## Release

- Version: `1.0.3`
- Repository: `https://github.com/Revnyirongo/samlidp-ubuntunet`
- Documentation: `https://gitlab.ubuntunet.net/`

## Platform

- Symfony application for administration and APIs
- SimpleSAMLphp for SAML IdP runtime
- PostgreSQL for application data
- Redis for locks, queues, and scheduler support
- Nginx for TLS termination and wildcard tenant routing
- Worker and scheduler services for background processing

Each tenant is exposed on its own subdomain:

```text
https://<tenant-slug>.example.com
```

## Core Capabilities

- Managed multitenant SAML IdPs
- Per-tenant branding and login experience
- SP metadata import and federation aggregate ingestion
- Per-SP attribute release controls
- Tenant-local users with invitation, reset, and approval workflows
- LDAP, SAML proxy, database, and RADIUS-backed tenant authentication models
- Federation metadata generation and publication controls
- eduroam-oriented authentication guidance for managed database tenants

## Quick Start

```bash
git clone https://github.com/Revnyirongo/samlidp-ubuntunet.git
cd samlidp-ubuntunet
cp .env.example .env
mkdir -p conf/credentials
```

Provide the required values in `.env`, place the TLS certificate material under `conf/credentials/`, then deploy:

```bash
make deploy-first
```

## Minimum Production Requirements

- Docker Engine 25+
- Docker Compose V2
- wildcard DNS for `*.example.com`
- TLS certificate covering both `example.com` and `*.example.com`
- at least 4 GB RAM and 20 GB storage

## Initial Access

After the first deployment, sign in through:

```text
https://example.com/login
```

Set a strong administrator password immediately after first access.

## Tenant Onboarding

Tenant onboarding is handled from the admin portal:

```text
https://example.com/admin/tenants/new
```

The tenant form supports:

- institution details
- logo upload or hosted logo URL
- federation metadata profile values
- authentication backend selection
- attribute release policy defaults
- federation publication settings
- optional eduroam authentication guidance fields

## Service Provider Onboarding

Service providers can be onboarded by:

- metadata URL import
- raw XML paste
- periodic federation aggregate import

Imported metadata is validated before persistence and can be approved per tenant.

## Operational Endpoints

- health check: `https://example.com/healthz`
- tenant metadata: `https://<tenant-slug>.example.com/saml2/idp/metadata.php`
- tenant SSO service: `https://<tenant-slug>.example.com/saml2/idp/SSOService.php`
- federation aggregate metadata: `https://example.com/api/federation/{slug}/metadata`

## Repository Standards

- secrets and certificates are excluded from version control
- runtime-generated metadata and key material are excluded from version control
- the project includes CI workflow, changelog, contribution guidance, and security reporting policy

## Documentation

Project documentation is intended to live on the UbuntuNet GitLab wiki:

```text
https://gitlab.ubuntunet.net/
```

Use this repository for the application source, deployment assets, and release history.
