<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add eduroam tenant profile and NT password hash for managed local users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenants ADD eduroam_profile JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE idp_users ADD nt_password_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idp_users DROP nt_password_hash');
        $this->addSql('ALTER TABLE tenants DROP eduroam_profile');
    }
}
