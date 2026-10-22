<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Importe les commerciaux Sage releves le 22/10/2026 et ajoute leur suivi aux envois d echantillons.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        $this->addSql('ALTER TABLE commercial ADD sage_first_name VARCHAR(100) DEFAULT NULL, ADD sage_last_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE sample_shipment ADD representative JSON DEFAULT NULL, ADD notification_sent_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE sample_shipment SET notification_sent_at = sent_at WHERE status = 'sent'");

        $representatives = [
            ['Douglas', 'WOODS'],
            ['Sonita', 'SIEK'],
            ['Martin', 'SCHWARTZ'],
            ['Corentin', 'BURY'],
            ['Kevin', 'LE'],
            ['Mathias', 'MEMERY'],
            ['Alain', 'UZAN'],
            ['Jean-François', 'SOUTRY'],
            ['Syrine', 'BOUTOUCHENT'],
            ['Anthony', 'CHAOUI'],
            ['Stefano', 'MODUGNO'],
            ['Aude', 'ROPOSTE'],
            ['Lisa', 'ROHR'],
            ['Nesrine', 'LALEM'],
            ['Enzo', 'HOUDE'],
            ['Jerome', 'DEGREVE'],
            ['Flora', 'ERHEL'],
            ['Vincent', 'TOUATI'],
            ['Noémie', 'HAAG'],
            ['Quentin', 'STRASSER'],
            ['Delphine', 'VERNAY'],
            ['Marius', 'BIGER'],
            ['Aleyna', 'YAGMUR'],
            ['Loic', 'LAPARRE'],
        ];
        $normalize = static fn (string $name): string => strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)));
        $existing = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, first_name, last_name FROM commercial') as $commercial) {
            $existing[$normalize($commercial['first_name']).':'.$normalize($commercial['last_name'])] = (int) $commercial['id'];
        }

        foreach ($representatives as [$firstName, $lastName]) {
            $id = $existing[$normalize($firstName).':'.$normalize($lastName)] ?? null;

            if ($id !== null) {
                $this->addSql('UPDATE commercial SET sage_first_name = ?, sage_last_name = ? WHERE id = ?', [$firstName, $lastName, $id]);
            } else {
                $this->addSql('INSERT INTO commercial (first_name, last_name, sage_first_name, sage_last_name, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)', [$firstName, $lastName, $firstName, $lastName]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sample_shipment DROP representative, DROP notification_sent_at');
        $this->addSql('ALTER TABLE commercial DROP sage_first_name, DROP sage_last_name');
    }
}
