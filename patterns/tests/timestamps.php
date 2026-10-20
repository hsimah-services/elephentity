<?php

declare(strict_types=1);

use App\Patterns\HasTimestamps\HasTimestampsTrigger;
use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Mutation\Mutation;
use Eleph\Runtime\SideEffect\SideEffectPhase;
use Eleph\Runtime\Type\NullProcessorRegistry;
use Eleph\Runtime\UnitOfWork\SideEffectDispatcher;
use Eleph\Runtime\UnitOfWork\VerificationPipeline;

require __DIR__ . '/support.php';

$workspace = $argv[1] ?? throw new RuntimeException('Pass the Elephentity workspace directory.');
require $workspace . '/elephentity-runtime/vendor/autoload.php';
require __DIR__ . '/../patterns/HasTimestamps/HasTimestampsTrigger.php';

$directory = temporaryProject('eleph-timestamps');

try {
    file_put_contents($directory . '/spec/project.yml', "project: TimestampTest\nstorage:\n  driver: memory\n");
    copy(__DIR__ . '/../patterns/HasTimestamps/pattern.yml', $directory . '/spec/patterns/HasTimestamps.yml');
    foreach (['Article', 'Comment'] as $name) {
        file_put_contents($directory . '/spec/entities/' . $name . '.yml', "entity: {$name}\nuse: [HasTimestamps]\nstorage:\n  table: " . strtolower($name) . "\n");
    }
    // eleph pipes the IR to eleph-codegen; a wrapper in front of it keeps a copy, so the test
    // can read what the compiler made of the pattern without a second compiler to ask.
    $codegen = $workspace . '/elephentity-codegen/bin/eleph-codegen';
    $irFile = $directory . '/ir.json';
    file_put_contents($directory . '/codegen', sprintf(
        "#!/bin/sh\ncase \" \$* \" in\n  *\" generate \"*) tee %s | %s \"\$@\" ;;\n  *) exec %s \"\$@\" ;;\nesac\n",
        escapeshellarg($irFile),
        escapeshellarg($codegen),
        escapeshellarg($codegen),
    ));
    chmod($directory . '/codegen', 0o755);
    writeConfig($directory, $workspace, ['php' => [
        'builder' => $workspace . '/elephentity-codegen-php/bin/eleph-gen-php',
        'output' => 'generated',
        'namespace' => 'TimestampTest',
        'typeNamespace' => 'TimestampTest\\Type',
    ]], $directory . '/codegen');
    runEleph($directory, ['generate']);
    $schema = json_decode(file_get_contents($irFile), true, flags: JSON_THROW_ON_ERROR)['schema'];
    $autoload = static function (string $class) use ($directory): void {
        if (str_starts_with($class, 'TimestampTest\\')) {
            $path = $directory . '/generated/' . str_replace('\\', '/', substr($class, strlen('TimestampTest\\'))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    };
    spl_autoload_register($autoload);

    $now = new DateTimeImmutable('2026-09-25T12:00:00Z');
    $calls = 0;
    $trigger = new class (static function () use (&$now, &$calls): DateTimeImmutable {
        ++$calls;
        return $now;
    }) extends HasTimestampsTrigger implements
        TimestampTest\Article\Contract\ArticleTimestampsSideEffect,
        TimestampTest\Comment\Contract\CommentTimestampsSideEffect
    {
    };
    $dispatcher = new SideEffectDispatcher([
        'Article' => new TimestampTest\Article\ArticleSideEffects($trigger),
        'Comment' => new TimestampTest\Comment\CommentSideEffects($trigger),
    ]);
    $verification = new VerificationPipeline([], [], new NullProcessorRegistry(), [
        'Article' => ['createdAt', 'updatedAt'],
        'Comment' => ['createdAt', 'updatedAt'],
    ]);

    foreach (['Article', 'Comment'] as $name) {
        foreach ($schema['entities'][$name]['fields'] as $field) {
            check(null === $field['managed'], 'Runtime stamping must not overwrite custom trigger values.');
        }
        $create = new Mutation($name, new PendingId($name));
        $create->set('createdAt', new DateTimeImmutable('2000-01-01Z'));
        $create->set('updatedAt', new DateTimeImmutable('2000-01-01Z'));
        $before = $calls;
        $dispatcher->dispatch(SideEffectPhase::PreCommit, [$create]);
        check($calls === $before + 1, 'Creation must read the clock once.');
        check($create->pending('createdAt') === $now && $create->pending('updatedAt') === $now, 'Creation must stamp both fields with the same instant.');
        check([] === $verification->verify($create), 'Trigger must fill required timestamps before verification.');
        $createdAt = $now;
        $updatedAt = $now;

        for ($i = 0; $i < 2; ++$i) {
            $now = $now->modify('+1 hour');
            $update = new Mutation($name, EntityId::of(1), ['createdAt' => $createdAt, 'updatedAt' => $updatedAt]);
            if (0 === $i) {
                $update->set('updatedAt', new DateTimeImmutable('2000-01-01Z'));
            }
            $dispatcher->dispatch(SideEffectPhase::PreCommit, [$update]);
            check(!$update->isChanged('createdAt') && $update->pending('createdAt') === $createdAt, 'Update must leave creation time untouched.');
            check($update->pending('updatedAt') === $now, 'Every update, including an empty update, must refresh modification time.');
            $updatedAt = $now;
        }

        $deletion = new Mutation($name, EntityId::of(1), ['createdAt' => $createdAt, 'updatedAt' => $updatedAt]);
        $before = $calls;
        $dispatcher->dispatchDeletions(SideEffectPhase::PreCommit, [$deletion]);
        $dispatcher->dispatchDeletions(SideEffectPhase::PostCommit, [$deletion]);
        $dispatcher->dispatch(SideEffectPhase::PostCommit, [$create, $update]);
        check($calls === $before && [] === $deletion->changes(), 'Delete and post-commit dispatch must not invoke the trigger.');

        $invalid = new Mutation($name, EntityId::of(1), ['createdAt' => $createdAt]);
        $invalid->set('createdAt', $now);
        try {
            $dispatcher->dispatch(SideEffectPhase::PreCommit, [$invalid]);
            throw new RuntimeException('A write to createdAt on update must be rejected.');
        } catch (DomainException) {
            check(!$invalid->isChanged('updatedAt'), 'Rejected creation-time edits must not stamp modification time.');
        }
    }
    $defaultTrigger = new class extends HasTimestampsTrigger {};
    $mutation = new Mutation('Article', new PendingId('Article'));
    $before = new DateTimeImmutable();
    $defaultTrigger->handle($mutation);
    $timestamp = $mutation->pending('createdAt');
    check($timestamp instanceof DateTimeImmutable && $timestamp >= $before && $timestamp <= new DateTimeImmutable(), 'Default clock must use the current time.');
    check('UTC' === $timestamp->getTimezone()->getName(), 'Default clock must use UTC.');
    echo "Timestamp trigger passes generated create/update/delete dispatch for two entities.\n";
} finally {
    if (isset($autoload)) {
        spl_autoload_unregister($autoload);
    }
    removeTree($directory);
}
