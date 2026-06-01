<?php

namespace App\Service\OrderForm;

class OrderFormSpreadsheetParser
{
    private const REQUIRED_HEADERS = [
        'articleRef' => 'Art. ref',
        'quantity' => 'Units par / carton',
        'unitPrice' => 'Unit price',
    ];

    private const OPTIONAL_HEADERS = [
        'description' => 'Description',
        'eanUnit' => 'EAN Unit',
    ];

    /**
     * @return array{
     *   success: bool,
     *   lineItems: array<int, array<string, mixed>>,
     *   errors: array<int, array<string, mixed>>,
     *   failedRows: array<int, array<string, mixed>>
     * }
     */
    public function parse(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension !== 'xlsx') {
            return [
                'success' => false,
                'lineItems' => [],
                'errors' => [[
                    'rowNumber' => 0,
                    'field' => 'file',
                    'message' => 'Le traitement automatique prend uniquement en charge les fichiers .xlsx.',
                ]],
                'failedRows' => [],
            ];
        }

        if (!class_exists(\ZipArchive::class)) {
            return [
                'success' => false,
                'lineItems' => [],
                'errors' => [[
                    'rowNumber' => 0,
                    'field' => 'file',
                    'message' => 'Le support ZIP requis pour lire les fichiers .xlsx est indisponible.',
                ]],
                'failedRows' => [],
            ];
        }

        $workbook = $this->readWorkbookRows($filePath);

        if ($workbook['rows'] === []) {
            return [
                'success' => false,
                'lineItems' => [],
                'errors' => [[
                    'rowNumber' => 0,
                    'field' => 'file',
                    'message' => 'Le fichier Excel est vide ou illisible.',
                ]],
                'failedRows' => [],
            ];
        }

        $headerRowNumber = $this->findHeaderRowNumber($workbook['rows']);
        $resolvedHeaders = $this->resolveHeaders($workbook['rows'][$headerRowNumber] ?? []);
        $headerErrors = $this->validateHeaders($resolvedHeaders);

        if ($headerErrors !== []) {
            return [
                'success' => false,
                'lineItems' => [],
                'errors' => $headerErrors,
                'failedRows' => [],
            ];
        }

        $lineItems = [];
        $failedRows = [];

        foreach ($workbook['rows'] as $rowNumber => $rowValues) {
            if ($rowNumber <= $headerRowNumber || $this->rowIsEmpty($rowValues)) {
                continue;
            }

            $mappedRow = $this->mapLineItemRow($rowValues, $resolvedHeaders, $rowNumber);

            if (($mappedRow['ignored'] ?? false) === true) {
                continue;
            }

            if (isset($mappedRow['errors'])) {
                $failedRows[] = $mappedRow;
                continue;
            }

            $lineItems[] = $mappedRow;
        }

        if ($lineItems === []) {
            return [
                'success' => false,
                'lineItems' => [],
                'errors' => [[
                    'rowNumber' => 0,
                    'field' => 'rows',
                    'message' => 'Aucune ligne exploitable n a ete detectee dans le fichier.',
                ]],
                'failedRows' => $failedRows,
            ];
        }

        if ($failedRows !== []) {
            return [
                'success' => false,
                'lineItems' => [],
                'errors' => [[
                    'rowNumber' => 0,
                    'field' => 'rows',
                    'message' => sprintf('%d ligne(s) sont invalides dans le fichier.', count($failedRows)),
                ]],
                'failedRows' => $failedRows,
            ];
        }

        return [
            'success' => true,
            'lineItems' => $lineItems,
            'errors' => [],
            'failedRows' => [],
        ];
    }

    /**
     * @return array{rows: array<int, array<int, string>>}
     */
    private function readWorkbookRows(string $filePath): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($filePath) !== true) {
            return ['rows' => []];
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $worksheetPath = $this->resolveFirstWorksheetPath($zip);

        if ($worksheetPath === null) {
            $zip->close();

            return ['rows' => []];
        }

        $worksheetContent = $zip->getFromName($worksheetPath);
        $zip->close();

        if (!is_string($worksheetContent) || trim($worksheetContent) === '') {
            return ['rows' => []];
        }

        $xml = @simplexml_load_string($worksheetContent);

        if (!$xml instanceof \SimpleXMLElement) {
            return ['rows' => []];
        }

        $rows = [];

        foreach ($xml->sheetData->row ?? [] as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);
            $cells = [];

            foreach ($row->c ?? [] as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = $this->columnLettersToIndex(preg_replace('/\d+/', '', $reference) ?: 'A');
                $cells[$columnIndex] = $this->extractCellValue($cell, $sharedStrings);
            }

            ksort($cells);
            $rows[$rowNumber] = $cells;
        }

        ksort($rows);

        return ['rows' => $rows];
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');

        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        $xml = @simplexml_load_string($content);

        if (!$xml instanceof \SimpleXMLElement) {
            return [];
        }

        $strings = [];

        foreach ($xml->si ?? [] as $sharedString) {
            $value = '';

            if (isset($sharedString->t)) {
                $value = (string) $sharedString->t;
            } else {
                foreach ($sharedString->r ?? [] as $run) {
                    $value .= (string) ($run->t ?? '');
                }
            }

            $strings[] = trim($value);
        }

        return $strings;
    }

    private function resolveFirstWorksheetPath(\ZipArchive $zip): ?string
    {
        $workbookContent = $zip->getFromName('xl/workbook.xml');
        $relationsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (!is_string($workbookContent) || !is_string($relationsContent)) {
            return null;
        }

        $workbookXml = @simplexml_load_string($workbookContent);
        $relationsXml = @simplexml_load_string($relationsContent);

        if (!$workbookXml instanceof \SimpleXMLElement || !$relationsXml instanceof \SimpleXMLElement) {
            return null;
        }

        $namespaces = $relationsXml->getNamespaces(true);
        $workbookXml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $relationsXml->registerXPathNamespace('rel', $namespaces[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships');

        $sheetNodes = $workbookXml->xpath('//main:sheets/main:sheet');

        if ($sheetNodes === false || $sheetNodes === []) {
            return null;
        }

        $relationshipId = (string) ($sheetNodes[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');

        if ($relationshipId === '') {
            return null;
        }

        $relationNodes = $relationsXml->xpath(sprintf('//rel:Relationship[@Id="%s"]', $relationshipId));

        if ($relationNodes === false || $relationNodes === []) {
            return null;
        }

        $target = (string) ($relationNodes[0]['Target'] ?? '');

        if ($target === '') {
            return null;
        }

        return str_starts_with($target, 'xl/') ? $target : 'xl/' . ltrim($target, '/');
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function findHeaderRowNumber(array $rows): int
    {
        foreach ($rows as $rowNumber => $rowValues) {
            if (!$this->rowIsEmpty($rowValues)) {
                return $rowNumber;
            }
        }

        return 1;
    }

    /**
     * @param array<int, string> $headerValues
     *
     * @return array<string, int>
     */
    private function resolveHeaders(array $headerValues): array
    {
        $resolved = [];

        foreach ($headerValues as $columnIndex => $headerValue) {
            $normalized = $this->normalizeHeader($headerValue);

            foreach (self::REQUIRED_HEADERS + self::OPTIONAL_HEADERS as $field => $label) {
                if ($normalized === $this->normalizeHeader($label)) {
                    $resolved[$field] = $columnIndex;
                }
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, int> $resolvedHeaders
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateHeaders(array $resolvedHeaders): array
    {
        $errors = [];

        foreach (self::REQUIRED_HEADERS as $field => $label) {
            if (!isset($resolvedHeaders[$field])) {
                $errors[] = [
                    'rowNumber' => 1,
                    'field' => $field,
                    'message' => sprintf('La colonne obligatoire "%s" est absente.', $label),
                ];
            }
        }

        return $errors;
    }

    /**
     * @param array<int, string> $rowValues
     * @param array<string, int> $resolvedHeaders
     *
     * @return array<string, mixed>
     */
    private function mapLineItemRow(array $rowValues, array $resolvedHeaders, int $rowNumber): array
    {
        $articleRef = trim((string) ($rowValues[$resolvedHeaders['articleRef']] ?? ''));
        $quantityValue = trim((string) ($rowValues[$resolvedHeaders['quantity']] ?? ''));
        $unitPriceValue = trim((string) ($rowValues[$resolvedHeaders['unitPrice']] ?? ''));

        if (
            $quantityValue === ''
            || $unitPriceValue === ''
            || ($this->isNumericString($quantityValue) && $this->toFloat($quantityValue) === 0.0)
            || ($this->isNumericString($unitPriceValue) && $this->toFloat($unitPriceValue) <= 0.0)
        ) {
            return [
                'rowNumber' => $rowNumber,
                'values' => $rowValues,
                'ignored' => true,
            ];
        }

        $errors = [];

        if ($articleRef === '') {
            $errors[] = 'Art. ref manquant.';
        }

        if (!$this->isNumericString($quantityValue)) {
            $errors[] = 'Units par / carton invalide.';
        } elseif ($this->toFloat($quantityValue) < 0.0) {
            $errors[] = 'Units par / carton doit etre positif.';
        }

        if (!$this->isNumericString($unitPriceValue)) {
            $errors[] = 'Unit price invalide.';
        }

        if ($errors !== []) {
            return [
                'rowNumber' => $rowNumber,
                'values' => $rowValues,
                'errors' => $errors,
            ];
        }

        $quantity = $this->toFloat($quantityValue);
        $unitPrice = $this->toFloat($unitPriceValue);

        return [
            'rowNumber' => $rowNumber,
            'articleRef' => $articleRef,
            'description' => $this->nullableString($rowValues[$resolvedHeaders['description']] ?? null),
            'eanUnit' => $this->nullableString($rowValues[$resolvedHeaders['eanUnit']] ?? null),
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'lineTotal' => $quantity * $unitPrice,
            'rawPayload' => $rowValues,
        ];
    }

    private function extractCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 's') {
            $index = (int) ($cell->v ?? 0);

            return trim((string) ($sharedStrings[$index] ?? ''));
        }

        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        return trim((string) ($cell->v ?? ''));
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;

        for ($i = 0, $length = strlen($letters); $i < $length; ++$i) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index;
    }

    /**
     * @param array<int, string> $rowValues
     */
    private function rowIsEmpty(array $rowValues): bool
    {
        foreach ($rowValues as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['.', '/', '\\', '-', '_'], ' ', $value);
        $value = preg_replace('/\s+/', '', $value) ?? '';
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isNumericString(string $value): bool
    {
        $normalized = str_replace(',', '.', trim($value));

        return $normalized !== '' && is_numeric($normalized);
    }

    private function toFloat(string $value): float
    {
        return (float) str_replace(',', '.', trim($value));
    }
}
