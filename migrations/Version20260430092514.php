<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260430092514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE anomaly ADD COLUMN updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE anomaly ADD COLUMN closed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__anomaly AS SELECT id, type, status, comment, photo_path, created_at, checkout_id, apartment_id, room_id, room_equipment_id, created_by_id FROM anomaly');
        $this->addSql('DROP TABLE anomaly');
        $this->addSql('CREATE TABLE anomaly (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, comment CLOB NOT NULL, photo_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, checkout_id INTEGER NOT NULL, apartment_id INTEGER NOT NULL, room_id INTEGER NOT NULL, room_equipment_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, CONSTRAINT FK_F9F97563146D8724 FOREIGN KEY (checkout_id) REFERENCES checkout (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F97563176DFE85 FOREIGN KEY (apartment_id) REFERENCES apartment (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F9756354177093 FOREIGN KEY (room_id) REFERENCES room (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F97563E70DF16D FOREIGN KEY (room_equipment_id) REFERENCES room_equipment (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F97563B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anomaly (id, type, status, comment, photo_path, created_at, checkout_id, apartment_id, room_id, room_equipment_id, created_by_id) SELECT id, type, status, comment, photo_path, created_at, checkout_id, apartment_id, room_id, room_equipment_id, created_by_id FROM __temp__anomaly');
        $this->addSql('DROP TABLE __temp__anomaly');
        $this->addSql('CREATE INDEX IDX_F9F97563146D8724 ON anomaly (checkout_id)');
        $this->addSql('CREATE INDEX IDX_F9F97563176DFE85 ON anomaly (apartment_id)');
        $this->addSql('CREATE INDEX IDX_F9F9756354177093 ON anomaly (room_id)');
        $this->addSql('CREATE INDEX IDX_F9F97563E70DF16D ON anomaly (room_equipment_id)');
        $this->addSql('CREATE INDEX IDX_F9F97563B03A8386 ON anomaly (created_by_id)');
    }
}
