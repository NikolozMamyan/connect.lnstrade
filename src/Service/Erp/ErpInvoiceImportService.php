<?php

namespace App\Service\Erp;

use App\Entity\ErpInvoice;
use App\Entity\ErpInvoiceLine;
use App\Repository\ErpInvoiceRepository;
use App\Service\Erp\SageClient;
use Doctrine\ORM\EntityManagerInterface;

class ErpInvoiceImportService
{
    private const BATCH_SIZE = 20;

    public function __construct(
        private readonly SageClient $sageClient,
        private readonly ErpInvoiceRepository $erpInvoiceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function importInvoicesFromErp(): array
    {
        $invoices = $this->sageClient->get('/invoices');
        $created = 0;
        $updated = 0;
        $errors = [];
        $processed = 0;

        foreach (array_values($invoices) as $index => $invoiceData) {
            if (!\is_array($invoiceData)) {
                continue;
            }

            try {
                $invoiceNumber = trim((string) ($invoiceData['numero_facture'] ?? ''));

                if ($invoiceNumber === '') {
                    throw new \RuntimeException('Numero de facture manquant.');
                }

                $invoice = $this->erpInvoiceRepository->findOneByInvoiceNumber($invoiceNumber);

                if (!$invoice instanceof ErpInvoice) {
                    $invoice = new ErpInvoice();
                    $invoice->setInvoiceNumber($invoiceNumber);
                    ++$created;
                } else {
                    ++$updated;
                }

                $this->hydrateInvoice($invoice, $invoiceData);
                $this->entityManager->persist($invoice);
                ++$processed;
            } catch (\Throwable $e) {
                $errors[] = [
                    'invoiceNumber' => $invoiceData['numero_facture'] ?? null,
                    'message' => $e->getMessage(),
                ];
            }

            if ((($index + 1) % self::BATCH_SIZE) === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                gc_collect_cycles();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        gc_collect_cycles();

        return [
            'imported' => $processed,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    private function hydrateInvoice(ErpInvoice $invoice, array $invoiceData): void
    {
        $documentSign = $this->resolveDocumentSign((string) $invoice->getInvoiceNumber());
        $lines = $invoiceData['commandes'] ?? [];
        $quantityTotal = 0.0;
        $amountTotal = 0.0;

        foreach ($invoice->getLines()->toArray() as $existingLine) {
            $invoice->removeLine($existingLine);
        }

        foreach ($lines as $position => $lineData) {
            if (!\is_array($lineData)) {
                continue;
            }

            $quantity = abs((float) ($lineData['quantite'] ?? 0)) * $documentSign;
            $unitPrice = abs((float) ($lineData['prix_unitaire'] ?? 0));
            $total = abs((float) ($lineData['total'] ?? ($quantity * $unitPrice))) * $documentSign;

            $line = (new ErpInvoiceLine())
                ->setPosition($position + 1)
                ->setReference($this->nullableString($lineData['reference'] ?? null))
                ->setIntitule($this->nullableString($lineData['intitule'] ?? null))
                ->setQuantite($quantity)
                ->setPrixUnitaire($unitPrice)
                ->setTotal($total)
                ->setRawPayload($lineData);

            $invoice->addLine($line);
            $quantityTotal += $quantity;
            $amountTotal += $total;
        }

        $invoice
            ->setClientId($this->nullableString($invoiceData['clientid'] ?? null))
            ->setLineCount(count($invoice->getLines()))
            ->setQuantityTotal($quantityTotal)
            ->setAmountTotal($amountTotal)
            ->setRawPayload($invoiceData)
            ->setLastSyncedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resolveDocumentSign(string $invoiceNumber): int
    {
        $prefix = strtoupper(substr(trim($invoiceNumber), 0, 2));

        return $prefix === 'FV' ? -1 : 1;
    }
}
