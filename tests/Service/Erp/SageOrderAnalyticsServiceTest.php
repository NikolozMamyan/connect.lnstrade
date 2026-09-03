<?php

namespace App\Tests\Service\Erp;

use App\Service\Erp\SageClient;
use App\Service\Erp\SageOrderAnalyticsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SageOrderAnalyticsServiceTest extends TestCase
{
    public function testNormalizeFiltersDefaultsToLastThreeMonthsOnFirstLoad(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SageOrderAnalyticsService::class, 'normalizeFilters');

        $filters = $method->invoke($service, []);
        $today = new \DateTimeImmutable('today');

        self::assertSame('last_3_months', $filters['period']);
        self::assertSame('all', $filters['document_scope']);
        self::assertSame('all', $filters['invoice_state']);
        self::assertSame(
            $today->modify('-2 months')->modify('first day of this month')->format('Y-m-d'),
            $filters['date_debut']
        );
        self::assertSame($today->format('Y-m-d'), $filters['date_fin']);
    }

    public function testBuildDashboardAggregatesOrderAmountsAndCommercials(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();

        $dashboard = $service->buildDashboard([
            [
                'piece' => 'BC001',
                'date' => '2026-01-10T00:00:00',
                'tiers' => 'CLI-1',
                'representant' => 'WOODS Douglas',
                'statut' => 'Saisi',
                'montantHT' => 1000.00,
                'montantTTC' => 1200.50,
                'champsLibres' => ['nomtiers' => 'Alpha'],
            ],
            [
                'piece' => 'BC002',
                'date' => '2026-02-15T00:00:00',
                'tiers' => 'CLI-2',
                'representant' => 'INCONNU Test',
                'statut' => 'A preparer',
                'montantHT' => 700.00,
                'montantTTC' => 800.00,
                'champsLibres' => ['nomtiers' => 'Beta'],
            ],
            [
                'piece' => 'BC003',
                'date' => '2026-02-20T00:00:00',
                'tiers' => 'CLI-3',
                'representant' => 'CHAOUI Anthony',
                'statut' => 'À préparer',
                'montantHT' => 1800.00,
                'montantTTC' => 2000.00,
                'champsLibres' => ['nomtiers' => 'Gamma'],
            ],
            [
                '_document_type' => 6,
                'piece' => 'FA001',
                'date' => '2026-02-18T00:00:00',
                'tiers' => 'CLI-3',
                'representant' => 'CHAOUI Anthony',
                'statut' => 'A comptabiliser',
                'montantHT' => 500.00,
                'montantTTC' => 600.00,
                'champsLibres' => ['nomtiers' => 'Gamma'],
            ],
            [
                '_document_type' => 7,
                'piece' => 'FA002',
                'date' => '2026-01-25T00:00:00',
                'tiers' => 'CLI-1',
                'representant' => 'WOODS Douglas',
                'statut' => 'Comptabilisé',
                'montantHT' => 2000.00,
                'montantTTC' => 2400.00,
                'champsLibres' => ['nomtiers' => 'Alpha'],
            ],
            [
                '_document_type' => 7,
                'piece' => 'FR001',
                'date' => '2026-01-26T00:00:00',
                'tiers' => 'CLI-1',
                'representant' => 'WOODS Douglas',
                'statut' => 'Comptabilisé',
                'montantHT' => -200.00,
                'montantTTC' => -240.00,
                'champsLibres' => ['nomtiers' => 'Alpha'],
            ],
        ], 'month', 10, [1, 6, 7]);

        self::assertSame(5, $dashboard['summary']['document_count']);
        self::assertSame(2, $dashboard['summary']['order_count']);
        self::assertSame(3, $dashboard['summary']['invoice_count']);
        self::assertSame(1, $dashboard['summary']['pending_invoice_count']);
        self::assertSame(2, $dashboard['summary']['posted_invoice_count']);
        self::assertSame(1, $dashboard['summary']['credit_count']);
        self::assertSame(2800.0, $dashboard['summary']['ordered_amount_ht']);
        self::assertSame(3200.5, $dashboard['summary']['ordered_amount_ttc']);
        self::assertSame(500.0, $dashboard['summary']['pending_invoice_amount_ht']);
        self::assertSame(1800.0, $dashboard['summary']['posted_revenue_ht']);
        self::assertSame(2300.0, $dashboard['summary']['invoice_amount_ht']);
        self::assertSame(766.67, $dashboard['summary']['average_invoice_ht']);
        self::assertSame(1400.0, $dashboard['summary']['average_order_ht']);
        self::assertSame(1, $dashboard['summary']['to_prepare_count']);
        self::assertSame(1800.0, $dashboard['summary']['to_prepare_amount_ht']);
        self::assertSame(50.0, $dashboard['summary']['to_prepare_share']);

        self::assertSame(['01/2026', '02/2026'], $dashboard['charts']['amounts']['labels']);
        self::assertSame([1000.0, 1800.0], $dashboard['charts']['amounts']['datasets'][0]['data']);
        self::assertSame([0.0, 500.0], $dashboard['charts']['amounts']['datasets'][1]['data']);
        self::assertSame([1800.0, 0.0], $dashboard['charts']['amounts']['datasets'][2]['data']);
        self::assertSame([1, 1], $dashboard['charts']['volumes']['datasets'][0]['data']);
        self::assertSame([0, 1], $dashboard['charts']['volumes']['datasets'][1]['data']);
        self::assertSame([2, 0], $dashboard['charts']['volumes']['datasets'][2]['data']);
        self::assertSame(['Douglas Woods', 'Anthony Chaoui'], $dashboard['charts']['commercials']['labels']);
        self::assertSame([0.0, 500.0], $dashboard['charts']['commercials']['datasets'][1]['data']);
        self::assertSame([1800.0, 0.0], $dashboard['charts']['commercials']['datasets'][2]['data']);
        self::assertSame(['Alpha', 'Gamma'], $dashboard['charts']['clients']['labels']);
        self::assertSame(['Comptabilisé', 'A comptabiliser'], $dashboard['charts']['statuses']['invoices']['labels']);
        self::assertSame([2, 1], $dashboard['charts']['statuses']['invoices']['data']);
        self::assertCount(5, $dashboard['recentDocuments']);
        self::assertSame('BC003', $dashboard['recentDocuments'][0]['piece']);
        self::assertSame(1800.0, $dashboard['topClients'][0]['invoice_amount_ht']);
        self::assertSame(1000.0, $dashboard['topClients'][0]['order_amount_ht']);
        self::assertCount(9, $dashboard['representants']);
        self::assertContains(['value' => 'WOODS Douglas', 'label' => 'Douglas Woods'], $dashboard['representants']);
        self::assertContains(['value' => 'CHAOUI Anthony', 'label' => 'Anthony Chaoui'], $dashboard['representants']);
    }

    public function testHeaderQueryAlwaysScopesTheRequestedSalesDocumentType(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();
        $normalize = new \ReflectionMethod(SageOrderAnalyticsService::class, 'normalizeFilters');
        $buildQuery = new \ReflectionMethod(SageOrderAnalyticsService::class, 'buildHeaderQuery');

        $query = $buildQuery->invoke($service, $normalize->invoke($service, [
            'period' => 'custom',
            'date_debut' => '2026-01-01',
            'date_fin' => '2026-01-31',
        ]), 7);

        self::assertSame(0, $query['domaine']);
        self::assertSame(7, $query['type']);
        self::assertSame('2026-01-01T00:00:00', $query['dateDebut']);
        self::assertSame('2026-01-31T23:59:59', $query['dateFin']);
    }

    public function testDocumentScopeSelectsOnlyTheRequestedSageTypes(): void
    {
        $service = (new \ReflectionClass(SageOrderAnalyticsService::class))->newInstanceWithoutConstructor();
        $normalize = new \ReflectionMethod(SageOrderAnalyticsService::class, 'normalizeFilters');
        $resolveTypes = new \ReflectionMethod(SageOrderAnalyticsService::class, 'resolveDocumentTypes');

        $globalPosted = $normalize->invoke($service, [
            'document_scope' => 'all',
            'invoice_state' => 'posted',
        ]);
        $pendingInvoices = $normalize->invoke($service, [
            'document_scope' => 'invoices',
            'invoice_state' => 'pending',
        ]);
        $orders = $normalize->invoke($service, ['document_scope' => 'orders']);

        self::assertSame([1, 7], $resolveTypes->invoke($service, $globalPosted));
        self::assertSame([6], $resolveTypes->invoke($service, $pendingInvoices));
        self::assertSame([1], $resolveTypes->invoke($service, $orders));
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

    public function testInvoiceDetailUsesTheVerifiedSalesDocumentScopeAndFields(): void
    {
        $queries = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$queries): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($method === 'POST' && $path === '/auth/login') {
                return self::jsonResponse(['accessToken' => 'test-token']);
            }

            if ($method === 'GET' && $path === '/Document/header') {
                $queries['header'] = $query;

                return self::jsonResponse([[
                    'piece' => 'FR001',
                    'reference' => 'REF-001',
                    'date' => '2026-08-15T00:00:00',
                    'dateLivraison' => '2026-08-20T00:00:00',
                    'tiers' => 'CLI-1',
                    'representant' => 'WOODS Douglas',
                    'statut' => 'Comptabilisé',
                    'montantHT' => -100.0,
                    'montantTTC' => -120.0,
                    'champsLibres' => ['nomtiers' => 'Alpha'],
                ]]);
            }

            if ($method === 'GET' && $path === '/Document/line') {
                $queries['line'] = $query;

                return self::jsonResponse([[
                    'referenceArticle' => 'ART-1',
                    'designationArticle' => 'Article',
                    'qteArticle' => 2,
                    'qtePreparee' => 1,
                    'uniteArticle' => 'U',
                    'prixHTArticle' => 50.0,
                    'montantArticle' => 100.0,
                    'montantTTC' => 120.0,
                ]]);
            }

            self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
        });
        $service = new SageOrderAnalyticsService(new SageClient($httpClient, new ParameterBag([
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ])));

        $detail = $service->getDocumentDetail(7, 'FR001', 'WOODS Douglas');

        self::assertSame(['domaine' => '0', 'type' => '7', 'piece' => 'FR001', 'representant' => 'WOODS Douglas'], $queries['header']);
        self::assertSame(['piece' => 'FR001', 'domaine' => '0', 'type' => '7'], $queries['line']);
        self::assertSame('FR001', $detail['document']['piece']);
        self::assertSame('Avoir comptabilisé', $detail['document']['type_label']);
        self::assertTrue($detail['document']['is_credit']);
        self::assertSame(-100.0, $detail['document']['montantHT']);
        self::assertSame(1.0, $detail['lines'][0]['qtePreparee']);
        self::assertSame(100.0, $detail['lines'][0]['montantHT']);
        self::assertSame(120.0, $detail['lines'][0]['montantTTC']);
    }

    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
