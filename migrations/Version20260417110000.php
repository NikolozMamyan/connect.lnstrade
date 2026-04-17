<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create erp_invoice and erp_invoice_line tables for Sage invoice sync';
    }

    public function up(Schema $schema): void
    {
        $invoiceTable = $schema->createTable('erp_invoice');
        $invoiceTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $invoiceTable->addColumn('invoice_number', 'string', ['length' => 64]);
        $invoiceTable->addColumn('client_id', 'string', ['length' => 64, 'notnull' => false]);
        $invoiceTable->addColumn('line_count', 'integer');
        $invoiceTable->addColumn('quantity_total', 'float');
        $invoiceTable->addColumn('amount_total', 'float');
        $invoiceTable->addColumn('raw_payload', 'json', ['notnull' => false]);
        $invoiceTable->addColumn('last_synced_at', 'datetime_immutable', ['notnull' => false]);
        $invoiceTable->addColumn('created_at', 'datetime_immutable');
        $invoiceTable->addColumn('updated_at', 'datetime_immutable');
        $invoiceTable->setPrimaryKey(['id']);
        $invoiceTable->addUniqueIndex(['invoice_number'], 'uniq_erp_invoice_number');
        $invoiceTable->addIndex(['client_id'], 'idx_erp_invoice_client_id');

        $lineTable = $schema->createTable('erp_invoice_line');
        $lineTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $lineTable->addColumn('invoice_id', 'integer');
        $lineTable->addColumn('position', 'integer');
        $lineTable->addColumn('reference', 'string', ['length' => 64, 'notnull' => false]);
        $lineTable->addColumn('intitule', 'text', ['notnull' => false]);
        $lineTable->addColumn('quantite', 'float');
        $lineTable->addColumn('prix_unitaire', 'float');
        $lineTable->addColumn('total', 'float');
        $lineTable->addColumn('raw_payload', 'json', ['notnull' => false]);
        $lineTable->setPrimaryKey(['id']);
        $lineTable->addIndex(['invoice_id'], 'idx_erp_invoice_line_invoice_id');
        $lineTable->addForeignKeyConstraint('erp_invoice', ['invoice_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('erp_invoice_line');
        $schema->dropTable('erp_invoice');
    }
}
