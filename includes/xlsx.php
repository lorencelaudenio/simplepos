<?php
declare(strict_types=1);

/** Minimal XLSX reader/writer for imports and exports (uses PHP's ZipArchive, no Composer needed). */

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function xlsx_column_index(string $reference): int
{
    if (!preg_match('/^([A-Za-z]+)/', $reference, $matches)) {
        return 0;
    }
    $index = 0;
    foreach (str_split(strtoupper($matches[1])) as $char) {
        $index = $index * 26 + (ord($char) - 64);
    }
    return max(0, $index - 1);
}

function xlsx_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function xlsx_shared_strings(ZipArchive $zip): array
{
    $path = 'xl/sharedStrings.xml';
    if ($zip->locateName($path) === false) {
        return [];
    }
    $xml = @simplexml_load_string((string)$zip->getFromName($path));
    if ($xml === false) {
        return [];
    }
    $xml->registerXPathNamespace('x', XLSX_NS);
    $strings = [];
    foreach ($xml->xpath('//x:si') ?: [] as $item) {
        $item->registerXPathNamespace('x', XLSX_NS);
        $text = '';
        foreach ($item->xpath('.//x:t') ?: [] as $node) {
            $text .= (string)$node;
        }
        $strings[] = $text;
    }
    return $strings;
}

function xlsx_sheet_path(ZipArchive $zip): ?string
{
    $candidate = 'xl/worksheets/sheet1.xml';
    if ($zip->locateName($candidate) !== false) {
        return $candidate;
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
            return $name;
        }
    }
    return null;
}

/** Returns rows of cell strings, indexed from zero. */
function xlsx_read_rows(string $path, int $maxRows = 5000): array
{
    if (!class_exists('ZipArchive')) {
        fail('The PHP ZipArchive extension is required for Excel import/export.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fail('The uploaded file is not a valid .xlsx workbook.');
    }
    $sheetPath = xlsx_sheet_path($zip);
    if ($sheetPath === null) {
        $zip->close();
        fail('The workbook does not contain a readable worksheet.');
    }
    $shared = xlsx_shared_strings($zip);
    $xml = @simplexml_load_string((string)$zip->getFromName($sheetPath));
    $zip->close();
    if ($xml === false) {
        fail('The worksheet could not be parsed.');
    }
    $xml->registerXPathNamespace('x', XLSX_NS);
    $rows = [];
    $rowCount = 0;
    foreach ($xml->xpath('//x:sheetData/x:row') ?: [] as $rowNode) {
        if ($rowCount++ >= $maxRows) {
            break;
        }
        $rowNode->registerXPathNamespace('x', XLSX_NS);
        $cells = [];
        foreach ($rowNode->xpath('./x:c') ?: [] as $cell) {
            $cell->registerXPathNamespace('x', XLSX_NS);
            $reference = (string)$cell['r'];
            $col = xlsx_column_index($reference);
            $type = (string)$cell['t'];
            $value = '';
            if ($type === 'inlineStr') {
                foreach ($cell->xpath('.//x:t') ?: [] as $node) {
                    $value .= (string)$node;
                }
            } else {
                $valueNodes = $cell->xpath('./x:v');
                $raw = isset($valueNodes[0]) ? (string)$valueNodes[0] : '';
                if ($type === 's') {
                    $value = $shared[(int)$raw] ?? '';
                } elseif ($type === 'b') {
                    $value = $raw === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $value = $raw;
                }
            }
            $cells[$col] = trim($value);
        }
        if ($cells) {
            $lastCol = max(array_keys($cells));
            for ($i = 0; $i <= $lastCol; $i++) {
                $cells[$i] ??= '';
            }
            ksort($cells);
            $rows[] = array_values($cells);
        }
    }
    return $rows;
}

function xlsx_send(string $filename, array $headers, array $rows): void
{
    if (!class_exists('ZipArchive')) {
        fail('The PHP ZipArchive extension is required for Excel export.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'posexport');
    if ($tmp === false) {
        fail('Unable to create a temporary export file.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        fail('Unable to build the Excel file.');
    }

    $escape = 'xlsx_xml_escape';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . XLSX_NS . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="' . XLSX_NS . '"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '</styleSheet>';

    $allRows = array_merge([$headers], $rows);
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . XLSX_NS . '"><sheetData>';
    $rowNumber = 1;
    foreach ($allRows as $row) {
        $sheet .= '<row r="' . $rowNumber . '">';
        $colNumber = 0;
        foreach ($row as $cell) {
            $colLetters = '';
            $n = $colNumber + 1;
            while ($n > 0) {
                $remainder = ($n - 1) % 26;
                $colLetters = chr(65 + $remainder) . $colLetters;
                $n = intdiv($n - 1, 26);
            }
            $text = is_scalar($cell) ? (string)$cell : '';
            $sheet .= '<c r="' . $colLetters . $rowNumber . '" t="inlineStr"><is><t xml:space="preserve">'
                . $escape($text) . '</t></is></c>';
            $colNumber++;
        }
        $sheet .= '</row>';
        $rowNumber++;
    }
    $sheet .= '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    // Clean any previous output buffers so the download is never corrupted.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
