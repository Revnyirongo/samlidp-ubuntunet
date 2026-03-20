<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-local user self-registration requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE tenant_user_registration_requests (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                tenant_id UUID NOT NULL,
                full_name VARCHAR(255) NOT NULL,
                email VARCHAR(180) NOT NULL,
                username VARCHAR(255) NOT NULL,
                given_name VARCHAR(255) DEFAULT NULL,
                surname VARCHAR(255) DEFAULT NULL,
                affiliation VARCHAR(255) DEFAULT NULL,
                message TEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                review_notes TEXT DEFAULT NULL,
                reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_tenant_user_registration_requests PRIMARY KEY (id),
                CONSTRAINT fk_tenant_user_registration_requests_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_tenant_user_registration_requests_tenant_status ON tenant_user_registration_requests (tenant_id, status)');
        $this->addSql('CREATE INDEX idx_tenant_user_registration_requests_tenant_email ON tenant_user_registration_requests (tenant_id, email)');
        $this->addSql('CREATE INDEX idx_tenant_user_registration_requests_tenant_username ON tenant_user_registration_requests (tenant_id, username)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tenant_user_registration_requests CASCADE');
    }
}
