<?php

namespace App\MessageHandler;

use App\Message\SyncProductMessage;
use App\Service\Flux\ClientFluxOrchestrator;
use App\Service\Log\SyncLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncProductMessageHandler
{
    public function __construct(
        private readonly ClientFluxOrchestrator $orchestrator,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly SyncLogService $syncLogService,
    ) {
    }

    public function __invoke(SyncProductMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-product-lock', 14400);

        if (!$lock->acquire()) {
            $this->logger->warning('Une synchronisation produit est deja en cours. Message ignore.');
            $this->syncLogService->warning('product', 'Synchronisation deja en cours', 'Le message a ete ignore car un traitement produit est deja actif.');

            return;
        }

        try {
            $this->syncLogService->info('product', 'Synchronisation produits demarree');
            $result = $this->orchestrator->runProductSync();

            $this->syncLogService->success(
                'product',
                'Synchronisation produits terminee',
                sprintf(
                    '%d produits importes, %d produits envoyes a HubSpot.',
                    $result['imported'] ?? 0,
                    $result['hubspotSent'] ?? 0,
                ),
                $result
            );
        } catch (\Throwable $e) {
            $this->logger->error('Erreur pendant la synchronisation produit : ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->syncLogService->error('product', 'Erreur synchronisation produits', $e->getMessage());

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
