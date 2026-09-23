<?php

namespace App\Tests\Service\Erp;

use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\SageClient;
use App\Service\Erp\SageDeliveryTrackingService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SageDeliveryTrackingServiceTest extends TestCase
{
    public function testTrackingMapsSalesDocumentsToDeliveryStages(): void
    {
        $queries = [];
        $documentsByType = [
            1 => [
                [
                    'piece' => 'BC001',
                    'reference' => 'WEB-001',
                    'date' => '2026-09-01T08:00:00+02:00',
                    'tiers' => 'CLI-1',
                    'representant' => 'WOODS Douglas',
                    'statut' => 'Saisi',
                    'champsLibres' => [
                        'nomtiers' => 'Alpha',
                        'Date de création' => '2026-09-01T07:30:00+02:00',
                    ],
                ],
                ['piece' => 'DV001'],
            ],
            3 => [[
                'piece' => 'BL001',
                'reference' => 'BC001',
                'date' => '2026-09-05T10:00:00+02:00',
                'tiers' => 'CLI-2',
                'representant' => 'CHAOUI Anthony',
                'statut' => 'Préparé',
                'champsLibres' => [
                    'Date de création' => '2026-09-03T09:00:00+02:00',
                    'Date de préparation' => '2026-09-05T11:30:00+02:00',
                ],
            ]],
            6 => [
                [
                    'piece' => 'FA001',
                    'date' => '2026-09-07T12:00:00+02:00',
                    'tiers' => 'CLI-3',
                    'representant' => '',
                    'champsLibres' => ['nomtiers' => 'Gamma'],
                ],
                ['piece' => 'FV001'],
            ],
            7 => [
                [
                    'piece' => 'FA002',
                    'date' => '2026-09-10T14:00:00+02:00',
                    'tiers' => 'CLI-4',
                    'representant' => 'WOODS Douglas',
                    'champsLibres' => ['nomtiers' => 'Delta'],
                ],
                ['piece' => 'FR001'],
            ],
        ];
        $httpClient = new MockHttpClient(static function (string $method, string $url) use (&$queries, $documentsByType): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($method === 'POST' && $path === '/auth/login') {
                return self::jsonResponse(['accessToken' => 'test-token']);
            }

            if ($method === 'GET' && $path === '/Document/header') {
                $queries[] = $query;

                return self::jsonResponse($documentsByType[(int) $query['type']]);
            }

            self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
        });
        $companyRepository = $this->createMock(HubspotCompanyRepository::class);
        $companyRepository
            ->expects(self::once())
            ->method('findNamesIndexedByErpIds')
            ->with(['CLI-1', 'CLI-2', 'CLI-3', 'CLI-4'])
            ->willReturn(['CLI-2' => 'Beta depuis HubSpot']);
        $service = new SageDeliveryTrackingService(
            new SageClient($httpClient, new ParameterBag([
                'base_uri_sage' => 'https://sage.test',
                'sage_username' => 'user',
                'sage_password' => 'password',
            ])),
            $companyRepository,
        );

        $tracking = $service->getTracking(
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-23'),
            'WOODS Douglas',
        );

        self::assertSame(['total' => 4, 'bc' => 1, 'bl' => 1, 'fc' => 2], $tracking['summary']);
        self::assertSame(['FA002', 'FA001', 'BL001', 'BC001'], array_column($tracking['rows'], 'piece'));
        self::assertSame(['fc', 'fc', 'bl', 'bc'], array_column($tracking['rows'], 'stage'));
        self::assertSame('Beta depuis HubSpot', $tracking['rows'][2]['clientName']);
        self::assertSame('Non assigné', $tracking['rows'][1]['owner']);
        self::assertSame('NA', $tracking['rows'][1]['ownerInitials']);
        self::assertSame('05/09/2026 11:30', $tracking['rows'][2]['updatedAt']->format('d/m/Y H:i'));
        self::assertInstanceOf(\DateTimeImmutable::class, $tracking['fetchedAt']);

        self::assertCount(4, $queries);
        self::assertSame([1, 3, 6, 7], array_map(static fn (array $query): int => (int) $query['type'], $queries));

        foreach ($queries as $query) {
            self::assertSame('0', $query['domaine']);
            self::assertSame('2026-09-01', $query['dateDebut']);
            self::assertSame('2026-09-23', $query['dateFin']);
            self::assertSame('WOODS Douglas', $query['representant']);
        }
    }

    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
