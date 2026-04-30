<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la photo et le numero de telephone sur les employes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD phone_number VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD photo_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN phone_number');
        $this->addSql('ALTER TABLE "user" DROP COLUMN photo_path');
    }
}
