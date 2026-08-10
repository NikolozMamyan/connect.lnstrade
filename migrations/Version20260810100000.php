<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260810100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add draft state and optimistic edit version to LNS documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lns_document ADD is_draft TINYINT(1) DEFAULT 0 NOT NULL, ADD edit_version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lns_document DROP is_draft, DROP edit_version');
    }
}
