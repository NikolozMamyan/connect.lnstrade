<?php

namespace App\Tests\Service\Erp;

use App\Repository\HubspotCompanyRepository;
use App\Service\Erp\SageClient;
use App\Service\Erp\SageDeliveryTrackingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

            if ($method === 'GET' && $path === '/Document/lettrage') {
                return self::jsonResponse([]);
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
            new NullLogger(),
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
        self::assertSame(['unknown', 'unknown', 'unknown', 'unknown'], array_column($tracking['rows'], 'paymentStatus'));

        self::assertCount(4, $queries);
        self::assertSame([1, 3, 6, 7], array_map(static fn (array $query): int => (int) $query['type'], $queries));

        foreach ($queries as $query) {
            self::assertSame('0', $query['domaine']);
            self::assertSame('2026-09-01', $query['dateDebut']);
            self::assertSame('2026-09-23', $query['dateFin']);
            self::assertSame('WOODS Douglas', $query['representant']);
        }
    }

    public function testPaymentsUseAllAccountingEntriesForTheSameInvoiceAndClient(): void
    {
        $pieces = ['FA002287', 'FA002378', 'FA-PARTIAL', 'FA-SPLIT', 'FA-PAID-SPLIT', 'FA-OTHER-CLIENT', 'FA-MISSING', 'FA-INVALID', 'FA-NO-DATE', 'FA-CARRIED-FORWARD'];
        $documents = array_map(static fn (string $piece): array => [
            'piece' => $piece,
            'tiers' => '9FOODE',
            'montantTTC' => 6040.83,
            'resteAPayer' => 6040.83,
        ], $pieces);
        $entries = [
            ['Piece' => 'FA002287', 'Client' => '9FOODE', 'Lettrage' => 'H', 'Lettre' => 1, "Date d'échéance" => '2026-08-27T00:00:00'],
            ['Piece' => 'FA002378', 'Client' => '9FOODE', 'Lettrage' => '', 'Lettre' => 0, "Date d'échéance" => '2026-10-03T00:00:00'],
            ['Piece' => 'FA-PARTIAL', 'Client' => '9FOODE', 'Lettrage' => 'a', 'Lettre' => 0],
            ['Piece' => 'FA-SPLIT', 'Client' => '9FOODE', 'Journal' => 'VE', 'Lettrage' => 'B', 'Lettre' => 1, "Date d'échéance" => '2026-08-01'],
            ['Piece' => 'FA-SPLIT', 'Client' => '9FOODE', 'Journal' => 'VE', 'Lettrage' => '', 'Lettre' => 0, "Date d'échéance" => '2026-09-01'],
            ['Piece' => 'FA-PAID-SPLIT', 'Client' => '9FOODE', 'Lettrage' => 'C', 'Lettre' => 1],
            ['Piece' => 'FA-PAID-SPLIT', 'Client' => '9FOODE', 'Lettrage' => 'C', 'Lettre' => '1'],
            ['Piece' => 'FA-OTHER-CLIENT', 'Client' => '9OTHER', 'Lettrage' => 'D', 'Lettre' => 1],
            ['Piece' => 'FA-INVALID', 'Client' => '9FOODE', 'Lettrage' => 'E'],
            ['Piece' => 'FA-NO-DATE', 'Client' => '9FOODE', 'Lettrage' => '', 'Lettre' => 0, "Date d'échéance" => '1753-01-01'],
            ['Piece' => 'FA-NO-DATE', 'Client' => '9FOODE', 'Lettrage' => '', 'Lettre' => 0, "Date d'échéance" => '1900-01-01'],
            ['Piece' => 'BC001', 'Client' => '9FOODE', 'Lettrage' => 'F', 'Lettre' => 1],
            ['Piece' => 'FA-CARRIED-FORWARD', 'Client' => '9FOODE', 'Journal' => 'VE', 'Lettrage' => '', 'Lettre' => 0],
            ['Piece' => 'FA-CARRIED-FORWARD', 'Client' => '9FOODE', 'Journal' => 'AN', 'Lettrage' => 'G', 'Lettre' => 1],
        ];
        $lettrageQueries = [];
        $service = $this->createPaymentTrackingService([
            1 => [['piece' => 'BC001', 'tiers' => '9FOODE']],
            3 => [['piece' => 'BL001', 'tiers' => '9FOODE']],
            7 => $documents,
        ], ['results' => $entries], $lettrageQueries);
        $tracking = $service->getTracking(new \DateTimeImmutable('2026-07-16'), new \DateTimeImmutable('2026-10-13'));
        $rows = array_column($tracking['rows'], null, 'piece');

        self::assertSame('paid', $rows['FA002287']['paymentStatus']);
        self::assertSame(['H'], $rows['FA002287']['paymentCodes']);
        self::assertSame('2026-08-27', $rows['FA002287']['paymentDueAt']->format('Y-m-d'));
        self::assertSame('unpaid', $rows['FA002378']['paymentStatus']);
        self::assertSame('2026-10-03', $rows['FA002378']['paymentDueAt']->format('Y-m-d'));
        self::assertSame('unpaid', $rows['FA-PARTIAL']['paymentStatus']);
        self::assertSame(['a'], $rows['FA-PARTIAL']['paymentCodes']);
        self::assertSame('unpaid', $rows['FA-SPLIT']['paymentStatus']);
        self::assertSame('2026-09-01', $rows['FA-SPLIT']['paymentDueAt']->format('Y-m-d'));
        self::assertSame('paid', $rows['FA-PAID-SPLIT']['paymentStatus']);
        self::assertSame(['C'], $rows['FA-PAID-SPLIT']['paymentCodes']);
        self::assertSame('unknown', $rows['FA-OTHER-CLIENT']['paymentStatus']);
        self::assertSame('unknown', $rows['FA-MISSING']['paymentStatus']);
        self::assertSame('unknown', $rows['FA-INVALID']['paymentStatus']);
        self::assertSame('unknown', $rows['FA-CARRIED-FORWARD']['paymentStatus']);
        self::assertNull($rows['FA-NO-DATE']['paymentDueAt']);
        self::assertSame('unknown', $rows['BC001']['paymentStatus']);
        self::assertSame('unknown', $rows['BL001']['paymentStatus']);
        self::assertSame([[]], $lettrageQueries);
    }

    public function testPaymentFailurePreservesDeliveryTracking(): void
    {
        $lettrageQueries = [];
        $service = $this->createPaymentTrackingService([
            7 => [['piece' => 'FA002287', 'tiers' => '9FOODE']],
        ], ['error' => 'Service indisponible'], $lettrageQueries, 503);
        $tracking = $service->getTracking(new \DateTimeImmutable('2026-07-16'), new \DateTimeImmutable('2026-10-13'));

        self::assertSame(['total' => 1, 'bc' => 0, 'bl' => 0, 'fc' => 1], $tracking['summary']);
        self::assertSame('FA002287', $tracking['rows'][0]['piece']);
        self::assertSame('unknown', $tracking['rows'][0]['paymentStatus']);
        self::assertSame([], $tracking['rows'][0]['paymentCodes']);
        self::assertNull($tracking['rows'][0]['paymentDueAt']);
    }

    public function testTrackingWithoutInvoicesDoesNotFetchAccountingEntries(): void
    {
        $lettrageQueries = [];
        $service = $this->createPaymentTrackingService([
            1 => [['piece' => 'BC001', 'tiers' => '9FOODE']],
            3 => [['piece' => 'BL001', 'tiers' => '9FOODE']],
        ], [], $lettrageQueries);
        $tracking = $service->getTracking(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-13'));

        self::assertCount(2, $tracking['rows']);
        self::assertSame(['unknown', 'unknown'], array_column($tracking['rows'], 'paymentStatus'));
        self::assertSame([], $lettrageQueries);
    }

    private function createPaymentTrackingService(array $documentsByType, array $lettrage, array &$lettrageQueries, int $lettrageStatus = 200): SageDeliveryTrackingService
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($documentsByType, $lettrage, &$lettrageQueries, $lettrageStatus): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if ($method === 'POST' && $path === '/auth/login') {
                return self::jsonResponse(['accessToken' => 'test-token']);
            }

            if ($method === 'GET' && $path === '/Document/header') {
                return self::jsonResponse($documentsByType[(int) $query['type']] ?? []);
            }

            if ($method === 'GET' && $path === '/Document/lettrage') {
                $lettrageQueries[] = $query;

                return self::jsonResponse($lettrage, $lettrageStatus);
            }

            self::fail(sprintf('Unexpected Sage request: %s %s', $method, $url));
        });
        $companyRepository = $this->createStub(HubspotCompanyRepository::class);
        $companyRepository->method('findNamesIndexedByErpIds')->willReturn([]);

        return new SageDeliveryTrackingService(new SageClient($httpClient, new ParameterBag([
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'user',
            'sage_password' => 'password',
        ])), $companyRepository, new NullLogger());
    }

    private static function jsonResponse(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
