<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125160000 extends AbstractMigration
{
    private const GIFI_ORDERS = [
        'FA002412' => [
            '1375042867442',
            '1466850284781',
            ['518170411202', '518113636573', '518226076912', '518112215240', '518159444158', '518130236640', '518241514739', '518226099429'],
        ],
        'FA002408' => [
            '1374260124876',
            '1466830388444',
            ['518254503148', '518108718320', '518173817062', '518209563846', '518214444255', '518126651639', '518087089346'],
        ],
        'FA001603' => [
            '1377297989849',
            '1466853520593',
            ['518168418498', '518150490305'],
        ],
        'FA000167' => [
            '1377271935183',
            '1466831232209',
            ['518108718324', '518130236649', '518254503149', '518168418501', '518205819102', '518252696788', '518145281252', '518185342182'],
        ],
    ];

    public function getDescription(): string
    {
        return 'Rattache les quatre factures GIFI reexportees a leurs propres Orders et lignes HubSpot.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        foreach (self::GIFI_ORDERS as $invoicePiece => [$oldOrderId, $newOrderId, $lineItemIds]) {
            $this->addSql(
                <<<'SQL'
                    UPDATE erp_delivery_note
                    SET hubspot_order_id = ?,
                        hubspot_line_item_ids = ?,
                        status = 'sent',
                        exported_payload_hash = payload_hash,
                        error_message = NULL,
                        exported_at = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE client_id = '9GIFID'
                      AND invoice_piece = ?
                      AND hubspot_order_id = ?
                    SQL,
                [$newOrderId, json_encode($lineItemIds, JSON_THROW_ON_ERROR), $invoicePiece, $oldOrderId],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les anciens rattachements partageaient le meme Order entre deux factures.');
    }
}
