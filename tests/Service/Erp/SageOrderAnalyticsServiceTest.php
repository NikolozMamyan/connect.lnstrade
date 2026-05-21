<?php

namespace App\Tests\Service\Erp;

use App\Service\Erp\SageOrderAnalyticsService;
use PHPUnit\Framework\TestCase;

class SageOrderAnalyticsServiceTest extends TestCase
{
    public function testBuildDashboardAggregatesRevenueAndCommercials(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();

        $dashboard = $service->buildDashboard([
            [
                'piece' => 'BC001',
                'date' => '2026-01-10T00:00:00',
                'tiers' => 'CLI-1',
                'representant' => 'Alice',
                'statut' => 'Saisi',
                'estValide' => true,
                'montantTTC' => 1200.50,
                'resteAPayer' => 300.25,
                'champsLibres' => ['nomtiers' => 'Alpha'],
            ],
            [
                'piece' => 'BC002',
                'date' => '2026-02-15T00:00:00',
                'tiers' => 'CLI-2',
                'representant' => 'Bob',
                'statut' => 'A preparer',
                'estValide' => false,
                'montantTTC' => 800.00,
                'resteAPayer' => 800.00,
                'champsLibres' => ['nomtiers' => 'Beta'],
            ],
            [
                'piece' => 'BC003',
                'date' => '2026-02-20T00:00:00',
                'tiers' => 'CLI-3',
                'representant' => 'Alice',
                'statut' => 'Saisi',
                'estValide' => true,
                'montantTTC' => 2000.00,
                'resteAPayer' => 0,
                'champsLibres' => ['nomtiers' => 'Gamma'],
            ],
        ], 'month', 10);

        self::assertSame(3, $dashboard['summary']['order_count']);
        self::assertSame(4000.5, $dashboard['summary']['revenue_total']);
        self::assertSame(3200.5, $dashboard['summary']['validated_revenue']);
        self::assertSame(1100.25, $dashboard['summary']['unpaid_total']);
        self::assertSame('Alice', $dashboard['summary']['best_commercial']['name']);
        self::assertSame(3200.5, $dashboard['summary']['best_commercial']['amount']);

        self::assertSame(['01/2026', '02/2026'], $dashboard['charts']['revenue']['labels']);
        self::assertSame([1200.5, 2800.0], $dashboard['charts']['revenue']['data']);
        self::assertSame(['Alice', 'Bob'], $dashboard['charts']['commercials']['labels']);
        self::assertSame([3200.5, 800.0], $dashboard['charts']['commercials']['data']);
        self::assertSame(['Saisi', 'A preparer'], $dashboard['charts']['statuses']['labels']);
        self::assertSame([2, 1], $dashboard['charts']['statuses']['data']);
        self::assertCount(3, $dashboard['recentOrders']);
        self::assertSame('BC003', $dashboard['recentOrders'][0]['piece']);
    }
}
