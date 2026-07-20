<?php

namespace App\Command;

use App\Service\Monitoring\CronHeartbeatService;
use App\Service\Monitoring\MessengerHealthService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:cron:heartbeat',
    description: 'Enregistre le dernier passage du worker cron et l etat de Messenger.',
)]
final class CronHeartbeatCommand extends Command
{
    public function __construct(
        private readonly CronHeartbeatService $cronHeartbeatService,
        private readonly MessengerHealthService $messengerHealthService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Etat du worker', 'alive')
            ->addOption('exit-code', null, InputOption::VALUE_REQUIRED, 'Code de sortie du worker');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = $input->getOption('exit-code');
        $this->cronHeartbeatService->record(
            trim((string) $input->getOption('status')) ?: 'alive',
            $exitCode !== null ? (int) $exitCode : null,
            $this->messengerHealthService->getSnapshot()
        );

        return Command::SUCCESS;
    }
}
