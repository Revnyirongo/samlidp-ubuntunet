<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant metadata profile JSON for richer federation metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenants ADD metadata_profile JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenants DROP metadata_profile');
    }
}
