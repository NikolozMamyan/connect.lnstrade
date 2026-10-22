<?php

namespace App\Tests\Service\SampleShipment;

use App\Entity\ErpProduct;
use App\Entity\SampleShipment;
use App\Repository\ErpProductRepository;
use App\Repository\SampleShipmentRepository;
use App\Service\Erp\SageClient;
use App\Service\SampleShipment\SampleShipmentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class SampleShipmentServiceTest extends TestCase
{
    public function testSubmitCreatesDedicatedDeliveryAndZeroPricedOrder(): void
    {
        $product = (new ErpProduct())
            ->setReference('ART-001')
            ->setDesignation('Produit test')
            ->setCodeBarre('3760000000012');
        $productRepository = $this->createMock(ErpProductRepository::class);
        $productRepository->expects(self::once())
            ->method('findIndexedByReferences')
            ->with(['ART-001'])
            ->willReturn(['ART-001' => $product]);

        $shipmentRepository = $this->createMock(SampleShipmentRepository::class);
        $shipmentRepository->expects(self::once())
            ->method('findOneByRequestToken')
            ->with('request-token-0001')
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (SampleShipment $shipment): void {
                $property = new \ReflectionProperty($shipment, 'id');
                $property->setValue($shipment, 42);
            });
        $entityManager->expects(self::exactly(3))->method('flush');

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $body = isset($options['body']) && $options['body'] !== ''
                ? json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR)
                : null;
            $requests[] = [$method, $path, $body];

            return match ([$method, $path]) {
                ['POST', '/auth/login'] => self::jsonResponse(['accessToken' => 'token']),
                ['GET', '/Clients'] => self::jsonResponse([]),
                ['POST', '/Clients'] => self::jsonResponse(['success' => true]),
                ['GET', '/Livraisons'] => self::jsonResponse([[
                    'client' => '9ECH',
                    'intitule' => 'Ancienne adresse #000001',
                    'livraisonPrincipal' => true,
                ]]),
                ['PATCH', '/Livraisons'] => self::jsonResponse(['success' => true]),
                ['POST', '/Livraisons'] => self::jsonResponse(['success' => true]),
                ['POST', '/order'] => self::jsonResponse(['piece' => 'BC001234']),
                default => throw new \RuntimeException(sprintf('Unexpected Sage request: %s %s', $method, $path)),
            };
        });

        $service = new SampleShipmentService(
            $productRepository,
            $shipmentRepository,
            $entityManager,
            new SageClient($httpClient, new ParameterBag([
                'base_uri_sage' => 'https://sage.test',
                'sage_username' => 'user',
                'sage_password' => 'password',
            ])),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $result = $service->submit([
            'requestToken' => 'request-token-0001',
            'company' => 'Boutique Démo',
            'contactName' => 'Marie Martin',
            'address' => '12 rue du Test',
            'addressComplement' => 'Service réception',
            'postalCode' => '75001',
            'city' => 'Paris',
            'country' => 'France',
            'phone' => '0102030405',
            'email' => 'marie@example.com',
            'message' => 'Livrer le matin',
            'lines' => [['reference' => 'ART-001', 'quantity' => 3]],
        ]);

        $deliveryPayload = $this->requestBody($requests, 'POST', '/Livraisons');
        $previousDeliveryPayload = $this->requestBody($requests, 'PATCH', '/Livraisons');
        $orderPayload = $this->requestBody($requests, 'POST', '/order');
        self::assertSame('9ECH', $deliveryPayload['client']);
        self::assertSame('Boutique Démo #000042', $deliveryPayload['intitule']);
        self::assertSame('12 rue du Test', $deliveryPayload['adresse']);
        self::assertTrue($deliveryPayload['livraisonPrincipal']);
        self::assertSame('Ancienne adresse #000001', $previousDeliveryPayload['intitule']);
        self::assertFalse($previousDeliveryPayload['livraisonPrincipal']);
        self::assertSame('9ECH', $orderPayload['numClient']);
        self::assertSame(0, $orderPayload['orderLines'][0]['prixHT']);
        self::assertSame(3, $orderPayload['orderLines'][0]['quantite']);
        self::assertSame('Boutique Démo - Livrer le matin', $orderPayload['instructionDeLivraison']);
        self::assertSame('BC001234', $result['sageDocument']);
        self::assertFalse($result['idempotent']);
    }

    public function testSubmitReturnsAnAlreadySentShipmentWithoutCallingSage(): void
    {
        $shipment = (new SampleShipment('request-token-0002'))->markSent(
            'ECH-20261022-000043',
            'ECH-20261022-000043',
            ['numClient' => '9ECH'],
            ['piece' => 'BC001235'],
        );
        $productRepository = $this->createMock(ErpProductRepository::class);
        $productRepository->expects(self::never())->method('findIndexedByReferences');
        $shipmentRepository = $this->createMock(SampleShipmentRepository::class);
        $shipmentRepository->expects(self::once())->method('findOneByRequestToken')->willReturn($shipment);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $httpClient = new MockHttpClient(static fn (): MockResponse => throw new \RuntimeException('Sage must not be called.'));

        $service = new SampleShipmentService(
            $productRepository,
            $shipmentRepository,
            $entityManager,
            new SageClient($httpClient, new ParameterBag([
                'base_uri_sage' => 'https://sage.test',
                'sage_username' => 'user',
                'sage_password' => 'password',
            ])),
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );

        $result = $service->submit(['requestToken' => 'request-token-0002']);

        self::assertTrue($result['idempotent']);
        self::assertSame('ECH-20261022-000043', $result['reference']);
        self::assertSame('BC001235', $result['sageDocument']);
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?array}> $requests
     *
     * @return array<string, mixed>
     */
    private function requestBody(array $requests, string $method, string $path): array
    {
        foreach ($requests as [$requestMethod, $requestPath, $body]) {
            if ($requestMethod === $method && $requestPath === $path) {
                return $body ?? [];
            }
        }

        self::fail(sprintf('Request not found: %s %s', $method, $path));
    }

    private static function jsonResponse(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]);
    }
}
