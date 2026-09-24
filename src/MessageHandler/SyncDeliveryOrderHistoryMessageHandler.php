<?php

namespace App\MessageHandler;

use App\Message\SyncDeliveryOrderHistoryMessage;
use App\Service\HubSpot\HubspotDeliveryOrderSyncService;
use App\Service\Log\SyncLogService;
use App\Service\Mailer\DeliveryOrderSyncReportMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
final class SyncDeliveryOrderHistoryMessageHandler
{
    private const CHUNK_DAYS = 90;
    private const LOCK_TTL = 43200;

    public function __construct(
        private readonly HubspotDeliveryOrderSyncService $deliveryOrderSyncService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly SyncLogService $syncLogService,
        private readonly DeliveryOrderSyncReportMailer $reportMailer,
    ) {
    }

    public function __invoke(SyncDeliveryOrderHistoryMessage $message): void
    {
        $dateFrom = new \DateTimeImmutable($message->dateFrom);
        $dateTo = new \DateTimeImmutable($message->dateTo);
        $lock = $this->lockFactory->createLock('sync-delivery-order-lock', self::LOCK_TTL);

        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException('Une synchronisation Orders HubSpot est deja en cours.');
        }

        try {
            $this->syncLogService->info(
                'delivery_order',
                'Rattrapage historique Orders HubSpot demarre',
                sprintf(
                    'Analyse chronologique des BL et factures Sage du %s au %s par blocs de %d jours.',
                    $dateFrom->format('d/m/Y'),
                    $dateTo->format('d/m/Y'),
                    self::CHUNK_DAYS,
                ),
            );
            $result = $this->emptyResult();
            $chunkFrom = $dateFrom;
            $chunkNumber = 0;

            while ($chunkFrom <= $dateTo) {
                $chunkTo = $chunkFrom->modify(sprintf('+%d days', self::CHUNK_DAYS - 1));

                if ($chunkTo > $dateTo) {
                    $chunkTo = $dateTo;
                }

                $chunkResult = $this->deliveryOrderSyncService->sync($chunkFrom, $chunkTo);
                $this->mergeResult($result, $chunkResult);
                ++$chunkNumber;
                $lock->refresh(self::LOCK_TTL);

                if ($chunkNumber % 10 === 0 || $chunkTo >= $dateTo) {
                    $this->logger->info('Progression du rattrapage historique Orders HubSpot.', [
                        'chunk' => $chunkNumber,
                        'processedUntil' => $chunkTo->format('Y-m-d'),
                        'analyzed' => $result['analyzed'],
                        'failed' => $result['failed'],
                    ]);
                    $this->syncLogService->info(
                        'delivery_order',
                        'Rattrapage historique Orders HubSpot en cours',
                        sprintf(
                            'Historique traite jusqu au %s : %d documents analyses et %d erreurs.',
                            $chunkTo->format('d/m/Y'),
                            $result['analyzed'],
                            $result['failed'],
                        ),
                    );
                }

                $chunkFrom = $chunkTo->modify('+1 day');
            }

            $result['chunks'] = $chunkNumber;
            $this->syncLogService->success(
                'delivery_order',
                'Rattrapage historique Orders HubSpot termine',
                sprintf(
                    '%d BL et %d factures analyses, %d Orders crees, %d actualises et %d erreurs sur %d blocs.',
                    $result['deliveryNotesAnalyzed'],
                    $result['invoicesAnalyzed'],
                    $result['sent'],
                    $result['updated'],
                    $result['failed'],
                    $chunkNumber,
                ),
                $result,
            );

            try {
                $this->reportMailer->sendCompleted($dateFrom, $dateTo, $result);
            } catch (\Throwable $exception) {
                $this->logger->warning('Le recapitulatif du rattrapage historique n a pas pu etre envoye.', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
                $this->syncLogService->warning(
                    'delivery_order',
                    'Recapitulatif historique Orders HubSpot non envoye',
                    $exception->getMessage(),
                );
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Erreur pendant le rattrapage historique des Orders HubSpot.', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $this->syncLogService->error(
                'delivery_order',
                'Erreur rattrapage historique Orders HubSpot',
                $exception->getMessage(),
            );

            try {
                $this->reportMailer->sendFailed($dateFrom, $dateTo, $exception);
            } catch (\Throwable $mailException) {
                $this->logger->warning('L alerte email du rattrapage historique n a pas pu etre envoyee.', [
                    'message' => $mailException->getMessage(),
                    'exception' => $mailException,
                ]);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'analyzed' => 0,
            'deliveryNotesAnalyzed' => 0,
            'invoicesAnalyzed' => 0,
            'discovered' => 0,
            'sent' => 0,
            'existing' => 0,
            'updated' => 0,
            'skipped' => 0,
            'changed' => 0,
            'failed' => 0,
            'warnings' => [],
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $chunkResult
     */
    private function mergeResult(array &$result, array $chunkResult): void
    {
        foreach ([
            'analyzed',
            'deliveryNotesAnalyzed',
            'invoicesAnalyzed',
            'discovered',
            'sent',
            'existing',
            'updated',
            'skipped',
            'changed',
            'failed',
        ] as $key) {
            $result[$key] += (int) ($chunkResult[$key] ?? 0);
        }

        $result['warnings'] = array_merge($result['warnings'], $chunkResult['warnings'] ?? []);
        $result['errors'] = array_merge($result['errors'], $chunkResult['errors'] ?? []);
    }
}
