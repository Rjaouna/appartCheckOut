<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502210500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le verrouillage manuel de l’accès locataire sur les appartements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE apartment ADD is_tenant_access_enabled BOOLEAN NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE apartment ADD tenant_access_locked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__apartment AS SELECT id, name, reference_code, address_line1, address_line2, city, postal_code, floor, door_number, mailbox_number, waze_link, google_maps_link, building_access_code, key_box_code, entry_instructions, condition_status, bedroom_count, sleeps_count, owner_name, owner_phone, internal_notes, status, is_inventory_priority, inventory_due_at, general_photos FROM apartment');
        $this->addSql('DROP TABLE apartment');
        $this->addSql('CREATE TABLE apartment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(150) NOT NULL, reference_code VARCHAR(50) NOT NULL, address_line1 VARCHAR(255) NOT NULL, address_line2 VARCHAR(255) DEFAULT NULL, city VARCHAR(120) NOT NULL, postal_code VARCHAR(20) NOT NULL, floor VARCHAR(30) DEFAULT NULL, door_number VARCHAR(30) DEFAULT NULL, mailbox_number VARCHAR(30) DEFAULT NULL, waze_link VARCHAR(255) NOT NULL, google_maps_link VARCHAR(255) DEFAULT NULL, building_access_code VARCHAR(80) DEFAULT NULL, key_box_code VARCHAR(80) DEFAULT NULL, entry_instructions CLOB NOT NULL, condition_status VARCHAR(120) NOT NULL, bedroom_count INTEGER NOT NULL, sleeps_count INTEGER NOT NULL, owner_name VARCHAR(120) DEFAULT NULL, owner_phone VARCHAR(40) DEFAULT NULL, internal_notes CLOB DEFAULT NULL, status VARCHAR(255) NOT NULL, is_inventory_priority BOOLEAN NOT NULL, inventory_due_at DATETIME DEFAULT NULL, general_photos CLOB NOT NULL --(DC2Type:json)
)');
        $this->addSql('INSERT INTO apartment (id, name, reference_code, address_line1, address_line2, city, postal_code, floor, door_number, mailbox_number, waze_link, google_maps_link, building_access_code, key_box_code, entry_instructions, condition_status, bedroom_count, sleeps_count, owner_name, owner_phone, internal_notes, status, is_inventory_priority, inventory_due_at, general_photos) SELECT id, name, reference_code, address_line1, address_line2, city, postal_code, floor, door_number, mailbox_number, waze_link, google_maps_link, building_access_code, key_box_code, entry_instructions, condition_status, bedroom_count, sleeps_count, owner_name, owner_phone, internal_notes, status, is_inventory_priority, inventory_due_at, general_photos FROM __temp__apartment');
        $this->addSql('DROP TABLE __temp__apartment');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3D403DCCE7927C74 ON apartment (reference_code)');
    }
}
