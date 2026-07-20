<?php

namespace App\Command;

use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync:enqueue',
    description: 'Ajoute une ou plusieurs synchronisations dedupliquees dans Messenger.',
)]
final class EnqueueSyncCommand extends Command
{
    public function __construct(
        private readonly SyncJobDispatcher $syncJobDispatcher,
        private readonly SyncLogService $syncLogService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'types',
            InputArgument::IS_ARRAY | InputArgument::REQUIRED,
            sprintf('Types disponibles : %s', implode(', ', SyncJobDispatcher::supportedTypes()))
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $types = array_values(array_unique(array_map(
            static fn (mixed $type): string => mb_strtolower(trim((string) $type)),
            (array) $input->getArgument('types')
        )));
        $invalidTypes = array_values(array_diff($types, SyncJobDispatcher::supportedTypes()));

        if ($invalidTypes !== []) {
            $io->error(sprintf(
                'Type(s) invalide(s) : %s. Types disponibles : %s.',
                implode(', ', $invalidTypes),
                implode(', ', SyncJobDispatcher::supportedTypes())
            ));

            return Command::INVALID;
        }

        foreach ($types as $type) {
            $this->syncJobDispatcher->dispatch($type);
            $this->syncLogService->info(
                str_replace('-', '_', $type),
                'Synchronisation planifiee par cron',
                sprintf('Le flux %s a ete propose a la file Messenger.', $type)
            );
        }

        $io->success(sprintf('Synchronisation(s) ajoutee(s) : %s.', implode(', ', $types)));

        return Command::SUCCESS;
    }
}
