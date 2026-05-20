<?php

namespace App\Service\Ui;

use App\Repository\NotificationRepository;

class TopbarContextProvider
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
    ) {
    }

    public function getUnreadNotificationCount(): int
    {
        return $this->notificationRepository->countUnread();
    }

    public function getLatestNotifications(int $limit = 8): array
    {
        return $this->notificationRepository->findLatest($limit);
    }
}
