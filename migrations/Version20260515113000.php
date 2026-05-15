<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add main image to apartments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE apartment ADD COLUMN image_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            return;
        }

        $this->addSql('ALTER TABLE apartment DROP COLUMN image_path');
    }
}
