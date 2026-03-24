# Security and 2FA

## Current Security Surface

The platform currently provides:

- role-based administration
- tenant-scoped management
- password hashing for platform administrators
- managed password lifecycle for tenant-local users
- tokenized invite and reset flows
- request ID support for tracing failures
- structured error pages instead of exposed stack traces
- MFA policy fields at tenant level

## Current 2FA State

Two-factor authentication is not fully complete as an end-user feature at this time.

What exists already:

- user fields for TOTP secret and enablement
- tenant-level MFA policy values:
  - `none`
  - `optional`
  - `required`
- legacy import mapping for older Google Authenticator style secrets
- runtime configuration hooks that emit MFA-related flags in generated tenant configuration

What is not yet complete:

- self-service TOTP enrollment UI
- QR code generation and secret confirmation flow
- OTP challenge step during platform sign-in
- OTP challenge step during tenant-local sign-in
- backup codes
- device recovery flow
- administrative 2FA reset workflow
- full enforcement logic that blocks sign-in when policy is `required`

## Recommended 2FA Rollout Plan

### Phase 1: Administrator TOTP

Scope:

- platform administrators only
- optional enrollment first
- then mandatory for super administrators

Required work:

- profile page for TOTP enrollment
- QR provisioning and code confirmation
- recovery codes
- login challenge after password verification
- admin reset and recovery path

### Phase 2: Tenant Administrator TOTP

Scope:

- tenant administrators
- tenant-level enforcement support

Required work:

- policy mapping between tenant role and required challenge
- recovery and support process
- audit logging for enrollment and disable actions

### Phase 3: Tenant-Local User TOTP

Scope:

- database-backed tenant users

Required work:

- enrollment UI in tenant account area
- challenge in tenant-local sign-in flow
- fallback recovery process
- user communication and onboarding material

### Phase 4: Policy Enforcement

Enforce:

- `none`: no MFA requirement
- `optional`: available but not enforced
- `required`: sign-in blocked until enrollment and successful second factor

## Design Recommendations

- use TOTP first, not SMS
- add backup codes before enforcing MFA
- keep recovery workflows auditable
- do not enable mandatory MFA without administrator recovery tooling
- ensure SP-initiated SSO and local account flows both respect the same policy model

## Documentation Guidance

Until the feature is complete, document 2FA as:

- planned
- partially implemented in schema/configuration
- not yet ready for mandatory production enforcement
