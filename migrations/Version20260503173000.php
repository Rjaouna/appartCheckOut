<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lie chaque réservation à son check-out automatique de fin de séjour';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE apartment_reservation ADD COLUMN linked_checkout_id INTEGER DEFAULT NULL REFERENCES checkout (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_C56E614610474812 ON apartment_reservation (linked_checkout_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La suppression de linked_checkout_id sur SQLite nécessite une recréation manuelle de table.');
    }
}
