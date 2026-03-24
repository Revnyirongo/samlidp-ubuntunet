# User Lifecycle and Email

## Platform Administrator Accounts

Platform administrator self-service endpoints:

- registration request: `/register`
- forgot password: `/forgot-password`
- reset password: `/reset-password/{token}`

Registration requests are reviewed by platform operators.

## Tenant-Local User Accounts

For database-backed tenants, the platform supports:

- tenant self-registration request
- invitation-driven first password setup
- tenant password reset
- direct admin-created local users
- bulk import

## Tenant Self-Registration Flow

1. user opens `/tenant/{slug}/register`
2. user submits full name, username, email, and optional supporting information
3. request is stored for review
4. tenant reviewers receive notification email where mail is enabled
5. tenant admin approves or rejects the request
6. if approved, the user receives a set-password link
7. the user sets a password and continues to the tenant login flow

## Password Reset Flow

### Platform administrators

1. request submitted on `/forgot-password`
2. email with token is sent
3. user resets password on `/reset-password/{token}`
4. user returns to `/login`

### Tenant-local users

1. request submitted on `/tenant/{slug}/forgot-password`
2. email with token is sent
3. user resets password on `/tenant-users/reset/{token}`
4. user is directed back toward the tenant login flow

## Invitation Flow

Tenant administrators can send invite emails from the tenant user management screen.

The invite flow is based on a set-password token:

- inactive or newly created user receives invite
- user sets first password
- user account becomes active

## Mail Delivery Requirements

The following should be validated in every production deployment:

- SMTP credentials
- sender domain alignment
- DKIM/SPF/DMARC on the sending domain
- reachable sender address
- successful test delivery to external mailboxes

## Mail Testing

The application includes a test email console command:

```bash
docker compose exec -T -u 1000:1000 app php bin/console app:mail:test user@example.org
```

If the command is unavailable in a running container, confirm the server is built from the current source tree.

## Operational Notes

- a disabled mail transport does not replace the need to test workflow behavior
- operators should validate both success and failure cases
- user-facing messages should remain generic and should not expose stack traces or transport internals
