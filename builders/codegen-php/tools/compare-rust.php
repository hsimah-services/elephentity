<?php

declare(strict_types=1);

// Run from any directory after cargo build and composer install. The PHP implementation
// is retained only as an independent migration oracle; production never invokes it.
require __DIR__ . '/../vendor/autoload.php';

function exchange(array $command, string $request): array
{
    $input = tmpfile();
    $output = tmpfile();
    $errors = tmpfile();
    fwrite($input, $request);
    rewind($input);
    $process = proc_open($command, [$input, $output, $errors], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run ' . implode(' ', $command));
    }
    $status = proc_close($process);
    rewind($output);
    rewind($errors);
    $stdout = stream_get_contents($output);
    $stderr = stream_get_contents($errors);
    fclose($input);
    fclose($output);
    fclose($errors);
    if ($status !== 0 || $stderr !== '') {
        throw new RuntimeException(implode(' ', $command) . ": exit $status: $stderr");
    }
    return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
}

function canonical(string $body): string
{
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
    $nodes = $parser->parse("<?php\n" . $body);
    $names = new PhpParser\NodeTraverser(new PhpParser\NodeVisitor\NameResolver());
    $nodes = $names->traverse($nodes);
    $clean = new PhpParser\NodeTraverser(new class extends PhpParser\NodeVisitorAbstract {
        public function leaveNode(PhpParser\Node $node): ?int
        {
            $node->setAttributes([]);
            return $node instanceof PhpParser\Node\Stmt\Use_ || $node instanceof PhpParser\Node\Stmt\GroupUse
                ? PhpParser\NodeVisitor::REMOVE_NODE : null;
        }
    });
    return (new PhpParser\PrettyPrinter\Standard())->prettyPrint($clean->traverse($nodes));
}

function compare(array $expected, array $actual, string $case): void
{
    $old = array_column($expected['files'], 'body', 'path');
    $new = array_column($actual['files'], 'body', 'path');
    if (array_keys($old) !== array_keys($new) || $expected['errors'] !== $actual['errors']) {
        throw new RuntimeException("$case: file paths or errors changed");
    }
    foreach ($old as $path => $body) {
        $left = canonical($body);
        $right = canonical($new[$path]);
        if ($left !== $right) {
            file_put_contents(__DIR__ . '/../target/parity-old.php', $left);
            file_put_contents(__DIR__ . '/../target/parity-new.php', $right);
            throw new RuntimeException("$case/$path: PHP AST differs; see target/parity-{old,new}.php");
        }
    }
    echo "$case: " . count($old) . " files agree\n";
}

$root = dirname(__DIR__);
$binary = [$root . '/bin/eleph-gen-php'];
$reference = [PHP_BINARY, $root . '/bin/eleph-gen-php-reference'];
foreach (glob($root . '/tests/fixtures/golden/*/request.json') as $path) {
    $request = file_get_contents($path);
    $expected = json_decode(file_get_contents(dirname($path) . '/response.reference.json'), true, 512, JSON_THROW_ON_ERROR);
    // Frozen snapshots belong to their original IR contract. Keep them intact;
    // a newer contract is compared with the independently maintained PHP generator.
    $version = json_decode($request, true, 512, JSON_THROW_ON_ERROR)['irVersion'];
    if ($expected['irVersion'] !== $version) {
        $expected = exchange($reference, $request);
    }
    compare($expected, exchange($binary, $request), basename(dirname($path)));
}

$base = json_decode(file_get_contents($root . '/tests/fixtures/golden/valid/request.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = [];
$policy = $base;
$entity = &$policy['schema']['entities']['Post'];
$origin = ['pattern' => null, 'file' => 'policy.yml'];
$entity['readPolicies'] = ['visible' => ['name' => 'visible', 'origin' => $origin]];
$entity['writePolicies'] = ['owner' => ['name' => 'owner', 'origin' => $origin]];
$entity['terminalRead'] = 'allow';
$cases['entity policies'] = $policy;
unset($entity);

$pattern = $base;
$origin['pattern'] = 'Auditable';
$pattern['schema']['patterns']['Auditable']['readPolicies'] = ['visible' => ['name' => 'visible', 'origin' => $origin]];
$pattern['schema']['patterns']['Auditable']['writePolicies'] = ['owner' => ['name' => 'owner', 'origin' => $origin]];
$pattern['schema']['entities']['Post']['readPolicies'] = $pattern['schema']['patterns']['Auditable']['readPolicies'];
$pattern['schema']['entities']['Post']['writePolicies'] = $pattern['schema']['patterns']['Auditable']['writePolicies'];
$cases['pattern policies'] = $pattern;

$scalars = $base;
foreach (['int', 'float', 'bool', 'json', 'id'] as $primitive) {
    $scalars['schema']['entities']['Post']['fields']['extra' . ucfirst($primitive)] = [
        'name' => 'extra' . ucfirst($primitive),
        'type' => ['primitive' => $primitive, 'declaredType' => null],
        'origin' => ['pattern' => null, 'file' => 'scalars.yml'],
        'nullable' => true,
        'verify' => true,
    ];
}
$scalars['schema']['types']['PostStatus']['primitive'] = 'int';
$cases['primitive conversions and integer enums'] = $scalars;

$enumAction = $base;
$enumAction['schema']['entities']['Post']['actions']['publish']['arguments']['at']['type'] = ['primitive' => null, 'declaredType' => 'PostStatus'];
$cases['declared enum action arguments'] = $enumAction;

$processor = json_decode(file_get_contents($root . '/tests/fixtures/golden/runtime-contracts/request.json'), true, 512, JSON_THROW_ON_ERROR);
$processor['schema']['types']['Amount']['primitive'] = 'datetime';
$processor['schema']['entities']['Page']['fields']['amount']['default'] = '2026-10-10T00:00:00Z';
$processor['schema']['entities']['Page']['actions']['publish'] = $base['schema']['entities']['Post']['actions']['publish'];
$processor['schema']['entities']['Page']['actions']['publish']['arguments']['at']['type'] = ['primitive' => null, 'declaredType' => 'Amount'];
$cases['datetime processor fields and action arguments'] = $processor;

$edges = $base;
$edges['schema']['entities']['Post']['edges']['related'] = [
    'name' => 'related', 'to' => 'Post', 'cardinality' => 'many',
    'origin' => ['pattern' => null, 'file' => 'edges.yml'],
    'onDelete' => 'cascade',
    'inverse' => ['derived' => false, 'name' => 'relatedFrom', 'unique' => false],
];
$cases['self relations and cascade deletion'] = $edges;

foreach ($cases as $name => $request) {
    $encoded = json_encode($request, JSON_THROW_ON_ERROR);
    compare(exchange($reference, $encoded), exchange($binary, $encoded), $name);
}
