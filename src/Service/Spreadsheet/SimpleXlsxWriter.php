<?php

namespace App\Service\Spreadsheet;

final class SimpleXlsxWriter
{
    private const STYLE_IDS = [
        'default' => 0,
        'header' => 1,
        'title' => 2,
        'label' => 3,
        'wrap' => 4,
        'error' => 5,
        'warning' => 6,
        'link' => 7,
        'number' => 8,
        'success' => 9,
    ];

    /**
     * @param list<array{
     *   name: string,
     *   rows: list<list<mixed>>,
     *   widths?: list<int|float>,
     *   freezeRows?: int,
     *   filterRow?: int,
     *   merges?: list<string>
     * }> $sheets
     */
    public function build(array $sheets): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('L extension PHP ZIP est requise pour generer le rapport Excel.');
        }

        if ($sheets === []) {
            throw new \InvalidArgumentException('Le classeur doit contenir au moins une feuille.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'lns_xlsx_');

        if ($tempPath === false) {
            throw new \RuntimeException('Impossible de creer le fichier Excel temporaire.');
        }

        try {
            $zip = new \ZipArchive();

            if ($zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Impossible d initialiser le fichier Excel.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($sheets)));
            $zip->addFromString('xl/styles.xml', $this->styles());

            foreach ($sheets as $index => $sheet) {
                [$worksheetXml, $relationshipsXml] = $this->worksheet($sheet);
                $sheetNumber = $index + 1;
                $zip->addFromString(sprintf('xl/worksheets/sheet%d.xml', $sheetNumber), $worksheetXml);

                if ($relationshipsXml !== null) {
                    $zip->addFromString(
                        sprintf('xl/worksheets/_rels/sheet%d.xml.rels', $sheetNumber),
                        $relationshipsXml,
                    );
                }
            }

            $zip->close();
            $content = file_get_contents($tempPath);

            if (!is_string($content) || $content === '') {
                throw new \RuntimeException('Le rapport Excel genere est vide.');
            }

            return $content;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function contentTypes(int $sheetCount): string
    {
        $worksheets = '';

        for ($index = 1; $index <= $sheetCount; ++$index) {
            $worksheets .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$worksheets
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param list<array{name: string}> $sheets
     */
    private function workbook(array $sheets): string
    {
        $sheetXml = '';

        foreach ($sheets as $index => $sheet) {
            $sheetXml .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->escape(mb_substr($sheet['name'], 0, 31)),
                $index + 1,
                $index + 1,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="14000"/></bookViews>'
            .'<sheets>'.$sheetXml.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/>'
            .'</workbook>';
    }

    private function workbookRelationships(int $sheetCount): string
    {
        $relationships = '';

        for ($index = 1; $index <= $sheetCount; ++$index) {
            $relationships .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index,
                $index,
            );
        }

        $relationships .= sprintf(
            '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
            $sheetCount + 1,
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'</Relationships>';
    }

    /**
     * @param array{
     *   rows: list<list<mixed>>,
     *   widths?: list<int|float>,
     *   freezeRows?: int,
     *   filterRow?: int,
     *   merges?: list<string>
     * } $sheet
     *
     * @return array{0: string, 1: string|null}
     */
    private function worksheet(array $sheet): array
    {
        $rowsXml = '';
        $hyperlinks = [];
        $relationships = [];
        $maxColumns = 1;

        foreach ($sheet['rows'] as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $maxColumns = max($maxColumns, count($row));
            $cellsXml = '';

            foreach ($row as $columnIndex => $rawCell) {
                $cell = is_array($rawCell) && array_key_exists('value', $rawCell)
                    ? $rawCell
                    : ['value' => $rawCell];
                $reference = $this->columnLetters($columnIndex + 1).$rowNumber;
                $style = self::STYLE_IDS[(string) ($cell['style'] ?? 'default')] ?? self::STYLE_IDS['default'];
                $value = $cell['value'] ?? '';

                if (($cell['type'] ?? null) === 'number' && is_numeric($value)) {
                    $cellsXml .= sprintf(
                        '<c r="%s" s="%d" t="n"><v>%s</v></c>',
                        $reference,
                        $style,
                        $this->escape((string) $value),
                    );
                } else {
                    $text = mb_substr($this->cleanText((string) $value), 0, 32767);
                    $cellsXml .= sprintf(
                        '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
                        $reference,
                        $style,
                        $this->escape($text),
                    );
                }

                $url = trim((string) ($cell['url'] ?? ''));

                if ($url !== '') {
                    $relationshipId = 'rId'.(count($relationships) + 1);
                    $hyperlinks[] = sprintf('<hyperlink ref="%s" r:id="%s"/>', $reference, $relationshipId);
                    $relationships[] = sprintf(
                        '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="%s" TargetMode="External"/>',
                        $relationshipId,
                        $this->escape($url),
                    );
                }
            }

            $rowsXml .= sprintf('<row r="%d">%s</row>', $rowNumber, $cellsXml);
        }

        $columnsXml = '';

        foreach ($sheet['widths'] ?? [] as $index => $width) {
            $columnsXml .= sprintf(
                '<col min="%d" max="%d" width="%s" customWidth="1"/>',
                $index + 1,
                $index + 1,
                max(4, min(80, (float) $width)),
            );
        }

        $freezeRows = max(0, (int) ($sheet['freezeRows'] ?? 0));
        $sheetViews = '<sheetViews><sheetView workbookViewId="0">';

        if ($freezeRows > 0) {
            $sheetViews .= sprintf(
                '<pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A%d" sqref="A%d"/>',
                $freezeRows,
                $freezeRows + 1,
                $freezeRows + 1,
                $freezeRows + 1,
            );
        }

        $sheetViews .= '</sheetView></sheetViews>';
        $mergeXml = '';
        $merges = $sheet['merges'] ?? [];

        if ($merges !== []) {
            $mergeXml = sprintf('<mergeCells count="%d">', count($merges));

            foreach ($merges as $merge) {
                $mergeXml .= sprintf('<mergeCell ref="%s"/>', $this->escape($merge));
            }

            $mergeXml .= '</mergeCells>';
        }

        $filterXml = '';
        $filterRow = (int) ($sheet['filterRow'] ?? 0);

        if ($filterRow > 0 && count($sheet['rows']) >= $filterRow) {
            $filterXml = sprintf(
                '<autoFilter ref="A%d:%s%d"/>',
                $filterRow,
                $this->columnLetters($maxColumns),
                count($sheet['rows']),
            );
        }

        $hyperlinksXml = $hyperlinks !== [] ? '<hyperlinks>'.implode('', $hyperlinks).'</hyperlinks>' : '';
        $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .$sheetViews
            .'<sheetFormatPr defaultRowHeight="18"/>'
            .($columnsXml !== '' ? '<cols>'.$columnsXml.'</cols>' : '')
            .'<sheetData>'.$rowsXml.'</sheetData>'
            .$filterXml.$mergeXml.$hyperlinksXml
            .'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            .'</worksheet>';
        $relationshipsXml = $relationships !== []
            ? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .implode('', $relationships)
                .'</Relationships>'
            : null;

        return [$worksheetXml, $relationshipsXml];
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="5">'
            .'<font><sz val="11"/><color rgb="FF24364B"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="20"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF102A43"/><name val="Calibri"/></font>'
            .'<font><u/><sz val="11"/><color rgb="FF1565C0"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="8">'
            .'<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF236B8E"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF102A43"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2F8"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFDECEA"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4D6"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F7F1"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"><color rgb="FFD8E2EA"/></left><right style="thin"><color rgb="FFD8E2EA"/></right><top style="thin"><color rgb="FFD8E2EA"/></top><bottom style="thin"><color rgb="FFD8E2EA"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="10">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function cleanText(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function columnLetters(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $modulo = ($index - 1) % 26;
            $letters = chr(65 + $modulo).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
