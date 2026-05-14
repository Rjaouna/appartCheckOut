<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514111500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add drawn signature data to reservation check-in forms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_checkin ADD COLUMN signature_data CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__reservation_checkin AS SELECT id, reservation_id, completed_by_id, processed_by_id, host_agent_name, host_agent_phone, guest_name, guest_whatsapp_number, apartment_name, apartment_address, guest_count, guest_identities, check_in_date, check_out_date, check_out_time, return_transport, extension_requested, visited_marrakech_before, no_unregistered_guests_accepted, no_dual_nationality_accepted, rules_accepted, signature_name, completed_at, processed_at, created_at, updated_at FROM reservation_checkin');
        $this->addSql('DROP TABLE reservation_checkin');
        $this->addSql('CREATE TABLE reservation_checkin (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reservation_id INTEGER DEFAULT NULL, completed_by_id INTEGER DEFAULT NULL, processed_by_id INTEGER DEFAULT NULL, host_agent_name VARCHAR(120) NOT NULL, host_agent_phone VARCHAR(30) DEFAULT NULL, guest_name VARCHAR(120) NOT NULL, guest_whatsapp_number VARCHAR(30) DEFAULT NULL, apartment_name VARCHAR(150) NOT NULL, apartment_address VARCHAR(500) NOT NULL, guest_count INTEGER NOT NULL, guest_identities CLOB NOT NULL, check_in_date DATE NOT NULL, check_out_date DATE NOT NULL, check_out_time VARCHAR(5) NOT NULL, return_transport VARCHAR(160) DEFAULT NULL, extension_requested VARCHAR(80) DEFAULT NULL, visited_marrakech_before BOOLEAN DEFAULT NULL, no_unregistered_guests_accepted BOOLEAN NOT NULL, no_dual_nationality_accepted BOOLEAN NOT NULL, rules_accepted BOOLEAN NOT NULL, signature_name VARCHAR(160) DEFAULT NULL, completed_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, CONSTRAINT FK_RESERVATION_CHECKIN_RESERVATION FOREIGN KEY (reservation_id) REFERENCES apartment_reservation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_RESERVATION_CHECKIN_COMPLETED_BY FOREIGN KEY (completed_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_RESERVATION_CHECKIN_PROCESSED_BY FOREIGN KEY (processed_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO reservation_checkin (id, reservation_id, completed_by_id, processed_by_id, host_agent_name, host_agent_phone, guest_name, guest_whatsapp_number, apartment_name, apartment_address, guest_count, guest_identities, check_in_date, check_out_date, check_out_time, return_transport, extension_requested, visited_marrakech_before, no_unregistered_guests_accepted, no_dual_nationality_accepted, rules_accepted, signature_name, completed_at, processed_at, created_at, updated_at) SELECT id, reservation_id, completed_by_id, processed_by_id, host_agent_name, host_agent_phone, guest_name, guest_whatsapp_number, apartment_name, apartment_address, guest_count, guest_identities, check_in_date, check_out_date, check_out_time, return_transport, extension_requested, visited_marrakech_before, no_unregistered_guests_accepted, no_dual_nationality_accepted, rules_accepted, signature_name, completed_at, processed_at, created_at, updated_at FROM __temp__reservation_checkin');
        $this->addSql('DROP TABLE __temp__reservation_checkin');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_86F6A714B83297E7 ON reservation_checkin (reservation_id)');
        $this->addSql('CREATE INDEX IDX_86F6A71485ECDE76 ON reservation_checkin (completed_by_id)');
        $this->addSql('CREATE INDEX IDX_86F6A7142FFD4FD3 ON reservation_checkin (processed_by_id)');
    }
}
