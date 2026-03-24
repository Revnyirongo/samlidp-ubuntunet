# Managed Multi-Tenant SAML IdP

This documentation covers deployment, configuration, operation, and troubleshooting for the Managed Multi-Tenant SAML IdP platform.

The software is designed for research and education identity operations, federation participation, and managed institutional identity services. It can also be used in other multi-tenant SAML deployments where each tenant operates its own IdP under a shared platform.

## Documentation Map

- [Architecture](Architecture.md)
- [Installation](Installation.md)
- [Configuration](Configuration.md)
- [Tenant Operations](Tenant-Operations.md)
- [Service Providers and Federations](Service-Providers-and-Federations.md)
- [User Lifecycle and Email](User-Lifecycle-and-Email.md)
- [Operations](Operations.md)
- [Security and 2FA](Security-and-2FA.md)
- [Troubleshooting](Troubleshooting.md)
- [Publishing to GitLab Wiki](Publishing-to-GitLab-Wiki.md)

## What the Platform Provides

- Central administration UI for multi-tenant IdP operations
- Per-tenant SAML 2.0 IdP endpoints
- Tenant branding and metadata profile controls
- Service provider onboarding through metadata URL, XML import, and federation aggregates
- Tenant-local user management for database-backed tenants
- External authentication models for LDAP, SAML proxy, and RADIUS-backed tenants
- Federation metadata publication endpoints
- Background metadata refresh and configuration regeneration

## Main Public Endpoints

- Platform landing page: `https://example.com/`
- Platform login: `https://example.com/login`
- Platform health check: `https://example.com/healthz`
- Tenant metadata: `https://<tenant-slug>.example.com/saml2/idp/metadata.php`
- Tenant SSO service: `https://<tenant-slug>.example.com/saml2/idp/SSOService.php`
- Tenant metadata API: `https://example.com/api/tenant/<tenant-slug>/metadata`
- Federation metadata aggregate: `https://example.com/api/federation/metadata`
- Federation metadata by federation slug: `https://example.com/api/federation/<federation-slug>/metadata`

## Recommended Reading Order

1. [Architecture](Architecture.md)
2. [Installation](Installation.md)
3. [Configuration](Configuration.md)
4. [Tenant Operations](Tenant-Operations.md)
5. [Service Providers and Federations](Service-Providers-and-Federations.md)
6. [Operations](Operations.md)
7. [Troubleshooting](Troubleshooting.md)

## Scope of the 2FA Documentation

The platform already contains some MFA-related schema and policy hooks. End-user two-factor enrollment and enforcement are not yet complete. The current state and rollout plan are documented in [Security and 2FA](Security-and-2FA.md).
