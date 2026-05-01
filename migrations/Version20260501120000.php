<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les services extras employes avec validation admin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_offer (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_by_id INTEGER DEFAULT NULL, approved_by_id INTEGER DEFAULT NULL, label VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL, is_standard BOOLEAN NOT NULL, created_at DATETIME NOT NULL --(DC2Type:datetime_immutable)
        , approved_at DATETIME DEFAULT NULL --(DC2Type:datetime_immutable)
        , CONSTRAINT FK_9D65A245B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9D65A245A3FDFF6B FOREIGN KEY (approved_by_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_9D65A245B03A8386 ON service_offer (created_by_id)');
        $this->addSql('CREATE INDEX IDX_9D65A245A3FDFF6B ON service_offer (approved_by_id)');
        $this->addSql('CREATE TABLE user_service_offer (user_id INTEGER NOT NULL, service_offer_id INTEGER NOT NULL, PRIMARY KEY(user_id, service_offer_id), CONSTRAINT FK_8AB37C8AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_8AB37C8AB0D3A883 FOREIGN KEY (service_offer_id) REFERENCES service_offer (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8AB37C8AA76ED395 ON user_service_offer (user_id)');
        $this->addSql('CREATE INDEX IDX_8AB37C8AB0D3A883 ON user_service_offer (service_offer_id)');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->addSql("INSERT INTO service_offer (label, status, is_standard, created_at, approved_at) VALUES ('Faire les courses', 'approved', 1, '$now', '$now')");
        $this->addSql("INSERT INTO service_offer (label, status, is_standard, created_at, approved_at) VALUES ('Livrer un repas', 'approved', 1, '$now', '$now')");
        $this->addSql("INSERT INTO service_offer (label, status, is_standard, created_at, approved_at) VALUES ('Accompagnement visite', 'approved', 1, '$now', '$now')");
        $this->addSql("INSERT INTO service_offer (label, status, is_standard, created_at, approved_at) VALUES ('Trouver une agence de location', 'approved', 1, '$now', '$now')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_service_offer');
        $this->addSql('DROP TABLE service_offer');
    }
}
