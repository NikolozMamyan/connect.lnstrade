<?php

namespace App\Tests\Service\HubSpot;

use App\Entity\ErpDeliveryNote;
use App\Repository\CommercialRepository;
use App\Repository\ErpDeliveryNoteRepository;
use App\Service\Erp\SageClient;
use App\Service\HubSpot\HubSpotClient;
use App\Service\HubSpot\HubspotDeliveryOrderSyncService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HubspotDeliveryOrderSyncServiceTest extends TestCase
{
    public function testDeliveryNoteIsPersistedExportedAndThenSkippedWithTheSamePayload(): void
    {
        $storedDeliveryNote = null;
        $sageRequests = 0;
        $hubspotRequests = 0;
        $orderPayload = null;
        $lineItemPayload = null;
        $sageClient = new SageClient(
            new MockHttpClient(static function (string $method, string $url) use (&$sageRequests): MockResponse {
                ++$sageRequests;
                $path = (string) parse_url($url, PHP_URL_PATH);

                return match ([$method, $path]) {
                    ['POST', '/auth/login'] => self::jsonResponse(['accessToken' => 'sage-token']),
                    ['GET', '/Document/lettrage'] => self::jsonResponse([]),
                    ['GET', '/Document/header'] => self::jsonResponse([[
                        'piece' => 'BL001',
                        'tiers' => 'CLI001',
                        'date' => '2026-09-20T00:00:00+02:00',
                        'dateLivraison' => '2026-09-22T00:00:00+02:00',
                        'montantHT' => 100.0,
                        'montantTTC' => 120.0,
                        'resteAPayer' => 0,
                        'statut' => 'A facturer',
                        'conditionLivraison' => 'DDP - Rendu droits acquittes',
                        'reference' => 'REF-CLIENT',
                        'champsLibres' => [],
                    ]]),
                    ['GET', '/Document/line'] => self::jsonResponse([
                        [
                            'referenceArticle' => 'SKU-1',
                            'designationArticle' => 'Article test',
                            'qteArticle' => 2,
                            'prixHTArticle' => 60.002,
                            'montantArticle' => 120,
                            'tauxTva' => 20,
                        ],
                        [
                            'referenceArticle' => 'ZREMISE',
                            'designationArticle' => 'Remise commerciale',
                            'qteArticle' => 1,
                            'prixHTArticle' => -20,
                            'montantArticle' => -20,
                            'tauxTva' => 0,
                        ],
                    ]),
                    ['GET', '/Clients'] => self::jsonResponse([[
                        'reference' => 'CLI001',
                        'intitule' => 'Client test',
                        'adresse' => '1 rue du Test',
                        'codePostal' => '75001',
                        'ville' => 'Paris',
                        'pays' => 'France',
                    ]]),
                    ['GET', '/Contacts'] => self::jsonResponse([[
                        'client' => 'CLI001',
                        'prenom' => 'Jean',
                        'nom' => 'Test',
                        'email' => 'jean@example.test',
                        'hubSpotID' => '321',
                    ]]),
                    ['GET', '/Livraisons'] => self::jsonResponse([[
                        'client' => 'CLI001',
                        'intitule' => 'Entrepot test',
                        'adresse' => '2 rue Livraison',
                        'codePostal' => '69001',
                        'ville' => 'Lyon',
                        'pays' => 'France',
                        'livraisonPrincipal' => 1,
                        'contact' => 'Jean Test',
                    ]]),
                    default => throw new \RuntimeException(sprintf('Unexpected Sage request: %s %s', $method, $path)),
                };
            }),
            $this->parameters(),
        );
        $hubSpotClient = new HubSpotClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$hubspotRequests, &$orderPayload, &$lineItemPayload): MockResponse {
                ++$hubspotRequests;
                $path = (string) parse_url($url, PHP_URL_PATH);
                $payload = isset($options['body']) ? json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR) : null;

                if ($method === 'POST' && $path === '/crm/objects/2026-09/orders/search') {
                    return self::jsonResponse(['results' => []]);
                }

                if ($method === 'GET' && $path === '/crm/objects/2026-09/contacts/321') {
                    return self::jsonResponse(['id' => '321', 'properties' => ['email' => 'jean@example.test']]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/products/search') {
                    return self::jsonResponse(['results' => [['id' => 'product-456', 'properties' => ['hs_sku' => 'SKU-1']]]]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/line_items') {
                    $lineItemPayload = $payload;

                    return self::jsonResponse(['id' => 'line-789']);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/orders') {
                    $orderPayload = $payload;

                    return self::jsonResponse(['id' => 'order-999']);
                }

                throw new \RuntimeException(sprintf('Unexpected HubSpot request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $repository = $this->createStub(ErpDeliveryNoteRepository::class);
        $repository
            ->method('findOneBySageKey')
            ->willReturnCallback(static function () use (&$storedDeliveryNote): ?ErpDeliveryNote {
                return $storedDeliveryNote;
            });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$storedDeliveryNote): void {
                if ($entity instanceof ErpDeliveryNote) {
                    $storedDeliveryNote = $entity;
                }
            });
        $commercialRepository = $this->createStub(CommercialRepository::class);
        $commercialRepository->method('findActiveOrdered')->willReturn([]);
        $service = new HubspotDeliveryOrderSyncService(
            $sageClient,
            $hubSpotClient,
            $repository,
            $commercialRepository,
            $entityManager,
            new NullLogger(),
            'pipeline-orders',
            'stage-preparation',
            'stage-invoiced',
            'stage-paid',
            ['20' => 'tax-20'],
            [],
            [],
            [' cli001 ' => ' company-123 '],
        );

        $firstResult = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(1, $firstResult['discovered']);
        self::assertSame(1, $firstResult['sent']);
        self::assertSame(0, $firstResult['failed']);
        self::assertInstanceOf(ErpDeliveryNote::class, $storedDeliveryNote);
        self::assertSame(ErpDeliveryNote::STATUS_SENT, $storedDeliveryNote->getStatus());
        self::assertSame('order-999', $storedDeliveryNote->getHubspotOrderId());
        self::assertSame(['line-789'], $storedDeliveryNote->getHubspotLineItemIds());
        self::assertSame('product-456', $lineItemPayload['properties']['hs_product_id']);
        self::assertSame('tax-20', $lineItemPayload['properties']['hs_tax_rate_group_id']);
        self::assertSame(60.002, $lineItemPayload['properties']['price']);
        self::assertArrayNotHasKey('discount', $lineItemPayload['properties']);
        self::assertSame('sage:0:3:BL001', $orderPayload['properties']['hs_external_order_id']);
        self::assertSame(120.0, $orderPayload['properties']['hs_subtotal_price']);
        self::assertSame(20.0, $orderPayload['properties']['hs_order_discount']);
        self::assertSame(120.0, $orderPayload['properties']['hs_total_price']);
        self::assertSame('stage-preparation', $orderPayload['properties']['hs_pipeline_stage']);
        self::assertArrayNotHasKey('order_paid', $orderPayload['properties']);
        self::assertSame('DDP', $orderPayload['properties']['incoterm']);
        self::assertSame('Entrepot test', $orderPayload['properties']['hs_shipping_address_name']);
        self::assertSame([509, 2694, 513], array_map(
            static fn (array $association): int => (int) $association['types'][0]['associationTypeId'],
            $orderPayload['associations'],
        ));
        self::assertSame('company-123', $orderPayload['associations'][0]['to']['id']);

        $hubspotRequestsAfterFirstRun = $hubspotRequests;
        $secondResult = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(1, $secondResult['skipped']);
        self::assertSame(0, $secondResult['sent']);
        self::assertSame($hubspotRequestsAfterFirstRun, $hubspotRequests, 'The unchanged BL must not call HubSpot again.');
        self::assertSame(13, $sageRequests);
    }

    public function testInvoiceUpdatesTheOrderAndLineItemsCreatedFromTheDeliveryNote(): void
    {
        $storedDeliveryNote = null;
        $createdOrders = 0;
        $hubspotRequests = 0;
        $lettrageCalls = 0;
        $updatedOrderPayload = null;
        $updatedLineItemPayload = null;
        $sageClient = new SageClient(
            new MockHttpClient(static function (string $method, string $url) use (&$lettrageCalls): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Document/lettrage') {
                    ++$lettrageCalls;

                    return self::jsonResponse([[
                        'Client' => 'CLI001',
                        'Piece' => 'FA001',
                        'Lettre' => $lettrageCalls === 1 ? 0 : 1,
                        'Lettrage' => $lettrageCalls === 1 ? '' : 'AB',
                        'Journal' => 'VTE',
                        "Date d'échéance" => '2026-10-31T00:00:00+01:00',
                    ]]);
                }

                if ($method === 'GET' && $path === '/Document/header') {
                    return match ((int) ($query['type'] ?? -1)) {
                        3 => self::jsonResponse([[
                            'piece' => 'BL001',
                            'tiers' => 'CLI001',
                            'date' => '2026-09-20T00:00:00+02:00',
                            'montantHT' => 100.0,
                            'montantTTC' => 120.0,
                            'reference' => 'BC001',
                            'champsLibres' => [],
                        ]]),
                        6 => self::jsonResponse([[
                            'piece' => 'FA001',
                            'tiers' => 'CLI001',
                            'date' => '2026-09-22T00:00:00+02:00',
                            'montantHT' => 110.0,
                            'montantTTC' => 132.0,
                            'reference' => 'BC001',
                            'champsLibres' => [],
                        ]]),
                        7 => self::jsonResponse([]),
                        default => throw new \RuntimeException('Unexpected Sage document type.'),
                    };
                }

                if ($method === 'GET' && $path === '/Document/line') {
                    $isInvoice = ($query['piece'] ?? '') === 'FA001';

                    return self::jsonResponse([[
                        'referenceArticle' => 'SKU-1',
                        'designationArticle' => 'Article test',
                        'qteArticle' => 2,
                        'prixHTArticle' => $isInvoice ? 55 : 50,
                        'montantArticle' => $isInvoice ? 110 : 100,
                        'tauxTva' => 20,
                    ]]);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse([['reference' => 'CLI001', 'intitule' => 'Client test']]);
                }

                if ($method === 'GET' && in_array($path, ['/Contacts', '/Livraisons'], true)) {
                    return self::jsonResponse([]);
                }

                throw new \RuntimeException(sprintf('Unexpected Sage request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $hubSpotClient = new HubSpotClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$createdOrders, &$hubspotRequests, &$updatedOrderPayload, &$updatedLineItemPayload): MockResponse {
                ++$hubspotRequests;
                $path = (string) parse_url($url, PHP_URL_PATH);
                $payload = isset($options['body']) ? json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR) : null;

                if ($method === 'POST' && $path === '/crm/objects/2026-09/orders/search') {
                    return self::jsonResponse(['results' => []]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/companies/search') {
                    return self::jsonResponse(['results' => [['id' => 'company-1']]]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/products/search') {
                    return self::jsonResponse(['results' => [['id' => 'product-1']]]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/line_items') {
                    return self::jsonResponse(['id' => 'line-1']);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/orders') {
                    ++$createdOrders;

                    return self::jsonResponse(['id' => 'order-1']);
                }

                if ($method === 'PATCH' && $path === '/crm/objects/2026-09/line_items/line-1') {
                    $updatedLineItemPayload = $payload;

                    return self::jsonResponse(['id' => 'line-1']);
                }

                if ($method === 'PATCH' && $path === '/crm/objects/2026-09/orders/order-1') {
                    $updatedOrderPayload = $payload;

                    return self::jsonResponse(['id' => 'order-1']);
                }

                throw new \RuntimeException(sprintf('Unexpected HubSpot request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $repository = $this->createStub(ErpDeliveryNoteRepository::class);
        $repository
            ->method('findOneBySageKey')
            ->willReturnCallback(static function (string $sageKey) use (&$storedDeliveryNote): ?ErpDeliveryNote {
                return $storedDeliveryNote?->getSageKey() === $sageKey ? $storedDeliveryNote : null;
            });
        $repository
            ->method('findOneByInvoicePiece')
            ->willReturnCallback(static function (string $piece) use (&$storedDeliveryNote): ?ErpDeliveryNote {
                return $storedDeliveryNote?->getInvoicePiece() === $piece ? $storedDeliveryNote : null;
            });
        $repository
            ->method('findPotentialInvoiceMatches')
            ->willReturnCallback(static function (string $clientId) use (&$storedDeliveryNote): array {
                return $storedDeliveryNote?->getClientId() === $clientId ? [$storedDeliveryNote] : [];
            });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$storedDeliveryNote): void {
                if ($entity instanceof ErpDeliveryNote) {
                    $storedDeliveryNote = $entity;
                }
            });
        $commercialRepository = $this->createStub(CommercialRepository::class);
        $commercialRepository->method('findActiveOrdered')->willReturn([]);
        $service = new HubspotDeliveryOrderSyncService(
            $sageClient,
            $hubSpotClient,
            $repository,
            $commercialRepository,
            $entityManager,
            new NullLogger(),
            'pipeline-orders',
            'stage-preparation',
            'stage-invoiced',
            'stage-paid',
            ['20' => 'tax-20'],
        );

        $result = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(1, $result['deliveryNotesAnalyzed']);
        self::assertSame(1, $result['invoicesAnalyzed']);
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);
        self::assertSame(1, $createdOrders);
        self::assertInstanceOf(ErpDeliveryNote::class, $storedDeliveryNote);
        self::assertSame('BL001', $storedDeliveryNote->getPiece());
        self::assertSame('FA001', $storedDeliveryNote->getInvoicePiece());
        self::assertSame(6, $storedDeliveryNote->getSourceDocumentType());
        self::assertSame(132.0, $storedDeliveryNote->getAmountIncludingTax());
        self::assertSame(55.0, $updatedLineItemPayload['properties']['price']);
        self::assertSame(132.0, $updatedOrderPayload['properties']['hs_total_price']);
        self::assertSame('BL BL001 / Facture FA001 - Client test', $updatedOrderPayload['properties']['hs_order_name']);
        self::assertSame('stage-invoiced', $updatedOrderPayload['properties']['hs_pipeline_stage']);
        self::assertSame('No', $updatedOrderPayload['properties']['order_paid']);
        self::assertSame('2026-10-31', $updatedOrderPayload['properties']['invoice_due_date']);

        $hubspotRequestsAfterFirstRun = $hubspotRequests;
        $secondResult = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(1, $secondResult['skipped']);
        self::assertSame(1, $secondResult['updated']);
        self::assertSame(ErpDeliveryNote::STATUS_SENT, $storedDeliveryNote->getStatus());
        self::assertGreaterThan($hubspotRequestsAfterFirstRun, $hubspotRequests);
        self::assertSame('stage-paid', $updatedOrderPayload['properties']['hs_pipeline_stage']);
        self::assertSame('Yes', $updatedOrderPayload['properties']['order_paid']);
        self::assertSame('2026-10-31', $updatedOrderPayload['properties']['invoice_due_date']);
    }

    public function testInvoiceCanRecoverAnOrderCreatedFromAnOlderDeliveryNoteByReference(): void
    {
        $storedDeliveryNote = null;
        $updatedOrderPayload = null;
        $sageClient = new SageClient(
            new MockHttpClient(static function (string $method, string $url): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Document/lettrage') {
                    return self::jsonResponse([]);
                }

                if ($method === 'GET' && $path === '/Document/header') {
                    return (int) ($query['type'] ?? -1) === 6
                        ? self::jsonResponse([[
                            'piece' => 'FA009',
                            'tiers' => 'CLI009',
                            'date' => '2026-09-22T00:00:00+02:00',
                            'montantHT' => 200.0,
                            'montantTTC' => 240.0,
                            'reference' => 'BC009',
                            'champsLibres' => [],
                        ]])
                        : self::jsonResponse([]);
                }

                if ($method === 'GET' && $path === '/Document/line') {
                    return self::jsonResponse([[
                        'referenceArticle' => 'SKU-9',
                        'designationArticle' => 'Article final',
                        'qteArticle' => 1,
                        'prixHTArticle' => 200,
                        'montantArticle' => 200,
                        'tauxTva' => 20,
                    ]]);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse([['reference' => 'CLI009', 'intitule' => 'Client final']]);
                }

                if ($method === 'GET' && in_array($path, ['/Contacts', '/Livraisons'], true)) {
                    return self::jsonResponse([]);
                }

                throw new \RuntimeException(sprintf('Unexpected Sage request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $hubSpotClient = new HubSpotClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$updatedOrderPayload): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $payload = isset($options['body']) ? json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR) : null;

                if ($method === 'POST' && $path === '/crm/objects/2026-09/orders/search') {
                    $property = $payload['filterGroups'][0]['filters'][0]['propertyName'] ?? '';

                    return $property === 'order_reference'
                        ? self::jsonResponse(['results' => [['id' => 'order-older', 'properties' => ['hs_total_price' => '240']]]])
                        : self::jsonResponse(['results' => []]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/companies/search') {
                    return self::jsonResponse(['results' => [['id' => 'company-9']]]);
                }

                if ($method === 'GET' && $path === '/crm/objects/2026-09/orders/order-older') {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                    return ($query['associations'] ?? '') === 'companies'
                        ? self::jsonResponse(['associations' => ['companies' => ['results' => [['id' => 'company-9']]]]])
                        : self::jsonResponse(['associations' => ['line_items' => ['results' => [['id' => 'line-older']]]]]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/products/search') {
                    return self::jsonResponse(['results' => []]);
                }

                if ($method === 'PATCH' && $path === '/crm/objects/2026-09/line_items/line-older') {
                    return self::jsonResponse(['id' => 'line-older']);
                }

                if ($method === 'PATCH' && $path === '/crm/objects/2026-09/orders/order-older') {
                    $updatedOrderPayload = $payload;

                    return self::jsonResponse(['id' => 'order-older']);
                }

                throw new \RuntimeException(sprintf('Unexpected HubSpot request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $repository = $this->createStub(ErpDeliveryNoteRepository::class);
        $repository->method('findOneBySageKey')->willReturn(null);
        $repository->method('findOneByInvoicePiece')->willReturn(null);
        $repository->method('findPotentialInvoiceMatches')->willReturn([]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$storedDeliveryNote): void {
                if ($entity instanceof ErpDeliveryNote) {
                    $storedDeliveryNote = $entity;
                }
            });
        $commercialRepository = $this->createStub(CommercialRepository::class);
        $commercialRepository->method('findActiveOrdered')->willReturn([]);
        $service = new HubspotDeliveryOrderSyncService(
            $sageClient,
            $hubSpotClient,
            $repository,
            $commercialRepository,
            $entityManager,
            new NullLogger(),
            'pipeline-orders',
            'stage-preparation',
            'stage-invoiced',
            'stage-paid',
            ['20' => 'tax-20'],
        );

        $result = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(0, $result['deliveryNotesAnalyzed']);
        self::assertSame(1, $result['invoicesAnalyzed']);
        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['sent']);
        self::assertSame(0, $result['failed']);
        self::assertInstanceOf(ErpDeliveryNote::class, $storedDeliveryNote);
        self::assertSame('order-older', $storedDeliveryNote->getHubspotOrderId());
        self::assertSame(['line-older'], $storedDeliveryNote->getHubspotLineItemIds());
        self::assertSame('Facture FA009 - Client final', $updatedOrderPayload['properties']['hs_order_name']);
        self::assertSame('sage:0:invoice:FA009', $updatedOrderPayload['properties']['hs_external_order_id']);
        self::assertSame('BC009', $updatedOrderPayload['properties']['order_reference']);
    }

    public function testCreditNoteCreatesADistinctNegativeOrder(): void
    {
        $storedDeliveryNote = null;
        $lineItemPayload = null;
        $orderPayload = null;
        $orderSearchProperties = [];
        $sageClient = new SageClient(
            new MockHttpClient(static function (string $method, string $url): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Document/lettrage') {
                    return self::jsonResponse([[
                        'Client' => 'CLI-CREDIT',
                        'Piece' => 'FV001',
                        'Lettre' => 0,
                        'Journal' => 'VTE',
                        "Date d'échéance" => '2026-10-15T00:00:00+02:00',
                    ]]);
                }

                if ($method === 'GET' && $path === '/Document/header') {
                    return (int) ($query['type'] ?? -1) === 7
                        ? self::jsonResponse([[
                            'piece' => 'FV001',
                            'tiers' => 'CLI-CREDIT',
                            'date' => '2026-09-22T00:00:00+02:00',
                            'montantHT' => -100.0,
                            'montantTTC' => -120.0,
                            'reference' => 'FA0001',
                            'champsLibres' => [],
                        ]])
                        : self::jsonResponse([]);
                }

                if ($method === 'GET' && $path === '/Document/line') {
                    return self::jsonResponse([[
                        'referenceArticle' => 'SKU-CREDIT',
                        'designationArticle' => 'Article retourne',
                        'qteArticle' => -2,
                        'prixHTArticle' => 50,
                        'montantArticle' => -100,
                        'tauxTva' => 20,
                    ]]);
                }

                if ($method === 'GET' && $path === '/Clients') {
                    return self::jsonResponse([['reference' => 'CLI-CREDIT', 'intitule' => 'Client avoir']]);
                }

                if ($method === 'GET' && in_array($path, ['/Contacts', '/Livraisons'], true)) {
                    return self::jsonResponse([]);
                }

                throw new \RuntimeException(sprintf('Unexpected Sage request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $hubSpotClient = new HubSpotClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$lineItemPayload, &$orderPayload, &$orderSearchProperties): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $payload = isset($options['body']) ? json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR) : null;

                if ($method === 'POST' && $path === '/crm/objects/2026-09/orders/search') {
                    $orderSearchProperties[] = $payload['filterGroups'][0]['filters'][0]['propertyName'] ?? '';

                    return self::jsonResponse(['results' => []]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/companies/search') {
                    return self::jsonResponse(['results' => [['id' => 'company-credit']]]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/products/search') {
                    return self::jsonResponse(['results' => [['id' => 'product-credit']]]);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/line_items') {
                    $lineItemPayload = $payload;

                    return self::jsonResponse(['id' => 'line-credit']);
                }

                if ($method === 'POST' && $path === '/crm/objects/2026-09/orders') {
                    $orderPayload = $payload;

                    return self::jsonResponse(['id' => 'order-credit']);
                }

                throw new \RuntimeException(sprintf('Unexpected HubSpot request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $repository = $this->createStub(ErpDeliveryNoteRepository::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$storedDeliveryNote): void {
                if ($entity instanceof ErpDeliveryNote) {
                    $storedDeliveryNote = $entity;
                }
            });
        $service = new HubspotDeliveryOrderSyncService(
            $sageClient,
            $hubSpotClient,
            $repository,
            $this->createStub(CommercialRepository::class),
            $entityManager,
            new NullLogger(),
            'pipeline-orders',
            'stage-preparation',
            'stage-invoiced',
            'stage-paid',
            ['20' => 'tax-20'],
        );

        $result = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(1, $result['invoicesAnalyzed']);
        self::assertSame(1, $result['sent']);
        self::assertSame(0, $result['failed']);
        self::assertSame(['hs_external_order_id'], $orderSearchProperties, 'The credit must not reuse the original invoice Order by reference.');
        self::assertSame('order-credit', $storedDeliveryNote->getHubspotOrderId());
        self::assertSame(2.0, $lineItemPayload['properties']['quantity']);
        self::assertSame(50.0, $lineItemPayload['properties']['price']);
        self::assertSame('Article retourne', $lineItemPayload['properties']['name']);
        self::assertSame('SKU-CREDIT', $lineItemPayload['properties']['hs_sku']);
        self::assertSame('product-credit', $lineItemPayload['properties']['hs_product_id']);
        self::assertSame('Avoir FV001 - Client avoir', $orderPayload['properties']['hs_order_name']);
        self::assertSame(-100.0, $orderPayload['properties']['hs_subtotal_price']);
        self::assertSame(0.0, $orderPayload['properties']['hs_order_discount']);
        self::assertSame(-120.0, $orderPayload['properties']['hs_total_price']);
        self::assertSame('2026-10-15', $orderPayload['properties']['invoice_due_date']);
    }

    public function testPaidInvoiceOutsideTheScanWindowIsRefreshedFromLettrage(): void
    {
        $invoice = new ErpDeliveryNote('sage:0:3:BL-OLD', 'BL-OLD');
        $invoice->refreshFromSage(
            [
                'piece' => 'FA-OLD',
                'tiers' => 'CLI-OLD',
                'date' => '2025-01-15T00:00:00+01:00',
                'montantHT' => 100,
                'montantTTC' => 120,
            ],
            [['referenceArticle' => 'SKU-OLD', 'qteArticle' => 1, 'montantArticle' => 100]],
            ['reference' => 'CLI-OLD', 'intitule' => 'Ancien client'],
            [],
            [],
            'old-payload-hash',
            6,
            [
                'stage' => 'stage-invoiced',
                'payment' => ['status' => 'unpaid', 'dueDate' => '2025-02-15', 'codes' => []],
            ],
        );
        $invoice->markSent('order-old', ['line-old'], 'old-payload-hash');
        $sageClient = new SageClient(
            new MockHttpClient(static function (string $method, string $url): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);

                return match ([$method, $path]) {
                    ['POST', '/auth/login'] => self::jsonResponse(['accessToken' => 'sage-token']),
                    ['GET', '/Document/lettrage'] => self::jsonResponse([[
                        'Client' => 'CLI-OLD',
                        'Piece' => 'FA-OLD',
                        'Lettre' => 1,
                        'Lettrage' => 'PAID-1',
                        'Journal' => 'VTE',
                        "Date d'échéance" => '2025-02-15T00:00:00+01:00',
                    ]]),
                    ['GET', '/Document/header'] => self::jsonResponse([]),
                    default => throw new \RuntimeException(sprintf('Unexpected Sage request: %s %s', $method, $path)),
                };
            }),
            $this->parameters(),
        );
        $updatedOrderPayload = null;
        $hubSpotClient = new HubSpotClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$updatedOrderPayload): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);

                if ($method === 'PATCH' && $path === '/crm/objects/2026-09/orders/order-old') {
                    $updatedOrderPayload = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);

                    return self::jsonResponse(['id' => 'order-old']);
                }

                throw new \RuntimeException(sprintf('Unexpected HubSpot request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $repository = $this->createStub(ErpDeliveryNoteRepository::class);
        $repository->method('findTrackedInvoicesForPaymentRefresh')->willReturn([$invoice]);
        $service = new HubspotDeliveryOrderSyncService(
            $sageClient,
            $hubSpotClient,
            $repository,
            $this->createStub(CommercialRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            'pipeline-orders',
            'stage-preparation',
            'stage-invoiced',
            'stage-paid',
            ['20' => 'tax-20'],
        );

        $result = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(0, $result['analyzed']);
        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['failed']);
        self::assertSame('stage-paid', $updatedOrderPayload['properties']['hs_pipeline_stage']);
        self::assertSame('Yes', $updatedOrderPayload['properties']['order_paid']);
        self::assertSame('2025-02-15', $updatedOrderPayload['properties']['invoice_due_date']);
        self::assertSame('paid', $invoice->getRawPayload()['hubspotOrderState']['payment']['status']);
    }

    public function testExcludedSupplierAndDocumentAreSkippedBeforeAnyLocalOrHubSpotWrite(): void
    {
        $sageClient = new SageClient(
            new MockHttpClient(static function (string $method, string $url): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if ($method === 'POST' && $path === '/auth/login') {
                    return self::jsonResponse(['accessToken' => 'sage-token']);
                }

                if ($method === 'GET' && $path === '/Document/lettrage') {
                    return self::jsonResponse([]);
                }

                if ($method === 'GET' && $path === '/Document/header') {
                    return (int) ($query['type'] ?? -1) === 3
                        ? self::jsonResponse([
                            [
                                'piece' => 'BL-FOURNISSEUR',
                                'tiers' => '9DACHSER',
                                'date' => '2026-10-20T00:00:00+02:00',
                            ],
                            [
                                'piece' => 'FA-MANUELLE',
                                'tiers' => 'CLIENT-ACTIF',
                                'date' => '2026-10-20T00:00:00+02:00',
                            ],
                        ])
                        : self::jsonResponse([]);
                }

                throw new \RuntimeException(sprintf('Unexpected Sage request: %s %s', $method, $path));
            }),
            $this->parameters(),
        );
        $hubSpotClient = new HubSpotClient(
            new MockHttpClient(static function (string $method, string $url): never {
                throw new \RuntimeException(sprintf('Unexpected HubSpot request: %s %s', $method, $url));
            }),
            $this->parameters(),
        );
        $repository = $this->createMock(ErpDeliveryNoteRepository::class);
        $repository->expects(self::never())->method('findOneBySageKey');
        $repository->expects(self::never())->method('findOneByInvoicePiece');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $service = new HubspotDeliveryOrderSyncService(
            $sageClient,
            $hubSpotClient,
            $repository,
            $this->createStub(CommercialRepository::class),
            $entityManager,
            new NullLogger(),
            'pipeline-orders',
            'stage-preparation',
            'stage-invoiced',
            'stage-paid',
            ['20' => 'tax-20'],
            [' 9dachser '],
            [' fa-manuelle '],
        );

        $result = $service->sync(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-20'));

        self::assertSame(2, $result['analyzed']);
        self::assertSame(2, $result['deliveryNotesAnalyzed']);
        self::assertSame(0, $result['invoicesAnalyzed']);
        self::assertSame(2, $result['skipped']);
        self::assertSame(0, $result['discovered']);
        self::assertSame(0, $result['sent']);
        self::assertSame(0, $result['failed']);
    }

    public function testHubSpotErrorsAreThrownInsteadOfBeingTreatedAsSuccessfulResponses(): void
    {
        $client = new HubSpotClient(
            new MockHttpClient(new MockResponse(
                json_encode(['message' => 'Invalid property'], JSON_THROW_ON_ERROR),
                ['http_code' => 400, 'response_headers' => ['content-type' => 'application/json']],
            )),
            $this->parameters(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Erreur API HubSpot [400] : Invalid property');

        $client->post('/crm/objects/2026-09/orders', ['properties' => []]);
    }

    private function parameters(): ParameterBag
    {
        return new ParameterBag([
            'base_uri_hubspot' => 'https://hubspot.test',
            'hubspot_access' => 'hubspot-token',
            'base_uri_sage' => 'https://sage.test',
            'sage_username' => 'sage-user',
            'sage_password' => 'sage-password',
        ]);
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(
            json_encode($data, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }
}
