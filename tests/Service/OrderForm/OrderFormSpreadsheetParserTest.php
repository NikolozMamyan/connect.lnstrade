<?php

namespace App\Tests\Service\OrderForm;

use App\Service\OrderForm\OrderFormSpreadsheetParser;
use PHPUnit\Framework\TestCase;

class OrderFormSpreadsheetParserTest extends TestCase
{
    public function testParseValidWorkbook(): void
    {
        $filePath = $this->createXlsxFile([
            ['Art. ref', 'Description', 'EAN Unit', 'Units par / carton', 'Unit price'],
            ['AR-100', 'Produit A', '1234567890123', '12', '3.50'],
            ['AR-200', '', '', '5', '9,99'],
        ]);

        $parser = new OrderFormSpreadsheetParser();
        $result = $parser->parse($filePath);

        self::assertTrue($result['success']);
        self::assertCount(2, $result['lineItems']);
        self::assertSame('AR-100', $result['lineItems'][0]['articleRef']);
        self::assertSame(12.0, $result['lineItems'][0]['quantity']);
        self::assertSame(9.99, $result['lineItems'][1]['unitPrice']);
    }

    public function testParseFailsWhenRequiredHeaderIsMissing(): void
    {
        $filePath = $this->createXlsxFile([
            ['Description', 'EAN Unit', 'Units par / carton', 'Unit price'],
            ['Produit A', '1234567890123', '12', '3.50'],
        ]);

        $parser = new OrderFormSpreadsheetParser();
        $result = $parser->parse($filePath);

        self::assertFalse($result['success']);
        self::assertNotEmpty($result['errors']);
    }

    public function testParseIgnoresRowsWithoutQuantityOrUnitPrice(): void
    {
        $filePath = $this->createXlsxFile([
            ['Art. ref', 'Description', 'EAN Unit', 'Units par / carton', 'Unit price'],
            ['AR-100', 'Produit A', '1234567890123', '12', '3.50'],
            ['AR-200', 'Produit B', '1234567890124', '', '9.99'],
            ['AR-300', 'Produit C', '1234567890125', '4', ''],
            ['AR-400', 'Produit D', '1234567890126', '0', '8.00'],
            ['AR-500', 'Produit E', '1234567890127', '2', '0'],
        ]);

        $parser = new OrderFormSpreadsheetParser();
        $result = $parser->parse($filePath);

        self::assertTrue($result['success']);
        self::assertCount(1, $result['lineItems']);
        self::assertSame([], $result['failedRows']);
        self::assertSame('AR-100', $result['lineItems'][0]['articleRef']);
    }

    public function testParseIgnoresZeroQuantityButRejectsNegativeQuantity(): void
    {
        $filePath = $this->createXlsxFile([
            ['Art. ref', 'Description', 'EAN Unit', 'Units par / carton', 'Unit price'],
            ['AR-100', 'Produit A', '1234567890123', '12', '3.50'],
            ['AR-200', 'Produit B', '1234567890124', '0', '9.99'],
            ['AR-300', 'Produit C', '1234567890125', '-4', '8.00'],
        ]);

        $parser = new OrderFormSpreadsheetParser();
        $result = $parser->parse($filePath);

        self::assertFalse($result['success']);
        self::assertCount(1, $result['failedRows']);
        self::assertSame(4, $result['failedRows'][0]['rowNumber']);
        self::assertSame(['Units par / carton doit etre positif.'], $result['failedRows'][0]['errors']);
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function createXlsxFile(array $rows): string
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is required for XLSX parser tests.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');

        if ($tempFile === false) {
            self::fail('Unable to create temporary file.');
        }

        $xlsxPath = $tempFile . '.xlsx';
        rename($tempFile, $xlsxPath);

        $sheetRowsXml = '';

        foreach ($rows as $rowIndex => $columns) {
            $sheetRowsXml .= sprintf('<row r="%d">', $rowIndex + 1);

            foreach ($columns as $columnIndex => $value) {
                $cellReference = $this->columnLetters($columnIndex + 1) . ($rowIndex + 1);
                $sheetRowsXml .= sprintf(
                    '<c r="%s" t="inlineStr"><is><t>%s</t></is></c>',
                    $cellReference,
                    htmlspecialchars($value, ENT_XML1)
                );
            }

            $sheetRowsXml .= '</row>';
        }

        $worksheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRowsXml . '</sheetData>'
            . '</worksheet>';

        $zip = new \ZipArchive();
        $zip->open($xlsxPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetXml);
        $zip->close();

        return $xlsxPath;
    }

    private function columnLetters(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $modulo = ($index - 1) % 26;
            $letters = chr(65 + $modulo) . $letters;
            $index = (int) (($index - $modulo) / 26);
        }

        return $letters;
    }
}
