<?php

declare(strict_types=1);

/**
 * `verify parity`'s comparison: php -r <this file> -- <label> <expect|-> [<ignored path>...], in the head run's app container.
 *
 * stdin: two base64 lines, the head's and the base's `curl -i` output ending in a VERIFY_STATUS line.
 * Prints one verdict line, or one per differing field; exits 0 on match, 1 otherwise, 2 when it cannot compare.
 */

/**
 * @return array{string, string} status and body; status 000 without a complete response
 */
function splitResponse(string $raw): array
{
    if (preg_match('/\nVERIFY_STATUS=(\d{3})\n\z/', $raw, $match) !== 1) {
        return ['000', ''];
    }

    $response = mb_substr($raw, 0, -mb_strlen($match[0], '8bit'), '8bit');
    $end = mb_strpos($response, "\r\n\r\n", 0, '8bit');

    return $end === false ? ['000', ''] : [$match[1], mb_substr($response, $end + 4, null, '8bit')];
}

/**
 * Decodes JSON with every number kept as its lexeme ("n:1.0") and every string tagged ("s:…"), keys included; null when it is not JSON.
 *
 * @return array{mixed}|null
 */
function decode(string $body): ?array
{
    json_decode($body, false, 512, \JSON_OBJECT_AS_ARRAY);

    if (json_last_error() !== \JSON_ERROR_NONE) {
        return null;
    }

    $tagged = preg_replace_callback(
        '/"(?:[^"\\\\]++|\\\\.)*+"|-?\d++(?:\.\d++)?(?:[eE][+-]?\d++)?/s',
        static fn(array $m): string => str_starts_with($m[0], '"') ? '"s:' . mb_substr($m[0], 1) : '"n:' . $m[0] . '"',
        $body,
    );

    $decoded = json_decode($tagged ?? '');

    return json_last_error() === \JSON_ERROR_NONE ? [$decoded] : null;
}

function keyToken(string $key): string
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key) === 1 ? '.' . $key : '[' . json_encode($key, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . ']';
}

/**
 * @param list<string> $tokens
 */
function path(array $tokens): string
{
    $path = implode('', $tokens);

    return str_starts_with($path, '.') ? $path : '.' . $path;
}

/**
 * @return list<string>
 */
function tokens(string $path): array
{
    preg_match_all('/\.[A-Za-z_][A-Za-z0-9_-]*|\.?\["[^"]+"\]|\.?\[\d*\]/', $path, $matches);

    return preg_replace(['/^\.(?=\[)/', '/^\["([A-Za-z_][A-Za-z0-9_-]*)"\]$/'], ['', '.$1'], $matches[0]);
}

/**
 * @param list<string> $tokens
 * @param list<list<string>> $ignored
 */
function isIgnored(array $tokens, array $ignored): bool
{
    foreach ($ignored as $pattern) {
        if (count($pattern) !== count($tokens)) {
            continue;
        }

        foreach ($pattern as $i => $token) {
            $actual = $tokens[$i] ?? '';

            if ($token !== $actual && !($token === '[]' && preg_match('/^\[\d+\]$/', $actual) === 1)) {
                continue 2;
            }
        }

        return true;
    }

    return false;
}

function shown(mixed $value): string
{
    $text = is_string($value) ? (str_starts_with($value, 'n:') ? mb_substr($value, 2) : (string) json_encode(mb_substr($value, 2), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)) : (string) json_encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR);

    return is_object($value) ? 'an object' : (is_array($value) ? 'an array of ' . count($value) : mb_substr($text, 0, 120));
}

/**
 * @param list<string> $tokens
 * @param list<list<string>> $ignored
 *
 * @return iterable<string>
 */
function differences(mixed $head, mixed $base, array $tokens, array $ignored): iterable
{
    if (is_array($head) && is_array($base)) {
        for ($i = 0, $n = max(count($head), count($base)); $i < $n; ++$i) {
            yield from childDifferences($head, $base, $i, [...$tokens, "[{$i}]"], $ignored);
        }

        return;
    }

    if (is_object($head) && is_object($base)) {
        $headFields = get_object_vars($head);
        $baseFields = get_object_vars($base);

        foreach ($headFields + $baseFields as $key => $_) {
            yield from childDifferences($headFields, $baseFields, $key, [...$tokens, keyToken(mb_substr((string) $key, 2))], $ignored);
        }

        return;
    }

    if ($head !== $base) {
        yield path($tokens) . ': head ' . shown($head) . ', base ' . shown($base);
    }
}

/**
 * @param array<array-key, mixed> $head
 * @param array<array-key, mixed> $base
 * @param list<string> $tokens
 * @param list<list<string>> $ignored
 *
 * @return iterable<string>
 */
function childDifferences(array $head, array $base, int|string $key, array $tokens, array $ignored): iterable
{
    if (isIgnored($tokens, $ignored)) {
        return;
    }

    if (!array_key_exists($key, $head) || !array_key_exists($key, $base)) {
        yield path($tokens) . ': only in the ' . (array_key_exists($key, $head) ? 'head' : 'base');

        return;
    }

    yield from differences($head[$key], $base[$key], $tokens, $ignored);
}

/**
 * @param list<list<string>> $ignored
 *
 * @return iterable<string>
 */
function bodyDifferences(string $head, string $base, array $ignored): iterable
{
    if ($head === $base) {
        return;
    }

    $headJson = decode($head);
    $baseJson = decode($base);

    if ($headJson === null || $baseJson === null) {
        yield 'body bytes: head ' . mb_strlen($head, '8bit') . ' bytes, base ' . mb_strlen($base, '8bit') . ' bytes, not both JSON';

        return;
    }

    yield from differences($headJson[0], $baseJson[0], [], $ignored);
}

$arguments = array_slice($argv, 1);
$label = (string) array_shift($arguments);
$expect = (string) array_shift($arguments);
$lines = explode("\n", (string) stream_get_contents(\STDIN));

if (count($lines) < 2) {
    fwrite(\STDERR, "parity: no responses to compare\n");
    exit(2);
}

[$headStatus, $headBody] = splitResponse((string) base64_decode($lines[0], true));
[$baseStatus, $baseBody] = splitResponse((string) base64_decode($lines[1], true));
$statuses = "head {$headStatus}, base {$baseStatus}";

if ($headStatus === '000' || $baseStatus === '000') {
    echo "failure {$label} no HTTP response ({$statuses})\n";
    exit(1);
}

if ($headStatus !== $baseStatus) {
    echo "difference {$label} status code: {$statuses}\n";
    exit(1);
}

if ($expect === '-' ? (int) $headStatus >= 400 : $headStatus !== $expect) {
    echo "failure {$label} " . ($expect === '-' ? 'error status' : "status is not the expected {$expect}") . " ({$statuses})\n";
    exit(1);
}

$limit = 50;
$found = 0;

foreach (bodyDifferences($headBody, $baseBody, array_map(tokens(...), $arguments)) as $difference) {
    if (++$found > $limit) {
        echo "difference {$label} more than {$limit} fields differ; the rest are not listed\n";
        break;
    }

    echo "difference {$label} {$difference}\n";
}

if ($found > 0) {
    exit(1);
}

echo "match {$label} status {$headStatus}\n";
