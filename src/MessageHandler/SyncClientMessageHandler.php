<?php

namespace App\MessageHandler;

use App\Message\SyncClientMessage;
use App\Service\Flux\ClientFluxOrchestrator;
use App\Service\Log\SyncLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncClientMessageHandler
{
    public function __construct(
        private readonly ClientFluxOrchestrator $orchestrator,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly SyncLogService $syncLogService,
    ) {
    }

    public function __invoke(SyncClientMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-client-lock', 14400);

        if (!$lock->acquire()) {
            $this->logger->warning('Une synchronisation client est deja en cours. Message ignore.');
            $this->syncLogService->warning('client', 'Synchronisation deja en cours', 'Le message a ete ignore car un traitement client est deja actif.');

            return;
        }

        try {
            $this->syncLogService->info('client', 'Synchronisation client demarree');
            $result = $this->orchestrator->run();

            $this->syncLogService->success(
                'client',
                'Synchronisation client terminee',
                sprintf(
                    '%d companies, %d contacts, %d relations, %d exports ERP, %d id_erp HubSpot.',
                    $result['savedCompanies'] ?? 0,
                    $result['savedContacts'] ?? 0,
                    $result['savedRelations'] ?? 0,
                    $result['erpSent'] ?? 0,
                    $result['hubspotUpdated'] ?? 0,
                ),
                $result
            );
        } catch (\Throwable $e) {
            $this->logger->error('Erreur pendant la synchronisation client : ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->syncLogService->error('client', 'Erreur synchronisation client', $e->getMessage());

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
