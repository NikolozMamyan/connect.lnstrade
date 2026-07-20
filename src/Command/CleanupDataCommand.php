<?php

namespace App\Command;

use App\Service\Maintenance\DataRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:maintenance:cleanup',
    description: 'Supprime les anciens logs de synchronisation et les notifications lues.',
)]
final class CleanupDataCommand extends Command
{
    public function __construct(private readonly DataRetentionService $dataRetentionService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('log-days', null, InputOption::VALUE_REQUIRED, 'Retention des logs en jours', '90')
            ->addOption('notification-days', null, InputOption::VALUE_REQUIRED, 'Retention des notifications lues en jours', '90')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les lignes sans les supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->dataRetentionService->cleanup(
                (int) $input->getOption('log-days'),
                (int) $input->getOption('notification-days'),
                (bool) $input->getOption('dry-run')
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->success(sprintf(
            '%s : %d log(s), %d notification(s) lue(s).',
            $result['dryRun'] ? 'Simulation' : 'Nettoyage termine',
            $result['syncLogs'],
            $result['readNotifications']
        ));

        return Command::SUCCESS;
    }
}
