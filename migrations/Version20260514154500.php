<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514154500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend configurable application appearance palette';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN background_color VARCHAR(7) NOT NULL DEFAULT '#f7f7f7'");
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN surface_color VARCHAR(7) NOT NULL DEFAULT '#ffffff'");
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN text_color VARCHAR(7) NOT NULL DEFAULT '#222222'");
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN muted_color VARCHAR(7) NOT NULL DEFAULT '#6a6a6a'");
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN border_color VARCHAR(7) NOT NULL DEFAULT '#e7e7e2'");
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN success_color VARCHAR(7) NOT NULL DEFAULT '#237b4b'");
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN warning_color VARCHAR(7) NOT NULL DEFAULT '#b35a00'");
        $this->addSql("ALTER TABLE app_appearance_settings ADD COLUMN danger_color VARCHAR(7) NOT NULL DEFAULT '#b42318'");
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            return;
        }

        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN background_color');
        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN surface_color');
        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN text_color');
        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN muted_color');
        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN border_color');
        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN success_color');
        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN warning_color');
        $this->addSql('ALTER TABLE app_appearance_settings DROP COLUMN danger_color');
    }
}
