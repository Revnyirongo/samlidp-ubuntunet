# Architecture

## Core Components

- `app`: Symfony application for administration, APIs, background tasks, and configuration generation
- `simplesamlphp`: SAML IdP runtime
- `nginx`: public TLS entry point and tenant-aware reverse proxy
- `db`: PostgreSQL application database
- `redis`: queue, cache, and coordination store
- `worker`: asynchronous/background task worker
- `scheduler`: scheduled job runner

## Runtime Model

The platform separates administration and SAML runtime responsibilities:

- Symfony manages tenants, service providers, branding, user lifecycle, federation metadata, and generated configuration
- SimpleSAMLphp serves tenant SAML endpoints and performs authentication
- Nginx routes requests between the platform and the tenant SAML runtime based on path and host

## Tenant Model

Each tenant is represented as a distinct identity provider under its own hostname:

```text
https://<tenant-slug>.example.com
```

Each tenant has:

- a unique slug
- SAML entity ID and endpoint set
- branding and metadata profile fields
- an authentication backend type
- optional administrators assigned to manage it
- optional publication rules for federation exports

## Supported Authentication Models

- `database`: tenant-local managed user store
- `ldap`: external LDAP or Active Directory
- `saml`: upstream SAML IdP proxy model
- `radius`: external RADIUS integration

## Metadata Flow

### Hosted tenant metadata

Tenant metadata is generated from tenant configuration and exposed through:

```text
https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

### Service provider metadata

SP metadata can come from:

- direct import by metadata URL
- direct import by XML paste/upload
- tenant federation aggregate refresh

Imported SP metadata is normalized before being written into the generated SimpleSAMLphp remote metadata.

### Federation publication

The platform exposes:

- full federation aggregate metadata
- federation-filtered metadata
- tenant metadata API views

## Data Ownership and Access Control

- super administrators can manage all tenants and platform-wide settings
- tenant administrators are expected to manage only tenants assigned to them
- tenant-local users exist only inside the tenant-local authentication model

## Branding Model

There are two branding layers:

- platform branding for the main site and admin surface
- tenant branding for SAML login experiences and tenant-specific workflows

## Background Jobs

Background and scheduled tasks handle:

- federation metadata refresh
- tenant configuration regeneration
- certificate checks
- deferred notifications or asynchronous work

## Deployment Pattern

The standard deployment pattern is:

1. wildcard DNS for tenant hosts
2. TLS certificate covering the main host and tenant hosts
3. Docker Compose deployment
4. PostgreSQL and Redis as local or external services
5. scheduled refresh of metadata and generated configuration
