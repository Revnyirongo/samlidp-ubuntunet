# Deployment Checklist

## Before First Deployment

- confirm `example.com` resolves to the target server
- confirm `*.example.com` resolves to the target server
- confirm TLS certificate coverage for the chosen hostname model
- confirm PostgreSQL connectivity
- confirm Redis connectivity
- confirm SMTP credentials and sender domain readiness
- confirm `.env` is complete
- confirm `conf/credentials/` contains the required certificate material

## First Deployment Checklist

- run `make deploy-first`
- sign in at `https://example.com/login`
- rotate bootstrap administrator credentials
- create or verify at least one tenant
- verify tenant metadata loads
- verify tenant SSO endpoint is reachable
- test password reset email delivery

## Update Deployment Checklist

- back up the database
- copy or pull the intended source release
- run `make deploy-update`
- run `make migrate` if needed
- run `make regenerate-configs`
- verify health, login, and metadata endpoints

## Federation Checklist

- verify federation aggregate URLs are reachable
- refresh metadata
- confirm expected SPs are imported
- confirm generated runtime metadata includes approved SPs

## Tenant Acceptance Checklist

- tenant branding appears correctly
- tenant metadata contains correct entity ID and contacts
- tenant authentication backend works
- tenant-local invite and reset flows work where applicable
- SP login succeeds and required attributes are released
