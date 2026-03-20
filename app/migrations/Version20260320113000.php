<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset tokens and self-service registration requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE user_action_tokens (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_user_action_tokens PRIMARY KEY (id),
                CONSTRAINT fk_user_action_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_action_tokens_hash ON user_action_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_user_action_tokens_user_purpose ON user_action_tokens (user_id, purpose)');

        $this->addSql(<<<SQL
            CREATE TABLE registration_requests (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                requested_tenant_id UUID DEFAULT NULL,
                full_name VARCHAR(255) NOT NULL,
                email VARCHAR(180) NOT NULL,
                organization_name VARCHAR(255) DEFAULT NULL,
                message TEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                review_notes TEXT DEFAULT NULL,
                reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_registration_requests PRIMARY KEY (id),
                CONSTRAINT fk_registration_requests_tenant FOREIGN KEY (requested_tenant_id) REFERENCES tenants(id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_registration_requests_status_created ON registration_requests (status, created_at DESC)');
        $this->addSql('CREATE INDEX idx_registration_requests_email ON registration_requests (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS registration_requests CASCADE');
        $this->addSql('DROP TABLE IF EXISTS user_action_tokens CASCADE');
    }
}
