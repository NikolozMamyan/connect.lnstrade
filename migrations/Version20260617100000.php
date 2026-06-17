<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move ERP order export deduplication from deal id to HubSpot event id';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('erp_order_export')) {
            return;
        }

        $table = $schema->getTable('erp_order_export');

        if (!$table->hasColumn('hubspot_event_id')) {
            $this->addSql('ALTER TABLE erp_order_export ADD hubspot_event_id VARCHAR(64) DEFAULT NULL');
        }

        $this->addSql("UPDATE erp_order_export SET hubspot_event_id = CONCAT('legacy:', id) WHERE hubspot_event_id IS NULL OR hubspot_event_id = ''");

        if ($table->hasIndex('uniq_erp_order_export_deal')) {
            $this->addSql('DROP INDEX uniq_erp_order_export_deal ON erp_order_export');
        }

        if (!$table->hasIndex('uniq_erp_order_export_event')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_erp_order_export_event ON erp_order_export (hubspot_event_id)');
        }

        if (!$table->hasIndex('idx_erp_order_export_deal_created')) {
            $this->addSql('CREATE INDEX idx_erp_order_export_deal_created ON erp_order_export (hubspot_deal_id, created_at)');
        }

        $this->addSql('ALTER TABLE erp_order_export MODIFY hubspot_event_id VARCHAR(64) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('erp_order_export')) {
            return;
        }

        $table = $schema->getTable('erp_order_export');

        if ($table->hasIndex('uniq_erp_order_export_event')) {
            $this->addSql('DROP INDEX uniq_erp_order_export_event ON erp_order_export');
        }

        if ($table->hasIndex('idx_erp_order_export_deal_created')) {
            $this->addSql('DROP INDEX idx_erp_order_export_deal_created ON erp_order_export');
        }

        if ($table->hasColumn('hubspot_event_id')) {
            $this->addSql('ALTER TABLE erp_order_export DROP hubspot_event_id');
        }
    }
}
