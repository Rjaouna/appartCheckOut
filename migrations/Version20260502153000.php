<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les étapes d’accès appartement avec texte, image et ordre d’affichage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE apartment_access_step (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, apartment_id INTEGER NOT NULL, instruction CLOB NOT NULL, image_path VARCHAR(255) DEFAULT NULL, display_order INTEGER NOT NULL, CONSTRAINT FK_C3098D9E4DF84261 FOREIGN KEY (apartment_id) REFERENCES apartment (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_C3098D9E4DF84261 ON apartment_access_step (apartment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE apartment_access_step');
    }
}
