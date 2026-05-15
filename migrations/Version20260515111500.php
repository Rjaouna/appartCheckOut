<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515111500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add icon metadata to Airbnb check equipments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE airbnb_check_equipment ADD COLUMN icon VARCHAR(80) NOT NULL DEFAULT 'box-seam'");
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            return;
        }

        $this->addSql('ALTER TABLE airbnb_check_equipment DROP COLUMN icon');
    }
}
