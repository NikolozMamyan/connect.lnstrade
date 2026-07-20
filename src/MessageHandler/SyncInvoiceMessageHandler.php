<?php

namespace App\MessageHandler;

use App\Message\SyncInvoiceMessage;
use App\Service\Erp\ErpInvoiceImportService;
use App\Service\Log\SyncLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncInvoiceMessageHandler
{
    public function __construct(
        private readonly ErpInvoiceImportService $erpInvoiceImportService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly SyncLogService $syncLogService,
    ) {
    }

    public function __invoke(SyncInvoiceMessage $message): void
    {
        $lock = $this->lockFactory->createLock('sync-invoice-lock', 14400);

        if (!$lock->acquire()) {
            $this->logger->warning('Une synchronisation facture est deja en cours. Message ignore.');
            $this->syncLogService->warning('invoice', 'Synchronisation factures deja en cours', 'Le message a ete ignore car un traitement factures est deja actif.');

            return;
        }

        try {
            $this->syncLogService->info('invoice', 'Synchronisation factures demarree');
            $result = $this->erpInvoiceImportService->importInvoicesFromErp();

            $this->syncLogService->success(
                'invoice',
                'Synchronisation factures terminee',
                sprintf(
                    '%d facture(s) importee(s), %d creee(s), %d mise(s) a jour, %d inchangee(s).',
                    $result['imported'] ?? 0,
                    $result['created'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['skipped'] ?? 0,
                ),
                $result
            );

            if (!empty($result['errors'])) {
                $this->syncLogService->warning(
                    'invoice',
                    'Erreurs partielles pendant l import factures',
                    sprintf('%d erreur(s) detectee(s) pendant l import Sage.', count($result['errors'])),
                    ['errors' => $result['errors']]
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Erreur pendant la synchronisation facture : ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $this->syncLogService->error('invoice', 'Erreur synchronisation factures', $e->getMessage());

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
