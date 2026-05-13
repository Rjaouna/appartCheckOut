<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add apartment manuals with video, short message and important notice';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE apartment_manual (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, apartment_id INT NOT NULL, title VARCHAR(160) NOT NULL, equipment_label VARCHAR(160) NOT NULL, short_message CLOB DEFAULT NULL, important_notice CLOB DEFAULT NULL, video_path VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, display_order INTEGER NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_4A8C7A5E4DF9B8C FOREIGN KEY (apartment_id) REFERENCES apartment (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4A8C7A5E4DF9B8C ON apartment_manual (apartment_id)');
        $this->addSql('CREATE INDEX IDX_4A8C7A5EEABF3A8E ON apartment_manual (equipment_label)');
        $this->addSql('CREATE INDEX IDX_4A8C7A5E4EC0013B ON apartment_manual (is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE apartment_manual');
    }
}
