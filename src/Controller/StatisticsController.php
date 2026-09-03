<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DealLineItemRepository;
use App\Repository\DealRepository;
use App\Repository\HubspotCompanyRepository;
use App\Repository\OrderFormRepository;
use App\Repository\SyncLogRepository;
use App\Repository\UserRepository;
use App\Service\Erp\SageOrderAnalyticsService;
use App\Service\Security\CommercialAccessService;
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
        CommercialAccessService $commercialAccessService,
    ): Response {
        $sageError = null;
        $sageStatistics = null;
        /** @var User|null $user */
        $user = $this->getUser();
        $commercial = $commercialAccessService->resolveCommercial($user);
        $isCommercialScope = $commercialAccessService->isCommercialUser($user);
        $filters = $request->query->all();

        try {
            if ($isCommercialScope) {
                $representant = $sageOrderAnalyticsService->resolveRepresentantValueByEmail((string) $user?->getEmail());

                if ($representant === null) {
                    throw new \RuntimeException('Aucun commercial Sage actif n est associe a votre compte.');
                }

                $filters['representant'] = $representant;
            }

            $sageStatistics = $sageOrderAnalyticsService->getStatistics($filters);
        } catch (\Throwable $exception) {
            $sageError = $exception->getMessage();
        }

        return $this->render('supervision/statistics/index.html.twig', [
            'sageStatistics' => $sageStatistics,
            'sageError' => $sageError,
            'isCommercialScope' => $isCommercialScope,
            'commercialScopeName' => $commercial?->getFullName(),
        ]);
    }

    #[Route('/document/{type}/{piece}', name: 'document_detail', methods: ['GET'], requirements: ['type' => '1|6|7', 'piece' => '[^/]+'])]
    public function documentDetail(
        int $type,
        string $piece,
        SageOrderAnalyticsService $sageOrderAnalyticsService,
        CommercialAccessService $commercialAccessService,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        $representant = null;

        if ($commercialAccessService->isCommercialUser($user)) {
            $representant = $sageOrderAnalyticsService->resolveRepresentantValueByEmail((string) $user?->getEmail());

            if ($representant === null) {
                return $this->render('supervision/statistics/_document_detail.html.twig', [
                    'selectedDocument' => null,
                    'selectedLines' => [],
                    'detailError' => 'Aucun commercial Sage actif n’est associé à votre compte.',
                ], new Response(status: Response::HTTP_FORBIDDEN));
            }
        }

        try {
            $detail = $sageOrderAnalyticsService->getDocumentDetail($type, $piece, $representant);

            if ($detail['document'] === null) {
                return $this->render('supervision/statistics/_document_detail.html.twig', [
                    'selectedDocument' => null,
                    'selectedLines' => [],
                    'detailError' => 'Ce document est introuvable dans votre périmètre Sage.',
                ], new Response(status: Response::HTTP_NOT_FOUND));
            }

            return $this->render('supervision/statistics/_document_detail.html.twig', [
                'selectedDocument' => $detail['document'],
                'selectedLines' => $detail['lines'],
                'detailError' => null,
            ]);
        } catch (\Throwable) {
            return $this->render('supervision/statistics/_document_detail.html.twig', [
                'selectedDocument' => null,
                'selectedLines' => [],
                'detailError' => 'Le détail Sage ne peut pas être chargé pour le moment.',
            ], new Response(status: Response::HTTP_BAD_GATEWAY));
        }
    }

    #[Route('/orderform', name: 'orderform', methods: ['GET'])]
    public function orderform(
        UserRepository $userRepository,
        SyncLogRepository $syncLogRepository,
        OrderFormRepository $orderFormRepository,
        DealRepository $dealRepository,
        DealLineItemRepository $dealLineItemRepository,
        HubspotCompanyRepository $hubspotCompanyRepository,
        CommercialAccessService $commercialAccessService,
    ): Response {
        $since24h = new \DateTimeImmutable('-24 hours');
        /** @var User|null $user */
        $user = $this->getUser();
        $commercial = $commercialAccessService->resolveCommercial($user);
        $isCommercialScope = $commercialAccessService->isCommercialUser($user);

        $companiesWithoutErpId = 0;

        if (!$isCommercialScope) {
            $companies = $hubspotCompanyRepository->findAll();

            foreach ($companies as $company) {
                if (trim((string) $company->getIdErp()) === '') {
                    ++$companiesWithoutErpId;
                }
            }
        }

        return $this->render('supervision/statistics/orderform.html.twig', [
            'stats' => [
                'users' => $isCommercialScope ? 0 : $userRepository->countAll(),
                'logs24h' => $isCommercialScope ? 0 : $syncLogRepository->countSince($since24h),
                'orderForms' => $isCommercialScope ? ($commercial ? $orderFormRepository->countByCommercial($commercial) : 0) : $orderFormRepository->countAll(),
                'deals' => $isCommercialScope ? ($commercial ? $dealRepository->countByCommercial($commercial) : 0) : $dealRepository->countAll(),
                'dealAmount' => $isCommercialScope ? ($commercial ? $dealRepository->sumTotalAmountByCommercial($commercial) : 0.0) : $dealRepository->sumTotalAmount(),
                'companiesWithoutErpId' => $companiesWithoutErpId,
            ],
            'roleCounts' => $isCommercialScope ? [] : $userRepository->countByPrimaryRole(),
            'levelCounts' => $isCommercialScope ? [] : array_replace(
                ['success' => 0, 'info' => 0, 'warning' => 0, 'error' => 0],
                $syncLogRepository->countByLevelSince($since24h)
            ),
            'fluxCounts' => $isCommercialScope ? [] : $syncLogRepository->countByFluxSince($since24h),
            'orderFormStatusCounts' => array_replace(
                [
                    OrderFormRepository::STATUS_PENDING => 0,
                    OrderFormRepository::STATUS_VALIDATED => 0,
                    OrderFormRepository::STATUS_FAILED => 0,
                ],
                $isCommercialScope
                    ? ($commercial ? $orderFormRepository->countByStatusForCommercial($commercial) : [])
                    : $orderFormRepository->countByStatus()
            ),
            'orderFormDailyCounts' => $isCommercialScope
                ? ($commercial ? $orderFormRepository->countLastDaysForCommercial($commercial, 7) : [])
                : $orderFormRepository->countLastDays(7),
            'topCommercials' => $isCommercialScope ? [] : $dealRepository->findTopCommercials(5),
            'topReferences' => $isCommercialScope
                ? ($commercial ? $dealLineItemRepository->findTopReferencesForCommercial($commercial, 10) : [])
                : $dealLineItemRepository->findTopReferences(10),
            'isCommercialScope' => $isCommercialScope,
            'commercialScopeName' => $commercial?->getFullName(),
        ]);
    }
}
