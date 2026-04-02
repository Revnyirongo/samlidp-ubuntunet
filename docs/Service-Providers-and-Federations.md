# Service Providers and Federations

## SP Onboarding Methods

Service providers can be onboarded through:

- metadata URL import
- XML upload or paste
- federation aggregate ingestion

## Tenant SP Import

From the tenant administration page, operators can import SP metadata and then review or approve it.

The platform stores normalized SP metadata and generates the SimpleSAMLphp remote metadata required by the runtime.

## Approval and Attribute Release

SPs are generally:

1. imported
2. reviewed
3. approved
4. assigned effective attribute release rules

Attribute release supports:

- tenant-wide defaults
- per-SP overrides
- requested-attribute visibility where present in imported metadata

## Federation Aggregates

Tenant-level federation aggregate URLs can be configured for periodic SP import from trusted metadata sources.

Refresh commands:

```bash
make metadata-refresh
make metadata-refresh-tenant slug=<tenant-slug>
```

## Manual SPs vs Federation SPs

The platform distinguishes between:

- manually added SPs
- federation-imported SPs

This is important operationally. Federation refresh may import thousands of SPs from a trusted aggregate, while the tenant may have registered only a small number directly.

## Public Metadata Endpoints

### Tenant metadata

```text
https://<tenant-slug>.example.com/saml2/idp/metadata.php
```

### Tenant metadata API

```text
https://example.com/api/tenant/<tenant-slug>/metadata
```

### Full federation metadata

```text
https://example.com/api/federation/metadata
```

### Federation-specific metadata

```text
https://example.com/api/federation/<federation-slug>/metadata
```

## Metadata Quality Expectations

For production federation use, ensure each tenant has:

- correct entity ID
- accurate display name
- valid contact details
- correct logo and branding data
- registration and federation profile values
- correct scopes and domain hints

## Common Metadata Problems

- missing ACS URL
- duplicate entity IDs with inconsistent data
- malformed or unusable certificates
- imported metadata missing a usable binding
- SP exists in the database but is absent from active generated runtime metadata

If the runtime reports `Metadata not found`, verify:

1. the SP exists in the application database
2. the SP is approved
3. the SP has a valid ACS URL
4. runtime configuration has been regenerated
5. the SP is not being overridden by a bad duplicate record
