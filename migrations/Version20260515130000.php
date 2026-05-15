<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add observed quantity to consumable checks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE consumable_check ADD COLUMN quantity INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql('CREATE TEMPORARY TABLE __temp__consumable_check AS SELECT id, checkout_id, consumable_item_id, apartment_id, checked_by_id, restocked_by_id, status, note, checked_at, restocked_at FROM consumable_check');
            $this->addSql('DROP TABLE consumable_check');
            $this->addSql('CREATE TABLE consumable_check (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, checkout_id INTEGER NOT NULL, consumable_item_id INTEGER NOT NULL, apartment_id INTEGER NOT NULL, checked_by_id INTEGER DEFAULT NULL, restocked_by_id INTEGER DEFAULT NULL, status VARCHAR(255) NOT NULL, note CLOB DEFAULT NULL, checked_at DATETIME NOT NULL, restocked_at DATETIME DEFAULT NULL, CONSTRAINT FK_CONSUMABLE_CHECK_CHECKOUT FOREIGN KEY (checkout_id) REFERENCES checkout (id) ON DELETE CASCADE, CONSTRAINT FK_CONSUMABLE_CHECK_ITEM FOREIGN KEY (consumable_item_id) REFERENCES consumable_item (id) ON DELETE CASCADE, CONSTRAINT FK_CONSUMABLE_CHECK_APARTMENT FOREIGN KEY (apartment_id) REFERENCES apartment (id) ON DELETE CASCADE, CONSTRAINT FK_CONSUMABLE_CHECK_CHECKED_BY FOREIGN KEY (checked_by_id) REFERENCES user (id) ON DELETE SET NULL, CONSTRAINT FK_CONSUMABLE_CHECK_RESTOCKED_BY FOREIGN KEY (restocked_by_id) REFERENCES user (id) ON DELETE SET NULL)');
            $this->addSql('INSERT INTO consumable_check (id, checkout_id, consumable_item_id, apartment_id, checked_by_id, restocked_by_id, status, note, checked_at, restocked_at) SELECT id, checkout_id, consumable_item_id, apartment_id, checked_by_id, restocked_by_id, status, note, checked_at, restocked_at FROM __temp__consumable_check');
            $this->addSql('DROP TABLE __temp__consumable_check');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CONSUMABLE_CHECK_CHECKOUT_ITEM ON consumable_check (checkout_id, consumable_item_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_CHECKOUT ON consumable_check (checkout_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_ITEM ON consumable_check (consumable_item_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_APARTMENT ON consumable_check (apartment_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_CHECKED_BY ON consumable_check (checked_by_id)');
            $this->addSql('CREATE INDEX IDX_CONSUMABLE_CHECK_RESTOCKED_BY ON consumable_check (restocked_by_id)');

            return;
        }

        $this->addSql('ALTER TABLE consumable_check DROP COLUMN quantity');
    }
}
