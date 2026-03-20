# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [1.0.3] - 2026-03-20

### Changed
- Replaced UbuntuNet deployment hostname examples with `example.com` placeholders across documentation, templates, defaults, and tests.
- Switched generated tenant URLs to use the configured `SAMLIDP_HOSTNAME` instead of a hardcoded deployment hostname.
- Updated deploy defaults and certificate mount names to use neutral example-oriented naming.

## [1.0.2] - 2026-03-20

### Changed
- Replaced institution-specific placeholder content with neutral research and education examples.
- Scoped tenant-admin views and dashboard data to managed tenants only.
- Simplified tenant onboarding with automatic defaults for organisation and federation metadata fields.
- Simplified tenant self-registration with username suggestions derived from the supplied name.
- Rewrote repository documentation in a neutral, release-oriented format.

## [1.0.1] - 2026-03-20

### Added
- Tenant branding across admin, landing, and SSO login surfaces.
- Tenant-local user invitation, reset, and self-registration flows.
- Tenant-level eduroam authentication kit generation for managed database tenants.
- Richer tenant and federation metadata fields suitable for federation publication.
- Public landing page, branded error pages, and request ID surfacing.
- Version header and release metadata wiring.

### Changed
- Hardened nginx routing for tenant subdomains and Docker-backed upstream resolution.
- Improved SimpleSAMLphp tenant config generation for metadata, branding, and auth sources.
- Improved SP metadata import, requested-attribute handling, and attribute release controls.
- Improved email workflow reporting with clearer operator feedback on delivery failure.

### Fixed
- Intermittent `502 Bad Gateway` errors caused by stale or failed upstream resolution.
- SSP 2.x compatibility issues in tenant authentication handlers.
- Tenant metadata generation errors for newly created tenants.
- Tenant logo rendering during SSO login.
- Production deployment drift between local code and server images.

## [1.0.0] - 2026-03-19

### Added
- First production-ready managed multitenant SAML IdP release for UbuntuNet Alliance.
