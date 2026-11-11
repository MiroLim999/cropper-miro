<?php
// ── HTR Dataset Preparation (paths) ─────────────────────────────────────────
$outputDir = __DIR__ . '/output/';          // word crops saved from the cropper UI
$htrDir = __DIR__ . '/htr_dataset/';     // batch-processed, HTR-ready dataset
$htrImageDir = $htrDir . 'images/';
$htrExcelRel = 'htr_dataset/dataset_labels.xlsx';
$htrExcelAbs = __DIR__ . '/' . $htrExcelRel;

if (!is_dir($outputDir))
    mkdir($outputDir, 0777, true);
if (!is_dir($htrImageDir))
    mkdir($htrImageDir, 0777, true);

/**
 * Normalizes a single saved word-crop so it's ready to feed into an HTR
 * model: grayscale, auto-trimmed to its ink bounding box, contrast-stretched,
 * then resized to a fixed height (preserving aspect ratio) with a small
 * clean margin — the standard input shape used by CRNN/Transformer HTR
 * architectures (e.g. IAM-style word strips).
 */
function prepareImageForHTR(\GdImage $src, int $targetHeight = 64, int $margin = 6): ?\GdImage
{
    imagefilter($src, IMG_FILTER_GRAYSCALE);

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 2 || $h < 2) {
        return null;
    }

    // Auto-trim: find the bounding box of "ink" pixels (darker than threshold)
    $threshold = 200; // 0-255; pixels darker than this count as ink
    $minX = $w;
    $minY = $h;
    $maxX = 0;
    $maxY = 0;
    $found = false;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $gray = imagecolorat($src, $x, $y) & 0xFF; // R=G=B after grayscale filter
            if ($gray < $threshold) {
                $found = true;
                if ($x < $minX)
                    $minX = $x;
                if ($x > $maxX)
                    $maxX = $x;
                if ($y < $minY)
                    $minY = $y;
                if ($y > $maxY)
                    $maxY = $y;
            }
        }
    }

    if (!$found) {
        $minX = 0;
        $minY = 0;
        $maxX = $w - 1;
        $maxY = $h - 1;
    }

    $cropW = $maxX - $minX + 1;
    $cropH = $maxY - $minY + 1;

    $trimmed = imagecreatetruecolor($cropW, $cropH);
    $white = imagecolorallocate($trimmed, 255, 255, 255);
    imagefill($trimmed, 0, 0, $white);
    imagecopy($trimmed, $src, 0, 0, $minX, $minY, $cropW, $cropH);

    // Stretch contrast so ink reads darker and background reads brighter,
    // which keeps crops from many different scans/lighting consistent.
    imagefilter($trimmed, IMG_FILTER_CONTRAST, -20);

    // Resize to a fixed height, preserving aspect ratio (variable width is
    // expected/required by sequence-based HTR models).
    $scale = $targetHeight / $cropH;
    $newW = max(1, (int) round($cropW * $scale));
    $newH = $targetHeight;

    $resized = imagecreatetruecolor($newW, $newH);
    $whiteR = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $whiteR);
    imagecopyresampled($resized, $trimmed, 0, 0, 0, 0, $newW, $newH, $cropW, $cropH);
    imagedestroy($trimmed);

    // Small white margin on every side (standard practice for CTC/CRNN HTR
    // inputs, avoids clipping ascenders/descenders at the image edge).
    $finalW = $newW + $margin * 2;
    $finalH = $newH + $margin * 2;
    $final = imagecreatetruecolor($finalW, $finalH);
    $whiteF = imagecolorallocate($final, 255, 255, 255);
    imagefill($final, 0, 0, $whiteF);
    imagecopy($final, $resized, $margin, $margin, 0, 0, $newW, $newH);
    imagedestroy($resized);

    return $final;
}

/** manifest.json = the annotation state of the dataset (one entry per image). */
function htrManifestPath(): string
{
    return __DIR__ . '/htr_dataset/manifest.json';
}

function htrLoadManifest(): array
{
    $p = htrManifestPath();
    if (!is_file($p)) return [];
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function htrSaveManifest(array $items): void
{
    $tmp = htrManifestPath() . '.tmp';
    $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('manifest could not be encoded');   // never overwrite it with garbage
    }
    file_put_contents($tmp, $json);
    rename($tmp, htrManifestPath());
}

/**
 * Writes the label files used for training from the annotation state:
 *   dataset_labels.xlsx  - spreadsheet (ID, image, path, size, transcription, status)
 *   labels.csv           - image,text   (UTF-8)
 *   labels.tsv           - "images/htr_0001.png<TAB>text"  (the common HTR layout)
 * Only images that were labelled (not skipped as unreadable) go into csv/tsv.
 */
function htrExportLabels(array $items): array
{
    $dir = __DIR__ . '/htr_dataset/';
    writeXlsx(
        $dir . 'dataset_labels.xlsx',
        'HTR Dataset',
        ['ID', 'Image Filename', 'Relative Path', 'Width', 'Height', 'Transcription', 'Status'],
        array_map(fn($r) => [
            $r['id'], $r['processed_filename'], $r['relative_path'], $r['width'], $r['height'],
            $r['transcription'], $r['status'],
        ], $items)
    );

    $csv = fopen($dir . 'labels.csv', 'w');
    fwrite($csv, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
    fputcsv($csv, ['image', 'text']);
    $tsv = '';
    $n = 0;
    foreach ($items as $r) {
        if ($r['status'] !== 'labeled' || trim($r['transcription']) === '') continue;
        fputcsv($csv, [$r['processed_filename'], $r['transcription']]);
        $tsv .= 'images/' . $r['processed_filename'] . "\t" . str_replace(["\t", "\r", "\n"], ' ', $r['transcription']) . "\n";
        $n++;
    }
    fclose($csv);
    file_put_contents($dir . 'labels.tsv', $tsv);
    return ['labeled' => $n];
}

/**
 * Batch-processes every verified word image in output/ into HTR-ready training
 * data (see prepareImageForHTR) saved under htr_dataset/images/ and registers them in
 * manifest.json for annotation. Re-running keeps the labels already typed: an image is
 * recognised by its original file name, so nothing has to be annotated twice.
 *
 * @return array{success:bool, processed:int, skipped:int, new:int, manifest:array}
 */
function batchProcessForHTR(string $outputDir, string $htrImageDir, string $htrExcelAbs, int $targetHeight = 64): array
{
    $allowedExt = ['png', 'jpg', 'jpeg', 'webp'];
    $files = glob($outputDir . '*') ?: [];
    natsort($files);

    $old = [];
    $maxId = 0;
    foreach (htrLoadManifest() as $r) {
        $old[$r['original_filename']] = $r;
        $maxId = max($maxId, (int) $r['id']);
    }

    $manifest = [];
    $processed = 0;
    $skipped = 0;
    $new = 0;
    $seen = [];

    foreach ($files as $path) {
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) { $skipped++; continue; }

        $orig = basename($path);
        $seen[$orig] = true;

        // already prepared (and maybe labelled): keep it as it is
        if (isset($old[$orig]) && is_file($htrImageDir . $old[$orig]['processed_filename'])) {
            $manifest[] = $old[$orig];
            $processed++;
            continue;
        }

        $mime = mime_content_type($path);
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => null,
        };
        if (!$src) { $skipped++; continue; }

        $prepared = prepareImageForHTR($src, $targetHeight);
        imagedestroy($src);
        if (!$prepared) { $skipped++; continue; }

        $id = isset($old[$orig]) ? (int) $old[$orig]['id'] : ++$maxId;
        $newFilename = sprintf('htr_%04d.png', $id);
        imagepng($prepared, $htrImageDir . $newFilename, 6);
        $finalW = imagesx($prepared);
        $finalH = imagesy($prepared);
        imagedestroy($prepared);

        $manifest[] = [
            'id' => $id,
            'original_filename' => $orig,
            'processed_filename' => $newFilename,
            'relative_path' => 'htr_dataset/images/' . $newFilename,
            'width' => $finalW,
            'height' => $finalH,
            'transcription' => $old[$orig]['transcription'] ?? '',
            'status' => $old[$orig]['status'] ?? 'pending',   // pending | labeled | unreadable
        ];
        $processed++;
        $new++;
    }

    // images whose source left output/: keep them only if they already carry work
    foreach ($old as $orig => $r) {
        if (isset($seen[$orig])) continue;
        if ($r['status'] !== 'pending' && is_file($htrImageDir . $r['processed_filename'])) {
            $manifest[] = $r;
        } elseif (is_file($htrImageDir . $r['processed_filename'])) {
            @unlink($htrImageDir . $r['processed_filename']);
        }
    }

    usort($manifest, fn($a, $b) => $a['id'] <=> $b['id']);
    htrSaveManifest($manifest);
    htrExportLabels($manifest);

    return [
        'success' => true,
        'processed' => $processed,
        'skipped' => $skipped,
        'new' => $new,
        'manifest' => $manifest,
    ];
}

/**
 * Writes a minimal single-sheet Excel (.xlsx) file using a hand-rolled ZIP
 * container (see createMinimalZip) so it works even when the ext-zip
 * extension is disabled — no Composer/PhpSpreadsheet dependency required.
 * Cell values are written as inline strings so no shared-strings part is
 * needed, keeping the workbook small and dependency-free.
 */
function writeXlsx(string $path, string $sheet, array $headers, array $rows): bool
{
    $sheetXml = xlsxBuildSheetXml(array_merge([$headers], $rows));
    $sheetNameEsc = htmlspecialchars($sheet, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="' . $sheetNameEsc . '" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '</Relationships>';

    return createMinimalZip($path, [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}

/**
 * Creates a ZIP archive from an in-memory map of entries without requiring
 * the ext-zip extension — only PHP's always-available zlib functions
 * (gzdeflate) and crc32() are used. This keeps Excel export working even on
 * PHP installs where ext-zip is disabled (a common default on XAMPP/Laragon).
 *
 * @param array<string,string> $files Map of in-archive path => raw file contents
 */
function createMinimalZip(string $zipPath, array $files): bool
{
    $dir = dirname($zipPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $localParts = [];
    $centralParts = [];
    $offset = 0;
    $dosTime = 0;
    $dosDate = (1 << 5) | 1; // Jan 1, 1980 — timestamp is irrelevant for this manifest

    foreach ($files as $name => $contents) {
        $nameEnc = str_replace('\\', '/', $name);
        $crc = crc32($contents);
        $uncompressedSize = strlen($contents);
        $compressed = gzdeflate($contents, 6);
        $compressedSize = strlen($compressed);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compressedSize,
            $uncompressedSize,
            strlen($nameEnc),
            0
        ) . $nameEnc . $compressed;

        $localParts[] = $localHeader;

        $centralParts[] = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compressedSize,
            $uncompressedSize,
            strlen($nameEnc),
            0,
            0,
            0,
            0,
            0x81A40000,
            $offset
        ) . $nameEnc;
        $offset += strlen($localHeader);
    }

    $localData = implode('', $localParts);
    $centralData = implode('', $centralParts);

    $endRecord = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        count($files),
        count($files),
        strlen($centralData),
        strlen($localData),
        0
    );

    if (file_exists($zipPath)) {
        @unlink($zipPath);
    }

    return file_put_contents($zipPath, $localData . $centralData . $endRecord) !== false;
}

/** Builds <sheetData> XML for a 2D array of rows using inline strings. */
function xlsxBuildSheetXml(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>';

    foreach ($rows as $rowIdx => $row) {
        $r = $rowIdx + 1;
        $xml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $colIdx => $value) {
            $cellRef = xlsxColumnLetter($colIdx) . $r;
            if (is_numeric($value) && $value !== '') {
                $xml .= '<c r="' . $cellRef . '"><v>' . htmlspecialchars((string) $value, ENT_XML1) . '</v></c>';
            } else {
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/** Converts a 0-based column index to an Excel column letter (0 -> A, 26 -> AA, ...). */
function xlsxColumnLetter(int $index): string
{
    $letter = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = (int) (($index - $mod) / 26);
    }
    return $letter;
}

