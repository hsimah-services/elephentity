<?php

declare(strict_types=1);

// Every ready catalog pattern compiles under the real compiler: `eleph validate` over a project
// with one entity using each, on the memory driver.

require __DIR__ . '/support.php';

$workspace = $argv[1] ?? throw new RuntimeException('Pass the Elephentity workspace directory.');
$catalog = json_decode(file_get_contents(__DIR__ . '/../catalog.json'), true, flags: JSON_THROW_ON_ERROR);
$project = temporaryProject('eleph-patterns');

try {
    file_put_contents($project . '/spec/project.yml', "project: CatalogTest\nstorage:\n  driver: memory\n");
    foreach ($catalog['patterns'] as $name => $entry) {
        if ('ready' !== $entry['status']) {
            continue;
        }
        copy(__DIR__ . '/../patterns/' . $name . '/pattern.yml', $project . '/spec/patterns/' . $name . '.yml');
        file_put_contents($project . '/spec/entities/' . $name . 'Example.yml', "entity: {$name}Example\nuse: [{$name}]\nstorage:\n  table: " . strtolower($name) . "_example\n");
    }
    writeConfig($project, $workspace, []);
    runEleph($project, ['validate']);
    echo "All ready catalog patterns compile successfully.\n";
} finally {
    removeTree($project);
}
