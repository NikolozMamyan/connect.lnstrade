<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime du registre local les documents des fournisseurs exclus des Orders HubSpot.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql(<<<'SQL'
            DELETE FROM erp_delivery_note
            WHERE UPPER(TRIM(COALESCE(client_id, ''))) IN (
                '9DACHSER',
                '9JUNG',
                '9EDTRANS',
                '9STEFRESTAURATION',
                '9LNSTR',
                '9LISALHUILLIER',
                '9LOGISTIQUESERV',
                '9MEDIATOONLIC',
                '9STEFT',
                '9STEFTS'
            )
            OR UPPER(TRIM(piece)) IN (
                'FA002509', 'FA002510', 'FA001672', 'FA001644', 'FA001434', 'FA001399',
                'FA001382', 'FA001384', 'FA002215', 'FA002192', 'FA001503', 'FA001448',
                'FA002051', 'FA002030', 'FA002261', 'FA001936', 'FA000219', 'FA001759',
                'FA002227', 'FA002110', 'FA001301', 'FA001439'
            )
            OR UPPER(TRIM(COALESCE(invoice_piece, ''))) IN (
                'FA002509', 'FA002510', 'FA001672', 'FA001644', 'FA001434', 'FA001399',
                'FA001382', 'FA001384', 'FA002215', 'FA002192', 'FA001503', 'FA001448',
                'FA002051', 'FA002030', 'FA002261', 'FA001936', 'FA000219', 'FA001759',
                'FA002227', 'FA002110', 'FA001301', 'FA001439'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les documents supprimes ne peuvent pas etre restaures depuis le registre local.');
    }
}
