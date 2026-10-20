<?php

declare(strict_types=1);

namespace Eleph\Tsquid;

use Closure;
use stdClass;
use Throwable;

final readonly class InitialData
{
    /**
     * Execute using the application's current viewer and normal query limits.
     * The callback receives (verbatim text, variables, operation name, persisted id).
     * Return the GraphQL response body, or null on transport failure. Configure any
     * transport timeout in this callback; PHP cannot interrupt a synchronous executor.
     */
    public function __construct(private Manifest $manifest, private Closure $execute)
    {
    }

    public function render(string $url, string $html): HtmlResponse
    {
        $match = $this->manifest->match($url);
        if ($match === null || !$match['route']->ssr || !isset($match['route']->query)) {
            return new HtmlResponse($html);
        }
        // Insert before any executable script, including modules placed in <head>.
        preg_match('/<script\b|<\/head\s*>/i', $html, $insertion, PREG_OFFSET_CAPTURE);
        if ($insertion === [] || preg_match('/\bid\s*=\s*[\x22\x27]tsquid-data[\x22\x27]/i', $html)) {
            return new HtmlResponse($html);
        }
        try {
            $query = $match['route']->query;
            $variables = Manifest::variables($query, $match['input']);
            $response = ($this->execute)($query->text, $variables, $query->operation, $query->id);
            if ((!is_array($response) && !$response instanceof stdClass)
                || (!array_key_exists('data', (array) $response) && !array_key_exists('errors', (array) $response))) {
                return new HtmlResponse($html);
            }
            $payload = json_encode([
                'version' => 1,
                'responses' => [[
                    'operation' => $query->operation,
                    'variables' => (object) $variables,
                    'response' => (object) $response,
                ]],
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        } catch (Throwable) {
            return new HtmlResponse($html);
        }
        $script = '<script type="application/json" id="tsquid-data">' . $payload . '</script>';
        $offset = $insertion[0][1];
        return new HtmlResponse(substr($html, 0, $offset) . $script . substr($html, $offset), [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
