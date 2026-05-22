<?php

namespace App\Tests\Service\Erp;

use App\Service\Erp\SageOrderAnalyticsService;
use PHPUnit\Framework\TestCase;

class SageOrderAnalyticsServiceTest extends TestCase
{
    public function testNormalizeFiltersDefaultsToLastThreeMonthsOnFirstLoad(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SageOrderAnalyticsService::class, 'normalizeFilters');

        $filters = $method->invoke($service, []);
        $today = new \DateTimeImmutable('today');

        self::assertSame('last_3_months', $filters['period']);
        self::assertSame(
            $today->modify('-2 months')->modify('first day of this month')->format('Y-m-d'),
            $filters['date_debut']
        );
        self::assertSame($today->format('Y-m-d'), $filters['date_fin']);
    }

    public function testBuildDashboardAggregatesRevenueAndCommercials(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();

        $dashboard = $service->buildDashboard([
            [
                'piece' => 'BC001',
                'date' => '2026-01-10T00:00:00',
                'tiers' => 'CLI-1',
                'representant' => 'WOODS Douglas',
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
                'representant' => 'INCONNU Test',
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
                'representant' => 'CHAOUI Anthony',
                'statut' => 'Saisi',
                'estValide' => true,
                'montantTTC' => 2000.00,
                'resteAPayer' => 0,
                'champsLibres' => ['nomtiers' => 'Gamma'],
            ],
        ], 'month', 10);

        self::assertSame(2, $dashboard['summary']['order_count']);
        self::assertSame(3200.5, $dashboard['summary']['revenue_total']);
        self::assertSame(3200.5, $dashboard['summary']['validated_revenue']);
        self::assertSame(300.25, $dashboard['summary']['unpaid_total']);
        self::assertSame('Anthony Chaoui', $dashboard['summary']['best_commercial']['name']);
        self::assertSame(2000.0, $dashboard['summary']['best_commercial']['amount']);

        self::assertSame(['01/2026', '02/2026'], $dashboard['charts']['revenue']['labels']);
        self::assertSame([1200.5, 2000.0], $dashboard['charts']['revenue']['data']);
        self::assertSame(['Anthony Chaoui', 'Douglas Woods'], $dashboard['charts']['commercials']['labels']);
        self::assertSame([2000.0, 1200.5], $dashboard['charts']['commercials']['data']);
        self::assertSame(['Gamma', 'Alpha'], $dashboard['charts']['clients']['labels']);
        self::assertSame([2000.0, 1200.5], $dashboard['charts']['clients']['data']);
        self::assertSame(['Saisi'], $dashboard['charts']['statuses']['labels']);
        self::assertSame([2], $dashboard['charts']['statuses']['data']);
        self::assertCount(2, $dashboard['recentOrders']);
        self::assertSame('BC003', $dashboard['recentOrders'][0]['piece']);
        self::assertSame([
            ['name' => 'Gamma', 'tiers' => 'CLI-3', 'amount' => 2000.0, 'orders' => 1],
            ['name' => 'Alpha', 'tiers' => 'CLI-1', 'amount' => 1200.5, 'orders' => 1],
        ], $dashboard['topClients']);
        self::assertSame([
            ['value' => 'WOODS Douglas', 'label' => 'Douglas Woods'],
            ['value' => 'CHAOUI Anthony', 'label' => 'Anthony Chaoui'],
        ], $dashboard['representants']);
    }

    public function testResolveRepresentantValueByEmail(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();

        self::assertSame(
            'STRASSER Quentin',
            $service->resolveRepresentantValueByEmail('quentin.strasser@lnstrade.fr')
        );
        self::assertNull($service->resolveRepresentantValueByEmail('unknown@example.test'));
    }
}
