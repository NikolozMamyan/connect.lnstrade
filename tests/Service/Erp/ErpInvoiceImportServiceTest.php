<?php

namespace App\Tests\Service\Erp;

use App\Entity\ErpInvoice;
use App\Repository\ErpInvoiceRepository;
use App\Service\Erp\ErpInvoiceImportService;
use App\Service\Erp\SageClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ErpInvoiceImportServiceTest extends TestCase
{
    public function testUnchangedInvoiceIsNotRewritten(): void
    {
        $payload = [
            'numero_facture' => 'FA001',
            'clientid' => 'CLIENT01',
            'commandes' => [],
        ];
        $invoice = (new ErpInvoice())
            ->setInvoiceNumber('FA001')
            ->setRawPayload($payload);

        $sageClient = $this->createMock(SageClient::class);
        $sageClient->expects(self::once())
            ->method('get')
            ->with('/invoices')
            ->willReturn([$payload]);

        $repository = $this->createMock(ErpInvoiceRepository::class);
        $repository->expects(self::once())
            ->method('findIndexedByInvoiceNumbers')
            ->with(['FA001'])
            ->willReturn(['FA001' => $invoice]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())->method('clear');

        $result = (new ErpInvoiceImportService(
            $sageClient,
            $repository,
            $entityManager,
        ))->importInvoicesFromErp();

        self::assertSame(0, $result['imported']);
        self::assertSame(0, $result['updated']);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $result['errors']);
    }
}
