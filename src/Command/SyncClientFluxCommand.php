<?php

namespace App\Command;

use App\Service\Flux\ClientFluxOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:client-flux',
    description: 'Synchronise les companies/contacts HubSpot puis prépare l’export ERP.',
)]
class SyncClientFluxCommand extends Command
{
    public function __construct(
        private readonly ClientFluxOrchestrator $clientFluxOrchestrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Lancement de la synchronisation du flux client');

        try {
            $result = $this->clientFluxOrchestrator->run();

            $io->success(sprintf(
                'Synchronisation terminée : %d company(s), %d contact(s), %d relation(s), %d export(s) ERP préparé(s).',
                $result['savedCompanies'] ?? 0,
                $result['savedContacts'] ?? 0,
                $result['savedRelations'] ?? 0,
                $result['erpSent'] ?? 0,
            ));

            if (($result['erpSkipped'] ?? 0) > 0) {
                $io->warning(sprintf(
                    '%d export(s) ERP ignoré(s).',
                    $result['erpSkipped']
                ));
            }

            if (!empty($result['erpErrors'])) {
                $io->error(sprintf(
                    '%d erreur(s) pendant l’export ERP.',
                    count($result['erpErrors'])
                ));

                foreach ($result['erpErrors'] as $error) {
                    $io->writeln(sprintf(
                        '- [%s] %s : %s',
                        $error['companyHubspotId'] ?? 'N/A',
                        $error['companyName'] ?? 'N/A',
                        $error['message'] ?? 'Erreur inconnue'
                    ));
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erreur lors de la synchronisation : ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}