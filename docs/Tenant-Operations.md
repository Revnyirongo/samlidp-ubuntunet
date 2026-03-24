# Tenant Operations

## Tenant Creation

Create tenants from:

```text
https://example.com/admin/tenants/new
```

Core tenant fields include:

- institution name
- slug
- organisation name and URL
- technical contact
- branding
- authentication backend
- federation metadata profile fields
- attribute release defaults
- optional eduroam-related configuration fields

## Tenant Hostname and Entity ID

Given slug `tenant-a` and platform hostname `example.com`:

- tenant base URL: `https://tenant-a.example.com`
- metadata URL: `https://tenant-a.example.com/saml2/idp/metadata.php`
- SSO URL: `https://tenant-a.example.com/saml2/idp/SSOService.php`

## Branding

Tenant branding supports:

- uploaded logo
- hosted logo URL
- tenant display name on the SAML login flow

Tenant branding is used on tenant-specific account and SAML authentication screens.

## Authentication Backend Selection

### Database

Use this when the platform will manage user accounts directly for the tenant.

This enables:

- local users
- invitations
- password resets
- tenant self-registration requests
- bulk import

### LDAP / AD

Use this when the tenant already has an LDAP or Active Directory source.

### SAML Proxy

Use this when the tenant delegates authentication to another SAML IdP.

### RADIUS

Use this when the tenant authentication model is external RADIUS-based.

## Tenant Administrator Scope

Tenant administrators are intended to manage only their assigned tenants and associated data. Super administrators manage the full installation.

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

Database-backed tenants can accept self-service registration requests through:

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

After password reset, the user is directed back into the tenant login flow through the tenant-specific continuation path.

## Tenant Metadata and Configuration Actions

Tenant-level actions available in the admin UI include:

- import SP metadata
- refresh metadata
- regenerate config
- regenerate keypair
- activate
- suspend

## Eduroam Configuration

The current platform provides authentication-oriented guidance for managed local-user tenants. It does not replace external switchboard or national roaming infrastructure.

Use the eduroam-related fields only where the tenant needs local authentication-side configuration guidance.
