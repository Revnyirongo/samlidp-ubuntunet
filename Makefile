# ── UbuntuNet SAML IdP — Makefile ────────────────────────────
# Usage: make <target>

.PHONY: help up down restart logs shell db-shell \
        migrate fixtures cache-clear \
        deploy-first deploy-update \
        encrypt-key decrypt-key \
        backup-db restore-db \
        test lint security-check \
        metadata-refresh regenerate-configs bootstrap-admin \
        create-admin

DOCKER_COMPOSE  = docker compose
APP_CONTAINER   = samlidp_app
SSP_CONTAINER   = samlidp_ssp
DB_CONTAINER    = samlidp_db

##@ General

help: ## Display this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[a-zA-Z_0-9-]+:.*?##/ { printf "  \033[36m%-25s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)

##@ Docker

up: ## Start all services
	$(DOCKER_COMPOSE) up -d
	@echo "✓ Services started. Admin: https://idp.ubuntunet.net/admin"

down: ## Stop all services
	$(DOCKER_COMPOSE) down

restart: ## Restart a specific service (make restart svc=app)
	$(DOCKER_COMPOSE) restart $(svc)

logs: ## Tail logs (make logs svc=app)
	$(DOCKER_COMPOSE) logs -f $(or $(svc),)

shell: ## Open shell in app container
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) bash

ssp-shell: ## Open shell in SimpleSAMLphp container
	$(DOCKER_COMPOSE) exec $(SSP_CONTAINER) bash

db-shell: ## Open psql shell
	$(DOCKER_COMPOSE) exec $(DB_CONTAINER) psql -U samlidp -d samlidp

##@ Deployment

deploy-first: ## First-time deployment: build, migrate, seed admin user
	@echo "==> Building images..."
	$(DOCKER_COMPOSE) build --no-cache
	@echo "==> Starting services..."
	$(DOCKER_COMPOSE) up -d db redis
	@sleep 5
	$(DOCKER_COMPOSE) up -d
	@sleep 5
	@echo "==> Running migrations..."
	$(MAKE) migrate
	@echo "==> Bootstrapping initial admin user..."
	$(MAKE) bootstrap-admin
	@echo "==> Regenerating SSP configs..."
	$(MAKE) regenerate-configs
	@echo ""
	@echo "✓ Deployment complete!"
	@echo "  Admin URL: https://idp.ubuntunet.net/admin"
	@echo "  Check .env.example for INITIAL_ADMIN_EMAIL / PASSWORD"

deploy-update: ## Update running deployment (pull, rebuild, migrate)
	@echo "==> Pulling latest images..."
	$(DOCKER_COMPOSE) pull
	@echo "==> Building app image..."
	$(DOCKER_COMPOSE) build app worker scheduler
	@echo "==> Restarting with zero downtime..."
	$(DOCKER_COMPOSE) up -d --no-deps app worker scheduler simplesamlphp
	@echo "==> Running pending migrations..."
	$(MAKE) migrate
	@echo "==> Clearing cache..."
	$(MAKE) cache-clear
	@echo "==> Regenerating SSP configs..."
	$(MAKE) regenerate-configs
	@echo "✓ Update complete."

##@ Application

migrate: ## Run database migrations
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

fixtures: ## Load initial fixtures (creates first super-admin)
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console doctrine:fixtures:load --no-interaction --append --group=initial

bootstrap-admin: ## Create or update the initial super-admin user from environment/defaults
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console app:user:create \
		--email="$(or $(INITIAL_ADMIN_EMAIL),admin@idp.ubuntunet.net)" \
		--name="$(or $(INITIAL_ADMIN_NAME),UbuntuNet Super Admin)" \
		--role=ROLE_SUPER_ADMIN \
		--password="$(or $(INITIAL_ADMIN_PASSWORD),ChangeMe123!)"

cache-clear: ## Clear and warm up Symfony cache
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console cache:clear --env=prod --no-debug

create-admin: ## Create a new admin user (make create-admin email=x@y.z name="Full Name" role=ROLE_ADMIN)
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console app:user:create \
		--email=$(or $(email),$(error email= required)) \
		--name="$(or $(name),Admin User)" \
		--role=$(or $(role),ROLE_ADMIN)

##@ IdP Operations

metadata-refresh: ## Refresh SP metadata for all tenants
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console samlidp:metadata:refresh

metadata-refresh-tenant: ## Refresh a specific tenant (make metadata-refresh-tenant slug=uon)
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console samlidp:metadata:refresh --tenant=$(slug)

regenerate-configs: ## Regenerate all SimpleSAMLphp config files
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console samlidp:config:regenerate

check-certs: ## Check for expiring SP certificates
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console samlidp:certs:check

##@ Security

encrypt-key: ## Encrypt wildcard private key with VAULT_PASS (place .key in conf/credentials/)
	@if [ -z "$(VAULT_PASS)" ]; then echo "Set VAULT_PASS env var"; exit 1; fi
	openssl aes256 -md sha256 -a \
		-k "$(VAULT_PASS)" \
		-in conf/credentials/wildcard_certificate.key \
		-out conf/credentials/wildcard_certificate.key.enc
	@echo "✓ Encrypted key written to conf/credentials/wildcard_certificate.key.enc"
	@echo "  IMPORTANT: Delete the plain .key file after encrypting!"
	@echo "  rm conf/credentials/wildcard_certificate.key"

decrypt-key: ## Decrypt wildcard key (for verification only)
	@if [ -z "$(VAULT_PASS)" ]; then echo "Set VAULT_PASS env var"; exit 1; fi
	openssl aes256 -md sha256 -a -d \
		-k "$(VAULT_PASS)" \
		-in conf/credentials/wildcard_certificate.key.enc \
		-out /tmp/wildcard_decrypted.key
	@echo "✓ Decrypted to /tmp/wildcard_decrypted.key (delete after use)"

##@ Database

backup-db: ## Backup database (make backup-db file=backup.sql.gz)
	$(DOCKER_COMPOSE) exec $(DB_CONTAINER) pg_dump -U samlidp samlidp | \
		gzip > $(or $(file),backups/samlidp_$(shell date +%Y%m%d_%H%M%S).sql.gz)
	@echo "✓ Database backed up."

restore-db: ## Restore database from backup (make restore-db file=backup.sql.gz)
	@if [ -z "$(file)" ]; then echo "file= required"; exit 1; fi
	gunzip -c $(file) | $(DOCKER_COMPOSE) exec -T $(DB_CONTAINER) psql -U samlidp samlidp
	@echo "✓ Database restored from $(file)"

##@ Development / QA

test: ## Run test suite
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/phpunit

lint: ## PHP syntax check
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php -l src/**/*.php

security-check: ## Check for known PHP vulnerabilities
	$(DOCKER_COMPOSE) exec $(APP_CONTAINER) php bin/console security:check
