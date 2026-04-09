<?php

namespace App\Controller;

use App\Entity\SyncLog;
use App\Repository\SyncLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/flux/logs', name: 'sync_logs_')]
final class SyncLogController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(SyncLogRepository $syncLogRepository): Response
    {
        $since24h = new \DateTimeImmutable('-24 hours');
        $recentLogs = $syncLogRepository->findLatest(180);
        $levelCounts = array_replace(
            ['success' => 0, 'info' => 0, 'warning' => 0, 'error' => 0],
            $syncLogRepository->countByLevelSince($since24h)
        );
        $fluxCounts = $syncLogRepository->countByFluxSince($since24h);
        $alertLogs = array_values(array_filter(
            $recentLogs,
            static fn (SyncLog $log): bool => \in_array($log->getLevel(), ['warning', 'error'], true)
        ));

        return $this->render('logs/index.html.twig', [
            'logs' => $recentLogs,
            'alertLogs' => array_slice($alertLogs, 0, 12),
            'levelCounts' => $levelCounts,
            'fluxCounts' => $fluxCounts,
            'totalLogs24h' => $syncLogRepository->countSince($since24h),
            'latestEvents' => array_slice($recentLogs, 0, 14),
        ]);
    }
}
