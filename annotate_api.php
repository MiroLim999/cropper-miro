<?php
// JSON API of the annotation tool (annotate.php).
//   action=list    -> manifest + counts
//   action=save    -> id, text, status(labeled|unreadable|pending)
//   action=export  -> (re)write dataset_labels.xlsx, labels.csv, labels.tsv
header('Content-Type: application/json');
require __DIR__ . '/htr_lib.php';

function out(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function stats(array $items): array
{
    $c = ['total' => count($items), 'labeled' => 0, 'unreadable' => 0, 'pending' => 0];
    foreach ($items as $r) $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
    return $c;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST required'], 405);
$action = $_POST['action'] ?? '';

// one writer at a time so quick consecutive saves cannot overwrite each other
$lock = fopen(__DIR__ . '/htr_dataset/.lock', 'c');
flock($lock, LOCK_EX);

$items = htrLoadManifest();

if ($action === 'list') {
    out(['ok' => true, 'items' => $items, 'stats' => stats($items)]);
}

if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'labeled');
    $text = trim((string) ($_POST['text'] ?? ''));
    if (!mb_check_encoding($text, 'UTF-8')) out(['ok' => false, 'error' => 'text is not valid UTF-8'], 400);
    $text = mb_substr($text, 0, 300);
    $text = preg_replace('/\s+/u', ' ', $text);
    if (!in_array($status, ['labeled', 'unreadable', 'pending'], true)) out(['ok' => false, 'error' => 'bad status'], 400);
    if ($status === 'labeled' && $text === '') $status = 'pending';   // an empty label is not a label
    if ($status !== 'labeled') $text = $status === 'unreadable' ? '' : $text;
    $found = false;
    foreach ($items as &$r) {
        if ((int) $r['id'] === $id) {
            $r['transcription'] = $text;
            $r['status'] = $status;
            $found = true;
            break;
        }
    }
    unset($r);
    if (!$found) out(['ok' => false, 'error' => 'image not found'], 404);
    htrSaveManifest($items);
    out(['ok' => true, 'status' => $status, 'text' => $text, 'stats' => stats($items)]);
}

if ($action === 'import') {
    $up = $_FILES['file'] ?? null;
    if (!$up || $up['error'] !== UPLOAD_ERR_OK || $up['size'] > 10 * 1024 * 1024) out(['ok' => false, 'error' => 'No file received (max 10 MB).'], 400);
    $overwrite = ($_POST['overwrite'] ?? '0') === '1';
    try {
        $rows = htrParseLabelFile($up['tmp_name'], (string) $up['name']);
    } catch (Throwable $e) {
        out(['ok' => false, 'error' => $e->getMessage()], 400);
    }
    if (!$items) out(['ok' => false, 'error' => 'There is no dataset yet. Run "Batch Process for HTR" first.'], 400);
    $merge = htrMergeLabels($items, $rows, $overwrite);
    if ($merge['updated'] > 0) {
        htrSaveManifest($items);
        htrExportLabels($items);          // keep xlsx / csv / tsv in step with the manifest
    }
    out(['ok' => true, 'merge' => $merge, 'stats' => stats($items)]);
}


if ($action === 'build_zip') {
    require_once __DIR__ . '/dataset_export.php';
    $presets = ['80-10-10' => [0.8, 0.1, 0.1], '70-15-15' => [0.7, 0.15, 0.15], '60-20-20' => [0.6, 0.2, 0.2]];
    $fr = $presets[(string) ($_POST['split'] ?? '80-10-10')] ?? $presets['80-10-10'];
    htrExportLabels($items);                  // keep the other label files in step first
    $res = dsBuildZip($items, $fr);
    out($res, $res['ok'] ? 200 : 400);
}

if ($action === 'export') {
    $res = htrExportLabels($items);
    out(['ok' => true, 'labeled' => $res['labeled'], 'stats' => stats($items),
         'files' => ['htr_dataset/dataset_labels.xlsx', 'htr_dataset/annotated_labels.xlsx', 'htr_dataset/labels.csv', 'htr_dataset/labels.tsv']]);
}

out(['ok' => false, 'error' => 'unknown action'], 400);
