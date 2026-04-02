# User Lifecycle and Email

## Platform Administrator Accounts

Platform administrator self-service endpoints:

- registration request: `/register`
- forgot password: `/forgot-password`
- reset password: `/reset-password/{token}`

Administrator registration requests are reviewed by platform operators.

## Tenant-Local User Accounts

For database-backed tenants, the platform supports:

- self-service registration requests
- invitation-driven first password setup
- tenant password reset
- direct admin-created local users
- CSV bulk import

## Tenant Self-Registration Flow

1. user opens `/tenant/{slug}/register`
2. user submits full name, username, email, and supporting data
3. request is stored for review
4. tenant reviewers are notified where mail is enabled
5. tenant admin approves or rejects the request
6. if approved, the user receives a set-password link
7. the user sets a password and proceeds to sign-in

## Password Reset Flow

### Platform administrators

1. request submitted on `/forgot-password`
2. email with token is sent
3. password reset completed on `/reset-password/{token}`
4. user returns to `/login`

### Tenant-local users

1. request submitted on `/tenant/{slug}/forgot-password`
2. email with token is sent
3. password reset completed on `/tenant-users/reset/{token}`
4. user is directed back toward the tenant sign-in flow

## Invitation Flow

Tenant administrators can send invite emails from the tenant user management screen.

The invite flow is token-based:

- user receives invite
- user sets first password
- user account becomes active

## Mail Delivery Requirements

Validate all of the following in production:

- SMTP credentials
- sender address and sender domain
- DKIM, SPF, and DMARC on the sending domain
- outbound network reachability
- successful delivery to external mailboxes

## Mail Testing

Test mail delivery with:

```bash
docker compose exec -T -u 1000:1000 app php bin/console app:mail:test user@example.org
```

If the command is missing in a running container, confirm the server is built from the intended source tree.

## Operator Guidance

- keep user-facing error messages generic
- do not expose SMTP or stack-trace detail in browser flows
- test both success and failure behavior when validating invite and reset workflows
