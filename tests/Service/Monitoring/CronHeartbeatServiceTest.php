<?php

namespace App\Tests\Service\Monitoring;

use App\Service\Monitoring\CronHeartbeatService;
use PHPUnit\Framework\TestCase;

final class CronHeartbeatServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/lnstrade-heartbeat-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $heartbeat = $this->projectDir.'/var/cron/heartbeat.json';

        if (is_file($heartbeat)) {
            unlink($heartbeat);
        }

        foreach ([$this->projectDir.'/var/cron', $this->projectDir.'/var', $this->projectDir] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testRecordCanBeReadBack(): void
    {
        $service = new CronHeartbeatService($this->projectDir);
        $service->record('finished', 0, ['pending' => 2, 'inProgress' => 1, 'failed' => 0]);

        $heartbeat = $service->read();

        self::assertSame('finished', $heartbeat['status']);
        self::assertSame(0, $heartbeat['exitCode']);
        self::assertTrue($heartbeat['fresh']);
        self::assertSame(['pending' => 2, 'inProgress' => 1, 'failed' => 0], $heartbeat['queue']);
        self::assertInstanceOf(\DateTimeImmutable::class, $heartbeat['updatedAt']);
    }
}
