<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120000 extends AbstractMigration
{
    private const PASSWORD_HASHES = [
        'anthony.chaoui@lnstrade.fr' => '$2y$13$a27qG5JNTwd1QSy0oQEp1uRlPHbsP3TQmoWRKTSI6V8IqcMNPOhma',
        'jerome.degreve@lnstrade.fr' => '$2y$13$T2x77DP/9TrUx9QnYV1F7O.6SvHJnwdyYNwFfLZ6IH2EFSi5g.pUW',
        'enzo.houde@lnstrade.fr' => '$2y$13$AztOX9YhG5g5.YvuFuhH9.z8kHyLQq7aF8NBuoVqa2bfNLeXhJsbq',
        'nesrine.lalem@lnstrade.fr' => '$2y$13$Cvf57ep.sCTrcuClDFdIA..mopyj/wx8WNgYpmH.72DK91D8fAO7S',
        'lisa.rohr@lnstrade.fr' => '$2y$13$n4eMDrIShKsVMMnfAD8hYOPeFNRkOd2.nSHMk/jJs9BNEb.pjTtq.',
        'savinien.saint-paul@lnstrade.fr' => '$2y$13$CFSUPP11CUCGL4F8pEBj9e4tVe1qnCLzq6ty2/bVN8YTxleFGLFMu',
        'vincent.touati@lnstrade.fr' => '$2y$13$xjOS/REIIbUtTA1B68hRP.lyq6jXdCy2R55WI08Rls5/g4ezUTLMq',
    ];

    public function getDescription(): string
    {
        return 'Cree les comptes commerciaux des owners qui ne disposent pas encore d un acces.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration compatible MySQL/MariaDB uniquement.');

        foreach (self::PASSWORD_HASHES as $email => $passwordHash) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO app_user (email, first_name, last_name, roles, password, created_at, updated_at)
                    SELECT ?, c.first_name, c.last_name, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM commercial c
                    WHERE LOWER(TRIM(c.email)) = ?
                      AND c.is_active = 1
                      AND NOT EXISTS (
                          SELECT 1 FROM app_user u WHERE LOWER(TRIM(u.email)) = ?
                      )
                    LIMIT 1
                    SQL,
                [$email, '["ROLE_COM"]', $passwordHash, $email, $email],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les comptes crees doivent etre supprimes manuellement pour preserver les utilisateurs existants.');
    }
}

