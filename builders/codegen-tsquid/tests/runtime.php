<?php

declare(strict_types=1);

require __DIR__ . '/../src/Manifest.php';
require __DIR__ . '/../src/HtmlResponse.php';
require __DIR__ . '/../src/InitialData.php';

use Eleph\Tsquid\{InitialData, Manifest};

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function canonical(mixed $value, bool $top = false): mixed
{
    if ($value instanceof stdClass) {
        $properties = (array) $value;
        if ($top) {
            $properties = array_filter($properties, static fn ($v) => $v !== null);
        }
        ksort($properties);
        return (object) array_map(static fn ($v) => canonical($v), $properties);
    }
    return is_array($value) ? array_map(static fn ($v) => canonical($v), $value) : $value;
}

$vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/vectors.json'), flags: JSON_THROW_ON_ERROR);
$manifest = new Manifest($vectors->manifest);
foreach ($vectors->urls as $case) {
    $match = $manifest->match($case->url);
    check(($match['route']->name ?? null) === $case->route, 'Route: ' . $case->url);
    if ($match !== null) {
        check((object) $match['input'] == $case->input, 'Input: ' . $case->url);
        if (isset($case->variables)) {
            check(canonical((object) Manifest::variables($match['route']->query, $match['input'])) == canonical($case->variables), 'Variables: ' . $case->url);
        }
    }
}
foreach ($vectors->variables as $case) {
    check((json_encode(canonical($case->client, true)) === json_encode(canonical($case->server, true))) === $case->match, 'Relay variable comparison');
}

$calls = [];
$response = ['data' => ['article' => ['id' => '7', 'title' => '</script><script>alert(1)</script>']], 'extensions' => ['trace' => 'ok']];
$initial = new InitialData($manifest, static function (...$args) use (&$calls, $response) {
    $calls[] = $args;
    return $response;
});
$html = '<html><head><script type="module" src="/app.js"></script></head><body></body></html>';
$rendered = $initial->render('/articles/7', $html);
check($rendered->headers === ['Cache-Control' => 'private, no-store'], 'Personalized HTML cache headers');
check(strpos($rendered->body, 'id="tsquid-data"') < strpos($rendered->body, 'type="module"'), 'Payload must precede module');
check(!str_contains($rendered->body, '<script>alert(1)'), 'Script escaping');
preg_match('/id="tsquid-data">(.*?)<\/script>/s', $rendered->body, $payload);
$data = json_decode($payload[1], flags: JSON_THROW_ON_ERROR);
check($data->version === 1 && $data->responses[0]->operation === 'ArticleQuery', 'Payload shape');
check($data->responses[0]->variables->id === '7' && $data->responses[0]->response == json_decode(json_encode($response)), 'Payload values');
check($calls[0] === [$vectors->manifest->routes[2]->query->text, ['id' => '7'], 'ArticleQuery', 'a2'], 'Exact query execution');

$count = count($calls);
foreach (['/', '/articles/new', '/missing/route', '/Articles', '/articles?page=1&page=2'] as $url) {
    $result = $initial->render($url, $html);
    check($result->body === $html && $result->headers === [], 'Fallback: ' . $url);
}
check(count($calls) === $count, 'Fallback must not execute queries');
check($initial->render('/articles/7', $rendered->body)->body === $rendered->body && count($calls) === $count, 'No duplicate script');
check($initial->render('/articles/7', '<html></html>')->body === '<html></html>' && count($calls) === $count, 'Missing insertion point');
foreach ([static fn () => throw new RuntimeException('timeout'), static fn () => null, static fn () => [], static fn () => ['data' => "\xFF"]] as $execute) {
    $result = (new InitialData($manifest, $execute))->render('/articles/7', $html);
    check($result->body === $html && $result->headers === [], 'Execution/encoding failure fallback');
}
$errors = (new InitialData($manifest, static fn () => ['data' => null, 'errors' => [['message' => 'Denied']]]))->render('/articles/7', $html);
check(str_contains($errors->body, 'Denied'), 'Preserve GraphQL errors');
$starter = new Manifest(json_decode(file_get_contents(__DIR__ . '/fixtures/starter.manifest.json'), flags: JSON_THROW_ON_ERROR));
$empty = (new InitialData($starter, static fn () => ['data' => ['viewer' => null]]))->render('/me', $html);
check(str_contains($empty->body, '"variables":{}'), 'Empty variables must be an object');

$tie = clone $vectors->manifest;
$tie->routes = [$tie->routes[2], clone $tie->routes[2]];
check((new Manifest($tie))->match('/articles/7') === null, 'Tied routes do not inline');
foreach (['/articles/%FF', '/articles/%C0%AF', '/articles/%', '/articles?page=9007199254740992', '/articles?page=-9007199254740992', "/articles?page=1%0A"] as $url) {
    check($manifest->match($url) === null, 'Invalid input: ' . $url);
}
check($manifest->match('/articles?page=9007199254740991')['input']['page'] === 9007199254740991, 'Maximum safe integer');
check($manifest->match('/articles?page=-0')['input']['page'] === 0, 'Negative zero');
check($manifest->match('/articles?tags=%FF')['input']['tags'] === ["\u{FFFD}"], 'Query UTF-8 replacement');
$unicode = clone $vectors->manifest;
$unicode->routes = [json_decode('{"name":"Cafe","path":"/café","ssr":true,"fields":{}}'), $vectors->manifest->routes[6]];
check((new Manifest($unicode))->match('/CAFÉ') === null, 'Case-insensitive Unicode selection must not fall back to a dynamic route');
$query = json_decode('{"variables":{"filter":{"default":{"empty":{},"nested":null}},"nullable":{"default":null}}}');
$variables = Manifest::variables($query, []);
check($variables['filter']->empty instanceof stdClass && property_exists($variables['filter'], 'nested') && array_key_exists('nullable', $variables), 'Preserve nested objects and null defaults');
try {
    new Manifest((object) ['version' => 2, 'routes' => []]);
    throw new LogicException('Version 2 accepted');
} catch (RuntimeException) {
}
echo count($vectors->urls) . " URL vectors, " . count($vectors->variables) . " variable vectors, and HTML/fallback checks passed.\n";
