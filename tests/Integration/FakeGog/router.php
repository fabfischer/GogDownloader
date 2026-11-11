<?php

declare(strict_types=1);

/*
 * Fake GOG download source for integration tests, served by PHP's built-in server:
 *   php -S 0.0.0.0:8080 tests/Integration/FakeGog/router.php
 * All content is synthetic pattern data (PatternData); nothing is stored, multi-GiB files are generated per range.
 *
 *   GET /health
 *   GET /downlink/{seed}/{size}?ttl=&<file query>   JSON like GOG's downlink API: {"downlink": ..., "checksum": ...}
 *   GET [/token=nva={unix}~...]/files/{seed}/{size}?<file query>
 *   GET /checksum/{seed}/{size}.xml                  GOG-style checksum XML (10 MiB chunks), up to 256 MiB, else 404
 *
 * File query parameters:
 *   fault=no-range|wrong-range|wrong-length|early-eof|etag-change|size-change|unknown-size|416|429|500|403|404
 *   skip=N         leave the first N file requests of this run untouched (default 0), e.g. the size probe
 *   fail-times=N   then apply the fault to N requests only (default: all following requests)
 *   run=ID         names the request counter for skip, fail-times and etag-change (use one ID per test)
 * An "nva" timestamp in the past answers 403, like an expired signed URL.
 */

use App\Tests\Integration\FakeGog\PatternData;

require __DIR__ . '/../../../vendor/autoload.php';

const LAST_MODIFIED = 'Thu, 13 Aug 2026 14:14:23 GMT';

$uri = (string) $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'fake-gog:8080');

/** Counts file requests per run ID across the server's worker processes. */
function requestNumber(string $run): int
{
    $file = sys_get_temp_dir() . '/fake-gog-' . preg_replace('/[^A-Za-z0-9_-]/', '', $run);
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Cannot open the request counter.');
    }
    flock($handle, LOCK_EX);
    $count = (int) stream_get_contents($handle) + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) $count);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $count;
}

/**
 * @param array<mixed> $query
 */
function param(array $query, string $name, string $default = ''): string
{
    return isset($query[$name]) && is_string($query[$name]) ? $query[$name] : $default;
}

if ($path === '/health') {
    echo 'ok';

    return true;
}

if (preg_match('#^/downlink/([A-Za-z0-9_-]+)/(\d+)$#', $path, $m) === 1) {
    $ttl = (int) param($query, 'ttl', '86400');
    unset($query['ttl']);
    header('Content-Type: application/json');
    echo json_encode([
        'downlink' => sprintf('http://%s/token=nva=%d~dirs=1~hash=fake/files/%s/%s%s', $host, time() + $ttl, $m[1], $m[2], $query === [] ? '' : '?' . http_build_query($query)),
        'checksum' => sprintf('http://%s/checksum/%s/%s.xml', $host, $m[1], $m[2]),
    ]);

    return true;
}

if (preg_match('#^/checksum/([A-Za-z0-9_-]+)/(\d+)\.xml$#', $path, $m) === 1) {
    $data = new PatternData($m[1], (int) $m[2]);
    if ($data->size > 256 * 1048576) {
        http_response_code(404);

        return true;
    }
    $chunks = '';
    $whole = hash_init('md5');
    for ($from = 0, $id = 0; $from < $data->size; $from += 10485760, ++$id) {
        $bytes = $data->slice($from, 10485760);
        hash_update($whole, $bytes);
        $chunks .= sprintf('<chunk id="%d" from="%d" to="%d" method="md5">%s</chunk>', $id, $from, $from + strlen($bytes) - 1, md5($bytes));
    }
    header('Content-Type: application/xml');
    printf(
        '<file name="setup_%s.bin" available="1" notavailablemsg="" md5="%s" chunks="%d" timestamp="2026-08-13 14:14:23" total_size="%d">%s</file>',
        $m[1],
        hash_final($whole),
        $id,
        $data->size,
        $chunks,
    );

    return true;
}

if (preg_match('#^(?:/token=nva=(\d+)[^/]*)?/files/([A-Za-z0-9_-]+)/(\d+)$#', $path, $m) !== 1) {
    http_response_code(404);

    return true;
}

$nva = $m[1] === '' ? null : (int) $m[1];
$data = new PatternData($m[2], (int) $m[3]);
$fault = param($query, 'fault');
$run = param($query, 'run', 'default');
$number = requestNumber($run);
$skip = (int) param($query, 'skip', '0');
$failTimes = param($query, 'fail-times') !== '' ? (int) param($query, 'fail-times') : PHP_INT_MAX - $skip;
$active = $fault !== '' && $number > $skip && $number <= $skip + $failTimes;

if ($nva !== null && $nva < time()) {
    http_response_code(403);

    return true;
}

$total = $data->size;
$etag = sprintf('"%s-%d"', $m[2], $data->size);
if ($active && $fault === 'etag-change') {
    $etag = sprintf('"%s-%d-v%d"', $m[2], $data->size, $number);
}
if ($active && $fault === 'size-change') {
    $total = $data->size + 1;
}

$status = ['416' => 416, '429' => 429, '500' => 500, '403' => 403, '404' => 404][$active ? $fault : ''] ?? null;
if ($status !== null) {
    http_response_code($status);
    if ($status === 429) {
        header('Retry-After: 2');
    }

    return true;
}

header('Accept-Ranges: bytes');
header('ETag: ' . $etag);
header('Last-Modified: ' . LAST_MODIFIED);
header('Content-Type: binary/octet-stream');

$range = $_SERVER['HTTP_RANGE'] ?? null;
if ($range === null || ($active && $fault === 'no-range')) {
    header('Content-Length: ' . $data->size);
    foreach ($data->stream(0, $data->size) as $piece) {
        echo $piece;
        flush();
    }

    return true;
}

if (preg_match('/^bytes=(\d+)-(\d*)$/', (string) $range, $r) !== 1 || (int) $r[1] >= $data->size) {
    http_response_code(416);
    header('Content-Range: bytes */' . $data->size);

    return true;
}
$from = (int) $r[1];
$to = $r[2] === '' ? $data->size - 1 : min((int) $r[2], $data->size - 1);
$length = $to - $from + 1;

http_response_code(206);
$shown = $active && $fault === 'wrong-range' ? [$from + 1, $to + 1] : [$from, $to];
header(sprintf('Content-Range: bytes %d-%d/%s', $shown[0], $shown[1], $active && $fault === 'unknown-size' ? '*' : (string) $total));
if ($active && $fault === 'wrong-length') {
    $length -= 1;
}
header('Content-Length: ' . $length);

$send = $active && $fault === 'early-eof' ? intdiv($length, 2) : $length;
foreach ($data->stream($from, $send, 65536) as $piece) {
    echo $piece;
    flush();
}
// early-eof: the response ends here although Content-Length promised more, like a dropped connection.

return true;
