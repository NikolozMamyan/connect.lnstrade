<?php

namespace App\Service\Monitoring;

use Doctrine\DBAL\Connection;

final class MessengerHealthService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{pending: int, inProgress: int, failed: int}
     */
    public function getSnapshot(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('queue_name', 'COUNT(*) AS total', 'SUM(CASE WHEN delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS in_progress')
            ->from('messenger_messages')
            ->groupBy('queue_name')
            ->executeQuery()
            ->fetchAllAssociative();
        $snapshot = ['pending' => 0, 'inProgress' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $queueName = (string) ($row['queue_name'] ?? '');
            $total = (int) ($row['total'] ?? 0);

            if ($queueName === 'failed') {
                $snapshot['failed'] += $total;
                continue;
            }

            $inProgress = (int) ($row['in_progress'] ?? 0);
            $snapshot['pending'] += max(0, $total - $inProgress);
            $snapshot['inProgress'] += $inProgress;
        }

        return $snapshot;
    }
}
