<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Messenger and lock infrastructure and track the last HubSpot stock sync.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        if (!$schema->hasTable('messenger_messages')) {
            $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_messenger_queue (queue_name, available_at, delivered_at, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('lock_keys')) {
            $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY(key_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if ($schema->hasTable('erp_product') && !$schema->getTable('erp_product')->hasColumn('stock_synced_at')) {
            $this->addSql('ALTER TABLE erp_product ADD stock_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        if ($schema->hasTable('erp_product') && $schema->getTable('erp_product')->hasColumn('stock_synced_at')) {
            $this->addSql('ALTER TABLE erp_product DROP stock_synced_at');
        }

        // Les tables techniques peuvent contenir des messages ou des verrous actifs.
        // Elles sont volontairement conservees lors d un rollback applicatif.
    }
}
