<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DealRepository;
use App\Repository\OrderFormRepository;
use App\Repository\DealLineItemRepository;
use App\Repository\ErpProductRepository;
use App\Repository\HubspotCompanyRepository;
use App\Repository\HubspotContactRepository;
use App\Repository\SyncLogRepository;
use App\Entity\SyncLog;
use App\Service\Security\CommercialAccessService;
use App\Service\Monitoring\CronHeartbeatService;
use App\Service\Monitoring\MessengerHealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/flux/dashboard', name: 'app_dashboard')]
    public function index(
        HubspotCompanyRepository $hubspotCompanyRepository,
        HubspotContactRepository $hubspotContactRepository,
        ErpProductRepository $erpProductRepository,
        SyncLogRepository $syncLogRepository,
        OrderFormRepository $orderFormRepository,
        DealRepository $dealRepository,
        DealLineItemRepository $dealLineItemRepository,
        CommercialAccessService $commercialAccessService,
        CronHeartbeatService $cronHeartbeatService,
        MessengerHealthService $messengerHealthService,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        $commercial = $commercialAccessService->resolveCommercial($user);

        if ($commercialAccessService->isCommercialUser($user)) {
            $statusCounts = $commercial ? array_replace(
                ['pending' => 0, 'validated' => 0, 'failed' => 0],
                $orderFormRepository->countByStatusForCommercial($commercial)
            ) : ['pending' => 0, 'validated' => 0, 'failed' => 0];

            return $this->render('dashboard/index.html.twig', [
                'dashboardScope' => 'commercial',
                'commercialScopeName' => $commercial?->getFullName(),
                'stats' => [
                    'orderForms' => $commercial ? $orderFormRepository->countByCommercial($commercial) : 0,
                    'deals' => $commercial ? $dealRepository->countByCommercial($commercial) : 0,
                    'dealAmount' => $commercial ? $dealRepository->sumTotalAmountByCommercial($commercial) : 0.0,
                    'pendingOrderForms' => $statusCounts['pending'],
                    'validatedOrderForms' => $statusCounts['validated'],
                    'failedOrderForms' => $statusCounts['failed'],
                ],
                'orderFormDailyCounts' => $commercial ? $orderFormRepository->countLastDaysForCommercial($commercial, 7) : [],
                'topReferences' => $commercial ? $dealLineItemRepository->findTopReferencesForCommercial($commercial, 8) : [],
                'recentOrderForms' => $commercial ? $orderFormRepository->findLatestForCommercial($commercial, 6) : [],
                'recentDeals' => $commercial ? $dealRepository->findLatestForCommercial($commercial, 6) : [],
            ]);
        }

        $since24h = new \DateTimeImmutable('-24 hours');
        $companies = $hubspotCompanyRepository->findAllWithContacts();
        $activeProducts = $erpProductRepository->findActiveProductsForSync();
        $recentLogs = $syncLogRepository->findLatest(14);
        $alertLogs = array_values(array_filter(
            $syncLogRepository->findLatest(60),
            static fn (SyncLog $log): bool => \in_array($log->getLevel(), ['warning', 'error'], true)
        ));

        $companiesReady = 0;
        $companiesWithErpId = 0;

        foreach ($companies as $company) {
            $sageIntegration = mb_strtolower(trim((string) $company->getSageIntegration()));

            if ($sageIntegration === 'yes') {
                ++$companiesReady;
            }

            if (trim((string) $company->getIdErp()) !== '') {
                ++$companiesWithErpId;
            }
        }

        $stockUpdatedToday = 0;
        $productsLinkedToHubspot = 0;
        $stockValue = 0.0;

        foreach ($activeProducts as $product) {
            if ($product->getStockUpdatedAt() !== null && $product->getStockUpdatedAt() >= $since24h) {
                ++$stockUpdatedToday;
            }

            if (trim((string) $product->getHubspotObjectId()) !== '') {
                ++$productsLinkedToHubspot;
            }

            $stockValue += max(0, (float) ($product->getStockReel() ?? 0)) * max(0, (float) ($product->getPrixTtc() ?? 0));
        }

        $levelCounts = array_replace(
            ['success' => 0, 'info' => 0, 'warning' => 0, 'error' => 0],
            $syncLogRepository->countByLevelSince($since24h)
        );
        $fluxCounts = $syncLogRepository->countByFluxSince($since24h);
        $totalLogs24h = $syncLogRepository->countSince($since24h);

        $fluxStatusCards = [
            $this->buildFluxStatusCard('client', 'Flux clients', $syncLogRepository),
            $this->buildFluxStatusCard('product', 'Catalogue produits', $syncLogRepository),
            $this->buildFluxStatusCard('product_stock', 'Stocks produits', $syncLogRepository),
            $this->buildFluxStatusCard('webhook', 'Webhook HubSpot', $syncLogRepository),
        ];
        $workerHealth = $cronHeartbeatService->read();

        try {
            $workerHealth['queue'] = $messengerHealthService->getSnapshot();
        } catch (\Throwable) {
            // Le dernier snapshot du heartbeat reste disponible si la base est momentanement indisponible.
        }

        return $this->render('dashboard/index.html.twig', [
            'dashboardScope' => 'global',
            'stats' => [
                'companies' => count($companies),
                'companiesReady' => $companiesReady,
                'companiesWithErpId' => $companiesWithErpId,
                'contacts' => $hubspotContactRepository->count([]),
                'products' => count($activeProducts),
                'productsLinkedToHubspot' => $productsLinkedToHubspot,
                'stockUpdatedToday' => $stockUpdatedToday,
                'stockValue' => $stockValue,
                'logs24h' => $totalLogs24h,
                'success24h' => $levelCounts['success'],
                'warning24h' => $levelCounts['warning'],
                'error24h' => $levelCounts['error'],
            ],
            'levelCounts' => $levelCounts,
            'fluxCounts' => $fluxCounts,
            'recentLogs' => $recentLogs,
            'alertLogs' => array_slice($alertLogs, 0, 8),
            'fluxStatusCards' => $fluxStatusCards,
            'workerHealth' => $workerHealth,
            'syncSchedule' => [
                ['label' => 'Clients', 'time' => '18:00', 'frequency' => '1 fois / jour'],
                ['label' => 'Produits', 'time' => '18:00', 'frequency' => '1 fois / jour'],
                ['label' => 'Stocks', 'time' => '09:00, 14:00, 20:00', 'frequency' => '3 fois / jour'],
                ['label' => 'Factures', 'time' => 'Toutes les 15 min', 'frequency' => 'Automatique'],
            ],
        ]);
    }
    #[Route('/', name: 'app_index')]
    public function appIndex(): Response 
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->redirectToRoute('app_login');
    }

    private function buildFluxStatusCard(string $fluxKey, string $label, SyncLogRepository $syncLogRepository): array
    {
        $logs = $syncLogRepository->findLatestByFluxKeys([$fluxKey], 1);
        $lastLog = $logs[0] ?? null;

        if (!$lastLog instanceof SyncLog) {
            return [
                'label' => $label,
                'status' => 'pending',
                'statusLabel' => 'Aucune activite',
                'lastAt' => null,
                'message' => 'Aucune trace disponible pour ce flux.',
            ];
        }

        $level = $lastLog->getLevel() ?? 'info';
        $status = match ($level) {
            'error' => 'error',
            'warning' => 'warning',
            'success' => 'success',
            default => 'info',
        };

        $statusLabel = match ($status) {
            'error' => 'Attention requise',
            'warning' => 'A surveiller',
            'success' => 'Operationnel',
            default => 'En activite',
        };

        return [
            'label' => $label,
            'status' => $status,
            'statusLabel' => $statusLabel,
            'lastAt' => $lastLog->getCreatedAt(),
            'message' => $lastLog->getTitle(),
        ];
    }
}
