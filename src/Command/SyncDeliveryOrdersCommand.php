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
    name: 'app:sync:delivery-orders',
    description: 'Planifie l analyse des BL et factures Sage vers les Orders HubSpot.',
)]
final class SyncDeliveryOrdersCommand extends Command
{
    private const ALLOWED_PERIODS = [30, 60, 90];
    private const DEFAULT_PERIOD = 90;

    public function __construct(
        private readonly SyncJobDispatcher $syncJobDispatcher,
        private readonly SyncLogService $syncLogService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Periode a analyser en jours : 30, 60 ou 90.',
            (string) self::DEFAULT_PERIOD,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT);

        if (!is_int($days) || !in_array($days, self::ALLOWED_PERIODS, true)) {
            $io->error('La periode doit etre 30, 60 ou 90 jours.');

            return Command::INVALID;
        }

        $dateTo = new \DateTimeImmutable('today');
        $dateFrom = $dateTo->modify(sprintf('-%d days', $days - 1));

        $this->syncJobDispatcher->dispatch(
            SyncJobDispatcher::DELIVERY_ORDER,
            $dateFrom,
            $dateTo,
        );
        $this->syncLogService->info(
            'delivery_order',
            'Synchronisation Orders HubSpot planifiee',
            sprintf(
                'Analyse des BL et factures Sage du %s au %s ajoutee a Messenger par la commande planifiee.',
                $dateFrom->format('d/m/Y'),
                $dateTo->format('d/m/Y'),
            ),
        );

        $io->success(sprintf(
            'Synchronisation des Orders HubSpot ajoutee a Messenger pour les %d derniers jours.',
            $days,
        ));

        return Command::SUCCESS;
    }
}
