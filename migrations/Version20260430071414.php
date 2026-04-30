<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260430071414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE anomaly (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, comment CLOB NOT NULL, photo_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, checkout_id INTEGER NOT NULL, apartment_id INTEGER NOT NULL, room_id INTEGER NOT NULL, room_equipment_id INTEGER NOT NULL, created_by_id INTEGER NOT NULL, CONSTRAINT FK_F9F97563146D8724 FOREIGN KEY (checkout_id) REFERENCES checkout (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F97563176DFE85 FOREIGN KEY (apartment_id) REFERENCES apartment (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F9756354177093 FOREIGN KEY (room_id) REFERENCES room (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F97563E70DF16D FOREIGN KEY (room_equipment_id) REFERENCES room_equipment (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F9F97563B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_F9F97563146D8724 ON anomaly (checkout_id)');
        $this->addSql('CREATE INDEX IDX_F9F97563176DFE85 ON anomaly (apartment_id)');
        $this->addSql('CREATE INDEX IDX_F9F9756354177093 ON anomaly (room_id)');
        $this->addSql('CREATE INDEX IDX_F9F97563E70DF16D ON anomaly (room_equipment_id)');
        $this->addSql('CREATE INDEX IDX_F9F97563B03A8386 ON anomaly (created_by_id)');
        $this->addSql('CREATE TABLE apartment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(150) NOT NULL, reference_code VARCHAR(50) NOT NULL, address_line1 VARCHAR(255) NOT NULL, address_line2 VARCHAR(255) DEFAULT NULL, city VARCHAR(120) NOT NULL, postal_code VARCHAR(20) NOT NULL, floor VARCHAR(30) DEFAULT NULL, door_number VARCHAR(30) DEFAULT NULL, mailbox_number VARCHAR(30) DEFAULT NULL, waze_link VARCHAR(255) NOT NULL, google_maps_link VARCHAR(255) DEFAULT NULL, building_access_code VARCHAR(80) DEFAULT NULL, key_box_code VARCHAR(80) DEFAULT NULL, entry_instructions CLOB NOT NULL, condition_status VARCHAR(120) NOT NULL, bedroom_count INTEGER NOT NULL, sleeps_count INTEGER NOT NULL, owner_name VARCHAR(120) DEFAULT NULL, owner_phone VARCHAR(40) DEFAULT NULL, internal_notes CLOB DEFAULT NULL, status VARCHAR(255) NOT NULL, is_inventory_priority BOOLEAN NOT NULL, inventory_due_at DATETIME DEFAULT NULL, general_photos CLOB NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4D7E6854D6C838 ON apartment (reference_code)');
        $this->addSql('CREATE TABLE apartment_assignment (apartment_id INTEGER NOT NULL, user_id INTEGER NOT NULL, PRIMARY KEY (apartment_id, user_id), CONSTRAINT FK_D320919B176DFE85 FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D320919BA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D320919B176DFE85 ON apartment_assignment (apartment_id)');
        $this->addSql('CREATE INDEX IDX_D320919BA76ED395 ON apartment_assignment (user_id)');
        $this->addSql('CREATE TABLE checkout (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(255) NOT NULL, scheduled_at DATETIME DEFAULT NULL, started_at DATETIME DEFAULT NULL, paused_at DATETIME DEFAULT NULL, completed_at DATETIME DEFAULT NULL, pause_reason VARCHAR(255) DEFAULT NULL, block_reason VARCHAR(255) DEFAULT NULL, priority VARCHAR(30) NOT NULL, apartment_id INTEGER NOT NULL, assigned_to_id INTEGER NOT NULL, CONSTRAINT FK_AF382D4E176DFE85 FOREIGN KEY (apartment_id) REFERENCES apartment (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AF382D4EF4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_AF382D4E176DFE85 ON checkout (apartment_id)');
        $this->addSql('CREATE INDEX IDX_AF382D4EF4BD7827 ON checkout (assigned_to_id)');
        $this->addSql('CREATE TABLE checkout_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(255) DEFAULT NULL, comment CLOB DEFAULT NULL, photo_path VARCHAR(255) DEFAULT NULL, checked_at DATETIME DEFAULT NULL, sequence INTEGER NOT NULL, checkout_id INTEGER NOT NULL, room_id INTEGER NOT NULL, room_equipment_id INTEGER NOT NULL, CONSTRAINT FK_3A4D4128146D8724 FOREIGN KEY (checkout_id) REFERENCES checkout (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3A4D412854177093 FOREIGN KEY (room_id) REFERENCES room (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3A4D4128E70DF16D FOREIGN KEY (room_equipment_id) REFERENCES room_equipment (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_3A4D4128146D8724 ON checkout_line (checkout_id)');
        $this->addSql('CREATE INDEX IDX_3A4D412854177093 ON checkout_line (room_id)');
        $this->addSql('CREATE INDEX IDX_3A4D4128E70DF16D ON checkout_line (room_equipment_id)');
        $this->addSql('CREATE TABLE equipment_catalog (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(150) NOT NULL, room_type VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, is_active BOOLEAN NOT NULL, is_required BOOLEAN NOT NULL, reference_photo VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE TABLE room (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(255) NOT NULL, name VARCHAR(120) NOT NULL, display_order INTEGER NOT NULL, notes CLOB DEFAULT NULL, apartment_id INTEGER NOT NULL, CONSTRAINT FK_729F519B176DFE85 FOREIGN KEY (apartment_id) REFERENCES apartment (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_729F519B176DFE85 ON room (apartment_id)');
        $this->addSql('CREATE TABLE room_equipment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(150) NOT NULL, display_order INTEGER NOT NULL, is_active BOOLEAN NOT NULL, notes CLOB DEFAULT NULL, room_id INTEGER NOT NULL, catalog_equipment_id INTEGER DEFAULT NULL, CONSTRAINT FK_4F9135EA54177093 FOREIGN KEY (room_id) REFERENCES room (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4F9135EA6000D330 FOREIGN KEY (catalog_equipment_id) REFERENCES equipment_catalog (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4F9135EA54177093 ON room_equipment (room_id)');
        $this->addSql('CREATE INDEX IDX_4F9135EA6000D330 ON room_equipment (catalog_equipment_id)');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, full_name VARCHAR(120) NOT NULL, is_active BOOLEAN NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE anomaly');
        $this->addSql('DROP TABLE apartment');
        $this->addSql('DROP TABLE apartment_assignment');
        $this->addSql('DROP TABLE checkout');
        $this->addSql('DROP TABLE checkout_line');
        $this->addSql('DROP TABLE equipment_catalog');
        $this->addSql('DROP TABLE room');
        $this->addSql('DROP TABLE room_equipment');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
