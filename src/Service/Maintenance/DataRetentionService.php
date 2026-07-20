<?php

namespace App\Service\Maintenance;

use App\Repository\NotificationRepository;
use App\Repository\SyncLogRepository;

final class DataRetentionService
{
    public function __construct(
        private readonly SyncLogRepository $syncLogRepository,
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    /**
     * @return array{syncLogs: int, readNotifications: int, dryRun: bool}
     */
    public function cleanup(int $logDays, int $notificationDays, bool $dryRun): array
    {
        if ($logDays < 30 || $notificationDays < 30) {
            throw new \InvalidArgumentException('La retention minimale est de 30 jours.');
        }

        $logBefore = new \DateTimeImmutable(sprintf('-%d days', $logDays));
        $notificationBefore = new \DateTimeImmutable(sprintf('-%d days', $notificationDays));

        if ($dryRun) {
            return [
                'syncLogs' => $this->syncLogRepository->countOlderThan($logBefore),
                'readNotifications' => $this->notificationRepository->countReadOlderThan($notificationBefore),
                'dryRun' => true,
            ];
        }

        return [
            'syncLogs' => $this->syncLogRepository->deleteOlderThan($logBefore),
            'readNotifications' => $this->notificationRepository->deleteReadOlderThan($notificationBefore),
            'dryRun' => false,
        ];
    }
}
