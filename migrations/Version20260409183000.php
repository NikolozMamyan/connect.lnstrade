<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sync_log table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('sync_log');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('flux_key', 'string', ['length' => 64]);
        $table->addColumn('level', 'string', ['length' => 16]);
        $table->addColumn('title', 'string', ['length' => 255]);
        $table->addColumn('message', 'text', ['notnull' => false]);
        $table->addColumn('context', 'json', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['flux_key', 'created_at'], 'idx_sync_log_flux_created');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('sync_log');
    }
}
