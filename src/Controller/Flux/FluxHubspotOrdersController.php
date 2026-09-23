<?php

namespace App\Controller\Flux;

use App\Entity\ErpDeliveryNote;
use App\Repository\ErpDeliveryNoteRepository;
use App\Repository\SyncLogRepository;
use App\Service\Flux\SyncJobDispatcher;
use App\Service\Log\SyncLogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/orders-hubspot', name: 'flux_hubspot_orders_')]
final class FluxHubspotOrdersController extends AbstractController
{
    private const PER_PAGE = 25;
    private const ALLOWED_PERIODS = [30, 60, 90];
    private const DEFAULT_PERIOD = 90;

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        ErpDeliveryNoteRepository $deliveryNoteRepository,
        SyncLogRepository $syncLogRepository,
    ): Response {
        $search = trim((string) $request->query->get('q', ''));
        $status = trim((string) $request->query->get('status', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $period = $this->normalizePeriod($request->query->getInt('period', self::DEFAULT_PERIOD));
        $today = new \DateTimeImmutable('today');
        $dateFrom = $today->modify(sprintf('-%d days', $period - 1));
        $total = $deliveryNoteRepository->countFiltered($search, $status);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $watchSince = $this->parseDateTime((string) $request->query->get('watchSince', ''));
        $statusCounts = $deliveryNoteRepository->countByStatus();

        return $this->render('flux/hubspot_orders/index.html.twig', [
            'deliveryNotes' => $deliveryNoteRepository->findPaginated($search, $status, $page, self::PER_PAGE),
            'latestLogs' => $syncLogRepository->findLatestByFluxKeys(['delivery_order'], 5),
            'filters' => [
                'q' => $search,
                'status' => $status,
                'period' => $period,
                'dateFrom' => $dateFrom->format('Y-m-d'),
                'dateTo' => $today->format('Y-m-d'),
            ],
            'statusCounts' => $statusCounts,
            'localTotal' => array_sum($statusCounts),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'watchSince' => $watchSince?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/sync', name: 'sync', methods: ['POST'])]
    public function sync(
        Request $request,
        SyncJobDispatcher $syncJobDispatcher,
        SyncLogService $syncLogService,
    ): Response {
        if (!$this->isCsrfTokenValid('flux_hubspot_orders_sync', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('flux_hubspot_orders_index');
        }

        $period = $this->normalizePeriod($request->request->getInt('period', self::DEFAULT_PERIOD));
        $dateTo = new \DateTimeImmutable('today');
        $dateFrom = $dateTo->modify(sprintf('-%d days', $period - 1));

        $requestedAt = new \DateTimeImmutable();
        $syncJobDispatcher->dispatch(SyncJobDispatcher::DELIVERY_ORDER, $dateFrom, $dateTo);
        $syncLogService->info(
            'delivery_order',
            'Synchronisation Orders HubSpot demandee',
            sprintf('Analyse des BL et factures Sage du %s au %s ajoutee a Messenger.', $dateFrom->format('d/m/Y'), $dateTo->format('d/m/Y')),
        );
        $this->addFlash('success', 'Analyse des BL et factures, puis synchronisation des Orders HubSpot lancees en arriere-plan.');

        return $this->redirectToRoute('flux_hubspot_orders_index', [
            'period' => $period,
            'watchSince' => $requestedAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/sync-status', name: 'sync_status', methods: ['GET'])]
    public function syncStatus(Request $request, SyncLogRepository $syncLogRepository): JsonResponse
    {
        $since = $this->parseDateTime((string) $request->query->get('since', ''));
        $logs = $syncLogRepository->findLatestByFluxKeys(['delivery_order'], 10);
        $latestLog = $logs[0] ?? null;
        $completed = false;

        foreach ($logs as $log) {
            if ($since !== null && $log->getCreatedAt() < $since) {
                continue;
            }

            $title = mb_strtolower((string) $log->getTitle());

            if (str_contains($title, 'terminee') || str_contains($title, 'erreur synchronisation')) {
                $completed = true;
                break;
            }
        }

        return $this->json([
            'completed' => $completed,
            'latestLog' => $latestLog ? [
                'title' => $latestLog->getTitle(),
                'level' => $latestLog->getLevel(),
                'createdAt' => $latestLog->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ] : null,
        ]);
    }

    private function normalizePeriod(int $period): int
    {
        return in_array($period, self::ALLOWED_PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
