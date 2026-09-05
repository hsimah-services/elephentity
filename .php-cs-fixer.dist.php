<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/packages')
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP83Migration' => true,

        // Non-negotiable: PHP silently coerces types without this, and the
        // framework leans on PHPStan to claw back what the runtime will not enforce.
        'declare_strict_types' => true,

        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'single_quote' => true,
        'yoda_style' => true,
    ])
    ->setFinder($finder);
