<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store Sage delivery notes and their HubSpot Order export state.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql('CREATE TABLE erp_delivery_note (id INT AUTO_INCREMENT NOT NULL, sage_key VARCHAR(190) NOT NULL, piece VARCHAR(64) NOT NULL, client_id VARCHAR(64) DEFAULT NULL, client_name VARCHAR(255) DEFAULT NULL, document_date DATETIME DEFAULT NULL, delivery_date DATETIME DEFAULT NULL, amount_excluding_tax DOUBLE PRECISION NOT NULL, amount_including_tax DOUBLE PRECISION NOT NULL, line_count INT NOT NULL, status VARCHAR(16) NOT NULL, hubspot_order_id VARCHAR(64) DEFAULT NULL, hubspot_line_item_ids JSON DEFAULT NULL, payload_hash VARCHAR(64) DEFAULT NULL, exported_payload_hash VARCHAR(64) DEFAULT NULL, raw_payload JSON DEFAULT NULL, warnings JSON DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, analyzed_at DATETIME DEFAULT NULL, exported_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_erp_delivery_note_sage_key (sage_key), INDEX idx_erp_delivery_note_status_date (status, document_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');
        $this->addSql('DROP TABLE erp_delivery_note');
    }
}
