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
        $table->addUniqueIndex(['hubspot_deal_id'], 'uniq_erp_order_export_deal');
        $table->addIndex(['status', 'updated_at'], 'idx_erp_order_export_status_updated');

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO erp_order_export (
                hubspot_deal_id,
                status,
                reference_commande,
                num_client,
                payload,
                erp_response,
                error_message,
                sent_at,
                created_at,
                updated_at
            )
            SELECT
                exported.deal_id,
                'sent',
                exported.reference_commande,
                exported.num_client,
                JSON_OBJECT(
                    'referenceCommande', exported.reference_commande,
                    'numClient', exported.num_client
                ),
                JSON_OBJECT('backfilledFromSyncLog', true),
                NULL,
                exported.last_created_at,
                exported.first_created_at,
                exported.last_created_at
            FROM (
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(context, '$.dealHubspotId')) AS deal_id,
                    MAX(JSON_UNQUOTE(JSON_EXTRACT(context, '$.referenceCommande'))) AS reference_commande,
                    MAX(JSON_UNQUOTE(JSON_EXTRACT(context, '$.numClient'))) AS num_client,
                    MIN(created_at) AS first_created_at,
                    MAX(created_at) AS last_created_at
                FROM sync_log
                WHERE flux_key = 'webhook'
                    AND level = 'success'
                    AND title = 'Webhook HubSpot deal traite'
                    AND context IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(context, '$.dealHubspotId')) IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(context, '$.dealHubspotId')) <> ''
                GROUP BY deal_id
            ) exported
        SQL);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('erp_order_export');
    }
}
