<?php

namespace App\MessageHandler;

use App\Message\SyncClientMessage;
use App\Service\Flux\ClientFluxOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncClientMessageHandler
{
    public function __construct(
        private ClientFluxOrchestrator $orchestrator,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncClientMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-client-lock', 3600);

        if (!$lock->acquire()) {
            $this->logger->warning('Une synchronisation client est déjà en cours. Message ignoré.');
            return;
        }

        try {
            $this->orchestrator->run();
        } catch (\Throwable $e) {
            $this->logger->error('Erreur pendant la synchronisation client : ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}