# Service Providers and Federations

## Service Provider Onboarding Paths

SPs can be onboarded in three main ways:

- metadata URL import
- metadata XML import
- federation aggregate ingestion

## Tenant SP Import

From the tenant administration page, operators can import SP metadata and then review or approve it.

The platform stores normalized SP metadata and generates the SimpleSAMLphp remote metadata required by the IdP runtime.

## Approval and Release

SPs are typically:

- imported
- reviewed
- approved
- assigned effective attribute release rules

## Attribute Release

The platform supports:

- tenant-wide default release rules
- per-SP overrides
- requested-attribute visibility where available in imported metadata

Operators should validate release decisions carefully, especially for research collaboration platforms that require persistent identifiers or scoped affiliation values.

## Federation Aggregates

Tenant-level federation aggregate URLs can be configured to support periodic SP import from trusted metadata sources.

Federation refresh is available through:

```bash
make metadata-refresh
make metadata-refresh-tenant slug=<tenant-slug>
```

## Public Metadata Endpoints

### Tenant metadata

```text
https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

### Tenant metadata API view

```text
https://example.com/api/tenant/<tenant-slug>/metadata
```

### Full federation aggregate

```text
https://example.com/api/federation/metadata
```

### Federation-filtered aggregate

```text
https://example.com/api/federation/<federation-slug>/metadata
```

## Metadata Quality Expectations

For production federation use, ensure each tenant has:

- correct entity ID
- accurate display name
- valid contact details
- branding/logo where appropriate
- registration and federation profile fields
- correct scopes and domain hints

## Common SP Metadata Failure Modes

- missing ACS URL
- duplicate entity IDs with inconsistent data
- malformed certificates
- imported metadata without valid bindings
- SP metadata present in old federation exports but absent in the active runtime configuration

If a tenant reports `Metadata not found`, verify:

1. the SP exists in the application database
2. it is approved
3. it has a valid ACS URL
4. generated SimpleSAMLphp remote metadata has been regenerated
