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
    name: 'app:sync:product-stock-flux',
    description: 'Synchronise les stocks produits Sage puis met a jour HubSpot.',
)]
class SyncProductStockFluxCommand extends Command
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

        $io->title('Lancement de la synchronisation du stock produit');

        try {
            $this->syncLogService->info(
                'product_stock',
                'Synchronisation stock demarree via commande',
                'Execution de la commande app:sync:product-stock-flux.'
            );

            $result = $this->clientFluxOrchestrator->runProductStockSync();

            $this->syncLogService->success(
                'product_stock',
                'Synchronisation stock terminee via commande',
                sprintf(
                    '%d stocks mis a jour, %d lignes envoyees a HubSpot.',
                    $result['stockUpdated'] ?? 0,
                    $result['hubspotSent'] ?? 0,
                ),
                $result
            );

            $io->success(sprintf(
                'Synchronisation terminee : %d stock(s) mis a jour, %d ligne(s) envoyee(s) a HubSpot.',
                $result['stockUpdated'] ?? 0,
                $result['hubspotSent'] ?? 0,
            ));

            if (($result['stockSkipped'] ?? 0) > 0) {
                $this->syncLogService->warning(
                    'product_stock',
                    'Stocks ignores cote Sage',
                    sprintf('%d ligne(s) de stock ignoree(s) pendant la mise a jour Sage.', $result['stockSkipped']),
                    ['stockSkipped' => $result['stockSkipped']]
                );

                $io->warning(sprintf(
                    '%d ligne(s) de stock ignoree(s) cote Sage.',
                    $result['stockSkipped']
                ));
            }

            if (($result['hubspotSkipped'] ?? 0) > 0) {
                $this->syncLogService->warning(
                    'product_stock',
                    'Stocks ignores cote HubSpot',
                    sprintf('%d ligne(s) ignoree(s) pendant la mise a jour HubSpot.', $result['hubspotSkipped']),
                    ['hubspotSkipped' => $result['hubspotSkipped']]
                );

                $io->warning(sprintf(
                    '%d ligne(s) ignoree(s) cote HubSpot.',
                    $result['hubspotSkipped']
                ));
            }

            if (!empty($result['stockErrors'])) {
                $this->syncLogService->error(
                    'product_stock',
                    'Erreurs pendant la recuperation des stocks',
                    sprintf('%d erreur(s) detectee(s) pendant la recuperation des stocks Sage.', count($result['stockErrors'])),
                    ['stockErrors' => $result['stockErrors']]
                );

                $io->error(sprintf(
                    '%d erreur(s) pendant la recuperation des stocks.',
                    count($result['stockErrors'])
                ));

                foreach ($result['stockErrors'] as $error) {
                    $io->writeln(sprintf(
                        '- %s',
                        $error['message'] ?? 'Erreur inconnue'
                    ));
                }
            }

            if (!empty($result['hubspotErrors'])) {
                $this->syncLogService->error(
                    'product_stock',
                    'Erreurs pendant l export stock HubSpot',
                    sprintf('%d erreur(s) detectee(s) pendant l export stock HubSpot.', count($result['hubspotErrors'])),
                    ['hubspotErrors' => $result['hubspotErrors']]
                );

                $io->error(sprintf(
                    '%d erreur(s) pendant l export stock HubSpot.',
                    count($result['hubspotErrors'])
                ));

                foreach ($result['hubspotErrors'] as $error) {
                    $io->writeln(sprintf(
                        '- [%s] %s',
                        $error['reference'] ?? 'N/A',
                        $error['message'] ?? 'Erreur inconnue'
                    ));
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->syncLogService->error(
                'product_stock',
                'Erreur synchronisation stock via commande',
                $e->getMessage()
            );

            $io->error('Erreur lors de la synchronisation du stock : ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
