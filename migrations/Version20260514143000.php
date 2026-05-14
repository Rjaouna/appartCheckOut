<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add configurable application appearance palette';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql('CREATE TABLE app_appearance_settings (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, primary_color VARCHAR(7) NOT NULL, secondary_color VARCHAR(7) NOT NULL, tertiary_color VARCHAR(7) NOT NULL, updated_at DATETIME NOT NULL)');
        } else {
            $this->addSql('CREATE TABLE app_appearance_settings (id INT AUTO_INCREMENT NOT NULL, primary_color VARCHAR(7) NOT NULL, secondary_color VARCHAR(7) NOT NULL, tertiary_color VARCHAR(7) NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        $this->addSql("INSERT INTO app_appearance_settings (primary_color, secondary_color, tertiary_color, updated_at) VALUES ('#ff385c', '#222222', '#33ccff', CURRENT_TIMESTAMP)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_appearance_settings');
    }
}
