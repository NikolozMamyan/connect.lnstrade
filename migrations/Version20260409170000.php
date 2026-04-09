<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create application users table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('app_user');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('email', 'string', ['length' => 180]);
        $table->addColumn('roles', 'json');
        $table->addColumn('password', 'string', ['length' => 255]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['email'], 'uniq_app_user_email');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('app_user');
    }
}
