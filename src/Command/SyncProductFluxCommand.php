<?php

namespace App\Command;

use App\Service\Flux\ClientFluxOrchestrator;
use App\Service\Log\SyncLogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:sync:product-flux',
    description: 'Synchronise les produits Sage puis prepare l export HubSpot.',
)]
class SyncProductFluxCommand extends Command
{
    public function __construct(
        private readonly ClientFluxOrchestrator $clientFluxOrchestrator,
        private readonly SyncLogService $syncLogService,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Lancement de la synchronisation du flux produit');
        $lock = $this->lockFactory->createLock('sync-product-lock', 14400);

        if (!$lock->acquire()) {
            $this->syncLogService->warning(
                'product',
                'Synchronisation deja en cours',
                'La commande a ete ignoree car un traitement produit est deja actif.'
            );
            $io->warning('Une synchronisation produit est deja en cours.');

            return Command::SUCCESS;
        }

        try {
            $this->syncLogService->info(
                'product',
                'Synchronisation produits demarree via commande',
                'Execution de la commande app:sync:product-flux.'
            );

            $result = $this->clientFluxOrchestrator->runProductSync();

            $this->syncLogService->success(
                'product',
                'Synchronisation produits terminee via commande',
                sprintf(
                    '%d produits importes, %d produits envoyes a HubSpot.',
                    $result['imported'] ?? 0,
                    $result['hubspotSent'] ?? 0,
                ),
                $result
            );

            $io->success(sprintf(
                'Synchronisation terminee : %d produit(s) importe(s), %d produit(s) HubSpot envoye(s).',
                $result['imported'] ?? 0,
                $result['hubspotSent'] ?? 0,
            ));

            if (($result['importSkipped'] ?? 0) > 0) {
                $this->syncLogService->warning(
                    'product',
                    'Produits ignores a l import',
                    sprintf('%d produit(s) Sage ignores a l import.', $result['importSkipped']),
                    ['importSkipped' => $result['importSkipped']]
                );

                $io->warning(sprintf(
                    '%d produit(s) Sage ignore(s) a l import.',
                    $result['importSkipped']
                ));
            }

            if (($result['hubspotSkipped'] ?? 0) > 0) {
                $this->syncLogService->warning(
                    'product',
                    'Produits ignores a l export',
                    sprintf('%d produit(s) HubSpot ignores a l export.', $result['hubspotSkipped']),
                    ['hubspotSkipped' => $result['hubspotSkipped']]
                );

                $io->warning(sprintf(
                    '%d produit(s) HubSpot ignore(s) a l export.',
                    $result['hubspotSkipped']
                ));
            }

            if (!empty($result['importErrors'])) {
                $this->syncLogService->error(
                    'product',
                    'Erreurs pendant l import Sage',
                    sprintf('%d erreur(s) detectee(s) pendant l import Sage.', count($result['importErrors'])),
                    ['importErrors' => $result['importErrors']]
                );

                $io->error(sprintf(
                    '%d erreur(s) pendant l import Sage.',
                    count($result['importErrors'])
                ));

                foreach ($result['importErrors'] as $error) {
                    $io->writeln(sprintf(
                        '- [%s] %s',
                        $error['reference'] ?? 'N/A',
                        $error['message'] ?? 'Erreur inconnue'
                    ));
                }
            }

            if (!empty($result['hubspotErrors'])) {
                $this->syncLogService->error(
                    'product',
                    'Erreurs pendant l export HubSpot',
                    sprintf('%d erreur(s) detectee(s) pendant l export HubSpot.', count($result['hubspotErrors'])),
                    ['hubspotErrors' => $result['hubspotErrors']]
                );

                $io->error(sprintf(
                    '%d erreur(s) pendant l export HubSpot.',
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
                'product',
                'Erreur synchronisation produits via commande',
                $e->getMessage()
            );

            $io->error('Erreur lors de la synchronisation : ' . $e->getMessage());

            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
