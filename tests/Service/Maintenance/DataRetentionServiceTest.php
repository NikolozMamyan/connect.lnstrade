<?php

namespace App\Tests\Service\Maintenance;

use App\Repository\NotificationRepository;
use App\Repository\SyncLogRepository;
use App\Service\Maintenance\DataRetentionService;
use PHPUnit\Framework\TestCase;

final class DataRetentionServiceTest extends TestCase
{
    public function testDryRunOnlyCountsRows(): void
    {
        $logs = $this->createMock(SyncLogRepository::class);
        $logs->expects(self::once())->method('countOlderThan')->willReturn(12);
        $logs->expects(self::never())->method('deleteOlderThan');
        $notifications = $this->createMock(NotificationRepository::class);
        $notifications->expects(self::once())->method('countReadOlderThan')->willReturn(3);
        $notifications->expects(self::never())->method('deleteReadOlderThan');

        $result = (new DataRetentionService($logs, $notifications))->cleanup(90, 90, true);

        self::assertSame(['syncLogs' => 12, 'readNotifications' => 3, 'dryRun' => true], $result);
    }

    public function testRetentionBelowThirtyDaysIsRejected(): void
    {
        $service = new DataRetentionService(
            $this->createStub(SyncLogRepository::class),
            $this->createStub(NotificationRepository::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->cleanup(29, 90, false);
    }
}
