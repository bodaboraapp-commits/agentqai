<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * Reads contact spreadsheets without a third-party Excel package.
 *
 * CSV/TXT use plain fgetcsv. XLSX is a zip of XML — this reads
 * xl/sharedStrings.xml + xl/worksheets/sheet1.xml directly, which covers what
 * a contact export contains (text/number cells). Legacy binary .xls is not
 * supported; users are told to save as .xlsx or .csv.
 *
 * Keeping this dependency-free matters here: vendor/ is committed for shared
 * hosting, and adding PhpSpreadsheet just to read a header row + phone/email
 * columns would be a large, update-fragile payload.
 */
class SpreadsheetReader
{
    /** @var list<string> */
    public const SUPPORTED_EXTENSIONS = ['csv', 'txt', 'xlsx'];

    /** @var list<string> */
    public const ACCEPTED_MIMES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    public static function isExcel(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return in_array($extension, ['xlsx', 'xls'], true);
    }

    public static function isXlsx(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx';
    }

    /**
     * Convert an uploaded XLSX into an UploadedFile whose bytes are CSV, so the
     * existing CSV validation/import pipeline works unchanged. The original
     * filename is preserved for display.
     */
    public static function convertExcelToCsvUpload(UploadedFile $file): UploadedFile
    {
        if (strtolower($file->getClientOriginalExtension()) === 'xls') {
            throw new RuntimeException('Legacy .xls files are not supported. Save the file as .xlsx or .csv and try again.');
        }

        $target = tempnam(sys_get_temp_dir(), 'contact_import_');

        if ($target === false) {
            throw new RuntimeException('Could not prepare a temporary import file.');
        }

        $target .= '.csv';

        (new self)->convertXlsxToCsv($file->getRealPath(), $target);

        return new UploadedFile($target, $file->getClientOriginalName(), 'text/csv', null, true);
    }

    /**
     * Read a bounded preview of a spreadsheet.
     *
     * @return array{headers: list<string>, rows: list<list<string>>, total_rows: int}
     */
    public function preview(UploadedFile $file, int $sampleRows = 5): array
    {
        $path = $file->getRealPath();

        if (self::isXlsx($path)) {
            return $this->previewXlsx($path, $sampleRows);
        }

        return $this->previewCsv($path, $sampleRows);
    }

    /** @return array{headers: list<string>, rows: list<list<string>>, total_rows: int} */
    private function previewCsv(string $path, int $sampleRows): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The file could not be opened for reading.');
        }

        $headers = fgetcsv($handle);
        if ($headers === false || $headers === [null]) {
            fclose($handle);
            throw new RuntimeException('The file is empty or invalid.');
        }

        $headers = array_map(static fn ($h): string => trim((string) $h), $headers);

        $rows = [];
        $totalRows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ($totalRows < $sampleRows) {
                $rows[] = array_map(static fn ($v): string => trim((string) $v), $row);
            }
            $totalRows++;
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows, 'total_rows' => $totalRows];
    }

    /** @return array{headers: list<string>, rows: list<list<string>>, total_rows: int} */
    private function previewXlsx(string $path, int $sampleRows): array
    {
        $sheet = $this->readXlsx($path);

        return [
            'headers' => $sheet['headers'],
            'rows' => array_slice($sheet['rows'], 0, $sampleRows),
            'total_rows' => count($sheet['rows']),
        ];
    }

    public function convertXlsxToCsv(string $source, string $target): void
    {
        $sheet = $this->readXlsx($source);

        $handle = fopen($target, 'w');
        if ($handle === false) {
            throw new RuntimeException('Could not write the converted import file.');
        }

        fputcsv($handle, $sheet['headers']);
        foreach ($sheet['rows'] as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Excel support requires the PHP zip extension, which is not enabled on this server. Save the file as .csv instead.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The Excel file could not be opened. It may be corrupt or password-protected.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheetXml === false) {
            $zip->close();

            throw new RuntimeException('The Excel workbook has no readable first sheet.');
        }

        $zip->close();

        $dom = new \DOMDocument();
        if (! @$dom->loadXML($sheetXml)) {
            throw new RuntimeException('The Excel sheet contents could not be parsed.');
        }

        $rows = [];
        $rowNodes = $dom->getElementsByTagNameNS('*', 'row');

        foreach ($rowNodes as $rowNode) {
            $cells = [];
            $maxIndex = -1;

            foreach ($rowNode->getElementsByTagNameNS('*', 'c') as $cell) {
                $ref = $cell->getAttribute('r');
                $index = $this->columnIndex($ref);
                if ($index === null) {
                    continue;
                }

                $type = $cell->getAttribute('t');
                $value = '';

                if ($type === 's') {
                    $valueNode = $cell->getElementsByTagNameNS('*', 'v')->item(0);
                    $sharedIndex = $valueNode !== null ? (int) $valueNode->nodeValue : -1;
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $this->collectText($cell);
                } else {
                    $valueNode = $cell->getElementsByTagNameNS('*', 'v')->item(0);
                    $value = $valueNode !== null ? (string) $valueNode->nodeValue : '';
                }

                $cells[$index] = trim($value);
                $maxIndex = max($maxIndex, $index);
            }

            $row = [];
            for ($i = 0; $i <= $maxIndex; $i++) {
                $row[] = $cells[$i] ?? '';
            }

            // Skip completely empty trailing rows.
            if (implode('', $row) === '') {
                continue;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('The Excel sheet is empty or invalid.');
        }

        $headers = array_map(static fn ($value): string => trim((string) $value), array_shift($rows));

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @return list<string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $dom = new \DOMDocument();
        if (! @$dom->loadXML($xml)) {
            return [];
        }

        $strings = [];
        foreach ($dom->getElementsByTagNameNS('*', 'si') as $si) {
            $strings[] = $this->collectText($si);
        }

        return $strings;
    }

    private function collectText(\DOMNode $node): string
    {
        $text = '';
        foreach ($node->getElementsByTagNameNS('*', 't') as $textNode) {
            $text .= (string) $textNode->nodeValue;
        }

        return $text;
    }

    /** Convert A1-style column letters to a zero-based index. */
    private function columnIndex(string $reference): ?int
    {
        if ($reference === '') {
            return null;
        }

        if (! preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return null;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
