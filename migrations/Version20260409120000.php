<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create erp_product table for Sage to HubSpot product sync';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('erp_product');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('reference', 'string', ['length' => 64]);
        $table->addColumn('designation', 'text', ['notnull' => false]);
        $table->addColumn('famille', 'string', ['length' => 100, 'notnull' => false]);
        $table->addColumn('unite_poids', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('poids_net', 'float', ['notnull' => false]);
        $table->addColumn('poids_brut', 'float', ['notnull' => false]);
        $table->addColumn('unite_vente', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('prix_achat', 'float', ['notnull' => false]);
        $table->addColumn('prix_vente', 'float', ['notnull' => false]);
        $table->addColumn('prix_ttc', 'float', ['notnull' => false]);
        $table->addColumn('suivi_stock', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('statut', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('anglais', 'text', ['notnull' => false]);
        $table->addColumn('espagnol', 'text', ['notnull' => false]);
        $table->addColumn('code_barre', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('code_fiscal', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('code_edi', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('pays', 'string', ['length' => 150, 'notnull' => false]);
        $table->addColumn('conditionnement', 'string', ['length' => 32, 'notnull' => false]);
        $table->addColumn('catalogue_1', 'string', ['length' => 150, 'notnull' => false]);
        $table->addColumn('catalogue_2', 'string', ['length' => 150, 'notnull' => false]);
        $table->addColumn('catalogue_3', 'string', ['length' => 150, 'notnull' => false]);
        $table->addColumn('catalogue_4', 'string', ['length' => 150, 'notnull' => false]);
        $table->addColumn('date_creation', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('champs_libres', 'json', ['notnull' => false]);
        $table->addColumn('raw_payload', 'json', ['notnull' => false]);
        $table->addColumn('hubspot_object_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('last_synced_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['reference'], 'uniq_erp_product_reference');
        $table->addIndex(['statut'], 'idx_erp_product_statut');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('erp_product');
    }
}
