<?php

declare(strict_types=1);

use Eleph\Tsquid\{InitialData, Manifest};
use GraphQL\GraphQL;

// Inside the application's existing GET handler, after session/viewer setup.
// $schema, $viewer, $html and $validationRules come from the application.
$initialData = new InitialData(
    Manifest::fromFile(__DIR__ . '/../generated/tsquid/tsquid-manifest.php'),
    static fn (string $text, array $variables, string $operation): array => GraphQL::executeQuery(
        $schema,
        $text,
        contextValue: $viewer,
        variableValues: $variables,
        operationName: $operation,
        validationRules: $validationRules,
    )->toArray(),
);
$response = $initialData->render($_SERVER['REQUEST_URI'], $html);
header('Content-Type: text/html; charset=utf-8');
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;
