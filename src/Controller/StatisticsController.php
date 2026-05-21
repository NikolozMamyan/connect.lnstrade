<?php

namespace App\Controller;

use App\Repository\DealLineItemRepository;
use App\Repository\DealRepository;
use App\Repository\HubspotCompanyRepository;
use App\Repository\OrderFormRepository;
use App\Repository\SyncLogRepository;
use App\Repository\UserRepository;
use App\Service\Erp\SageOrderAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/supervision/statistics', name: 'supervision_statistics_')]
class StatisticsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        SageOrderAnalyticsService $sageOrderAnalyticsService,
    ): Response {
        $sageError = null;
        $sageStatistics = null;

        try {
            $sageStatistics = $sageOrderAnalyticsService->getStatistics($request->query->all());
        } catch (\Throwable $exception) {
            $sageError = $exception->getMessage();
        }

        return $this->render('supervision/statistics/index.html.twig', [
            'sageStatistics' => $sageStatistics,
            'sageError' => $sageError,
        ]);
    }

    #[Route('/orderform', name: 'orderform', methods: ['GET'])]
    public function orderform(
        UserRepository $userRepository,
        SyncLogRepository $syncLogRepository,
        OrderFormRepository $orderFormRepository,
        DealRepository $dealRepository,
        DealLineItemRepository $dealLineItemRepository,
        HubspotCompanyRepository $hubspotCompanyRepository,
    ): Response {
        $since24h = new \DateTimeImmutable('-24 hours');
        $companies = $hubspotCompanyRepository->findAll();
        $companiesWithoutErpId = 0;

        foreach ($companies as $company) {
            if (trim((string) $company->getIdErp()) === '') {
                ++$companiesWithoutErpId;
            }
        }

        return $this->render('supervision/statistics/orderform.html.twig', [
            'stats' => [
                'users' => $userRepository->countAll(),
                'logs24h' => $syncLogRepository->countSince($since24h),
                'orderForms' => $orderFormRepository->countAll(),
                'deals' => $dealRepository->countAll(),
                'dealAmount' => $dealRepository->sumTotalAmount(),
                'companiesWithoutErpId' => $companiesWithoutErpId,
            ],
            'roleCounts' => $userRepository->countByPrimaryRole(),
            'levelCounts' => array_replace(
                ['success' => 0, 'info' => 0, 'warning' => 0, 'error' => 0],
                $syncLogRepository->countByLevelSince($since24h)
            ),
            'fluxCounts' => $syncLogRepository->countByFluxSince($since24h),
            'orderFormStatusCounts' => array_replace(
                [
                    OrderFormRepository::STATUS_PENDING => 0,
                    OrderFormRepository::STATUS_VALIDATED => 0,
                    OrderFormRepository::STATUS_FAILED => 0,
                ],
                $orderFormRepository->countByStatus()
            ),
            'orderFormDailyCounts' => $orderFormRepository->countLastDays(7),
            'topCommercials' => $dealRepository->findTopCommercials(5),
            'topReferences' => $dealLineItemRepository->findTopReferences(10),
        ]);
    }
}
