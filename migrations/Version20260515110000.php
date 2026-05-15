<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Airbnb usability check reports and owner email';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->addSql('ALTER TABLE apartment ADD COLUMN owner_email VARCHAR(180) DEFAULT NULL');

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql('CREATE TABLE airbnb_check (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, apartment_id INTEGER NOT NULL, created_by_id INTEGER DEFAULT NULL, score INTEGER NOT NULL, missing_issue_count INTEGER NOT NULL, status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, report_sent_at DATETIME DEFAULT NULL, CONSTRAINT FK_AIRBNB_CHECK_APARTMENT FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE, CONSTRAINT FK_AIRBNB_CHECK_USER FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX IDX_BDAEBC5B176DFE85 ON airbnb_check (apartment_id)');
            $this->addSql('CREATE INDEX IDX_BDAEBC5BB03A8386 ON airbnb_check (created_by_id)');
            $this->addSql('CREATE TABLE airbnb_check_room (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, check_id INTEGER NOT NULL, room_key VARCHAR(80) NOT NULL, room_name VARCHAR(120) NOT NULL, room_type VARCHAR(80) NOT NULL, icon VARCHAR(80) NOT NULL, display_order INTEGER NOT NULL, score INTEGER NOT NULL, CONSTRAINT FK_AIRBNB_CHECK_ROOM_CHECK FOREIGN KEY (check_id) REFERENCES airbnb_check (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX IDX_B5508EE7709385E7 ON airbnb_check_room (check_id)');
            $this->addSql('CREATE TABLE airbnb_check_equipment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, room_id INTEGER NOT NULL, equipment_key VARCHAR(100) NOT NULL, name VARCHAR(150) NOT NULL, category VARCHAR(120) NOT NULL, importance VARCHAR(30) NOT NULL, weight INTEGER NOT NULL, status VARCHAR(40) DEFAULT NULL, note CLOB DEFAULT NULL, photo_path VARCHAR(255) DEFAULT NULL, task_label VARCHAR(180) DEFAULT NULL, display_order INTEGER NOT NULL, updated_at DATETIME DEFAULT NULL, CONSTRAINT FK_AIRBNB_CHECK_EQUIPMENT_ROOM FOREIGN KEY (room_id) REFERENCES airbnb_check_room (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX IDX_8F65E17654177093 ON airbnb_check_equipment (room_id)');

            return;
        }

        $this->addSql('CREATE TABLE airbnb_check (id INT AUTO_INCREMENT NOT NULL, apartment_id INT NOT NULL, created_by_id INT DEFAULT NULL, score INT NOT NULL, missing_issue_count INT NOT NULL, status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', report_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_BDAEBC5B176DFE85 (apartment_id), INDEX IDX_BDAEBC5BB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE airbnb_check_room (id INT AUTO_INCREMENT NOT NULL, check_id INT NOT NULL, room_key VARCHAR(80) NOT NULL, room_name VARCHAR(120) NOT NULL, room_type VARCHAR(80) NOT NULL, icon VARCHAR(80) NOT NULL, display_order INT NOT NULL, score INT NOT NULL, INDEX IDX_B5508EE7709385E7 (check_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE airbnb_check_equipment (id INT AUTO_INCREMENT NOT NULL, room_id INT NOT NULL, equipment_key VARCHAR(100) NOT NULL, name VARCHAR(150) NOT NULL, category VARCHAR(120) NOT NULL, importance VARCHAR(30) NOT NULL, weight INT NOT NULL, status VARCHAR(40) DEFAULT NULL, note LONGTEXT DEFAULT NULL, photo_path VARCHAR(255) DEFAULT NULL, task_label VARCHAR(180) DEFAULT NULL, display_order INT NOT NULL, updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8F65E17654177093 (room_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE airbnb_check ADD CONSTRAINT FK_AIRBNB_CHECK_APARTMENT FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE airbnb_check ADD CONSTRAINT FK_AIRBNB_CHECK_USER FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE airbnb_check_room ADD CONSTRAINT FK_AIRBNB_CHECK_ROOM_CHECK FOREIGN KEY (check_id) REFERENCES airbnb_check (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE airbnb_check_equipment ADD CONSTRAINT FK_AIRBNB_CHECK_EQUIPMENT_ROOM FOREIGN KEY (room_id) REFERENCES airbnb_check_room (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql('DROP TABLE airbnb_check_equipment');
            $this->addSql('DROP TABLE airbnb_check_room');
            $this->addSql('DROP TABLE airbnb_check');

            return;
        }

        $this->addSql('ALTER TABLE airbnb_check_equipment DROP FOREIGN KEY FK_AIRBNB_CHECK_EQUIPMENT_ROOM');
        $this->addSql('ALTER TABLE airbnb_check_room DROP FOREIGN KEY FK_AIRBNB_CHECK_ROOM_CHECK');
        $this->addSql('ALTER TABLE airbnb_check DROP FOREIGN KEY FK_AIRBNB_CHECK_APARTMENT');
        $this->addSql('ALTER TABLE airbnb_check DROP FOREIGN KEY FK_AIRBNB_CHECK_USER');
        $this->addSql('DROP TABLE airbnb_check_equipment');
        $this->addSql('DROP TABLE airbnb_check_room');
        $this->addSql('DROP TABLE airbnb_check');
        $this->addSql('ALTER TABLE apartment DROP COLUMN owner_email');
    }
}
