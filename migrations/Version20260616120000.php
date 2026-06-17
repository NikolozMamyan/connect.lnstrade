<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ERP order export tracking table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('erp_order_export');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('hubspot_event_id', 'string', ['length' => 64]);
        $table->addColumn('hubspot_deal_id', 'string', ['length' => 64]);
        $table->addColumn('status', 'string', ['length' => 16]);
        $table->addColumn('reference_commande', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('num_client', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('payload', 'json', ['notnull' => false]);
        $table->addColumn('erp_response', 'json', ['notnull' => false]);
        $table->addColumn('error_message', 'text', ['notnull' => false]);
        $table->addColumn('sent_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['hubspot_event_id'], 'uniq_erp_order_export_event');
        $table->addIndex(['hubspot_deal_id', 'created_at'], 'idx_erp_order_export_deal_created');
        $table->addIndex(['status', 'updated_at'], 'idx_erp_order_export_status_updated');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('erp_order_export');
    }
}
