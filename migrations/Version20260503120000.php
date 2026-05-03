<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les réservations locataires et le suivi des arrivées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE apartment_reservation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, apartment_id INTEGER NOT NULL, created_by_id INTEGER DEFAULT NULL, guest_name VARCHAR(120) NOT NULL, guest_whatsapp_number VARCHAR(30) NOT NULL, arrival_date DATE NOT NULL, departure_date DATE NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, updated_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
, access_message_sent_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
, access_message_sent_count INTEGER NOT NULL)');
        $this->addSql('CREATE INDEX IDX_C56E614673A0016C ON apartment_reservation (apartment_id)');
        $this->addSql('CREATE INDEX IDX_C56E6146B03A8386 ON apartment_reservation (created_by_id)');
        $this->addSql('CREATE INDEX IDX_C56E6146EF6BBD79 ON apartment_reservation (arrival_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE apartment_reservation');
    }
}
