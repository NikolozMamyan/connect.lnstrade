<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le journal local des envois d echantillons vers Sage.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql('CREATE TABLE sample_shipment (id INT AUTO_INCREMENT NOT NULL, request_token VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, order_reference VARCHAR(32) DEFAULT NULL, delivery_label VARCHAR(64) DEFAULT NULL, company VARCHAR(150) NOT NULL, contact_name VARCHAR(150) DEFAULT NULL, address VARCHAR(150) NOT NULL, address_complement VARCHAR(150) DEFAULT NULL, postal_code VARCHAR(32) NOT NULL, city VARCHAR(100) NOT NULL, country VARCHAR(100) NOT NULL, phone VARCHAR(50) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, message LONGTEXT DEFAULT NULL, order_lines JSON NOT NULL, sage_payload JSON DEFAULT NULL, sage_response JSON DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_sample_shipment_request_token (request_token), INDEX idx_sample_shipment_status_created (status, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql('DROP TABLE sample_shipment');
    }
}
