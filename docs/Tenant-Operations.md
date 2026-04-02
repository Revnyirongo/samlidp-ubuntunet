# Tenant Operations

## Tenant Creation

Create tenants from:

```text
https://example.com/admin/tenants/new
```

Key tenant fields include:

- institution name
- slug
- organisation name and URL
- technical contact details
- branding
- authentication backend
- metadata profile values
- attribute release defaults
- federation publication settings
- optional eduroam-related guidance fields

## Tenant Hostname and Entity ID

For a tenant with slug `tenant-a` on `example.com`:

- tenant base URL: `https://tenant-a.example.com`
- metadata URL: `https://tenant-a.example.com/saml2/idp/metadata.php`
- SSO URL: `https://tenant-a.example.com/saml2/idp/SSOService.php`

## Branding

Tenant branding supports:

- uploaded logo
- externally hosted logo URL
- tenant display name on tenant-facing login pages

Tenant branding appears on:

- tenant SAML sign-in pages
- tenant account workflows
- tenant metadata-related operator views

## Authentication Backend Selection

### Database

Use this when the platform will manage user accounts directly.

Enabled features:

- local users
- invitations
- password resets
- self-service registration requests
- bulk import

### LDAP / AD

Use this when the tenant already has an LDAP or Active Directory source.

### SAML Proxy

Use this when the tenant authenticates against an upstream SAML identity source.

### RADIUS

Use this when the tenant authentication model is external RADIUS.

## Tenant Administrator Scope

Tenant administrators are intended to manage only:

- tenants assigned to them
- tenant-local users inside those tenants
- SPs and federation settings attached to those tenants

Super administrators manage the full installation.

## Tenant User Management

Tenant-local users are managed from:

```text
/admin/tenants/{tenant-id}/users
```

Available actions:

- create user
- edit user
- activate or suspend user
- send invite
- send reset
- bulk import by CSV

## Tenant Registration Requests

Database-backed tenants can accept self-service registration requests at:

```text
https://<tenant-slug>.example.com/tenant/<tenant-slug>/register
```

Tenant administrators review those requests from:

```text
/admin/tenants/{tenant-id}/registration-requests
```

## Tenant Password Reset

Database-backed tenants can use:

```text
https://<tenant-slug>.example.com/tenant/<tenant-slug>/forgot-password
```

After password reset, the user is directed back into the tenant login flow.

## Tenant Configuration Actions

Tenant-level actions include:

- import SP metadata
- refresh metadata
- regenerate runtime configuration
- regenerate keypair
- activate
- suspend

## Eduroam Guidance

The platform provides authentication-side guidance for managed local-user tenants. It does not replace external roaming, switchboard, or national proxy infrastructure.
