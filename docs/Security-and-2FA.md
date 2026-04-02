# Security and 2FA

## Current Security Controls

The platform currently provides:

- role-based administration
- tenant-scoped management
- password hashing for platform administrators
- managed password lifecycle for tenant-local users
- tokenized invitation and password reset flows
- request ID support for tracing failures
- structured error pages instead of exposed stack traces
- tenant MFA policy fields

## 2FA Status

Two-factor authentication is not yet a complete production-enforced feature across all account types.

What already exists in the platform design:

- tenant-level MFA policy values:
  - `none`
  - `optional`
  - `required`
- account data model support for TOTP-related fields
- runtime policy hooks intended for future enforcement

What still needs to be completed before broad production enforcement:

- self-service TOTP enrollment UI across all account types
- QR provisioning and code confirmation flow
- login challenge after password verification
- backup codes
- account recovery workflow
- administrator reset path
- full enforcement logic for `required`

## Recommended Rollout Plan

### Phase 1: Platform administrators

- optional enrollment
- recovery workflow
- challenge during platform login

### Phase 2: Tenant administrators

- tenant-admin enrollment and challenge
- auditable reset and support workflow

### Phase 3: Tenant-local users

- enrollment in tenant account area
- challenge in tenant sign-in flow
- recovery path

### Phase 4: Enforcement

- `none`: no MFA requirement
- `optional`: MFA available but not enforced
- `required`: sign-in blocked until enrollment and successful second factor

## Design Guidance

- use TOTP before SMS
- add backup codes before enabling mandatory MFA
- keep recovery workflows auditable
- apply the same policy model to local login and SP-initiated SSO
