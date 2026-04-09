<?php

namespace App\Command;

use App\Service\Flux\ClientFluxOrchestrator;
use App\Service\Log\SyncLogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:client-flux',
    description: 'Synchronise les companies/contacts HubSpot puis prepare l export ERP.',
)]
class SyncClientFluxCommand extends Command
{
    public function __construct(
        private readonly ClientFluxOrchestrator $clientFluxOrchestrator,
        private readonly SyncLogService $syncLogService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Lancement de la synchronisation du flux client');

        try {
            $this->syncLogService->info(
                'client',
                'Synchronisation client demarree via commande',
                'Execution de la commande app:sync:client-flux.'
            );

            $result = $this->clientFluxOrchestrator->run();

            $this->syncLogService->success(
                'client',
                'Synchronisation client terminee via commande',
                sprintf(
                    '%d companies, %d contacts, %d relations, %d exports ERP.',
                    $result['savedCompanies'] ?? 0,
                    $result['savedContacts'] ?? 0,
                    $result['savedRelations'] ?? 0,
                    $result['erpSent'] ?? 0,
                ),
                $result
            );

            $io->success(sprintf(
                'Synchronisation terminee : %d company(s), %d contact(s), %d relation(s), %d export(s) ERP prepares.',
                $result['savedCompanies'] ?? 0,
                $result['savedContacts'] ?? 0,
                $result['savedRelations'] ?? 0,
                $result['erpSent'] ?? 0,
            ));

            if (($result['erpSkipped'] ?? 0) > 0) {
                $this->syncLogService->warning(
                    'client',
                    'Exports ERP ignores',
                    sprintf('%d export(s) ERP ignores.', $result['erpSkipped']),
                    ['erpSkipped' => $result['erpSkipped']]
                );

                $io->warning(sprintf(
                    '%d export(s) ERP ignores.',
                    $result['erpSkipped']
                ));
            }

            if (!empty($result['erpErrors'])) {
                $this->syncLogService->error(
                    'client',
                    'Erreurs pendant l export ERP',
                    sprintf('%d erreur(s) detectee(s) pendant l export ERP.', count($result['erpErrors'])),
                    ['erpErrors' => $result['erpErrors']]
                );

                $io->error(sprintf(
                    '%d erreur(s) pendant l export ERP.',
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
            $this->syncLogService->error(
                'client',
                'Erreur synchronisation client via commande',
                $e->getMessage()
            );

            $io->error('Erreur lors de la synchronisation : ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
