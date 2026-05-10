<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510124500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add checked_by traceability on checkout lines';
    }

    public function up(Schema $schema): void
    {
        $isSqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;

        $this->addSql('ALTER TABLE checkout_line ADD checked_by_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_3CB69C8C341470FB ON checkout_line (checked_by_id)');

        if (!$isSqlite) {
            $this->addSql('ALTER TABLE checkout_line ADD CONSTRAINT FK_3CB69C8C341470FB FOREIGN KEY (checked_by_id) REFERENCES user (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $isSqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;

        if (!$isSqlite) {
            $this->addSql('ALTER TABLE checkout_line DROP FOREIGN KEY FK_3CB69C8C341470FB');
            $this->addSql('DROP INDEX IDX_3CB69C8C341470FB ON checkout_line');
            $this->addSql('ALTER TABLE checkout_line DROP checked_by_id');

            return;
        }

        $this->addSql('DROP INDEX IF EXISTS IDX_3CB69C8C341470FB');
        $this->addSql('CREATE TEMPORARY TABLE __temp__checkout_line AS SELECT id, checkout_id, room_id, room_equipment_id, status, comment, photo_path, checked_at, sequence FROM checkout_line');
        $this->addSql('DROP TABLE checkout_line');
        $this->addSql('CREATE TABLE checkout_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, checkout_id INT NOT NULL, room_id INT NOT NULL, room_equipment_id INT NOT NULL, status VARCHAR(255) DEFAULT NULL, comment CLOB DEFAULT NULL, photo_path VARCHAR(255) DEFAULT NULL, checked_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , sequence INTEGER NOT NULL, CONSTRAINT FK_3CB69C8CCB40E2C FOREIGN KEY (checkout_id) REFERENCES checkout (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3CB69C8C54177093 FOREIGN KEY (room_id) REFERENCES room (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3CB69C8C17FC0F99 FOREIGN KEY (room_equipment_id) REFERENCES room_equipment (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO checkout_line (id, checkout_id, room_id, room_equipment_id, status, comment, photo_path, checked_at, sequence) SELECT id, checkout_id, room_id, room_equipment_id, status, comment, photo_path, checked_at, sequence FROM __temp__checkout_line');
        $this->addSql('DROP TABLE __temp__checkout_line');
        $this->addSql('CREATE INDEX IDX_3CB69C8CCB40E2C ON checkout_line (checkout_id)');
        $this->addSql('CREATE INDEX IDX_3CB69C8C54177093 ON checkout_line (room_id)');
        $this->addSql('CREATE INDEX IDX_3CB69C8C17FC0F99 ON checkout_line (room_equipment_id)');
    }
}
