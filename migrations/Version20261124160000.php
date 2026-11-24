<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Exclut le client 1BOHRERJACKY des Orders HubSpot et retire ses erreurs du registre local.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql(<<<'SQL'
            DELETE FROM erp_delivery_note
            WHERE UPPER(TRIM(COALESCE(client_id, ''))) = '1BOHRERJACKY'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les documents supprimes ne peuvent pas etre restaures depuis le registre local.');
    }
}
