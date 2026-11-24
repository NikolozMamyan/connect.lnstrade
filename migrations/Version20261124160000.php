<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124160000 extends AbstractMigration
{
    private const ORDER_IDS_BY_PIECE = [
        'FV0183' => '1465542075620',
        'FV0245' => '1465592453349',
        'FV0158' => '1465537513694',
        'FV0123' => '1465541367003',
        'FV0239' => '1465527373031',
        'FV0238' => '1465596978411',
        'FV0237' => '1465517117667',
        'FV0236' => '1465567806712',
    ];

    public function getDescription(): string
    {
        return 'Rattache les avoirs corriges et retire du registre les documents des clients internes exclus.';
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
            WHERE UPPER(TRIM(COALESCE(client_id, ''))) IN ('1BOHRERJACKY', '9ECH', '9LNSUK')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les documents supprimes ne peuvent pas etre restaures depuis le registre local.');
    }
}
