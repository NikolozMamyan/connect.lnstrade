<?php

namespace App\Controller\Flux;

use App\Repository\ErpInvoiceRepository;
use App\Repository\SyncLogRepository;
use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use App\Service\Pdf\InvoicePdfGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/factures', name: 'flux_factures_')]
class FluxFacturesController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/', name: 'index')]
    public function index(
        Request $request,
        ErpInvoiceRepository $erpInvoiceRepository,
        SyncLogRepository $syncLogRepository,
    ): Response
    {
        $clientId = trim((string) $request->query->get('clientId', ''));
        $search = trim((string) $request->query->get('q', ''));
        $documentType = trim((string) $request->query->get('type', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $watchSince = null;
        $watchSinceValue = trim((string) $request->query->get('watchSince', ''));

        if ($watchSinceValue !== '') {
            try {
                $watchSince = new \DateTimeImmutable($watchSinceValue);
            } catch (\Throwable) {
                $watchSince = null;
            }
        }

        $invoiceEntities = $erpInvoiceRepository->findPaginated($search, $clientId, $documentType, $page, self::PER_PAGE);
        $total = $erpInvoiceRepository->countFiltered($search, $clientId, $documentType);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $latestLogs = $syncLogRepository->findLatestByFluxKeys(['invoice'], 5);
        $invoiceSummaries = array_map(fn ($invoice) => $this->buildInvoiceSummary($invoice), $invoiceEntities);

        return $this->render('flux/factures/index.html.twig', [
            'invoices' => $invoiceSummaries,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'filters' => [
                'clientId' => $clientId,
                'q' => $search,
                'type' => $documentType,
            ],
            'latestInvoiceLogs' => $latestLogs,
            'watchSince' => $watchSince?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/refresh', name: 'sync', methods: ['POST'])]
    public function sync(
        Request $request,
        SyncJobDispatcher $syncJobDispatcher,
        SyncLogService $syncLogService,
    ): Response {
        if (!$this->isCsrfTokenValid('flux_invoice_sync', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('flux_factures_index');
        }

        $requestedAt = new \DateTimeImmutable();
        $syncJobDispatcher->dispatch(SyncJobDispatcher::INVOICE);
        $syncLogService->info(
            'invoice',
            'Synchronisation factures demandee',
            'Le message Messenger a ete envoye pour rafraichir les factures Sage en base.'
        );
        $this->addFlash('success', 'Demande de synchronisation factures prise en compte.');

        return $this->redirectToRoute('flux_factures_index', [
            'watchSince' => $requestedAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{invoiceNumber}', name: 'show', requirements: ['invoiceNumber' => '[A-Za-z0-9_-]+'])]
    public function show(string $invoiceNumber, ErpInvoiceRepository $erpInvoiceRepository): Response
    {
        $invoice = $erpInvoiceRepository->findOneByInvoiceNumber($invoiceNumber);

        if ($invoice === null) {
            throw $this->createNotFoundException(sprintf('Facture %s introuvable.', $invoiceNumber));
        }

        $summary = $this->buildInvoiceSummary($invoice);
        $lines = [];

        foreach ($invoice->getLines() as $line) {
            $lines[] = [
                'reference' => (string) ($line->getReference() ?? ''),
                'intitule' => (string) ($line->getIntitule() ?? ''),
                'quantite' => $line->getQuantite(),
                'prix_unitaire' => $line->getPrixUnitaire(),
                'total' => $line->getTotal(),
            ];
        }

        return $this->render('flux/factures/show.html.twig', [
            'invoice' => $summary,
            'lines' => $lines,
            'pdfAudit' => $this->buildPdfAudit($summary, $lines),
            'transformActions' => [
                ['label' => 'Transformer en facture', 'endpoint' => 'POST /Document/transformEnFacture'],
                ['label' => 'Transformer en bon de livraison', 'endpoint' => 'POST /Document/transformEnBondelivraison'],
                ['label' => 'Transformer en bon de commande', 'endpoint' => 'POST /Document/transformEnBondecommande'],
            ],
        ]);
    }

    #[Route('/{invoiceNumber}/pdf', name: 'pdf', requirements: ['invoiceNumber' => '[A-Za-z0-9_-]+'])]
    public function pdf(string $invoiceNumber, ErpInvoiceRepository $erpInvoiceRepository): Response
    {
        $invoice = $erpInvoiceRepository->findOneByInvoiceNumber($invoiceNumber);

        if ($invoice === null) {
            throw $this->createNotFoundException(sprintf('Facture %s introuvable.', $invoiceNumber));
        }

        $summary = $this->buildInvoiceSummary($invoice);
        $lines = [];

        foreach ($invoice->getLines() as $line) {
            $lines[] = [
                'reference' => (string) ($line->getReference() ?? ''),
                'intitule' => (string) ($line->getIntitule() ?? ''),
                'quantite' => $line->getQuantite(),
                'prix_unitaire' => $line->getPrixUnitaire(),
                'total' => $line->getTotal(),
            ];
        }

        return $this->render('flux/factures/pdf.html.twig', [
            'invoice' => $summary,
            'lines' => $lines,
            'pdfAudit' => $this->buildPdfAudit($summary, $lines),
        ]);
    }

    #[Route('/{invoiceNumber}/pdf/download', name: 'pdf_download', requirements: ['invoiceNumber' => '[A-Za-z0-9_-]+'])]
    public function pdfDownload(
        string $invoiceNumber,
        ErpInvoiceRepository $erpInvoiceRepository,
        InvoicePdfGenerator $invoicePdfGenerator,
    ): Response {
        $invoice = $erpInvoiceRepository->findOneByInvoiceNumber($invoiceNumber);

        if ($invoice === null) {
            throw $this->createNotFoundException(sprintf('Facture %s introuvable.', $invoiceNumber));
        }

        $summary = $this->buildInvoiceSummary($invoice);
        $lines = [];

        foreach ($invoice->getLines() as $line) {
            $lines[] = [
                'reference' => (string) ($line->getReference() ?? ''),
                'intitule' => (string) ($line->getIntitule() ?? ''),
                'quantite' => $line->getQuantite(),
                'prix_unitaire' => $line->getPrixUnitaire(),
                'total' => $line->getTotal(),
            ];
        }

        $pdf = $invoicePdfGenerator->generate($summary, $lines);
        $safeFilename = preg_replace('/[^A-Za-z0-9_-]/', '_', $invoiceNumber) ?: 'invoice';

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s.pdf"', $safeFilename),
        ]);
    }

    #[Route('/sync-status/check', name: 'sync_status', methods: ['GET'])]
    public function syncStatus(Request $request, SyncLogRepository $syncLogRepository): JsonResponse
    {
        $since = $request->query->get('since');
        $logs = $syncLogRepository->findLatestByFluxKeys(['invoice'], 10);
        $completed = false;
        $latestLog = $logs[0] ?? null;

        foreach ($logs as $log) {
            if ($since !== null && $log->getCreatedAt()?->format(\DateTimeInterface::ATOM) < $since) {
                continue;
            }

            $title = mb_strtolower((string) $log->getTitle());

            if (str_contains($title, 'terminee') || str_contains($title, 'erreur')) {
                $completed = true;
                break;
            }
        }

        return $this->json([
            'completed' => $completed,
            'latestLog' => $latestLog ? [
                'id' => $latestLog->getId(),
                'title' => $latestLog->getTitle(),
                'level' => $latestLog->getLevel(),
                'createdAt' => $latestLog->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ] : null,
        ]);
    }

    private function buildInvoiceSummary(object $invoice): array
    {
        $invoiceNumber = (string) $invoice->getInvoiceNumber();

        return [
            'numero_facture' => $invoiceNumber,
            'clientid' => (string) ($invoice->getClientId() ?? ''),
            'line_count' => $invoice->getLineCount(),
            'quantity_total' => $invoice->getQuantityTotal(),
            'amount_total' => $invoice->getAmountTotal(),
            'last_synced_at' => $invoice->getLastSyncedAt(),
            'document_type' => $this->resolveDocumentType($invoiceNumber),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array{availableFields: array<int, array{label: string, value: string}>, missingFields: array<int, string>}
     */
    private function buildPdfAudit(array $invoice, array $lines): array
    {
        $availableFields = [
            ['label' => 'Numero facture', 'value' => (string) ($invoice['numero_facture'] ?? '')],
            ['label' => 'Type document', 'value' => (string) ($invoice['document_type'] ?? '')],
            ['label' => 'Client ID Sage', 'value' => (string) ($invoice['clientid'] ?? '')],
            ['label' => 'Nombre de lignes', 'value' => (string) ($invoice['line_count'] ?? 0)],
            ['label' => 'Quantite totale', 'value' => number_format((float) ($invoice['quantity_total'] ?? 0), 0, ',', ' ')],
            ['label' => 'Montant total', 'value' => number_format((float) ($invoice['amount_total'] ?? 0), 2, ',', ' ') . ' EUR'],
            ['label' => 'Derniere sync', 'value' => ($invoice['last_synced_at'] instanceof \DateTimeInterface) ? $invoice['last_synced_at']->format('d/m/Y H:i') : 'N/A'],
            ['label' => 'Table lignes > Product ref', 'value' => 'reference'],
            ['label' => 'Table lignes > Description', 'value' => 'intitule'],
            ['label' => 'Table lignes > Qty', 'value' => 'quantite'],
            ['label' => 'Table lignes > Unit price', 'value' => 'prix_unitaire'],
            ['label' => 'Table lignes > Amount Ext', 'value' => 'total'],
            ['label' => 'Reference ligne 1', 'value' => (string) ($lines[0]['reference'] ?? 'N/A')],
            ['label' => 'Intitule ligne 1', 'value' => (string) ($lines[0]['intitule'] ?? 'N/A')],
            ['label' => 'Quantite ligne 1', 'value' => isset($lines[0]['quantite']) ? number_format((float) $lines[0]['quantite'], 0, ',', ' ') : 'N/A'],
            ['label' => 'Prix unitaire ligne 1', 'value' => isset($lines[0]['prix_unitaire']) ? number_format((float) $lines[0]['prix_unitaire'], 4, ',', ' ') . ' EUR' : 'N/A'],
        ];

        $missingFields = [
            'Nom client',
            'Date de facture',
            'Adresse de facturation',
            'Adresse de livraison',
            'N TVA client',
            'Order number',
            'Sales representative',
            'Nature of operations',
            'Incoterm',
            'Date d echeance',
            'Conditions de paiement',
            'Banque',
            'IBAN',
            'BIC',
            'Commentaires',
            'Shipping costs',
            'Sous-total HT',
            'Montant TVA',
            'Total discount',
            'Acompte',
            'Total detaille',
            'Colonne discount par ligne',
            'Colonne taux TVA par ligne',
            'EAN par ligne',
            'Lot par ligne',
            'Informations societes emettrice',
            'Mentions legales',
            'Logo / branding',
        ];

        return [
            'availableFields' => $availableFields,
            'missingFields' => $missingFields,
        ];
    }

    private function resolveDocumentType(string $invoiceNumber): string
    {
        $prefix = strtoupper(substr(trim($invoiceNumber), 0, 2));

        return match ($prefix) {
            'FV' => 'Avoir',
            'FA' => 'Facture',
            default => 'Document',
        };
    }
}
