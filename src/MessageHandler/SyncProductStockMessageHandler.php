<?php

namespace App\MessageHandler;

use App\Message\SyncProductStockMessage;
use App\Service\Flux\ClientFluxOrchestrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncProductStockMessageHandler
{
    public function __construct(
        private ClientFluxOrchestrator $orchestrator,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncProductStockMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-product-stock-lock', 3600);

        if (!$lock->acquire()) {
            $this->logger->warning('Une synchronisation de stock produit est deja en cours. Message ignore.');

            return;
        }

        try {
            $this->orchestrator->runProductStockSync();
        } catch (\Throwable $e) {
            $this->logger->error('Erreur pendant la synchronisation de stock produit : ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
