<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link Sage delivery notes to their final invoices for HubSpot Orders synchronization.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql('ALTER TABLE erp_delivery_note ADD reference VARCHAR(255) DEFAULT NULL, ADD source_document_type INT DEFAULT 3 NOT NULL, ADD invoice_piece VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_erp_delivery_note_invoice_piece ON erp_delivery_note (invoice_piece)');
        $this->addSql('CREATE INDEX idx_erp_delivery_note_client_reference ON erp_delivery_note (client_id, reference)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql('DROP INDEX uniq_erp_delivery_note_invoice_piece ON erp_delivery_note');
        $this->addSql('DROP INDEX idx_erp_delivery_note_client_reference ON erp_delivery_note');
        $this->addSql('ALTER TABLE erp_delivery_note DROP reference, DROP source_document_type, DROP invoice_piece');
    }
}
