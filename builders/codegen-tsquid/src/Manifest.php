<?php

declare(strict_types=1);

namespace Eleph\Tsquid;

use RuntimeException;
use stdClass;

final readonly class Manifest
{
    public function __construct(private stdClass $manifest)
    {
        if (($manifest->version ?? null) !== 1 || !is_array($manifest->routes ?? null)) {
            throw new RuntimeException('Unsupported or malformed tsquid manifest; expected version 1.');
        }
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException('Missing tsquid manifest. Run eleph generate: ' . $path);
        }
        $manifest = require $path;
        if (!$manifest instanceof self) {
            throw new RuntimeException('The generated tsquid manifest must return a Manifest: ' . $path);
        }
        return $manifest;
    }

    /** @return array{route: stdClass, input: array<string, mixed>}|null */
    public function match(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $segments = explode('/', rtrim($parts['path'] ?? '/', '/') ?: '/');
        $best = null;
        $score = -1;
        $tied = false;
        foreach ($this->manifest->routes as $route) {
            $pattern = explode('/', rtrim($route->path, '/') ?: '/');
            if (count($pattern) !== count($segments)) {
                continue;
            }
            $static = 0;
            foreach ($pattern as $index => $segment) {
                if (str_starts_with($segment, ':')) {
                    if ($segments[$index] === '') {
                        continue 2;
                    }
                } else {
                    $decoded = self::pathValue($segments[$index]);
                    if ($decoded === null || preg_match('~\A' . preg_quote($segment, '~') . '\z~iu', $decoded) !== 1) {
                        continue 2;
                    }
                    ++$static;
                }
            }
            if ($static > $score) {
                $best = $route;
                $score = $static;
                $tied = false;
            } elseif ($static === $score) {
                $tied = true;
            }
        }
        if ($best === null || $tied) {
            return null;
        }

        $path = [];
        foreach (explode('/', rtrim($best->path, '/') ?: '/') as $index => $segment) {
            if (str_starts_with($segment, ':')) {
                $value = self::pathValue($segments[$index]);
                if ($value === null) {
                    return null;
                }
                $path[substr($segment, 1)] = [$value];
            } elseif ($segment !== $segments[$index]) {
                return null;
            }
        }

        // parse_str loses repeated keys and changes dots/spaces in names.
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[self::queryValue($key)][] = self::queryValue($value);
        }
        $input = [];
        foreach ($best->fields as $name => $field) {
            $values = ($field->in === 'path' ? $path : $query)[$name] ?? [];
            if ($values === []) {
                if (!$field->optional) {
                    return null;
                }
                continue;
            }
            $array = str_ends_with($field->kind, '[]');
            if (!$array && count($values) !== 1) {
                return null;
            }
            $parsed = [];
            foreach ($values as $value) {
                $parsedValue = self::value($array ? substr($field->kind, 0, -2) : $field->kind, $value);
                if ($parsedValue === null) {
                    return null;
                }
                $parsed[] = $parsedValue;
            }
            $input[$name] = $array ? $parsed : $parsed[0];
        }
        return ['route' => $best, 'input' => $input];
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public static function variables(stdClass $query, array $input): array
    {
        $variables = [];
        foreach ($query->variables as $name => $binding) {
            if (isset($binding->input) && array_key_exists($binding->input, $input)) {
                $variables[$name] = $input[$binding->input];
            } elseif (property_exists($binding, 'default')) {
                $variables[$name] = $binding->default;
            }
        }
        return $variables;
    }

    private static function pathValue(string $value): ?string
    {
        if (preg_match('/%(?![0-9a-f]{2})/i', $value)) {
            return null;
        }
        $decoded = rawurldecode($value);
        return preg_match('//u', $decoded) === 1 ? $decoded : null;
    }

    private static function queryValue(string $value): string
    {
        // URLSearchParams replaces malformed UTF-8, rather than rejecting it.
        return json_decode(json_encode(urldecode($value), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    }

    private static function value(string $kind, string $value): string|int|bool|null
    {
        return match ($kind) {
            'string' => $value,
            'bool' => match ($value) { 'true' => true, 'false' => false, default => null },
            'int' => preg_match('/^-?[0-9]+\z/', $value) === 1 && abs((float) $value) <= 9007199254740991
                ? (int) $value : null,
            default => null,
        };
    }
}
