<?php

namespace App\Command;

use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:delivery-orders-history',
    description: 'Planifie le rattrapage de tout l historique BL et factures Sage vers les Orders HubSpot.',
)]
final class SyncDeliveryOrderHistoryCommand extends Command
{
    private const DEFAULT_START_DATE = '2025-01-01';

    public function __construct(
        private readonly SyncJobDispatcher $syncJobDispatcher,
        private readonly SyncLogService $syncLogService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'from',
            null,
            InputOption::VALUE_REQUIRED,
            'Premiere date Sage a analyser au format AAAA-MM-JJ.',
            self::DEFAULT_START_DATE,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawDateFrom = trim((string) $input->getOption('from'));
        $dateFrom = \DateTimeImmutable::createFromFormat('!Y-m-d', $rawDateFrom);
        $dateTo = new \DateTimeImmutable('today');

        if (!$dateFrom instanceof \DateTimeImmutable || $dateFrom->format('Y-m-d') !== $rawDateFrom) {
            $io->error('La date --from doit respecter le format AAAA-MM-JJ.');

            return Command::INVALID;
        }

        if ($dateFrom > $dateTo) {
            $io->error('La date --from ne peut pas etre dans le futur.');

            return Command::INVALID;
        }

        $this->syncJobDispatcher->dispatchDeliveryOrderHistory($dateFrom, $dateTo);
        $this->syncLogService->info(
            'delivery_order',
            'Rattrapage historique Orders HubSpot planifie',
            sprintf(
                'Analyse complete des BL et factures Sage du %s au %s ajoutee a Messenger.',
                $dateFrom->format('d/m/Y'),
                $dateTo->format('d/m/Y'),
            ),
        );

        $io->success(sprintf(
            'Rattrapage historique ajoute a Messenger du %s au %s. Un seul recapitulatif sera envoye a la fin.',
            $dateFrom->format('d/m/Y'),
            $dateTo->format('d/m/Y'),
        ));

        return Command::SUCCESS;
    }
}
