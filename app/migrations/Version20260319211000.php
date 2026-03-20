<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260319211000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant published federations and SP requested attributes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenants ADD published_federations JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE service_providers ADD requested_attributes JSON DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_providers DROP requested_attributes');
        $this->addSql('ALTER TABLE tenants DROP published_federations');
    }
}
