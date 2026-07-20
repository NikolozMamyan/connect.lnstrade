<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create LNS document editor storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lns_document (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, title VARCHAR(180) NOT NULL, description LONGTEXT NOT NULL, auto_generate_toc TINYINT(1) DEFAULT 1 NOT NULL, content JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_E6C752CDB03A8386 (created_by_id), INDEX idx_lns_document_updated_at (updated_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE lns_document ADD CONSTRAINT FK_E6C752CDB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lns_document DROP FOREIGN KEY FK_E6C752CDB03A8386');
        $this->addSql('DROP TABLE lns_document');
    }
}
