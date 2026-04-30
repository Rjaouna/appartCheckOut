<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l historique de statuts des anomalies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE anomaly_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, anomaly_id INTEGER NOT NULL, changed_by_id INTEGER DEFAULT NULL, from_status VARCHAR(255) DEFAULT NULL, to_status VARCHAR(255) NOT NULL, changed_at DATETIME NOT NULL, CONSTRAINT FK_DF7E0FBBA6E38D10 FOREIGN KEY (anomaly_id) REFERENCES anomaly (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF7E0FBBB03A8386 FOREIGN KEY (changed_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_DF7E0FBBA6E38D10 ON anomaly_status_history (anomaly_id)');
        $this->addSql('CREATE INDEX IDX_DF7E0FBBB03A8386 ON anomaly_status_history (changed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE anomaly_status_history');
    }
}
