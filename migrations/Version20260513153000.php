<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deal tables and extend order_form processing metadata';
    }

    public function up(Schema $schema): void
    {
        $dealTable = $schema->createTable('deal');
        $dealTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $dealTable->addColumn('order_form_id', 'integer');
        $dealTable->addColumn('commercial_id', 'integer');
        $dealTable->addColumn('reference_number', 'string', ['length' => 16]);
        $dealTable->addColumn('deal_type', 'string', ['length' => 16]);
        $dealTable->addColumn('deal_id', 'string', ['length' => 64, 'notnull' => false]);
        $dealTable->addColumn('enterprise_id', 'string', ['length' => 64, 'notnull' => false]);
        $dealTable->addColumn('status', 'string', ['length' => 16, 'default' => 'validated']);
        $dealTable->addColumn('submitted_at', 'datetime_immutable');
        $dealTable->addColumn('line_item_count', 'integer');
        $dealTable->addColumn('total_amount', 'float');
        $dealTable->addColumn('source_file_name', 'string', ['length' => 255, 'notnull' => false]);
        $dealTable->addColumn('created_at', 'datetime_immutable');
        $dealTable->addColumn('updated_at', 'datetime_immutable');
        $dealTable->setPrimaryKey(['id']);
        $dealTable->addUniqueIndex(['reference_number'], 'uniq_deal_reference_number');
        $dealTable->addUniqueIndex(['order_form_id'], 'uniq_deal_order_form_id');
        $dealTable->addIndex(['submitted_at'], 'idx_deal_submitted_at');
        $dealTable->addIndex(['commercial_id'], 'idx_deal_commercial_id');
        $dealTable->addForeignKeyConstraint('order_form', ['order_form_id'], ['id'], ['onDelete' => 'CASCADE']);
        $dealTable->addForeignKeyConstraint('commercial', ['commercial_id'], ['id'], ['onDelete' => 'RESTRICT']);

        $lineItemTable = $schema->createTable('deal_line_item');
        $lineItemTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $lineItemTable->addColumn('deal_id', 'integer');
        $lineItemTable->addColumn('position', 'integer');
        $lineItemTable->addColumn('article_ref', 'string', ['length' => 128]);
        $lineItemTable->addColumn('description', 'text', ['notnull' => false]);
        $lineItemTable->addColumn('ean_unit', 'string', ['length' => 128, 'notnull' => false]);
        $lineItemTable->addColumn('quantity', 'float');
        $lineItemTable->addColumn('unit_price', 'float');
        $lineItemTable->addColumn('line_total', 'float');
        $lineItemTable->addColumn('raw_payload', 'json', ['notnull' => false]);
        $lineItemTable->setPrimaryKey(['id']);
        $lineItemTable->addIndex(['deal_id'], 'idx_deal_line_item_deal_id');
        $lineItemTable->addForeignKeyConstraint('deal', ['deal_id'], ['id'], ['onDelete' => 'CASCADE']);

        $orderFormTable = $schema->getTable('order_form');
        $orderFormTable->changeColumn('status', ['length' => 16, 'default' => 'pending']);
        $orderFormTable->addColumn('processed_at', 'datetime_immutable', ['notnull' => false]);
        $orderFormTable->addColumn('processing_errors', 'json', ['notnull' => false]);
        $orderFormTable->addColumn('retry_rows', 'json', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $orderFormTable = $schema->getTable('order_form');
        $orderFormTable->dropColumn('processed_at');
        $orderFormTable->dropColumn('processing_errors');
        $orderFormTable->dropColumn('retry_rows');

        $schema->dropTable('deal_line_item');
        $schema->dropTable('deal');
    }
}
