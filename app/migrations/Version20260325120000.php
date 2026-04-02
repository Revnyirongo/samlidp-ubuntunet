<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TOTP columns for tenant-local users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idp_users ADD totp_secret VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE idp_users ADD totp_enabled BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idp_users DROP totp_enabled');
        $this->addSql('ALTER TABLE idp_users DROP totp_secret');
    }
}
