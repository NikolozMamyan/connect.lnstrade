<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Erp\SageDeliveryTrackingService;
use App\Service\Erp\SageOrderAnalyticsService;
use App\Service\Security\CommercialAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeliveryTrackingController extends AbstractController
{
    private const ALLOWED_PERIODS = [30, 60, 90];
    private const PER_PAGE = 25;

    #[Route('/suivi-livraison', name: 'delivery_tracking_index', methods: ['GET'])]
    public function index(
        Request $request,
        SageDeliveryTrackingService $trackingService,
        SageOrderAnalyticsService $analyticsService,
        CommercialAccessService $commercialAccessService,
    ): Response {
        $period = $request->query->getInt('period', 30);
        $period = in_array($period, self::ALLOWED_PERIODS, true) ? $period : 30;
        $search = mb_strtolower(trim((string) $request->query->get('q', '')));
        $stage = trim((string) $request->query->get('stage', ''));
        $stage = in_array($stage, ['bc', 'bl', 'fc'], true) ? $stage : '';
        $owner = trim((string) $request->query->get('owner', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $dateTo = new \DateTimeImmutable('today');
        $dateFrom = $dateTo->modify(sprintf('-%d days', $period - 1));
        /** @var User|null $user */
        $user = $this->getUser();
        $isCommercialScope = $commercialAccessService->isCommercialUser($user);
        $representative = null;
        $error = null;
        $tracking = [
            'rows' => [],
            'summary' => ['total' => 0, 'bc' => 0, 'bl' => 0, 'fc' => 0],
            'fetchedAt' => new \DateTimeImmutable(),
        ];

        try {
            if ($isCommercialScope) {
                $representative = $analyticsService->resolveRepresentantValueByEmail((string) $user?->getEmail());

                if ($representative === null) {
                    throw new \RuntimeException('Aucun représentant Sage actif n’est associé à votre compte.');
                }
            }

            $tracking = $trackingService->getTracking($dateFrom, $dateTo, $representative);
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $ownerOptions = array_values(array_unique(array_column($tracking['rows'], 'owner')));
        sort($ownerOptions, SORT_NATURAL | SORT_FLAG_CASE);
        $filteredRows = array_values(array_filter($tracking['rows'], static function (array $row) use ($search, $stage, $owner): bool {
            if ($stage !== '' && $row['stage'] !== $stage) {
                return false;
            }

            if ($owner !== '' && $row['owner'] !== $owner) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', [
                $row['piece'],
                $row['reference'],
                $row['clientId'],
                $row['clientName'],
                $row['owner'],
            ]));

            return str_contains($haystack, $search);
        }));
        $total = count($filteredRows);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        return $this->render('delivery_tracking/index.html.twig', [
            'rows' => array_slice($filteredRows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'summary' => $tracking['summary'],
            'fetchedAt' => $tracking['fetchedAt'],
            'error' => $error,
            'ownerOptions' => $ownerOptions,
            'filters' => [
                'period' => $period,
                'q' => (string) $request->query->get('q', ''),
                'stage' => $stage,
                'owner' => $isCommercialScope ? '' : $owner,
            ],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'page' => $page,
            'total' => $total,
            'totalPages' => $totalPages,
            'isCommercialScope' => $isCommercialScope,
            'commercialScopeName' => $commercialAccessService->resolveCommercial($user)?->getFullName(),
        ]);
    }
}
