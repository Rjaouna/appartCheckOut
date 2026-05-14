<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reservation check-in forms linked to apartment reservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reservation_checkin (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reservation_id INTEGER DEFAULT NULL, completed_by_id INTEGER DEFAULT NULL, processed_by_id INTEGER DEFAULT NULL, host_agent_name VARCHAR(120) NOT NULL, host_agent_phone VARCHAR(30) DEFAULT NULL, guest_name VARCHAR(120) NOT NULL, guest_whatsapp_number VARCHAR(30) DEFAULT NULL, apartment_name VARCHAR(150) NOT NULL, apartment_address VARCHAR(500) NOT NULL, guest_count INTEGER NOT NULL, guest_identities CLOB NOT NULL --(DC2Type:json)
        , check_in_date DATE NOT NULL --(DC2Type:date_immutable)
        , check_out_date DATE NOT NULL --(DC2Type:date_immutable)
        , check_out_time VARCHAR(5) NOT NULL, return_transport VARCHAR(160) DEFAULT NULL, extension_requested VARCHAR(80) DEFAULT NULL, visited_marrakech_before BOOLEAN DEFAULT NULL, no_unregistered_guests_accepted BOOLEAN NOT NULL, no_dual_nationality_accepted BOOLEAN NOT NULL, rules_accepted BOOLEAN NOT NULL, signature_name VARCHAR(160) DEFAULT NULL, completed_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , processed_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_RESERVATION_CHECKIN_RESERVATION FOREIGN KEY (reservation_id) REFERENCES apartment_reservation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_RESERVATION_CHECKIN_COMPLETED_BY FOREIGN KEY (completed_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_RESERVATION_CHECKIN_PROCESSED_BY FOREIGN KEY (processed_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_86F6A714B83297E7 ON reservation_checkin (reservation_id)');
        $this->addSql('CREATE INDEX IDX_86F6A71485ECDE76 ON reservation_checkin (completed_by_id)');
        $this->addSql('CREATE INDEX IDX_86F6A7142FFD4FD3 ON reservation_checkin (processed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reservation_checkin');
    }
}
