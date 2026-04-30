<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430114000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réaligne la table anomaly_status_history avec le schéma Doctrine SQLite';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__anomaly_status_history AS SELECT id, anomaly_id, changed_by_id, from_status, to_status, changed_at FROM anomaly_status_history');
        $this->addSql('DROP TABLE anomaly_status_history');
        $this->addSql('CREATE TABLE anomaly_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, anomaly_id INTEGER NOT NULL, changed_by_id INTEGER DEFAULT NULL, from_status VARCHAR(255) DEFAULT NULL, to_status VARCHAR(255) NOT NULL, changed_at DATETIME NOT NULL, CONSTRAINT FK_8AB32F0DBAF977BB FOREIGN KEY (anomaly_id) REFERENCES anomaly (id) ON UPDATE NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_8AB32F0D828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anomaly_status_history (id, anomaly_id, changed_by_id, from_status, to_status, changed_at) SELECT id, anomaly_id, changed_by_id, from_status, to_status, changed_at FROM __temp__anomaly_status_history');
        $this->addSql('DROP TABLE __temp__anomaly_status_history');
        $this->addSql('CREATE INDEX IDX_8AB32F0DBAF977BB ON anomaly_status_history (anomaly_id)');
        $this->addSql('CREATE INDEX IDX_8AB32F0D828AD0A0 ON anomaly_status_history (changed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__anomaly_status_history AS SELECT id, anomaly_id, changed_by_id, from_status, to_status, changed_at FROM anomaly_status_history');
        $this->addSql('DROP TABLE anomaly_status_history');
        $this->addSql('CREATE TABLE anomaly_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, anomaly_id INTEGER NOT NULL, changed_by_id INTEGER DEFAULT NULL, from_status VARCHAR(255) DEFAULT NULL, to_status VARCHAR(255) NOT NULL, changed_at DATETIME NOT NULL, CONSTRAINT FK_DF7E0FBBA6E38D10 FOREIGN KEY (anomaly_id) REFERENCES anomaly (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF7E0FBBB03A8386 FOREIGN KEY (changed_by_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO anomaly_status_history (id, anomaly_id, changed_by_id, from_status, to_status, changed_at) SELECT id, anomaly_id, changed_by_id, from_status, to_status, changed_at FROM __temp__anomaly_status_history');
        $this->addSql('DROP TABLE __temp__anomaly_status_history');
        $this->addSql('CREATE INDEX IDX_DF7E0FBBA6E38D10 ON anomaly_status_history (anomaly_id)');
        $this->addSql('CREATE INDEX IDX_DF7E0FBBB03A8386 ON anomaly_status_history (changed_by_id)');
    }
}
