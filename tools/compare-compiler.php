<?php

declare(strict_types=1);

/**
 * The PHP compiler as an oracle for the Rust one.
 *
 * Runs the real SchemaCompiler stages over every fixture in packages/schema/tests/fixtures
 * and writes what they produce to compiler/tests/oracle/<case>/:
 *
 *   input.json     the fixture and any pooled documents the case supplies
 *   resolved.json  the project, types and entities after load, validation and patterns
 *   errors.json    code, file and pointer of every error, when resolution fails
 *
 * Rust tests read these snapshots, so Rust CI needs no PHP. The snapshot stops where step 3
 * starts: semantic validation and the IR do not run, so their errors are not here yet.
 *
 * The private build* stages are called through reflection rather than reimplemented, so the
 * oracle is the shipped code and the PHP compiler needs no changes.
 *
 *   tools/compare-compiler             fail when the committed snapshots differ from PHP
 *   tools/compare-compiler --refresh   rewrite the snapshots from PHP
 */

use Eleph\Schema\Error\SpecError;
use Eleph\Schema\Integration\IntegrationDefinition;
use Eleph\Schema\Integration\IntegrationRegistry;
use Eleph\Schema\Ir\ConfigParameter;
use Eleph\Schema\Ir\EdgeDefinition;
use Eleph\Schema\Ir\EntityDefinition;
use Eleph\Schema\Ir\EnumSource;
use Eleph\Schema\Ir\FieldDefinition;
use Eleph\Schema\Ir\Origin;
use Eleph\Schema\Ir\PolicyDefinition;
use Eleph\Schema\Ir\QueryDefinition;
use Eleph\Schema\Ir\SideEffectDefinition;
use Eleph\Schema\Ir\TypeDefinition;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\Spec\RawSpec;
use Eleph\Schema\Spec\SchemaValidator;
use Eleph\Schema\Spec\SpecKind;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
$fixtures = $root . '/packages/schema/tests/fixtures';
$oracle = $root . '/compiler/tests/oracle';
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

/** @param mixed $value */
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

function origin(Origin $origin, string $base): array
{
    return ['pattern' => $origin->pattern, 'file' => relative($origin->file, $base)];
}

function enumSource(?EnumSource $enum): ?array
{
    if (null === $enum) {
        return null;
    }

    return $enum->isInline() ? ['inline' => $enum->inlineValues] : ['declared' => $enum->declaredType];
}

function arguments(array $arguments): stdClass
{
    return object(array_map(static fn ($a): array => [
        'name' => $a->name,
        'type' => $a->type->name(),
        'nullable' => $a->nullable,
    ], $arguments));
}

function field(FieldDefinition $f, string $base): array
{
    return [
        'name' => $f->name,
        'type' => $f->type->name(),
        'origin' => origin($f->origin, $base),
        'description' => $f->description,
        'required' => $f->required,
        'nullable' => $f->nullable,
        'default' => $f->default,
        'hasDefault' => $f->hasDefault,
        'unique' => $f->unique,
        'indexed' => $f->indexed,
        'immutable' => $f->immutable,
        'managed' => $f->managed?->value,
        'maxLength' => $f->maxLength,
        'enum' => enumSource($f->enum),
        'verify' => $f->verify,
    ];
}

function edge(EdgeDefinition $e, string $base): array
{
    return [
        'name' => $e->name,
        'to' => $e->to,
        'cardinality' => $e->cardinality->value,
        'origin' => origin($e->origin, $base),
        'description' => $e->description,
        'inverse' => null === $e->inverse ? null : [
            'derived' => $e->inverse->derived,
            'name' => $e->inverse->name,
            'unique' => $e->inverse->unique,
        ],
        'onDelete' => $e->onDelete->value,
        'required' => $e->required,
    ];
}

function query(QueryDefinition $q, string $base): array
{
    return [
        'name' => $q->name,
        'returns' => ['type' => $q->returns->type, 'cardinality' => $q->returns->cardinality->value],
        'origin' => origin($q->origin, $base),
        'arguments' => arguments($q->arguments),
        'description' => $q->description,
        'integrations' => object(array_map(object(...), $q->integrations)),
    ];
}

function sideEffect(SideEffectDefinition $s, string $base): array
{
    return [
        'name' => $s->name,
        'events' => array_map(static fn ($e): string => $e->value, $s->events),
        'origin' => origin($s->origin, $base),
        'phase' => $s->phase->value,
        'description' => $s->description,
    ];
}

function policy(PolicyDefinition $p, string $base): array
{
    return ['name' => $p->name, 'origin' => origin($p->origin, $base), 'description' => $p->description];
}

function entity(EntityDefinition $e, string $base): array
{
    return [
        'name' => $e->name,
        'storage' => ['driver' => $e->storage->driver, 'table' => $e->storage->table, 'handle' => $e->storage->handle],
        'sourceFile' => relative($e->sourceFile, $base),
        'description' => $e->description,
        'uses' => $e->uses,
        'appliedPatterns' => $e->appliedPatterns,
        'fields' => object(array_map(static fn ($f): array => field($f, $base), $e->fields)),
        'edges' => object(array_map(static fn ($x): array => edge($x, $base), $e->edges)),
        'queries' => object(array_map(static fn ($x): array => query($x, $base), $e->queries)),
        'actions' => object(array_map(static fn ($a): array => [
            'name' => $a->name,
            'writes' => ['fields' => $a->writes->fields, 'edges' => $a->writes->edges],
            'origin' => origin($a->origin, $base),
            'arguments' => arguments($a->arguments),
            'description' => $a->description,
        ], $e->actions)),
        'sideEffects' => object(array_map(static fn ($x): array => sideEffect($x, $base), $e->sideEffects)),
        'config' => object($e->config),
        'integrations' => object(array_map(object(...), $e->integrations)),
        'readPolicies' => object(array_map(static fn ($x): array => policy($x, $base), $e->readPolicies)),
        'writePolicies' => object(array_map(static fn ($x): array => policy($x, $base), $e->writePolicies)),
        'terminalRead' => $e->terminalRead->value,
        'terminalWrite' => $e->terminalWrite->value,
    ];
}

function type(TypeDefinition $t, string $base): array
{
    return [
        'name' => $t->name,
        'primitive' => $t->primitive->value,
        'sourceFile' => relative($t->sourceFile, $base),
        'description' => $t->description,
        'hasProcessors' => $t->hasProcessors,
        'values' => $t->values,
    ];
}

/**
 * SchemaCompiler::compile() up to, but not including, building the Schema.
 *
 * @param list<RawSpec> $pooledPatterns
 * @param list<RawSpec> $pooledTypes
 *
 * @return array{errors: list<SpecError>, resolved: ?array<string, mixed>}
 */
function resolve(SpecSource $source, IntegrationRegistry $registry, array $pooledPatterns, array $pooledTypes, string $base): array
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
        return ['errors' => $errors, 'resolved' => null];
    }

    $stage = static function (string $method, array $arguments) use ($reflection, $compiler): mixed {
        return $reflection->getMethod($method)->invokeArgs($compiler, $arguments);
    };

    $project = $stage('buildProject', [$specs, $source, &$errors]);

    if (null === $project) {
        return ['errors' => $errors, 'resolved' => null];
    }

    $types = $stage('buildTypes', [$specs, &$errors]);
    $patterns = $stage('buildPatterns', [$specs, &$errors]);
    $entities = $stage('buildEntities', [$project, $specs, $patterns, &$errors]);

    if ([] !== $errors) {
        return ['errors' => $errors, 'resolved' => null];
    }

    return ['errors' => [], 'resolved' => [
        'project' => [
            'name' => $project->name,
            'driver' => $project->driver,
            'sourceFile' => relative($project->sourceFile, $base),
            'integrations' => object(array_map(object(...), $project->integrations)),
            'tablePrefix' => $project->tablePrefix,
            'description' => $project->description,
        ],
        'types' => object(array_map(static fn ($t): array => type($t, $base), $types)),
        'entities' => object(array_map(static fn ($e): array => entity($e, $base), $entities)),
    ]];
}

$registry = TestIntegrations::registry();
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

foreach ($cases as $name => $case) {
    $directory = $fixtures . '/' . $case['fixture'];
    $raw = static fn (SpecKind $kind): Closure => static fn (array $d): RawSpec => new RawSpec($kind, $d['file'], $d['data']);

    $result = resolve(
        new SpecSource($directory),
        $registry,
        array_map($raw(SpecKind::Pattern), $case['pooledPatterns']),
        array_map($raw(SpecKind::Type), $case['pooledTypes']),
        $directory,
    );

    $files[$name . '/input.json'] = encode([
        'fixture' => $case['fixture'],
        'pooledPatterns' => $case['pooledPatterns'],
        'pooledTypes' => $case['pooledTypes'],
    ]);

    if (null === $result['resolved']) {
        $files[$name . '/errors.json'] = encode(array_map(static fn (SpecError $e): array => [
            'code' => $e->code,
            'file' => relative($e->file, $directory),
            'pointer' => $e->pointer,
        ], $result['errors']));
    } else {
        $files[$name . '/resolved.json'] = encode($result['resolved']);
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
