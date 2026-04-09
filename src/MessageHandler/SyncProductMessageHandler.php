<?php

namespace App\MessageHandler;

use App\Message\SyncProductMessage;
use App\Service\Flux\ClientFluxOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncProductMessageHandler
{
    public function __construct(
        private ClientFluxOrchestrator $orchestrator,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncProductMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-product-lock', 3600);

        if (!$lock->acquire()) {
            $this->logger->warning('Une synchronisation produit est deja en cours. Message ignore.');

            return;
        }

        try {
            $this->orchestrator->runProductSync();
        } catch (\Throwable $e) {
            $this->logger->error('Erreur pendant la synchronisation produit : ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
