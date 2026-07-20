<?php

namespace App\Service\Monitoring;

final class CronHeartbeatService
{
    private readonly string $heartbeatPath;

    public function __construct(string $projectDir)
    {
        $this->heartbeatPath = $projectDir.'/var/cron/heartbeat.json';
    }

    /**
     * @param array{pending: int, inProgress: int, failed: int} $queue
     */
    public function record(string $status, ?int $exitCode, array $queue): void
    {
        $directory = dirname($this->heartbeatPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Impossible de creer le repertoire %s.', $directory));
        }

        $payload = json_encode([
            'status' => $status,
            'exitCode' => $exitCode,
            'updatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'queue' => $queue,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporaryPath = $this->heartbeatPath.'.tmp';

        if (file_put_contents($temporaryPath, $payload, LOCK_EX) === false || !rename($temporaryPath, $this->heartbeatPath)) {
            @unlink($temporaryPath);

            throw new \RuntimeException('Impossible d enregistrer le heartbeat cron.');
        }
    }

    /**
     * @return array{status: string, exitCode: ?int, updatedAt: ?\DateTimeImmutable, fresh: bool, queue: array{pending: int, inProgress: int, failed: int}}
     */
    public function read(): array
    {
        $default = [
            'status' => 'unknown',
            'exitCode' => null,
            'updatedAt' => null,
            'fresh' => false,
            'queue' => ['pending' => 0, 'inProgress' => 0, 'failed' => 0],
        ];

        if (!is_file($this->heartbeatPath)) {
            return $default;
        }

        try {
            $data = json_decode((string) file_get_contents($this->heartbeatPath), true, 512, JSON_THROW_ON_ERROR);
            $updatedAt = isset($data['updatedAt']) ? new \DateTimeImmutable((string) $data['updatedAt']) : null;

            return [
                'status' => (string) ($data['status'] ?? 'unknown'),
                'exitCode' => isset($data['exitCode']) ? (int) $data['exitCode'] : null,
                'updatedAt' => $updatedAt,
                'fresh' => $updatedAt !== null && $updatedAt >= new \DateTimeImmutable('-5 minutes'),
                'queue' => [
                    'pending' => (int) ($data['queue']['pending'] ?? 0),
                    'inProgress' => (int) ($data['queue']['inProgress'] ?? 0),
                    'failed' => (int) ($data['queue']['failed'] ?? 0),
                ],
            ];
        } catch (\Throwable) {
            return $default;
        }
    }
}
