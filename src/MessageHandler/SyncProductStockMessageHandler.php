<?php

namespace App\MessageHandler;

use App\Message\SyncProductStockMessage;
use App\Service\Flux\ClientFluxOrchestrator;
use App\Service\Log\SyncLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncProductStockMessageHandler
{
    public function __construct(
        private readonly ClientFluxOrchestrator $orchestrator,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly SyncLogService $syncLogService,
    ) {
    }

    public function __invoke(SyncProductStockMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-product-stock-lock', 14400);

        if (!$lock->acquire()) {
            $this->logger->warning('Une synchronisation de stock produit est deja en cours. Message ignore.');
            $this->syncLogService->warning('product_stock', 'Synchronisation stock deja en cours', 'Le message a ete ignore car un traitement stock est deja actif.');

            return;
        }

        try {
            $this->syncLogService->info('product_stock', 'Synchronisation stock demarree');
            $result = $this->orchestrator->runProductStockSync();

            $this->syncLogService->success(
                'product_stock',
                'Synchronisation stock terminee',
                sprintf(
                    '%d stocks mis a jour, %d lignes envoyees a HubSpot.',
                    $result['stockUpdated'] ?? 0,
                    $result['hubspotSent'] ?? 0,
                ),
                $result
            );
        } catch (\Throwable $e) {
            $this->logger->error('Erreur pendant la synchronisation de stock produit : ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->syncLogService->error('product_stock', 'Erreur synchronisation stock', $e->getMessage());

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
