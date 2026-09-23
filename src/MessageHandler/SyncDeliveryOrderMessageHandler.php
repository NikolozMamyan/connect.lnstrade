<?php

namespace App\MessageHandler;

use App\Message\SyncDeliveryOrderMessage;
use App\Service\HubSpot\HubspotDeliveryOrderSyncService;
use App\Service\Log\SyncLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncDeliveryOrderMessageHandler
{
    public function __construct(
        private readonly HubspotDeliveryOrderSyncService $deliveryOrderSyncService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly SyncLogService $syncLogService,
    ) {
    }

    public function __invoke(SyncDeliveryOrderMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-delivery-order-lock', 7200);

        if (!$lock->acquire()) {
            $this->syncLogService->warning(
                'delivery_order',
                'Synchronisation Orders HubSpot deja en cours',
                'Le message a ete ignore car un import de BL est deja actif.'
            );

            return;
        }

        try {
            $dateFrom = new \DateTimeImmutable($message->dateFrom);
            $dateTo = new \DateTimeImmutable($message->dateTo);
            $this->syncLogService->info(
                'delivery_order',
                'Analyse des BL Sage demarree',
                sprintf('Periode du %s au %s.', $dateFrom->format('d/m/Y'), $dateTo->format('d/m/Y'))
            );
            $result = $this->deliveryOrderSyncService->sync($dateFrom, $dateTo);
            $this->syncLogService->success(
                'delivery_order',
                'Synchronisation Orders HubSpot terminee',
                sprintf(
                    '%d BL analyses, %d Orders crees, %d existants, %d ignores, %d modifies, %d en erreur.',
                    $result['analyzed'],
                    $result['sent'],
                    $result['existing'],
                    $result['skipped'],
                    $result['changed'],
                    $result['failed'],
                ),
                $result,
            );

            if ($result['errors'] !== []) {
                $this->syncLogService->warning(
                    'delivery_order',
                    'Erreurs partielles pendant la synchronisation des BL',
                    sprintf('%d BL n ont pas pu etre exportes.', count($result['errors'])),
                    ['errors' => $result['errors']],
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Erreur pendant la synchronisation des BL vers HubSpot.', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->syncLogService->error('delivery_order', 'Erreur synchronisation Orders HubSpot', $exception->getMessage());

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
