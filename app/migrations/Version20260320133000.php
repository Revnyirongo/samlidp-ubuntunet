<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320133000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-local user action tokens for invite and password reset emails';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE idp_user_action_tokens (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT pk_idp_user_action_tokens PRIMARY KEY (id),
                CONSTRAINT fk_idp_user_action_tokens_user FOREIGN KEY (user_id) REFERENCES idp_users(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_idp_user_action_tokens_hash ON idp_user_action_tokens (token_hash)');
        $this->addSql('CREATE INDEX idx_idp_user_action_tokens_user_purpose ON idp_user_action_tokens (user_id, purpose)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS idp_user_action_tokens CASCADE');
    }
}
