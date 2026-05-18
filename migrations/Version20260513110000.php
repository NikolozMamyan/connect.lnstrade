<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create commercial and order_form tables for public order form submissions';
    }

    public function up(Schema $schema): void
    {
        $commercialTable = $schema->createTable('commercial');
        $commercialTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $commercialTable->addColumn('first_name', 'string', ['length' => 100]);
        $commercialTable->addColumn('hubspot_id', 'string', ['length' => 64, 'notnull' => false]);
        $commercialTable->addColumn('last_name', 'string', ['length' => 100]);
        $commercialTable->addColumn('email', 'string', ['length' => 180, 'notnull' => false]);
        $commercialTable->addColumn('is_active', 'boolean', ['default' => true]);
        $commercialTable->addColumn('created_at', 'datetime_immutable');
        $commercialTable->addColumn('updated_at', 'datetime_immutable');
        $commercialTable->setPrimaryKey(['id']);
        $commercialTable->addIndex(['is_active'], 'idx_commercial_is_active');
        $commercialTable->addIndex(['last_name', 'first_name'], 'idx_commercial_name');

        $orderFormTable = $schema->createTable('order_form');
        $orderFormTable->addColumn('id', 'integer', ['autoincrement' => true]);
        $orderFormTable->addColumn('commercial_id', 'integer');
        $orderFormTable->addColumn('deal_type', 'string', ['length' => 16]);
        $orderFormTable->addColumn('deal_id', 'string', ['length' => 64, 'notnull' => false]);
        $orderFormTable->addColumn('enterprise_id', 'string', ['length' => 64, 'notnull' => false]);
        $orderFormTable->addColumn('file_name', 'string', ['length' => 255, 'notnull' => false]);
        $orderFormTable->addColumn('original_file_name', 'string', ['length' => 255, 'notnull' => false]);
        $orderFormTable->addColumn('file_size', 'integer', ['notnull' => false]);
        $orderFormTable->addColumn('submitted_at', 'datetime_immutable');
        $orderFormTable->addColumn('status', 'string', ['length' => 16, 'default' => 'pending']);
        $orderFormTable->addColumn('reference_number', 'string', ['length' => 16]);
        $orderFormTable->setPrimaryKey(['id']);
        $orderFormTable->addUniqueIndex(['reference_number'], 'uniq_order_form_reference_number');
        $orderFormTable->addIndex(['submitted_at'], 'idx_order_form_submitted_at');
        $orderFormTable->addIndex(['status'], 'idx_order_form_status');
        $orderFormTable->addIndex(['commercial_id'], 'idx_order_form_commercial_id');
        $orderFormTable->addForeignKeyConstraint('commercial', ['commercial_id'], ['id'], ['onDelete' => 'RESTRICT']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('order_form');
        $schema->dropTable('commercial');
    }
}
