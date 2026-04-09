<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stock fields to erp_product';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('erp_product');
        $table->addColumn('stock_reel', 'float', ['notnull' => false]);
        $table->addColumn('stock_dispo', 'float', ['notnull' => false]);
        $table->addColumn('stock_a_terme', 'float', ['notnull' => false]);
        $table->addColumn('raw_stock_payload', 'json', ['notnull' => false]);
        $table->addColumn('stock_updated_at', 'datetime_immutable', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('erp_product');
        $table->dropColumn('stock_reel');
        $table->dropColumn('stock_dispo');
        $table->dropColumn('stock_a_terme');
        $table->dropColumn('raw_stock_payload');
        $table->dropColumn('stock_updated_at');
    }
}
