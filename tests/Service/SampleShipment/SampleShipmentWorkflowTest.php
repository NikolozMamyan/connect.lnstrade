<?php

namespace App\Tests\Service\SampleShipment;

use App\Entity\Commercial;
use App\Entity\ErpProduct;
use App\Entity\SampleShipment;
use App\Message\SampleShipmentNotificationMessage;
use App\Repository\CommercialRepository;
use App\Repository\ErpProductRepository;
use App\Repository\SampleShipmentRepository;
use App\Service\Erp\SageClient;
use App\Service\Mailer\SampleShipmentMailer;
use App\Service\SampleShipment\SampleShipmentNotificationException;
use App\Service\SampleShipment\SampleShipmentService;
use App\Service\SampleShipment\SampleShipmentValidationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SampleShipmentWorkflowTest extends TestCase
{
    private ?SampleShipment $shipment = null;
    private Commercial $commercial;
    private CommercialRepository $commercials;
    private SampleShipmentMailer $mailer;
    private MessageBusInterface $bus;
    private array $requests = [];
    private array $dispatched = [];
    private int $nextShipmentId = 42;

    protected function setUp(): void
    {
        $this->commercial = (new Commercial())->setSageFirstName('Sonita')->setSageLastName('SIEK');
        (new \ReflectionProperty($this->commercial, 'id'))->setValue($this->commercial, 7);
        $this->commercials = $this->createStub(CommercialRepository::class);
        $this->commercials->method('find')->willReturn($this->commercial);
        $this->mailer = $this->createStub(SampleShipmentMailer::class);
        $this->bus = $this->createStub(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });
    }

    #[DataProvider('invalidExpiryDates')]
    public function testExpiryDateIsRequiredAndMustBeARealDate(mixed $expiryDate): void
    {
        $input = $this->input();
        $input['lines'][0]['expiryDate'] = $expiryDate;

        try {
            $this->service()->submit($input);
            self::fail('An invalid expiry date must be rejected.');
        } catch (SampleShipmentValidationException $exception) {
            self::assertArrayHasKey('lines.0.expiryDate', $exception->getErrors());
        }

        self::assertNull($this->shipment);
        self::assertSame([], $this->requests);
    }

    public static function invalidExpiryDates(): array
    {
        return [[''], [null], ['2027-02-30'], ['2027-2-3']];
    }

    public function testRepresentativeIsRequired(): void
    {
        $input = $this->input();
        unset($input['representativeId']);

        try {
            $this->service()->submit($input);
            self::fail('A representative must be selected.');
        } catch (SampleShipmentValidationException $exception) {
            self::assertArrayHasKey('representativeId', $exception->getErrors());
        }
    }

    public function testInactiveRepresentativeIsRejected(): void
    {
        $this->commercial->setIsActive(false);

        try {
            $this->service()->submit($this->input());
            self::fail('An inactive representative must be rejected.');
        } catch (SampleShipmentValidationException $exception) {
            self::assertArrayHasKey('representativeId', $exception->getErrors());
        }
    }

    public function testRetryUsesCurrentCompanyForDeliveryAndKeepsExpiryLocally(): void
    {
        $this->prepareShipment();
        $input = $this->input();
        $input['company'] = 'Nouvelle entreprise';
        $service = $this->service([
            ['accessToken' => 'token'],
            [],
            [['reference' => '9ECH']],
            [['intitule' => 'Ancienne entreprise #000042', 'livraisonPrincipal' => true]],
            ['success' => true],
            ['success' => true],
            ['message' => 'Bon de commande N° BC002885 créé avec succès.'],
        ]);

        $result = $service->submit($input);

        self::assertSame('Nouvelle entreprise #000042', $this->shipment->getDeliveryLabel());
        self::assertSame('2027-03-15', $this->shipment->getLines()[0]['expiryDate']);
        self::assertSame('Sonita', $this->shipment->getRepresentative()['firstName']);
        self::assertSame('BC002885', $result['sageDocument']);
        $delivery = $this->requests[5][2];
        self::assertSame('Nouvelle entreprise #000042', $delivery['intitule']);
        self::assertTrue($delivery['livraisonPrincipal']);
        self::assertFalse($this->requests[4][2]['livraisonPrincipal']);
        self::assertArrayNotHasKey('expiryDate', $this->requests[6][2]['orderLines'][0]);
        self::assertInstanceOf(SampleShipmentNotificationMessage::class, $this->dispatched[0]);
        self::assertSame(42, $this->dispatched[0]->shipmentId);

        $again = $service->submit($input);
        self::assertTrue($again['idempotent']);
        self::assertCount(7, $this->requests);
    }

    public function testRetryRecoversAnOrderCreatedBeforeAConnectionFailure(): void
    {
        $this->prepareShipment();
        $service = $this->service([
            ['accessToken' => 'token'],
            [['reference' => 'ECH-261022-000042', 'piece' => 'BC002885']],
        ]);
        $input = $this->input();
        $input['company'] = 'Ne doit pas remplacer le destinataire initial';

        $result = $service->submit($input);

        self::assertTrue($result['idempotent']);
        self::assertSame('BC002885', $result['sageDocument']);
        self::assertSame('Ancienne entreprise', $this->shipment->getRecipient()['company']);
        self::assertSame('sent', $this->shipment->getStatus());
        self::assertCount(2, $this->requests);
        self::assertSame('GET', $this->requests[1][0]);
    }

    #[DataProvider('shipmentPurposes')]
    public function testQueueFailureCanBeRetriedWithoutRecreatingTheOrder(string $purpose): void
    {
        $this->prepareShipment($purpose);
        $this->shipment->markSent((string) $this->shipment->getOrderReference(), 'Entreprise #000042', [], ['piece' => 'BC002885']);
        $attempt = 0;
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function (object $message) use (&$attempt): Envelope {
            if (++$attempt === 1) {
                throw new \RuntimeException('Queue temporarily unavailable');
            }

            return new Envelope($message);
        });
        $service = $this->service();
        $submit = fn (): array => $purpose === SampleShipment::PURPOSE_CONSUMPTION
            ? $service->submitConsumption($this->input())
            : $service->submit($this->input());

        try {
            $submit();
            self::fail('Queue errors must be reported independently of Sage.');
        } catch (SampleShipmentNotificationException) {
            self::assertTrue($this->shipment->isSent());
        }

        self::assertTrue($submit()['idempotent']);
        self::assertSame([], $this->requests);
    }

    #[DataProvider('shipmentPurposes')]
    public function testEmailFailureIsRetriedAndSuccessfulNotificationsAreNotSentAgain(string $purpose): void
    {
        $this->prepareShipment($purpose);
        $this->shipment->markSent((string) $this->shipment->getOrderReference(), 'Entreprise #000042', [], ['piece' => 'BC002885']);
        $attempt = 0;
        $this->mailer = $this->createMock(SampleShipmentMailer::class);
        $this->mailer->expects(self::exactly(2))->method('send')->willReturnCallback(static function () use (&$attempt): void {
            if (++$attempt === 1) {
                throw new \RuntimeException('SMTP temporarily unavailable');
            }
        });
        $service = $this->service();

        try {
            $service->sendNotification(42);
            self::fail('Delivery errors must remain retryable by Messenger.');
        } catch (\RuntimeException) {
            self::assertFalse($this->shipment->isNotified());
        }

        $service->sendNotification(42);
        self::assertTrue($this->shipment->isNotified());
        $service->sendNotification(42);
        self::assertSame([], $this->requests);
    }

    public function testConsumptionCreatesOneFixedClientAndAddressAndReusesThem(): void
    {
        $this->commercials = $this->createMock(CommercialRepository::class);
        $this->commercials->expects(self::never())->method('find');
        $this->mailer = $this->createMock(SampleShipmentMailer::class);
        $this->mailer->expects(self::once())->method('send');
        $notFound = static fn (): MockResponse => new MockResponse('{"message":"Not found"}', ['http_code' => 404]);
        $service = $this->service([
            ['accessToken' => 'token'], $notFound(), ['success' => true], $notFound(), ['success' => true], ['piece' => 'BC003001'],
            [['reference' => 'CONSOLNS']], [['intitule' => 'LNS CONSO', 'livraisonPrincipal' => true]], ['success' => true], ['piece' => 'BC003002'],
        ]);
        $input = [
            'requestToken' => 'consumption-request-0042',
            'lines' => $this->input()['lines'],
            'company' => 'Must be ignored', 'address' => 'Must be ignored', 'representativeId' => 999,
        ];

        $result = $service->submitConsumption($input);

        self::assertSame('BC003001', $result['sageDocument']);
        self::assertTrue($this->shipment->isConsumption());
        self::assertSame('13 quai Kleber', $this->shipment->getRecipient()['address']);
        self::assertSame('67000', $this->shipment->getRecipient()['postalCode']);
        self::assertSame('Strasbourg', $this->shipment->getRecipient()['city']);
        self::assertSame('LNS CONSO', $this->shipment->getDeliveryLabel());
        self::assertSame('Corentin', $this->shipment->getRepresentative()['firstName']);
        self::assertSame('BURY', $this->shipment->getRepresentative()['lastName']);
        self::assertSame('2027-03-15', $this->shipment->getLines()[0]['expiryDate']);
        self::assertTrue($service->submitConsumption($input)['idempotent']);
        self::assertCount(6, $this->requests);
        $service->sendNotification(42);
        $service->sendNotification(42);

        $this->shipment = null;
        $input['requestToken'] = 'consumption-request-0043';
        $service->submitConsumption($input);
        $clientCreates = array_values(array_filter($this->requests, static fn (array $request): bool => $request[0] === 'POST' && $request[1] === '/Clients'));
        $addressCreates = array_values(array_filter($this->requests, static fn (array $request): bool => $request[0] === 'POST' && $request[1] === '/Livraisons'));
        $orders = array_values(array_filter($this->requests, static fn (array $request): bool => $request[0] === 'POST' && $request[1] === '/Order'));
        self::assertCount(1, $clientCreates);
        self::assertSame('CONSOLNS', $clientCreates[0][2]['reference']);
        self::assertCount(1, $addressCreates);
        self::assertSame('CONSOLNS', $addressCreates[0][2]['client']);
        self::assertSame('67000', $addressCreates[0][2]['codePostal']);
        self::assertCount(2, $orders);
        self::assertNotSame($orders[0][2]['referenceCommande'], $orders[1][2]['referenceCommande']);
        foreach ($orders as $order) {
            self::assertSame('CONSOLNS', $order[2]['numClient']);
            self::assertSame('Corentin', $order[2]['ownerFirstName']);
            self::assertSame('BURY', $order[2]['ownerName']);
            self::assertStringStartsWith('CNS-', $order[2]['referenceCommande']);
            self::assertLessThanOrEqual(17, strlen($order[2]['referenceCommande']));
            self::assertSame(0, $order[2]['orderLines'][0]['prixHT']);
            self::assertSame(3, $order[2]['orderLines'][0]['quantite']);
            self::assertSame('ART-001', $order[2]['orderLines'][0]['reference']);
            self::assertArrayNotHasKey('expiryDate', $order[2]['orderLines'][0]);
        }
        self::assertSame([42, 42, 43], array_map(static fn (SampleShipmentNotificationMessage $message): int => $message->shipmentId, $this->dispatched));
    }

    #[DataProvider('invalidExpiryDates')]
    public function testConsumptionRequiresARealExpiryDate(mixed $expiryDate): void
    {
        $input = ['requestToken' => 'consumption-request-0042', 'lines' => $this->input()['lines']];
        $input['lines'][0]['expiryDate'] = $expiryDate;

        try {
            $this->service()->submitConsumption($input);
            self::fail('Consumption expiry dates must be validated.');
        } catch (SampleShipmentValidationException $exception) {
            self::assertArrayHasKey('lines.0.expiryDate', $exception->getErrors());
            self::assertArrayNotHasKey('representativeId', $exception->getErrors());
            self::assertArrayNotHasKey('company', $exception->getErrors());
        }
        self::assertSame([], $this->requests);
    }

    public function testConsumptionRetryRecoversTheOrderUsingItsOwnSageClient(): void
    {
        $this->prepareShipment(SampleShipment::PURPOSE_CONSUMPTION);
        $service = $this->service([
            ['accessToken' => 'token'], [['reference' => 'CNS-261022-000042', 'piece' => 'BC003001']],
        ]);

        $result = $service->submitConsumption(['requestToken' => 'request-token-0042']);

        self::assertTrue($result['idempotent']);
        self::assertSame('BC003001', $result['sageDocument']);
        self::assertSame('CONSOLNS', $this->requests[1][3]['tiers']);
        self::assertCount(2, $this->requests);
        self::assertCount(1, $this->dispatched);
        self::assertSame(42, $this->dispatched[0]->shipmentId);
    }

    #[DataProvider('shipmentPurposes')]
    public function testRequestTokensCannotCrossBetweenConsumptionAndSamples(string $purpose): void
    {
        $this->prepareShipment($purpose);
        $this->shipment->markSent((string) $this->shipment->getOrderReference(), 'Lieu', [], ['piece' => 'BC003001']);
        $service = $this->service();

        try {
            if ($purpose === SampleShipment::PURPOSE_SAMPLES) $service->submitConsumption($this->input());
            else $service->submit($this->input());
            self::fail('A request token cannot be reused in the other flow.');
        } catch (SampleShipmentValidationException $exception) {
            self::assertArrayHasKey('requestToken', $exception->getErrors());
        }
        self::assertSame([], $this->requests);
        self::assertSame([], $this->dispatched);
    }

    public static function shipmentPurposes(): array
    {
        return [[SampleShipment::PURPOSE_SAMPLES], [SampleShipment::PURPOSE_CONSUMPTION]];
    }

    private function input(): array
    {
        return [
            'requestToken' => 'request-token-0042',
            'representativeId' => 7,
            'company' => 'Entreprise',
            'contactName' => 'Marie Martin',
            'address' => '12 rue du Test',
            'postalCode' => '75001',
            'city' => 'Paris',
            'country' => 'France',
            'lines' => [['reference' => 'ART-001', 'quantity' => 3, 'expiryDate' => '2027-03-15']],
        ];
    }

    private function prepareShipment(string $purpose = SampleShipment::PURPOSE_SAMPLES): void
    {
        $recipient = $this->input() + ['addressComplement' => null, 'phone' => null, 'email' => null, 'message' => null];
        $recipient['company'] = 'Ancienne entreprise';
        $this->shipment = (new SampleShipment('request-token-0042', $purpose))
            ->setRecipient($recipient)
            ->setLines($this->input()['lines'])
            ->setRepresentative(['id' => 7, 'firstName' => 'Sonita', 'lastName' => 'SIEK'])
            ->prepareExport(($purpose === SampleShipment::PURPOSE_CONSUMPTION ? 'CNS' : 'ECH').'-261022-000042', 'Ancienne entreprise #000042', ['numClient' => $purpose === SampleShipment::PURPOSE_CONSUMPTION ? 'CONSOLNS' : '9ECH'])
            ->markFailed('Connection interrupted');
        (new \ReflectionProperty($this->shipment, 'id'))->setValue($this->shipment, 42);
    }

    private function service(array $responses = []): SampleShipmentService
    {
        $products = $this->createStub(ErpProductRepository::class);
        $products->method('findIndexedByReferences')->willReturn(['ART-001' => (new ErpProduct())->setReference('ART-001')->setDesignation('Produit test')]);
        $shipments = $this->createStub(SampleShipmentRepository::class);
        $shipments->method('findOneByRequestToken')->willReturnCallback(fn (): ?SampleShipment => $this->shipment);
        $shipments->method('find')->willReturnCallback(fn (): ?SampleShipment => $this->shipment);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (SampleShipment $shipment): void {
            (new \ReflectionProperty($shipment, 'id'))->setValue($shipment, $this->nextShipmentId++);
            $this->shipment = $shipment;
        });
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = !empty($options['body']) ? json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR) : null;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->requests[] = [$method, parse_url($url, PHP_URL_PATH), $body, $query];
            self::assertNotEmpty($responses, 'Unexpected Sage request: '.$method.' '.$url);

            $response = array_shift($responses);

            return $response instanceof MockResponse ? $response : new MockResponse(json_encode($response, JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        });

        return new SampleShipmentService(
            $products, $shipments, $entityManager,
            new SageClient($client, new ParameterBag(['base_uri_sage' => 'https://sage.test', 'sage_username' => 'user', 'sage_password' => 'password'])),
            new LockFactory(new InMemoryStore()), new NullLogger(), $this->commercials, $this->bus, $this->mailer,
        );
    }
}
