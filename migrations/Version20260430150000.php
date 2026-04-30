<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260430150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la permission de gestion du suivi des anomalies sur les employes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD can_manage_anomaly_workflow BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN can_manage_anomaly_workflow');
    }
}
