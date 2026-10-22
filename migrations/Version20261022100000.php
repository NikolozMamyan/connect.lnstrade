<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022100000 extends AbstractMigration
{
    private const ORDER_IDS_BY_PIECE = [
        'BL002189' => '1407348210888',
        'FA000458' => '1407457190116',
        'FA000240' => '1407456467142',
        'FA000567' => '1407455756532',
        'FA000438' => '1407348990167',
        'FA000354' => '1407454320857',
        'FA000568' => '1407454321858',
        'FA001150' => '1407348993240',
    ];

    public function getDescription(): string
    {
        return 'Rattache les documents deja traites a leurs Orders HubSpot et retire la facture nulle exclue.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        foreach (self::ORDER_IDS_BY_PIECE as $piece => $orderId) {
            $this->addSql(
                <<<'SQL'
                    UPDATE erp_delivery_note
                    SET status = 'sent',
                        hubspot_order_id = ?,
                        exported_payload_hash = payload_hash,
                        error_message = NULL,
                        exported_at = COALESCE(exported_at, CURRENT_TIMESTAMP),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE UPPER(TRIM(piece)) = ?
                       OR UPPER(TRIM(COALESCE(invoice_piece, ''))) = ?
                    SQL,
                [$orderId, $piece, $piece],
            );
        }

        $this->addSql(<<<'SQL'
            DELETE FROM erp_delivery_note
            WHERE UPPER(TRIM(piece)) = 'FA000546'
               OR UPPER(TRIM(COALESCE(invoice_piece, ''))) = 'FA000546'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les anciens statuts et la facture supprimee ne peuvent pas etre restaures automatiquement.');
    }
}
