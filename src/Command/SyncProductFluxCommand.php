<?php

namespace App\Command;

use App\Service\Flux\ClientFluxOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:product-flux',
    description: 'Synchronise les produits Sage puis prepare l export HubSpot.',
)]
class SyncProductFluxCommand extends Command
{
    public function __construct(
        private readonly ClientFluxOrchestrator $clientFluxOrchestrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Lancement de la synchronisation du flux produit');

        try {
            $result = $this->clientFluxOrchestrator->runProductSync();

            $io->success(sprintf(
                'Synchronisation terminee : %d produit(s) importe(s), %d produit(s) HubSpot envoye(s).',
                $result['imported'] ?? 0,
                $result['hubspotSent'] ?? 0,
            ));

            if (($result['importSkipped'] ?? 0) > 0) {
                $io->warning(sprintf(
                    '%d produit(s) Sage ignore(s) a l import.',
                    $result['importSkipped']
                ));
            }

            if (($result['hubspotSkipped'] ?? 0) > 0) {
                $io->warning(sprintf(
                    '%d produit(s) HubSpot ignore(s) a l export.',
                    $result['hubspotSkipped']
                ));
            }

            if (!empty($result['importErrors'])) {
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
            $io->error('Erreur lors de la synchronisation : ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
