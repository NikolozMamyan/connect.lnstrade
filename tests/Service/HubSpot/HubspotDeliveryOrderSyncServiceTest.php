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
                    ['GET', '/Document/line'] => self::jsonResponse([[
                        'referenceArticle' => 'SKU-1',
                        'designationArticle' => 'Article test',
                        'qteArticle' => 2,
                        'prixHTArticle' => 50,
                        'montantArticle' => 100,
                        'tauxTva' => 20,
                    ]]),
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

                if ($method === 'POST' && $path === '/crm/objects/2026-09/companies/search') {
                    return self::jsonResponse(['results' => [['id' => 'company-123']]]);
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
            'stage-invoiced',
            ['20' => 'tax-20'],
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
        self::assertSame('sage:0:3:BL001', $orderPayload['properties']['hs_external_order_id']);
        self::assertSame(120.0, $orderPayload['properties']['hs_total_price']);
        self::assertSame('Yes', $orderPayload['properties']['order_paid']);
        self::assertSame('DDP', $orderPayload['properties']['incoterm']);
        self::assertSame('Entrepot test', $orderPayload['properties']['hs_shipping_address_name']);
        self::assertSame([509, 2694, 513], array_map(
            static fn (array $association): int => (int) $association['types'][0]['associationTypeId'],
            $orderPayload['associations'],
        ));

        $hubspotRequestsAfterFirstRun = $hubspotRequests;
        $secondResult = $service->sync(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-23'));

        self::assertSame(1, $secondResult['skipped']);
        self::assertSame(0, $secondResult['sent']);
        self::assertSame($hubspotRequestsAfterFirstRun, $hubspotRequests, 'The unchanged BL must not call HubSpot again.');
        self::assertSame(11, $sageRequests);
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
