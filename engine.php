<?php
// Shared scanning engine used by process.php (single upload) and batch_api.php (folder batch).
// Nothing uploaded is stored in the project: originals live in the system temp dir for the
// duration of the request; only the cropped previews are written (uploads/previews/).

$pythonCandidates = [
    'C:/xampp/htdocs/crms-laravel-12/ml/.venv-kraken/Scripts/python.exe',
    'C:/laragon/www/crms-laravel-12-main/ml/.venv-kraken/Scripts/python.exe',
    __DIR__ . '/.venv-kraken/Scripts/python.exe',
    __DIR__ . '/.venv/Scripts/python.exe',
];
$krakenPy = 'python';
foreach ($pythonCandidates as $candidate) {
    if (file_exists($candidate)) {
        $krakenPy = $candidate;
        break;
    }
}
define('KRAKEN_PYTHON', $krakenPy);
define('KRAKEN_SCRIPT', __DIR__ . '/kraken_segment.py');
define('TABLE_SCRIPT', __DIR__ . '/table_cells.py');
define('PDF_SCRIPT', __DIR__ . '/pdf_to_pages.py');

$GLOBALS['__tempFiles'] = [];
register_shutdown_function(function () {
    foreach ($GLOBALS['__tempFiles'] as $t) if (is_file($t)) @unlink($t);
});
function tempFile(string $suffix): string
{
    $p = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kraken_' . uniqid('', true) . $suffix;
    $GLOBALS['__tempFiles'][] = $p;
    return $p;
}

/** Run a python script, return [exitCode, stdout+stderr] */
function runPy(string $script, array $args): array
{
    $cmd = escapeshellarg(KRAKEN_PYTHON) . ' ' . escapeshellarg($script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, trim(implode("\n", $out))];
}

function jsonFromOutput(string $txt, string $open, string $close)
{
    $s = strpos($txt, $open);
    $e = strrpos($txt, $close);
    if ($s === false || $e === false || $e <= $s) return null;
    return json_decode(substr($txt, $s, $e - $s + 1), true);
}

function loadGd(string $path): ?array
{
    $mime = mime_content_type($path);
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => false,
    };
    return $img ? [$img, $mime] : null;
}

/** Screen copy of a page (max 1500 px wide JPEG, in memory only) for the viewer. */
function displayCopy(\GdImage $img, int $w, int $h): string
{
    $maxW = 1500;
    $out = $img;
    if ($w > $maxW) {
        $out = imagescale($img, $maxW, (int) round($h * $maxW / $w));
    }
    ob_start();
    imagejpeg($out, null, 82);
    $bin = ob_get_clean();
    if ($out !== $img) imagedestroy($out);
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}

// $cell (x0,y0,x1,y1) keeps a crop inside its grid cell (never over the lines).
function saveCrop(\GdImage $src, int $x, int $y, int $w, int $h, int $padding,
                  int $imgW, int $imgH, string $previewDir, string $outputFormat,
                  string $baseName, ?array $cell = null): ?array
{
    $x0 = max(0, $x - $padding);
    $y0 = max(0, $y - $padding);
    $x1 = min($imgW, $x + $w + $padding);
    $y1 = min($imgH, $y + $h + $padding);

    if ($cell && count($cell) === 4) {
        $x0 = max($x0, (int) $cell[0] + 1);
        $y0 = max($y0, (int) $cell[1] + 1);
        $x1 = min($x1, (int) $cell[2] - 1);
        $y1 = min($y1, (int) $cell[3] - 1);
    }

    $cw = $x1 - $x0;
    $ch = $y1 - $y0;
    if ($cw <= 0 || $ch <= 0) return null;

    $croppedImg = imagecrop($src, ['x' => $x0, 'y' => $y0, 'width' => $cw, 'height' => $ch]);
    if ($croppedImg === false) return null;

    $filename = "{$baseName}.{$outputFormat}";
    $path = $previewDir . $filename;
    switch ($outputFormat) {
        case 'jpeg': imagejpeg($croppedImg, $path, 95); break;
        case 'webp': imagewebp($croppedImg, $path, 95); break;
        default:     imagepng($croppedImg, $path);      break;
    }
    imagedestroy($croppedImg);

    return ['file' => 'uploads/previews/' . $filename, 'filename' => $filename, 'w' => $cw, 'h' => $ch, 'x' => $x0, 'y' => $y0];
}

/**
 * Scan uploads (images and/or PDFs). Every PDF page / image becomes one "page".
 * $uploads: [['name','tmp','error', 'local'?]]   $prefix is put in front of every crop file name.
 * Returns ['pages'=>[], 'words'=>[], 'tableMode'=>bool, 'warnings'=>[]]
 */
function scanUploads(array $uploads, string $prefix, int $padding, string $outputFormat, string $previewDir): array
{
    $warnings = [];
    $pages = [];
    $words = [];
    $tableMode = false;

    // ── Expand PDFs into one image per page ──
    $expanded = [];
    foreach ($uploads as $up) {
        if (strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            $expanded[] = $up;
            continue;
        }
        if ($up['error'] !== UPLOAD_ERR_OK) {
            $warnings[] = "{$up['name']}: upload failed (error code {$up['error']}).";
            continue;
        }
        $pdfTmp = tempFile('.pdf');
        $moved = !empty($up['local']) ? copy($up['tmp'], $pdfTmp) : move_uploaded_file($up['tmp'], $pdfTmp);
        if (!$moved) {
            $warnings[] = "{$up['name']}: could not read uploaded PDF.";
            continue;
        }
        $pagesDir = tempFile('_pages');
        [$pCode, $pOut] = runPy(PDF_SCRIPT, [$pdfTmp, $pagesDir]);
        $pngs = $pCode === 0 ? jsonFromOutput($pOut, '[', ']') : null;
        if (!$pngs) {
            $warnings[] = "{$up['name']}: could not render PDF pages.";
            continue;
        }
        foreach ($pngs as $n => $png) {
            $GLOBALS['__tempFiles'][] = $png;
            $expanded[] = [
                'name' => pathinfo($up['name'], PATHINFO_FILENAME) . '_p' . ($n + 1) . '.png',
                'tmp' => $png, 'error' => UPLOAD_ERR_OK, 'local' => true,
            ];
        }
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($expanded as $up) {
        $pageNo = count($pages) + 1;   // numbering only counts pages that were processed
        if ($up['error'] !== UPLOAD_ERR_OK) {
            $warnings[] = "{$up['name']}: upload failed (error code {$up['error']}).";
            continue;
        }
        $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            $warnings[] = "{$up['name']}: unsupported file type (use PDF, PNG, JPG or WEBP).";
            continue;
        }

        $uploadPath = tempFile('.' . $ext);
        $moved = !empty($up['local']) ? copy($up['tmp'], $uploadPath) : move_uploaded_file($up['tmp'], $uploadPath);
        if (!$moved) {
            $warnings[] = "{$up['name']}: could not read uploaded file.";
            continue;
        }

        // 1) Table / grid pages: crop every cell (row x column) like a spreadsheet.
        $workPath = $uploadPath;
        $boxes = [];
        $deskewPath = tempFile('_desk.png');
        [$tCode, $tOut] = file_exists(TABLE_SCRIPT) ? runPy(TABLE_SCRIPT, [$uploadPath, $deskewPath]) : [1, ''];
        $table = $tCode === 0 ? jsonFromOutput($tOut, '{', '}') : null;
        if ($table && !empty($table['cells']) && count($table['cols']) >= 3 && count($table['rows']) >= 3 && is_file($deskewPath)) {
            $workPath = $deskewPath;   // cells are measured on the straightened page
            $tableMode = true;
            foreach ($table['cells'] as $c) {
                $boxes[] = [
                    'x' => (int) $c['x'], 'y' => (int) $c['y'], 'w' => (int) $c['w'], 'h' => (int) $c['h'],
                    'label' => "P{$pageNo} R{$c['row']}·C{$c['col']}",
                    'base'  => sprintf('%sp%d_r%02d_c%02d', $prefix, $pageNo, $c['row'], $c['col']),
                    'cell'  => null, 'pad' => 0,
                    'row' => (int) $c['row'], 'col' => (int) $c['col'],
                ];
            }
        } else {
            // 2) No grid: fall back to Kraken word-by-word segmentation.
            [$kCode, $kOut] = runPy(KRAKEN_SCRIPT, [$uploadPath]);
            if ($kCode !== 0) {
                $warnings[] = "{$up['name']}: word segmentation failed.";
                continue;
            }
            $kBoxes = jsonFromOutput($kOut, '[', ']') ?? [];
            $n = 0;
            foreach ($kBoxes as $b) {
                $n++;
                $boxes[] = [
                    'x' => (int) $b['x'], 'y' => (int) $b['y'], 'w' => (int) $b['w'], 'h' => (int) $b['h'],
                    'label' => "P{$pageNo} Word #{$n}",
                    'base'  => sprintf('%sp%d_w%03d', $prefix, $pageNo, $n),
                    'cell'  => $b['cell'] ?? null, 'pad' => $padding,
                    'row' => 0, 'col' => 0,
                ];
            }
        }

        if (!$boxes) {
            $warnings[] = "{$up['name']}: nothing detected. Try a clearer scan.";
            continue;
        }

        $gd = loadGd($workPath);
        if (!$gd) {
            $warnings[] = "{$up['name']}: could not read the image.";
            continue;
        }
        [$sourceImage] = $gd;
        $imgWidth = imagesx($sourceImage);
        $imgHeight = imagesy($sourceImage);

        $page = [
            'no' => $pageNo,
            'name' => $up['name'],
            'w' => $imgWidth,
            'h' => $imgHeight,
            // embedded for on-screen display only (never written to the project)
            'src' => displayCopy($sourceImage, $imgWidth, $imgHeight),
        ];

        foreach ($boxes as $b) {
            $crop = saveCrop($sourceImage, $b['x'], $b['y'], $b['w'], $b['h'], $b['pad'],
                             $imgWidth, $imgHeight, $previewDir, $outputFormat, $b['base'], $b['cell']);
            if ($crop === null) continue;
            $words[] = [
                'id' => count($words) + 1,
                'page' => $pageNo,
                'label' => $b['label'],
                'row' => $b['row'],
                'col' => $b['col'],
                'file' => $crop['file'],
                'filename' => $crop['filename'],
                'w' => $crop['w'],
                'h' => $crop['h'],
                'crop' => ['x' => $crop['x'], 'y' => $crop['y']],
                'box' => ['x' => $b['x'], 'y' => $b['y'], 'w' => $b['w'], 'h' => $b['h']],
            ];
        }
        imagedestroy($sourceImage);
        $pages[] = $page;
    }

    return ['pages' => $pages, 'words' => $words, 'tableMode' => $tableMode, 'warnings' => $warnings];
}
