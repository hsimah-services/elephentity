<?php

declare(strict_types=1);

/**
 * The PHP compiler as an oracle for the Rust one.
 *
 * Runs the real SchemaCompiler over every fixture in packages/schema/tests/fixtures and writes
 * what it produces to compiler/tests/oracle/<case>/:
 *
 *   input.json     the fixture and any pooled documents the case supplies
 *   resolved.json  the IR after load, validation and patterns (before semantic validation),
 *                  as IrCodec encodes it, when that much succeeds
 *   ir.json        the exact bytes `eleph generate` sends to eleph-codegen, when the whole
 *                  compile succeeds
 *   errors.json    code, file and pointer of every error, when it does not
 *
 * Rust tests read these snapshots, so Rust CI needs no PHP. The stage-2 stages are called
 * through reflection rather than reimplemented, so the oracle is the shipped code and the PHP
 * compiler needs no changes.
 *
 *   tools/compare-compiler             fail when the committed snapshots differ from PHP
 *   tools/compare-compiler --refresh   rewrite the snapshots from PHP
 */

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Integration\IntegrationDefinition;
use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\Spec\RawSpec;
use Eleph\Schema\Spec\SchemaValidator;
use Eleph\Schema\Spec\SpecKind;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Storage\StorageRuleRegistry;
use Eleph\Schema\Storage\StorageRules;
use Eleph\Schema\Tests\Support\TestIntegrations;
use Eleph\Schema\Wire\IrCodec;

require __DIR__ . '/../vendor/autoload.php';

// Every path in an error or the IR is relative to the repository root, so a snapshot does not
// depend on where the repository is checked out.
$root = dirname(__DIR__);
chdir($root);

$fixtures = 'packages/schema/tests/fixtures';
$oracle = 'compiler/tests/oracle';
$refresh = in_array('--refresh', $argv, true);

/** @var array<string, array{fixture: string, pooledPatterns: list<array{file: string, data: array<string, mixed>}>, pooledTypes: list<array{file: string, data: array<string, mixed>}>}> $cases */
$cases = [];

foreach (glob($fixtures . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
    $name = basename($directory);
    $cases[$name] = ['fixture' => $name, 'pooledPatterns' => [], 'pooledTypes' => []];
}

$taxonomy = static fn (array $extra): array => [
    'file' => 'elephentity/codegen-wordpress:patterns/Taxonomy.yml',
    'data' => ['pattern' => 'Taxonomy', ...$extra],
];

// The cases PooledSpecTest builds in code: a pooled document has no file on disk.
$cases['pooled-with-pattern'] = [
    'fixture' => 'pooled',
    'pooledPatterns' => [$taxonomy(['requires' => ['driver' => 'wordpress'], 'fields' => ['name' => ['type' => 'string']]])],
    'pooledTypes' => [],
];
$cases['pooled-with-type'] = [
    'fixture' => 'pooled-empty',
    'pooledPatterns' => [],
    'pooledTypes' => [[
        'file' => 'elephentity/codegen-php:types/PostStatus.yml',
        'data' => ['type' => 'PostStatus', 'primitive' => 'string', 'values' => ['draft', 'published']],
    ]],
];
$cases['pooled-collision-with-pattern'] = [
    'fixture' => 'pooled-collision',
    'pooledPatterns' => [$taxonomy(['fields' => ['name' => ['type' => 'string']]])],
    'pooledTypes' => [],
];
$cases['pooled-malformed'] = [
    'fixture' => 'pooled-empty',
    'pooledPatterns' => [$taxonomy(['notAField' => true])],
    'pooledTypes' => [],
];

ksort($cases);

function encode(mixed $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * A map that stays a JSON object when empty. PHP cannot tell `[]` the list from `[]` the map.
 *
 * @param array<array-key, mixed> $map
 */
function object(array $map): stdClass
{
    return (object) $map;
}

function relative(?string $path, string $base): ?string
{
    if (null === $path) {
        return null;
    }

    if ($path === $base) {
        return '.';
    }

    return str_starts_with($path, $base . '/') ? substr($path, strlen($base) + 1) : $path;
}

/** @return array<string, mixed> */
function parameter(ConfigParameter $parameter): array
{
    return [
        'name' => $parameter->name,
        'type' => $parameter->type->value,
        'description' => $parameter->description,
        'of' => $parameter->of?->value,
        'values' => $parameter->values,
        'nullable' => $parameter->nullable,
        'default' => $parameter->default,
        'hasDefault' => $parameter->hasDefault,
    ];
}

/** What `eleph generate` sends to eleph-codegen: GenerateCommand::request(), byte for byte. */
function request(Schema $schema): string
{
    return json_encode([
        'elephentity' => 1,
        'irVersion' => IrCodec::VERSION,
        'schema' => IrCodec::encode($schema),
        'files' => (object) [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * SchemaCompiler::compile() up to, but not including, building the Schema and validating it
 * semantically.
 *
 * @param list<RawSpec> $pooledPatterns
 * @param list<RawSpec> $pooledTypes
 *
 * @return array{errors: list<SpecError>, schema: ?Schema}
 */
function resolve(SpecSource $source, IntegrationRegistry $registry, array $pooledPatterns, array $pooledTypes): array
{
    $compiler = new SchemaCompiler(
        integrations: $registry,
        pooledPatterns: $pooledPatterns,
        pooledTypes: $pooledTypes,
    );
    $reflection = new ReflectionClass($compiler);
    $loader = $reflection->getProperty('loader')->getValue($compiler);

    $loaded = $loader->load($source);
    $errors = $loaded['errors'];
    $specs = [...$pooledPatterns, ...$pooledTypes, ...$loaded['specs']];

    $validator = new SchemaValidator();

    // opis stops at the first schema error in a document (maxErrors defaults to 1), which
    // breaks the rule that errors accumulate. The oracle asks for all of them so the
    // snapshot can be compared with a compiler that reports everything. See
    // docs/divergence.md.
    (new ReflectionProperty(SchemaValidator::class, 'validator'))
        ->getValue($validator)
        ->setMaxErrors(1000);

    foreach ($specs as $spec) {
        foreach ($validator->validate($spec) as $error) {
            $errors[] = $error;
        }
    }

    if ([] !== $errors) {
        return ['errors' => $errors, 'schema' => null];
    }

    $stage = static function (string $method, array $arguments) use ($reflection, $compiler): mixed {
        return $reflection->getMethod($method)->invokeArgs($compiler, $arguments);
    };

    $project = $stage('buildProject', [$specs, $source, &$errors]);

    if (null === $project) {
        return ['errors' => $errors, 'schema' => null];
    }

    $types = $stage('buildTypes', [$specs, &$errors]);
    $patterns = $stage('buildPatterns', [$specs, &$errors]);
    $entities = $stage('buildEntities', [$project, $specs, $patterns, &$errors]);

    if ([] !== $errors) {
        return ['errors' => $errors, 'schema' => null];
    }

    // No declared patterns yet: those are built by the stage after this one.
    return ['errors' => [], 'schema' => new Schema($project, $entities, $types)];
}

function errorList(array $errors, string $base): array
{
    return array_map(static fn (SpecError $e): array => [
        'code' => $e->code,
        'file' => relative($e->file, $base),
        'pointer' => $e->pointer,
    ], $errors);
}

$registry = TestIntegrations::registry();
// The rules the WordPress builder declares, plus reserved handles so that rule is exercised
// too: TestStorageRules reserves none.
$storageRules = new StorageRuleRegistry([
    'wordpress' => new StorageRules(
        maxHandleLength: 20,
        handlePattern: '/^[a-z][a-z0-9_-]*$/',
        reservedHandles: ['attachment', 'revision'],
    ),
]);
$files = [];

$files['registry.json'] = encode(['integrations' => array_map(
    static fn (string $name): array => (static function (IntegrationDefinition $d): array {
        return [
            'name' => $d->name,
            'description' => $d->description,
            'projectConfig' => object(array_map(parameter(...), $d->projectConfig)),
            'entityConfig' => object(array_map(parameter(...), $d->entityConfig)),
            'queryConfig' => object(array_map(parameter(...), $d->queryConfig)),
        ];
    })($registry->get($name) ?? throw new LogicException($name)),
    $registry->names(),
)]);

/** @var array<string, \Eleph\Schema\Storage\StorageRules> $rules */
$rules = (new ReflectionProperty(StorageRuleRegistry::class, 'rules'))->getValue($storageRules);

$files['storage-rules.json'] = encode(['rules' => object(array_map(static fn ($r): array => [
    'maxHandleLength' => $r->maxHandleLength,
    'handlePattern' => $r->handlePattern,
    'reservedHandles' => $r->reservedHandles,
], $rules))]);

foreach ($cases as $name => $case) {
    $directory = $fixtures . '/' . $case['fixture'];
    $raw = static fn (SpecKind $kind): Closure => static fn (array $d): RawSpec => new RawSpec($kind, $d['file'], $d['data']);
    $patterns = array_map($raw(SpecKind::Pattern), $case['pooledPatterns']);
    $types = array_map($raw(SpecKind::Type), $case['pooledTypes']);

    $files[$name . '/input.json'] = encode([
        'fixture' => $case['fixture'],
        'pooledPatterns' => $case['pooledPatterns'],
        'pooledTypes' => $case['pooledTypes'],
    ]);

    $resolved = resolve(new SpecSource($directory), $registry, $patterns, $types);

    if (null === $resolved['schema']) {
        $files[$name . '/errors.json'] = encode(errorList($resolved['errors'], $directory));

        continue;
    }

    // Paths inside the IR are the fixture's, relative to the repository root: unlike the
    // errors they are not rewritten, because they are part of the bytes builders receive.
    $files[$name . '/resolved.json'] = encode(IrCodec::encode($resolved['schema']));

    $compiled = (new SchemaCompiler(
        integrations: $registry,
        pooledPatterns: $patterns,
        pooledTypes: $types,
        storageRules: $storageRules,
    ))->compile(new SpecSource($directory));

    if ($compiled->isSuccess()) {
        $files[$name . '/ir.json'] = request($compiled->schema());
    } else {
        $files[$name . '/errors.json'] = encode(errorList($compiled->errors, $directory));
    }
}

$drift = [];

foreach ($files as $path => $contents) {
    $target = $oracle . '/' . $path;

    if ($refresh) {
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o775, true)) {
            fwrite(STDERR, "Cannot create " . dirname($target) . "\n");
            exit(2);
        }

        file_put_contents($target, $contents);

        continue;
    }

    if (!is_file($target) || file_get_contents($target) !== $contents) {
        $drift[] = $path;
    }
}

// A snapshot with no case behind it is stale: the fixture or case was removed.
foreach (glob($oracle . '/*/*.json') ?: [] as $existing) {
    $path = basename(dirname($existing)) . '/' . basename($existing);

    if (isset($files[$path])) {
        continue;
    }

    if ($refresh) {
        unlink($existing);
    } else {
        $drift[] = $path . ' (no longer produced)';
    }
}

if ($refresh) {
    fwrite(STDOUT, sprintf("Wrote %d snapshots for %d cases.\n", count($files), count($cases)));
    exit(0);
}

if ([] !== $drift) {
    fwrite(STDERR, "Oracle snapshots differ from the PHP compiler:\n  " . implode("\n  ", $drift) . "\nRun tools/compare-compiler --refresh and commit the result.\n");
    exit(1);
}

fwrite(STDOUT, sprintf("%d snapshots match the PHP compiler.\n", count($files)));
