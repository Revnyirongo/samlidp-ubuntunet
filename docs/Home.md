# eduID.africa Platform Documentation

This documentation set covers deployment, administration, federation operations, user lifecycle management, and troubleshooting for the multi-tenant SAML Identity Provider platform.

The platform is intended for research and education identity operations and can also be used in other shared SAML identity deployments where each tenant operates its own IdP under a managed service.

## Documentation Map

- [Architecture](Architecture.md)
- [Installation](Installation.md)
- [Configuration](Configuration.md)
- [Tenant Operations](Tenant-Operations.md)
- [Service Providers and Federations](Service-Providers-and-Federations.md)
- [User Lifecycle and Email](User-Lifecycle-and-Email.md)
- [Operations](Operations.md)
- [Deployment Checklist](Deployment-Checklist.md)
- [Security and 2FA](Security-and-2FA.md)
- [Troubleshooting](Troubleshooting.md)
- [Publishing to GitLab Wiki](Publishing-to-GitLab-Wiki.md)

## What The Platform Provides

- A central administration portal for a shared identity service
- Per-tenant SAML 2.0 IdP endpoints
- Tenant branding, metadata profile, and contact controls
- Service provider onboarding from metadata URL, XML, and federation aggregates
- Tenant-local user management for database-backed tenants
- External authentication models for LDAP, upstream SAML, and RADIUS-backed tenants
- Federation metadata publication and filtering
- Background jobs for metadata refresh and runtime configuration regeneration

## Main Endpoints

- Platform home: `https://example.com/`
- Platform login: `https://example.com/login`
- Platform health check: `https://example.com/healthz`
- Tenant metadata: `https://<tenant-slug>.example.com/saml2/idp/metadata.php`
- Tenant SSO service: `https://<tenant-slug>.example.com/saml2/idp/SSOService.php`
- Tenant metadata API: `https://example.com/api/tenant/<tenant-slug>/metadata`
- Federation metadata: `https://example.com/api/federation/metadata`
- Federation metadata by slug: `https://example.com/api/federation/<federation-slug>/metadata`

## Recommended Reading Order

1. [Architecture](Architecture.md)
2. [Installation](Installation.md)
3. [Configuration](Configuration.md)
4. [Tenant Operations](Tenant-Operations.md)
5. [Service Providers and Federations](Service-Providers-and-Federations.md)
6. [Operations](Operations.md)
7. [Deployment Checklist](Deployment-Checklist.md)
8. [Troubleshooting](Troubleshooting.md)

## Naming Convention

All hostnames in this documentation use neutral examples:

- main platform host: `example.com`
- tenant host: `<tenant-slug>.example.com`

Replace these with your real deployment hostnames during installation.
