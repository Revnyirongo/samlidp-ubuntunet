<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legacy salt columns for imported admin and tenant-local users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD legacy_salt VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE idp_users ADD legacy_salt VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idp_users DROP legacy_salt');
        $this->addSql('ALTER TABLE users DROP legacy_salt');
    }
}
