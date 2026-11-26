<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$ref = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', (string) ($_GET['ref'] ?? ''));
$file = basename((string) ($_GET['file'] ?? ''));

if ($ref === '' || $file === '') {
    http_response_code(404);
    exit;
}

$baseDir = realpath(__DIR__ . '/documents/produit/' . $ref);
if ($baseDir === false || !is_dir($baseDir)) {
    http_response_code(404);
    exit;
}

$path = realpath($baseDir . DIRECTORY_SEPARATOR . $file);
if ($path === false || strpos($path, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($path) ?: 'application/octet-stream';
if (!preg_match('/^image\\//', $mime)) {
    http_response_code(415);
    exit;
}

$width = min(1200, max(0, (int) ($_GET['w'] ?? 0)));
if ($width >= 120 && extension_loaded('gd')) {
    $size = @getimagesize($path);
    if ($size && $size[0] > $width) {
        [$sourceWidth, $sourceHeight] = $size;
        $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
        $create = null;
        if ($mime === 'image/jpeg') {
            $create = 'imagecreatefromjpeg';
        } elseif ($mime === 'image/png') {
            $create = 'imagecreatefrompng';
        } elseif ($mime === 'image/webp') {
            $create = 'imagecreatefromwebp';
        }
        if ($create && function_exists($create)) {
            $source = @$create($path);
            if ($source) {
                $target = imagecreatetruecolor($width, $height);
                imagealphablending($target, false);
                imagesavealpha($target, true);
                imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);
                header('Content-Type: image/webp');
                header('Cache-Control: public, max-age=604800');
                imagewebp($target, null, 82);
                imagedestroy($source);
                imagedestroy($target);
                exit;
            }
        }
    }
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . filesize($path));
readfile($path);
