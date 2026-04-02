# Architecture

## Overview

The platform is split into two main layers:

- a Symfony application for administration, APIs, data management, and config generation
- a SimpleSAMLphp runtime for tenant-facing SAML IdP endpoints

Nginx sits in front of both layers and routes requests based on host and path.

## Core Services

- `app`: administration UI, API endpoints, metadata normalization, configuration generation
- `simplesamlphp`: SAML IdP runtime
- `nginx`: TLS termination and tenant-aware reverse proxy
- `db`: PostgreSQL application database
- `redis`: cache, locks, queue coordination
- `worker`: asynchronous tasks
- `scheduler`: scheduled jobs such as metadata refresh and certificate checks

## Tenant Model

Each tenant is represented as an individual IdP under its own hostname:

```text
https://<tenant-slug>.example.com
```

Each tenant has:

- its own entity ID and SAML endpoints
- a chosen authentication backend
- tenant branding and metadata profile values
- optional tenant-local administrators
- service provider relationships
- federation publication settings

## Authentication Backends

The platform supports these tenant authentication models:

- `database`: managed tenant-local users
- `ldap`: external LDAP or Active Directory
- `saml`: upstream SAML identity source
- `radius`: external RADIUS-backed authentication

## Metadata Model

### Hosted tenant metadata

Tenant hosted metadata is generated from tenant configuration and served by the SAML runtime.

### Service provider metadata

SP metadata can enter the platform from:

- metadata URL import
- XML upload or paste
- tenant federation aggregate refresh

Imported metadata is normalized before it is written into the generated SimpleSAMLphp remote metadata.

### Federation publication

The platform can publish:

- full federation aggregate metadata
- federation-filtered metadata
- tenant metadata API views

## Access Control

- super administrators can manage the full installation
- tenant administrators are limited to their assigned tenants and related data
- tenant-local users only exist inside database-backed tenants

## Branding Model

The software uses two layers of branding:

- platform branding for the main site and administration surfaces
- tenant branding for tenant login pages, tenant account flows, and tenant metadata UI fields

## Runtime Flow

1. The operator creates or updates a tenant in the Symfony application.
2. The application persists tenant, SP, and federation configuration in PostgreSQL.
3. Configuration generation writes SimpleSAMLphp runtime metadata and tenant configuration.
4. Nginx routes tenant SAML traffic to the runtime.
5. The runtime authenticates users according to the tenant backend and releases attributes to approved SPs.
