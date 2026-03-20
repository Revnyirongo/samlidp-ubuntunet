<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: tenants, service_providers, users, tenant_admins, idp_users';
    }

    public function up(Schema $schema): void
    {
        // ── users ────────────────────────────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE users (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                email VARCHAR(180) NOT NULL,
                roles JSON NOT NULL DEFAULT '[]',
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT true,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                totp_secret VARCHAR(255) DEFAULT NULL,
                totp_enabled BOOLEAN NOT NULL DEFAULT false,
                CONSTRAINT pk_users PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');

        // ── tenants ──────────────────────────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE tenants (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                slug VARCHAR(63) NOT NULL,
                name VARCHAR(255) NOT NULL,
                entity_id VARCHAR(500) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                auth_type VARCHAR(20) NOT NULL DEFAULT 'ldap',
                ldap_config JSON DEFAULT NULL,
                saml_upstream_config JSON DEFAULT NULL,
                radius_config JSON DEFAULT NULL,
                attribute_release_policy JSON NOT NULL DEFAULT '{}',
                metadata_aggregate_urls JSON NOT NULL DEFAULT '[]',
                signing_certificate TEXT DEFAULT NULL,
                signing_private_key TEXT DEFAULT NULL,
                technical_contact_email VARCHAR(255) DEFAULT NULL,
                technical_contact_name VARCHAR(255) DEFAULT NULL,
                organization_name VARCHAR(255) DEFAULT NULL,
                organization_url VARCHAR(255) DEFAULT NULL,
                logo_url VARCHAR(255) DEFAULT NULL,
                mfa_policy VARCHAR(20) NOT NULL DEFAULT 'none',
                custom_login_css TEXT DEFAULT NULL,
                custom_login_html TEXT DEFAULT NULL,
                admin_notes TEXT DEFAULT NULL,
                metadata_last_refreshed TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_tenants PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tenants_slug ON tenants (slug)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenants_entity_id ON tenants (entity_id)');
        $this->addSql('CREATE INDEX idx_tenants_status ON tenants (status)');

        // ── service_providers ────────────────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE service_providers (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL,
                entity_id VARCHAR(500) NOT NULL,
                name VARCHAR(255) DEFAULT NULL,
                description VARCHAR(255) DEFAULT NULL,
                acs_url VARCHAR(500) DEFAULT NULL,
                slo_url VARCHAR(500) DEFAULT NULL,
                certificate TEXT DEFAULT NULL,
                name_id_format VARCHAR(255) NOT NULL DEFAULT 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
                sign_assertions BOOLEAN NOT NULL DEFAULT true,
                encrypt_assertions BOOLEAN NOT NULL DEFAULT false,
                attribute_release_override JSON DEFAULT NULL,
                approved BOOLEAN NOT NULL DEFAULT false,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                raw_metadata_xml TEXT DEFAULT NULL,
                contact_email VARCHAR(255) DEFAULT NULL,
                certificate_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                metadata_refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_service_providers PRIMARY KEY (id),
                CONSTRAINT fk_sp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_sp_tenant_entity ON service_providers (tenant_id, entity_id)');
        $this->addSql('CREATE INDEX idx_sp_tenant ON service_providers (tenant_id)');
        $this->addSql('CREATE INDEX idx_sp_approved ON service_providers (approved)');
        $this->addSql('CREATE INDEX idx_sp_cert_expiry ON service_providers (certificate_expires_at)');

        // ── tenant_admins (many-to-many) ─────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE tenant_admins (
                tenant_id UUID NOT NULL,
                user_id UUID NOT NULL,
                CONSTRAINT pk_tenant_admins PRIMARY KEY (tenant_id, user_id),
                CONSTRAINT fk_ta_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        SQL);

        // ── idp_users (local userpass auth per tenant) ───────────
        $this->addSql(<<<SQL
            CREATE TABLE idp_users (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL,
                username VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                attributes JSON NOT NULL DEFAULT '{}',
                is_active BOOLEAN NOT NULL DEFAULT true,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_idp_users PRIMARY KEY (id),
                CONSTRAINT fk_idp_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_idp_user_tenant_username ON idp_users (tenant_id, username)');

        // ── audit_log ────────────────────────────────────────────
        $this->addSql(<<<SQL
            CREATE TABLE audit_log (
                id BIGSERIAL NOT NULL,
                user_id UUID DEFAULT NULL,
                tenant_id UUID DEFAULT NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100) DEFAULT NULL,
                entity_id VARCHAR(255) DEFAULT NULL,
                data JSON DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_audit_log PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_audit_log_user ON audit_log (user_id)');
        $this->addSql('CREATE INDEX idx_audit_log_tenant ON audit_log (tenant_id)');
        $this->addSql('CREATE INDEX idx_audit_log_created ON audit_log (created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_log CASCADE');
        $this->addSql('DROP TABLE IF EXISTS idp_users CASCADE');
        $this->addSql('DROP TABLE IF EXISTS tenant_admins CASCADE');
        $this->addSql('DROP TABLE IF EXISTS service_providers CASCADE');
        $this->addSql('DROP TABLE IF EXISTS tenants CASCADE');
        $this->addSql('DROP TABLE IF EXISTS users CASCADE');
    }
}
