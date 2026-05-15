<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515124500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add apartment consumable stock tracking';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql('CREATE TABLE consumable_item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, apartment_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, unit VARCHAR(40) DEFAULT NULL, minimum_quantity INTEGER NOT NULL, current_quantity INTEGER DEFAULT NULL, active BOOLEAN NOT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_CONSUMABLE_ITEM_APARTMENT FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_ITEM_APARTMENT ON consumable_item (apartment_id)');
            $this->addSql('CREATE TABLE consumable_check (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, checkout_id INTEGER NOT NULL, consumable_item_id INTEGER NOT NULL, apartment_id INTEGER NOT NULL, checked_by_id INTEGER DEFAULT NULL, restocked_by_id INTEGER DEFAULT NULL, status VARCHAR(255) NOT NULL, note CLOB DEFAULT NULL, checked_at DATETIME NOT NULL, restocked_at DATETIME DEFAULT NULL, CONSTRAINT FK_CONSUMABLE_CHECK_CHECKOUT FOREIGN KEY (checkout_id) REFERENCES checkout (id) ON DELETE CASCADE, CONSTRAINT FK_CONSUMABLE_CHECK_ITEM FOREIGN KEY (consumable_item_id) REFERENCES consumable_item (id) ON DELETE CASCADE, CONSTRAINT FK_CONSUMABLE_CHECK_APARTMENT FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE, CONSTRAINT FK_CONSUMABLE_CHECK_CHECKED_BY FOREIGN KEY (checked_by_id) REFERENCES user (id) ON DELETE SET NULL, CONSTRAINT FK_CONSUMABLE_CHECK_RESTOCKED_BY FOREIGN KEY (restocked_by_id) REFERENCES user (id) ON DELETE SET NULL)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_CHECKOUT ON consumable_check (checkout_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_ITEM ON consumable_check (consumable_item_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_APARTMENT ON consumable_check (apartment_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_CHECKED_BY ON consumable_check (checked_by_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_RESTOCKED_BY ON consumable_check (restocked_by_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CONSUMABLE_CHECK_CHECKOUT_ITEM ON consumable_check (checkout_id, consumable_item_id)');

            return;
        }

        $this->addSql('CREATE TABLE consumable_item (id INT AUTO_INCREMENT NOT NULL, apartment_id INT NOT NULL, name VARCHAR(120) NOT NULL, unit VARCHAR(40) DEFAULT NULL, minimum_quantity INT NOT NULL, current_quantity INT DEFAULT NULL, active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CONSUMABLE_ITEM_APARTMENT (apartment_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE consumable_check (id INT AUTO_INCREMENT NOT NULL, checkout_id INT NOT NULL, consumable_item_id INT NOT NULL, apartment_id INT NOT NULL, checked_by_id INT DEFAULT NULL, restocked_by_id INT DEFAULT NULL, status VARCHAR(255) NOT NULL, note LONGTEXT DEFAULT NULL, checked_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', restocked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CONSUMABLE_CHECK_CHECKOUT (checkout_id), INDEX IDX_CONSUMABLE_CHECK_ITEM (consumable_item_id), INDEX IDX_CONSUMABLE_CHECK_APARTMENT (apartment_id), INDEX IDX_CONSUMABLE_CHECK_CHECKED_BY (checked_by_id), INDEX IDX_CONSUMABLE_CHECK_RESTOCKED_BY (restocked_by_id), UNIQUE INDEX UNIQ_CONSUMABLE_CHECK_CHECKOUT_ITEM (checkout_id, consumable_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE consumable_item ADD CONSTRAINT FK_CONSUMABLE_ITEM_APARTMENT FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consumable_check ADD CONSTRAINT FK_CONSUMABLE_CHECK_CHECKOUT FOREIGN KEY (checkout_id) REFERENCES checkout (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consumable_check ADD CONSTRAINT FK_CONSUMABLE_CHECK_ITEM FOREIGN KEY (consumable_item_id) REFERENCES consumable_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consumable_check ADD CONSTRAINT FK_CONSUMABLE_CHECK_APARTMENT FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consumable_check ADD CONSTRAINT FK_CONSUMABLE_CHECK_CHECKED_BY FOREIGN KEY (checked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE consumable_check ADD CONSTRAINT FK_CONSUMABLE_CHECK_RESTOCKED_BY FOREIGN KEY (restocked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if (!$platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql('ALTER TABLE consumable_check DROP FOREIGN KEY FK_CONSUMABLE_CHECK_RESTOCKED_BY');
            $this->addSql('ALTER TABLE consumable_check DROP FOREIGN KEY FK_CONSUMABLE_CHECK_CHECKED_BY');
            $this->addSql('ALTER TABLE consumable_check DROP FOREIGN KEY FK_CONSUMABLE_CHECK_APARTMENT');
            $this->addSql('ALTER TABLE consumable_check DROP FOREIGN KEY FK_CONSUMABLE_CHECK_ITEM');
            $this->addSql('ALTER TABLE consumable_check DROP FOREIGN KEY FK_CONSUMABLE_CHECK_CHECKOUT');
            $this->addSql('ALTER TABLE consumable_item DROP FOREIGN KEY FK_CONSUMABLE_ITEM_APARTMENT');
        }

        $this->addSql('DROP TABLE consumable_check');
        $this->addSql('DROP TABLE consumable_item');
    }
}
