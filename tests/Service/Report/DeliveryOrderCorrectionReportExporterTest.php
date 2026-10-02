<?php

namespace App\Tests\Service\Report;

use App\Repository\ErpDeliveryNoteRepository;
use App\Service\Report\DeliveryOrderCorrectionReportExporter;
use App\Service\Spreadsheet\SimpleXlsxWriter;
use PHPUnit\Framework\TestCase;

final class DeliveryOrderCorrectionReportExporterTest extends TestCase
{
    public function testReportConsolidatesCompaniesAndProvidesCorrectionGuidance(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required to generate XLSX reports.');
        }

        $repository = $this->createStub(ErpDeliveryNoteRepository::class);
        $repository->method('findFailedForCorrectionReport')->willReturn([
            [
                'piece' => 'BL001',
                'invoicePiece' => 'FA001',
                'sourceDocumentType' => 6,
                'clientId' => 'CLI001',
                'clientName' => 'Client test',
                'documentDate' => new \DateTimeImmutable('2026-09-10'),
                'amountIncludingTax' => 120.0,
                'hubspotOrderId' => null,
                'errorMessage' => 'Aucune societe HubSpot trouvee pour le client Sage CLI001.',
                'analyzedAt' => new \DateTimeImmutable('2026-10-01 02:15:00'),
                'updatedAt' => new \DateTimeImmutable('2026-10-01 02:15:00'),
            ],
            [
                'piece' => 'FA002',
                'invoicePiece' => 'FA002',
                'sourceDocumentType' => 7,
                'clientId' => 'CLI001',
                'clientName' => 'Client test',
                'documentDate' => new \DateTimeImmutable('2026-09-15'),
                'amountIncludingTax' => 80.0,
                'hubspotOrderId' => null,
                'errorMessage' => 'Erreur API HubSpot [429] : You have reached your secondly limit.',
                'analyzedAt' => new \DateTimeImmutable('2026-10-01 02:16:00'),
                'updatedAt' => new \DateTimeImmutable('2026-10-01 02:16:00'),
            ],
            [
                'piece' => 'FA003',
                'invoicePiece' => 'FA003',
                'sourceDocumentType' => 6,
                'clientId' => null,
                'clientName' => null,
                'documentDate' => null,
                'amountIncludingTax' => 0.0,
                'hubspotOrderId' => null,
                'errorMessage' => 'Erreur API Sage [429] : API calls quota exceeded! maximum admitted 60 per 1m.',
                'analyzedAt' => null,
                'updatedAt' => new \DateTimeImmutable('2026-10-01 02:17:00'),
            ],
        ]);
        $exporter = new DeliveryOrderCorrectionReportExporter(
            $repository,
            new SimpleXlsxWriter(),
            '143807682',
        );

        $report = $exporter->generate();

        self::assertSame(3, $report['failedDocuments']);
        self::assertSame(1, $report['companies']);
        self::assertStringEndsWith('.xlsx', $report['filename']);
        self::assertStringStartsWith('PK', $report['content']);

        $tempPath = tempnam(sys_get_temp_dir(), 'correction_report_');
        self::assertIsString($tempPath);

        try {
            file_put_contents($tempPath, $report['content']);
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($tempPath));
            $workbook = (string) $zip->getFromName('xl/workbook.xml');
            $styles = (string) $zip->getFromName('xl/styles.xml');
            $companiesSheet = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
            $documentsSheet = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
            $companyLinks = (string) $zip->getFromName('xl/worksheets/_rels/sheet2.xml.rels');
            $zip->close();

            self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($workbook));
            self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($styles));
            self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($companiesSheet));
            self::assertInstanceOf(\SimpleXMLElement::class, simplexml_load_string($documentsSheet));
            self::assertStringContainsString('Synthese', $workbook);
            self::assertStringContainsString('Entreprises', $workbook);
            self::assertStringContainsString('Documents', $workbook);
            self::assertStringContainsString('Client test', $companiesSheet);
            self::assertStringContainsString('id_erp', $companiesSheet);
            self::assertGreaterThanOrEqual(2, substr_count($companiesSheet, 'CLI001'));
            self::assertStringContainsString('Quota API Sage atteint', $documentsSheet);
            self::assertStringContainsString('Non identifiee', $documentsSheet);
            self::assertStringContainsString('/objects/0-2/views/all/list', $companyLinks);
        } finally {
            if (is_string($tempPath) && is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }
}
