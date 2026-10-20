<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function fails(callable $work, string $message): void
{
    try { $work(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}
function fixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true, flags: JSON_THROW_ON_ERROR);
}
function generated(array $request, callable $work): void
{
    $binary = dirname(__DIR__) . '/bin/eleph-gen-graphql-php';
    $process = proc_open([$binary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($process) === 0, $stderr);
    $response = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    check($response['errors'] === [], json_encode($response['errors']));
    $directory = sys_get_temp_dir() . '/eleph-generated-' . bin2hex(random_bytes(8));
    mkdir($directory);
    try {
        foreach ($response['files'] as $file) {
            check(basename($file['path']) === $file['path'], 'Unexpected output path');
            file_put_contents($directory . '/' . $file['path'], "<?php\ndeclare(strict_types=1);\n\n" . $file['body']);
        }
        $work($directory);
    } finally {
        foreach (glob($directory . '/*') as $path) unlink($path);
        rmdir($directory);
    }
}
