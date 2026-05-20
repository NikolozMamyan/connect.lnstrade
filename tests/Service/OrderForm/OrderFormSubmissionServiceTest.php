<?php

namespace App\Tests\Service\OrderForm;

use App\Entity\Commercial;
use App\Entity\OrderForm;
use App\Repository\OrderFormRepository;
use App\Service\HubSpot\HubspotOrderFormDealSyncService;
use App\Service\Mailer\SimpleMailerService;
use App\Service\OrderForm\OrderFormSpreadsheetParser;
use App\Service\OrderForm\OrderFormSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

class OrderFormSubmissionServiceTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . DIRECTORY_SEPARATOR . '*');

            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }

            rmdir($directory);
        }

        $this->temporaryDirectories = [];
    }

    public function testSubmitMarksOrderFormAsFailedWhenHubspotSyncFails(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $entityManager->expects($this->once())->method('persist')->with($this->isInstanceOf(OrderForm::class));

        $orderFormRepository = $this->createStub(OrderFormRepository::class);
        $orderFormRepository->method('referenceExists')->willReturn(false);

        $parser = $this->createStub(OrderFormSpreadsheetParser::class);
        $parser->method('parse')->willReturn([
            'success' => true,
            'lineItems' => [[
                'articleRef' => 'AR-100',
                'quantity' => 12.0,
                'unitPrice' => 3.5,
                'lineTotal' => 42.0,
                'rawPayload' => [],
            ]],
            'errors' => [],
            'failedRows' => [],
        ]);

        $hubspotSync = $this->createStub(HubspotOrderFormDealSyncService::class);
        $hubspotSync->method('sync')->willReturn([
            'success' => false,
            'hubspotDealId' => null,
            'errors' => [[
                'field' => 'articleRef',
                'message' => 'La reference produit AR-100 est inconnue localement.',
            ]],
        ]);

        $service = new OrderFormSubmissionService(
            $entityManager,
            $orderFormRepository,
            $parser,
            $hubspotSync,
            $this->createStub(SimpleMailerService::class),
            new AsciiSlugger(),
            '143807682',
            $this->createUploadDirectory(),
        );

        $result = $service->submit($this->createOrderForm(OrderForm::DEAL_TYPE_NOUVEAU), $this->createUploadedFile());

        self::assertFalse($result['success']);
        self::assertNull($result['deal']);
        self::assertSame(OrderForm::STATUS_FAILED, $result['orderForm']->getStatus());
        self::assertNotNull($result['orderForm']->getProcessedAt());
        self::assertSame('articleRef', $result['errors'][0]['field']);
    }

    public function testSubmitCreatesValidatedDealWithHubspotDealId(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $orderFormRepository = $this->createStub(OrderFormRepository::class);
        $orderFormRepository->method('referenceExists')->willReturn(false);

        $parser = $this->createStub(OrderFormSpreadsheetParser::class);
        $parser->method('parse')->willReturn([
            'success' => true,
            'lineItems' => [[
                'articleRef' => 'AR-100',
                'description' => 'Produit A',
                'eanUnit' => '1234567890123',
                'quantity' => 12.0,
                'unitPrice' => 3.5,
                'lineTotal' => 42.0,
                'rawPayload' => ['AR-100'],
            ]],
            'errors' => [],
            'failedRows' => [],
        ]);

        $hubspotSync = $this->createStub(HubspotOrderFormDealSyncService::class);
        $hubspotSync->method('sync')->willReturn([
            'success' => true,
            'hubspotDealId' => '987654',
            'errors' => [],
        ]);

        $service = new OrderFormSubmissionService(
            $entityManager,
            $orderFormRepository,
            $parser,
            $hubspotSync,
            $this->createStub(SimpleMailerService::class),
            new AsciiSlugger(),
            '143807682',
            $this->createUploadDirectory(),
        );

        $result = $service->submit($this->createOrderForm(OrderForm::DEAL_TYPE_NOUVEAU), $this->createUploadedFile());

        self::assertTrue($result['success']);
        self::assertNotNull($result['deal']);
        self::assertSame(OrderForm::STATUS_VALIDATED, $result['orderForm']->getStatus());
        self::assertSame('987654', $result['deal']->getDealId());
        self::assertSame(1, $result['deal']->getLineItemCount());
        self::assertSame(42.0, $result['deal']->getTotalAmount());
    }

    private function createOrderForm(string $dealType): OrderForm
    {
        $commercial = (new Commercial())
            ->setFirstName('Quentin')
            ->setLastName('Strasser')
            ->setHubspotId('78020060')
            ->setEmail('quentin.strasser@lnstrade.fr');

        $orderForm = (new OrderForm())
            ->setDealType($dealType)
            ->setCommercial($commercial)
            ->setEnterpriseId('123456');

        if ($dealType === OrderForm::DEAL_TYPE_EXISTANT) {
            $orderForm->setDealId('654321');
        }

        return $orderForm;
    }

    private function createUploadedFile(): UploadedFile
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'order_form_');

        if ($tempFile === false) {
            self::fail('Unable to create temporary file.');
        }

        file_put_contents($tempFile, 'test');

        return new UploadedFile(
            $tempFile,
            'catalog.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function createUploadDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'order_form_uploads_' . bin2hex(random_bytes(4));

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            self::fail('Unable to create upload directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
