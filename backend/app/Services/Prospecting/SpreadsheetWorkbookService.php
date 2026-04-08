<?php

namespace App\Services\Prospecting;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ZipArchive;

class SpreadsheetWorkbookService
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, string|null>>}
     */
    public function readRows(string $filePath, ?string $sheetName = null): array
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => $this->readCsvRows($filePath),
            'xlsx' => $this->readXlsxRows($filePath, $sheetName),
            default => throw new InvalidArgumentException('Format de fichier non supporte: ' . $extension),
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    public function writeRows(string $filePath, array $headers, array $rows, ?string $sheetName = null): void
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        match ($extension) {
            'csv' => $this->writeCsvRows($filePath, $headers, $rows),
            'xlsx', '' => $this->writeXlsxRows($filePath, $headers, $rows, $sheetName),
            default => throw new InvalidArgumentException('Format de fichier non supporte: ' . $extension),
        };
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string|null>>}
     */
    private function readCsvRows(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Impossible d\'ouvrir le fichier CSV.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            return ['headers' => [], 'rows' => []];
        }

        $delimiter = $this->detectDelimiter($firstLine);
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, $delimiter);
        if (! is_array($headerRow)) {
            fclose($handle);

            return ['headers' => [], 'rows' => []];
        }

        $headers = array_values(array_map(fn ($value) => $this->cleanHeader((string) $value), $headerRow));
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = array_key_exists($index, $row) ? $this->normalizeCellValue($row[$index]) : null;
            }

            if ($this->isRowEmpty($assoc)) {
                continue;
            }

            $rows[] = $assoc;
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string, string|null>>}
     */
    private function readXlsxRows(string $filePath, ?string $sheetName = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new InvalidArgumentException('Impossible d\'ouvrir le fichier XLSX.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetPath = $this->resolveWorksheetPath($zip, $sheetName);
        $sheetXml = $zip->getFromName($sheetPath);

        if (! is_string($sheetXml) || $sheetXml === '') {
            $zip->close();
            throw new InvalidArgumentException('Feuille XLSX introuvable.');
        }

        $document = new DOMDocument();
        $document->loadXML($sheetXml);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rowNodes = $xpath->query('/main:worksheet/main:sheetData/main:row');
        $matrix = [];

        foreach ($rowNodes ?: [] as $rowNode) {
            if (! $rowNode instanceof DOMElement) {
                continue;
            }

            $rowValues = [];
            $cellNodes = $xpath->query('./main:c', $rowNode);
            foreach ($cellNodes ?: [] as $cellNode) {
                if (! $cellNode instanceof DOMElement) {
                    continue;
                }

                $reference = $cellNode->getAttribute('r');
                $columnLetters = preg_replace('/\d+/', '', $reference) ?: 'A';
                $columnIndex = $this->columnLettersToIndex($columnLetters);
                $rowValues[$columnIndex] = $this->extractXlsxCellValue($cellNode, $xpath, $sharedStrings);
            }

            if ($rowValues !== []) {
                ksort($rowValues);
                $matrix[] = $rowValues;
            }
        }

        $zip->close();

        if ($matrix === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = [];
        foreach ($matrix[0] as $index => $value) {
            $headers[$index] = $this->cleanHeader((string) $value);
        }

        $rows = [];
        foreach (array_slice($matrix, 1) as $rowValues) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                $assoc[$header] = array_key_exists($index, $rowValues) ? $this->normalizeCellValue($rowValues[$index]) : null;
            }

            if ($this->isRowEmpty($assoc)) {
                continue;
            }

            $rows[] = $assoc;
        }

        return ['headers' => array_values($headers), 'rows' => $rows];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsvRows(string $filePath, array $headers, array $rows): void
    {
        File::ensureDirectoryExists(dirname($filePath));

        $handle = fopen($filePath, 'wb');
        if ($handle === false) {
            throw new InvalidArgumentException('Impossible d\'ecrire le fichier CSV.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers, ';');

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = array_key_exists($header, $row) ? (string) ($row[$header] ?? '') : '';
            }
            fputcsv($handle, $line, ';');
        }

        fclose($handle);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeXlsxRows(string $filePath, array $headers, array $rows, ?string $sheetName = null): void
    {
        File::ensureDirectoryExists(dirname($filePath));

        $safeSheetName = $this->sanitizeSheetName($sheetName ?: 'Prospection');
        $allRows = [array_combine($headers, $headers) ?: []];
        foreach ($rows as $row) {
            $allRows[] = $row;
        }

        $sheetRowsXml = '';
        $rowCount = count($allRows);
        $columnCount = max(1, count($headers));

        foreach ($allRows as $rowIndex => $row) {
            $excelRowIndex = $rowIndex + 1;
            $cellsXml = '';

            foreach ($headers as $columnIndex => $header) {
                $columnRef = $this->columnIndexToLetters($columnIndex + 1) . $excelRowIndex;
                $value = array_key_exists($header, $row) ? (string) ($row[$header] ?? '') : '';
                $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $cellsXml .= sprintf('<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $columnRef, $escaped);
            }

            $sheetRowsXml .= sprintf('<row r="%d">%s</row>', $excelRowIndex, $cellsXml);
        }

        $lastCell = $this->columnIndexToLetters($columnCount) . $rowCount;
        $sheetXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <dimension ref="A1:{$lastCell}"/>
  <sheetViews><sheetView workbookViewId="0"/></sheetViews>
  <sheetFormatPr defaultRowHeight="15"/>
  <sheetData>{$sheetRowsXml}</sheetData>
</worksheet>
XML;

        $zip = new ZipArchive();
        if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new InvalidArgumentException('Impossible de creer le fichier XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML);

        $zip->addFromString('_rels/.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML);

        $zip->addFromString('docProps/app.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Helix</Application>
</Properties>
XML);

        $zip->addFromString('docProps/core.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:creator>Helix</dc:creator>
  <cp:lastModifiedBy>Helix</cp:lastModifiedBy>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$this->nowIso()}</dcterms:created>
  <dcterms:modified xsi:type="dcterms:W3CDTF">{$this->nowIso()}</dcterms:modified>
</cp:coreProperties>
XML);

        $zip->addFromString('xl/workbook.xml', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="{$safeSheetName}" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);

        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
    }

    private function detectDelimiter(string $line): string
    {
        $delimiters = [',', ';', "\t"];
        $counts = [];

        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($line, $delimiter);
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function cleanHeader(string $header): string
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $clean = trim($clean);

        return $clean !== '' ? $clean : 'column_' . uniqid();
    }

    private function normalizeCellValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function extractXlsxCellValue(DOMElement $cellNode, DOMXPath $xpath, array $sharedStrings): string
    {
        $type = $cellNode->getAttribute('t');

        if ($type === 'inlineStr') {
            $texts = [];
            foreach ($xpath->query('.//main:is//main:t', $cellNode) ?: [] as $textNode) {
                $texts[] = $textNode->textContent;
            }

            return implode('', $texts);
        }

        $valueNode = $xpath->query('./main:v', $cellNode)?->item(0);
        $value = $valueNode?->textContent ?? '';

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        if ($type === 'b') {
            return $value === '1' ? 'true' : 'false';
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $document = new DOMDocument();
        $document->loadXML($xml);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($xpath->query('/main:sst/main:si') ?: [] as $stringNode) {
            $parts = [];
            foreach ($xpath->query('.//main:t', $stringNode) ?: [] as $textNode) {
                $parts[] = $textNode->textContent;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function resolveWorksheetPath(ZipArchive $zip, ?string $sheetName): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbookXml) || ! is_string($relsXml)) {
            return 'xl/worksheets/sheet1.xml';
        }

        $relsDocument = new DOMDocument();
        $relsDocument->loadXML($relsXml);
        $relsXpath = new DOMXPath($relsDocument);
        $relsXpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $relationships = [];
        foreach ($relsXpath->query('/rel:Relationships/rel:Relationship') ?: [] as $relationshipNode) {
            if (! $relationshipNode instanceof DOMElement) {
                continue;
            }
            $relationships[$relationshipNode->getAttribute('Id')] = $relationshipNode->getAttribute('Target');
        }

        $workbookDocument = new DOMDocument();
        $workbookDocument->loadXML($workbookXml);
        $workbookXpath = new DOMXPath($workbookDocument);
        $workbookXpath->registerNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbookXpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $selectedTarget = null;
        foreach ($workbookXpath->query('/main:workbook/main:sheets/main:sheet') ?: [] as $sheetNode) {
            if (! $sheetNode instanceof DOMElement) {
                continue;
            }

            $name = $sheetNode->getAttribute('name');
            $relationshipId = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $relationships[$relationshipId] ?? null;
            if (! is_string($target) || $target === '') {
                continue;
            }

            if ($sheetName === null || $sheetName === '' || $name === $sheetName) {
                $selectedTarget = $target;
                break;
            }
        }

        if (! is_string($selectedTarget) || $selectedTarget === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        return str_starts_with($selectedTarget, 'xl/') ? $selectedTarget : 'xl/' . ltrim($selectedTarget, '/');
    }

    private function sanitizeSheetName(string $sheetName): string
    {
        $clean = preg_replace('/[\[\]\:\*\?\/\\\\]/', '-', $sheetName) ?? $sheetName;
        $clean = trim($clean);

        return mb_substr($clean !== '' ? $clean : 'Prospection', 0, 31);
    }

    private function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;

        foreach (str_split($letters) as $char) {
            $index = ($index * 26) + (ord($char) - 64);
        }

        return $index;
    }

    private function columnIndexToLetters(int $index): string
    {
        $letters = '';

        while ($index > 0) {
            $modulo = ($index - 1) % 26;
            $letters = chr(65 + $modulo) . $letters;
            $index = (int) floor(($index - 1) / 26);
        }

        return $letters;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    private function isRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    private function nowIso(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
