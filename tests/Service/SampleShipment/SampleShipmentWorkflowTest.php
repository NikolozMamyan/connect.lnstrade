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

    public function testQueueFailureCanBeRetriedWithoutRecreatingTheOrder(): void
    {
        $this->prepareShipment();
        $this->shipment->markSent('ECH-261022-000042', 'Entreprise #000042', [], ['piece' => 'BC002885']);
        $attempt = 0;
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function (object $message) use (&$attempt): Envelope {
            if (++$attempt === 1) {
                throw new \RuntimeException('Queue temporarily unavailable');
            }

            return new Envelope($message);
        });
        $service = $this->service();

        try {
            $service->submit($this->input());
            self::fail('Queue errors must be reported independently of Sage.');
        } catch (SampleShipmentNotificationException) {
            self::assertTrue($this->shipment->isSent());
        }

        self::assertTrue($service->submit($this->input())['idempotent']);
        self::assertSame([], $this->requests);
    }

    public function testEmailFailureIsRetriedAndSuccessfulNotificationsAreNotSentAgain(): void
    {
        $this->prepareShipment();
        $this->shipment->markSent('ECH-261022-000042', 'Entreprise #000042', [], ['piece' => 'BC002885']);
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

    private function prepareShipment(): void
    {
        $recipient = $this->input() + ['addressComplement' => null, 'phone' => null, 'email' => null, 'message' => null];
        $recipient['company'] = 'Ancienne entreprise';
        $this->shipment = (new SampleShipment('request-token-0042'))
            ->setRecipient($recipient)
            ->setLines($this->input()['lines'])
            ->setRepresentative(['id' => 7, 'firstName' => 'Sonita', 'lastName' => 'SIEK'])
            ->prepareExport('ECH-261022-000042', 'Ancienne entreprise #000042', ['numClient' => '9ECH'])
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
            (new \ReflectionProperty($shipment, 'id'))->setValue($shipment, 42);
            $this->shipment = $shipment;
        });
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $body = !empty($options['body']) ? json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR) : null;
            $this->requests[] = [$method, parse_url($url, PHP_URL_PATH), $body];
            self::assertNotEmpty($responses, 'Unexpected Sage request: '.$method.' '.$url);

            return new MockResponse(json_encode(array_shift($responses), JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        });

        return new SampleShipmentService(
            $products, $shipments, $entityManager,
            new SageClient($client, new ParameterBag(['base_uri_sage' => 'https://sage.test', 'sage_username' => 'user', 'sage_password' => 'password'])),
            new LockFactory(new InMemoryStore()), new NullLogger(), $this->commercials, $this->bus, $this->mailer,
        );
    }
}
