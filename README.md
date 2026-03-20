# UbuntuNet Multitenant SAML IdP
### Version 1.0.1
### Production Deployment Guide — idp.ubuntunet.net

---

## Overview

This is a production-grade multitenant SAML 2.0 Identity Provider as a Service built for UbuntuNet Alliance NRENs.

- Public repository: `https://github.com/Revnyirongo/samlidp-ubuntunet`
- Planned documentation home: `https://gitlab.ubuntunet.net/`
- Release line: `1.0.x`

**Architecture:**
```
Internet
   │
   ▼
Nginx (TLS termination, rate limiting, wildcard subdomain routing)
   │
   ├──► Symfony App (admin portal, tenant management, API)  ─── PostgreSQL
   │          │                                              ─── Redis
   │          └──► SimpleSAMLphp (SAML engine, per-tenant)
   │
   └──► Worker + Scheduler (metadata refresh, cert alerts)
```

**Per-tenant IdPs** are served at `<slug>.idp.ubuntunet.net` backed by a single shared SimpleSAMLphp instance whose config is dynamically written per-tenant by the Symfony app.

---

## What Was Fixed / Improved

| Issue | Solution |
|-------|----------|
| XML entity injection in metadata parsing | `LIBXML_NONET` flag; external entity loading disabled |
| Unsafe metadata XML loading | Full XPath-based parser with namespace awareness, proper error collection |
| Config file race conditions | Atomic write (tmp + rename) with Redis-backed distributed locks |
| No certificate expiry tracking | Parsed from SP cert at import; email alerts at 60/30/14/7/1 days |
| Metadata refresh not atomic | Doctrine transactions, per-SP error handling, never aborts entire batch |
| SimpleSAMLphp config drift | Scheduler regenerates every 30 min + on every tenant/SP change |
| Insecure wildcard key storage | AES-256 encrypted at rest; decrypted only at container startup |
| No brute-force protection | Symfony `login_throttling` (5 attempts / 15 min) + Nginx rate limiting |
| Wildcard cert shared across tenants | Per-tenant RSA-4096 signing keypair, wildcard only for TLS |
| No audit trail | `audit_log` table recording all admin actions with IP + user agent |
| No health check | `/healthz` endpoint checks DB + Redis, returns 503 if degraded |
| TLS config weak | TLS 1.2+, HSTS preload, OCSP stapling, secure cipher suite |
| SimpleSAMLphp 1.x (EOL) | Upgraded to SimpleSAMLphp 2.3.x |
| PHP 7.x (EOL) | PHP 8.3+ modern runtime |
| No per-tenant branding | Custom CSS/HTML per tenant, Smarty login template |
| Attribute release not per-SP | Per-SP override on top of per-tenant default policy |

---

## Prerequisites

- Docker Engine 25+ and Docker Compose V2
- A wildcard TLS certificate for `*.idp.ubuntunet.net` (and `idp.ubuntunet.net`)
- DNS wildcard record: `*.idp.ubuntunet.net → your server IP`
- DNS A record: `idp.ubuntunet.net → your server IP`
- A server with at least 4 GB RAM and 20 GB disk

---

## Initial Deployment (Step-by-Step)

### 1. Clone and configure

```bash
git clone https://github.com/Revnyirongo/samlidp-ubuntunet.git
cd samlidp-ubuntunet
cp .env.example .env
```

## Open Source Release Notes

- No secrets, certificates, or tenant runtime data are committed.
- Hidden developer-only tracking is intentionally not included.
- If telemetry is introduced later, it should be explicit, documented, and opt-in.

Edit `.env` and fill in **all** values. Minimum required:
```bash
APP_SECRET=                 # openssl rand -hex 32
DB_PASSWORD=                # strong random password
REDIS_PASSWORD=             # strong random password
VAULT_PASS=                 # strong password to encrypt the wildcard key
SSP_SECRET_SALT=            # openssl rand -base64 32
SSP_ADMIN_PASSWORD_HASH=    # php -r "echo password_hash('pass', PASSWORD_BCRYPT);"
MAILER_DSN=                 # smtp://user:pass@host:587
```

### 2. Place your wildcard certificate

```bash
mkdir -p conf/credentials

# Copy your wildcard cert + key:
cp /path/to/wildcard.crt conf/credentials/wildcard_certificate.crt
cp /path/to/wildcard.key conf/credentials/wildcard_certificate.key

# Encrypt the private key (recommended for production):
source .env   # load VAULT_PASS
make encrypt-key

# Delete the plaintext key after encrypting:
rm conf/credentials/wildcard_certificate.key
```

If you skip encryption, the plain `.key` file is used directly — acceptable for dev.

### 3. Deploy

```bash
make deploy-first
```

This will:
1. Build all Docker images
2. Start PostgreSQL and Redis
3. Start all services
4. Run database migrations
5. Seed the initial super-admin user

### 4. Set your admin password

On first login, go to `https://idp.ubuntunet.net/admin` and log in with:
- Email: `admin@idp.ubuntunet.net` (or your `INITIAL_ADMIN_EMAIL`)
- Password: `ChangeMe123!` (or your `INITIAL_ADMIN_PASSWORD`)

**Change the password immediately.**

---

## Creating Your First Tenant

### Via the Admin UI

1. Go to `https://idp.ubuntunet.net/admin/tenants/new`
2. Fill in:
   - **Slug**: e.g. `uon` (becomes `uon.idp.ubuntunet.net`)
   - **Name**: University of Nairobi
   - **Auth Type**: `ldap`
   - **LDAP Config** (JSON):
   ```json
   {
     "host": "ldaps://ldap.uon.ac.ke",
     "base_dn": "ou=people,dc=uon,dc=ac,dc=ke",
     "bind_dn": "cn=saml-bind,dc=uon,dc=ac,dc=ke",
     "bind_password": "secret",
     "user_attr": "uid",
     "search_filter": "(&(objectClass=person)(uid=%username%))",
     "start_tls": false,
     "attribute_map": {
       "uid": ["uid"],
       "mail": ["mail"],
       "cn": ["cn"],
       "eduPersonPrincipalName": ["eduPersonPrincipalName"],
       "eduPersonAffiliation": ["eduPersonAffiliation"],
       "schacHomeOrganization": ["schacHomeOrganization"]
     }
   }
   ```
3. Click **Create Tenant** — this will generate a keypair and write SSP configs automatically.
4. Click **Activate** to enable the tenant.

The IdP will now be live at `https://uon.idp.ubuntunet.net/`.

### Via CLI

```bash
# Or create a tenant via the console (useful for scripted onboarding)
docker compose exec samlidp_app php bin/console samlidp:tenant:create \
  --slug=uon \
  --name="University of Nairobi" \
  --auth-type=ldap \
  --contact=admin@uon.ac.ke
```

---

## Registering Service Providers

### Option A: Import by URL (recommended)

In the tenant detail page, enter the SP's metadata URL and click **Import SP**. The system will:
- Fetch and parse the XML
- Extract ACS URL, SLO URL, certificate, NameID format
- Record the certificate expiry date for renewal alerts
- Generate the SSP `saml20-sp-remote.php` config entry

### Option B: Federation aggregate auto-import

In the tenant Edit page, add one or more metadata aggregate URLs (one per line), e.g.:
```
https://metadata.safire.ac.za/safire-hub-metadata.xml
https://metadata.edugain.org/edugain-v2.xml
```

These are fetched every 4 hours automatically. All SPs in the aggregate are imported and auto-approved.

### Option C: Paste raw XML

Use the **Paste XML** tab on the Import SP form. Metadata is validated before import.

---

## DNS and Certificate Requirements

```
# DNS Records Required:
idp.ubuntunet.net.          A    203.0.113.10
*.idp.ubuntunet.net.        A    203.0.113.10

# Or use a CNAME:
*.idp.ubuntunet.net.        CNAME idp.ubuntunet.net.
```

The wildcard certificate must cover both `idp.ubuntunet.net` AND `*.idp.ubuntunet.net`.

Let's Encrypt does not issue wildcards via HTTP-01; use DNS-01 challenge or use a commercial cert.

---

## Operational Commands

```bash
# View logs
make logs svc=app
make logs svc=simplesamlphp
make logs svc=worker

# Refresh metadata for all tenants NOW
make metadata-refresh

# Refresh a specific tenant
make metadata-refresh-tenant slug=uon

# Regenerate all SimpleSAMLphp configs
make regenerate-configs

# Create a new admin user
make create-admin email=jane@ubuntunet.net name="Jane Doe" role=ROLE_ADMIN

# Open app shell
make shell

# Database backup
make backup-db file=backups/pre-upgrade.sql.gz

# Apply updates
make deploy-update
```

---

## Security Hardening Checklist

- [x] TLS 1.2+ only with strong cipher suite
- [x] HSTS with preload (max-age=63072000)
- [x] `X-Frame-Options: DENY` on IdP login pages
- [x] Content-Security-Policy on admin portal
- [x] Login rate limiting: 5 attempts per 15 minutes
- [x] SAML SSO rate limiting: 10 requests/min per IP
- [x] Wildcard private key AES-256 encrypted at rest
- [x] Per-tenant signing keys (not shared)
- [x] XML external entity (XXE) injection blocked
- [x] Metadata size limits enforced (50 MB max, 100 bytes min)
- [x] PHP dangerous functions disabled (`exec`, `system`, etc.)
- [x] `expose_php=Off`, `server_tokens off`
- [x] Docker containers run as non-root user
- [x] Backend network is internal (not externally routable)
- [x] Redis requires password authentication
- [x] CSRF protection on all forms
- [x] Audit log of all admin actions
- [ ] Enable 2FA for admin users (configure in Admin → Profile)
- [ ] Set up external log aggregation (Loki, ELK, etc.)
- [ ] Configure Sentry DSN for error alerting

---

## Monitoring & Alerting

### Health endpoint
```
GET https://idp.ubuntunet.net/healthz
→ {"status":"healthy","checks":{"database":"ok","cache":"ok"}}
```
Returns HTTP 503 if any check fails. Wire into your monitoring system (UptimeRobot, Nagios, Zabbix).

### SimpleSAMLphp admin page
Available at `https://<slug>.idp.ubuntunet.net/simplesaml/` (password protected).

### Logs
All containers log to stdout/stderr in structured format. Ship with Promtail/Filebeat or:
```bash
docker compose logs -f --since=1h
```

---

## SAML Federation Integration

To publish your IdPs to a federation (e.g. eduGAIN via SAFIRE/EaPConnect):

1. Each tenant's metadata is available at: `https://<slug>.idp.ubuntunet.net/saml2/idp/metadata.php`
2. For a federation aggregate covering all tenants, use: `https://idp.ubuntunet.net/api/federation/metadata`
3. Submit the metadata URL(s) to your NREN federation operator.

---

## Architecture Notes

### Multi-tenancy Model
Each tenant maps to:
- A database record (`tenants` table)
- A unique subdomain (`<slug>.idp.ubuntunet.net`)
- Generated SSP config files (`saml20-idp-hosted.php`, `saml20-sp-remote.php`, `authsources-<slug>.php`)
- A unique RSA-4096 signing keypair (stored encrypted in DB, written to disk on config generation)

The SSP instance is shared but config files are regenerated atomically whenever a tenant or SP changes.

### Config Generation Flow
```
Admin changes tenant/SP
        ↓
TenantController calls MetadataService::regenerateConfigForTenant()
        ↓
SimpleSamlphpConfigWriter acquires Redis lock
        ↓
Writes PHP files to shared volume:
  - /var/simplesamlphp/config/authsources-<slug>.php
  - /var/simplesamlphp/metadata/saml20-idp-hosted.php
  - /var/simplesamlphp/metadata/saml20-sp-remote.php
        ↓
PHP lint validates the generated file
        ↓
Atomic rename() replaces old file
        ↓
SSP picks up new config on next request (no restart needed)
```

---

## Upgrading

```bash
git pull
make deploy-update
```

Migrations run automatically. Zero-downtime is achieved by starting new containers before stopping old ones.

---

## Troubleshooting

### "Invalid metadata" on SP import
- Check the SP's metadata URL returns valid XML
- Use the **Validate XML** button before submitting
- Check for trailing whitespace or BOM characters in pasted XML
- Ensure the SP has `<md:SPSSODescriptor>` (not just an IdP descriptor)

### SSO loop / redirect loop
- Verify the SP's ACS URL is correct in the metadata
- Check `saml20-sp-remote.php` was generated: `make shell` → `cat simplesamlphp/metadata/saml20-sp-remote.php`
- Verify the IdP entity ID in SP config matches `tenant.entityId`

### LDAP authentication fails
- Test LDAP connectivity from the SSP container: `make ssp-shell` → `ldapsearch -H ldap://host -D "cn=bind,dc=x" -w pass -b "dc=x" uid=testuser`
- Check the `bind_dn` and `bind_password` in the tenant's LDAP config
- Enable SSP debug logging temporarily: `make ssp-shell` → edit `/var/simplesamlphp/config/config.php` → `'logging.level' => SimpleSAML\Logger::DEBUG`

### Metadata not refreshing
- Check the worker is running: `docker compose ps worker`
- Run manually: `make metadata-refresh-tenant slug=<slug>`
- Check if the aggregate URL is reachable from the container: `make shell` → `curl -I <url>`

### Certificate expiry alerts not sending
- Verify `MAILER_DSN` in `.env`
- Check worker logs: `make logs svc=worker`
- Run manually: `make shell` → `php bin/console samlidp:certs:check`

---

## License

MIT — UbuntuNet Alliance
