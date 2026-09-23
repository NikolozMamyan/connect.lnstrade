<?php

namespace App\MessageHandler;

use App\Message\SyncDeliveryOrderMessage;
use App\Service\HubSpot\HubspotDeliveryOrderSyncService;
use App\Service\Log\SyncLogService;
use App\Service\Mailer\DeliveryOrderSyncReportMailer;
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
        private readonly DeliveryOrderSyncReportMailer $reportMailer,
    ) {
    }

    public function __invoke(SyncDeliveryOrderMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-delivery-order-lock', 7200);

        if (!$lock->acquire()) {
            $this->syncLogService->warning(
                'delivery_order',
                'Synchronisation Orders HubSpot deja en cours',
                'Le message a ete ignore car une analyse des documents Sage est deja active.'
            );

            return;
        }

        try {
            $dateFrom = new \DateTimeImmutable($message->dateFrom);
            $dateTo = new \DateTimeImmutable($message->dateTo);
            $this->syncLogService->info(
                'delivery_order',
                'Analyse des BL et factures Sage demarree',
                sprintf('Periode du %s au %s.', $dateFrom->format('d/m/Y'), $dateTo->format('d/m/Y'))
            );
            $result = $this->deliveryOrderSyncService->sync($dateFrom, $dateTo);
            $this->syncLogService->success(
                'delivery_order',
                'Synchronisation Orders HubSpot terminee',
                sprintf(
                    '%d BL et %d factures analyses, %d Orders crees, %d actualises, %d existants, %d ignores, %d modifies, %d en erreur.',
                    $result['deliveryNotesAnalyzed'],
                    $result['invoicesAnalyzed'],
                    $result['sent'],
                    $result['updated'],
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
                    'Erreurs partielles pendant la synchronisation des documents Sage',
                    sprintf('%d documents n ont pas pu etre synchronises.', count($result['errors'])),
                    ['errors' => $result['errors']],
                );
            }

            try {
                $this->reportMailer->sendCompleted($dateFrom, $dateTo, $result);
            } catch (\Throwable $exception) {
                $this->logger->warning('Le recapitulatif email Orders HubSpot n a pas pu etre envoye.', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
                $this->syncLogService->warning(
                    'delivery_order',
                    'Recapitulatif email Orders HubSpot non envoye',
                    $exception->getMessage(),
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Erreur pendant la synchronisation des documents Sage vers HubSpot.', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->syncLogService->error('delivery_order', 'Erreur synchronisation Orders HubSpot', $exception->getMessage());

            if (isset($dateFrom, $dateTo)) {
                try {
                    $this->reportMailer->sendFailed($dateFrom, $dateTo, $exception);
                } catch (\Throwable $mailException) {
                    $this->logger->warning('L alerte email Orders HubSpot n a pas pu etre envoyee.', [
                        'message' => $mailException->getMessage(),
                        'exception' => $mailException,
                    ]);
                }
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
