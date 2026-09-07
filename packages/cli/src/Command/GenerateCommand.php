<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Eleph\Cli\Codegen;
use Eleph\Cli\Integrations;
use Eleph\Cli\ProjectConfig;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Wire\IrCodec;
use Eleph\WordPress\Manifest\StorageManifestBuilder;
use Eleph\WordPress\Manifest\StorageManifestExporter;
use Eleph\WPGraphQL\Integration\WpGraphQL;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use Eleph\WPGraphQL\Manifest\ManifestExporter;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compiles the specs and hands them to the code generator.
 *
 * The division is the whole point: this half knows what a spec means and nothing about
 * what a file looks like; `eleph-codegen` knows how to run builders, sign and write, and
 * nothing about YAML. Between them is one JSON document on a pipe, so the generator can
 * be rewritten in another language without this changing at all.
 *
 * Spec errors are reported here, because they are about the spec. Everything after —
 * a missing builder, a target that rejected its config, a tree that has drifted — is
 * reported by the generator, and its output is forwarded rather than re-rendered.
 * `--check` and `--targets` are passed straight through for the same reason.
 */
#[AsCommand(
    name: 'generate',
    description: 'Compile the specs and generate every configured target. Use --check to verify without writing.',
)]
final class GenerateCommand extends Command
{
    public const MANIFEST_PATH = 'graphql-manifest.php';

    public const STORAGE_MANIFEST_PATH = 'storage-manifest.php';

    /**
     * The target the manifests are written alongside.
     *
     * They are PHP the WordPress adaptor loads by path at boot, so what this names is
     * the target whose output directory they belong in — not a generator this knows.
     */
    private const PHP_TARGET = 'php';

    protected function configure(): void
    {
        $this->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'Report what would change and fail if anything would, without writing.',
        );

        $this->addOption(
            'project',
            'p',
            InputOption::VALUE_REQUIRED,
            'Directory holding eleph.json.',
            '.',
        );

        $this->addOption(
            'targets',
            't',
            InputOption::VALUE_REQUIRED,
            'Comma-separated targets to generate. Every configured target if omitted.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $io->error('--project must be a directory path.');

            return Command::INVALID;
        }

        $config = ProjectConfig::load($directory);
        $root = rtrim($directory, '/');

        $compiled = (new SchemaCompiler(integrations: Integrations::registry()))->compile(
            new SpecSource($root . '/' . $config->specDirectory),
        );

        if (!$compiled->isSuccess()) {
            $io->error(sprintf('%d problem(s) in the specs; nothing was generated.', count($compiled->errors)));

            foreach ($compiled->errors as $error) {
                $io->writeln('  ' . $error->describe());
            }

            return Command::FAILURE;
        }

        $schema = $compiled->schema();

        try {
            $request = $this->request($schema);
        } catch (JsonException $exception) {
            $io->error('Could not encode the compiled spec: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        try {
            return (new Codegen($root, $config->codegen))->run($this->arguments($input, $root), $request, $output);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function arguments(InputInterface $input, string $root): array
    {
        $arguments = ['generate', '--project', $root];

        if (true === $input->getOption('check')) {
            $arguments[] = '--check';
        }

        $targets = $input->getOption('targets');

        if (is_string($targets) && '' !== $targets) {
            $arguments[] = '--targets';
            $arguments[] = $targets;
        }

        return $arguments;
    }

    /**
     * @throws JsonException
     */
    private function request(Schema $schema): string
    {
        $files = [];

        foreach ($this->manifests($schema) as $path => $body) {
            $files[] = ['path' => $path, 'body' => $body];
        }

        return json_encode([
            'elephentity' => 1,
            'irVersion' => IrCodec::VERSION,
            'schema' => IrCodec::encode($schema),
            'files' => (object) ([] === $files ? [] : [self::PHP_TARGET => $files]),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The manifests the runtime needs at boot, which the IR that produced them does not
     * survive to answer.
     *
     * They are compiled here rather than by a builder because building them needs code
     * that knows what WordPress is, which is exactly what the generator is built not to
     * know. So they travel with the request, addressed to the PHP target, and are signed
     * and written with it — nothing on disk says which side of the pipe they came from.
     *
     * docs/PLAN.md §15 has them becoming targets of their own eventually. That would move
     * them to new directories the WordPress plugin loads by path at boot, so it is a
     * change with a runtime consequence rather than a tidy-up.
     *
     * @return array<string, string>
     */
    private function manifests(Schema $schema): array
    {
        $files = [];

        // The physical schema, for whichever driver the project declared. The adaptor
        // needs it at run time.
        if ('wordpress' === $schema->project->driver) {
            $files[self::STORAGE_MANIFEST_PATH] = (new StorageManifestExporter())->export(
                (new StorageManifestBuilder())->build($schema),
            );
        }

        // Only when the project speaks GraphQL: a project that does not should not find
        // a manifest in its tree wondering where it came from.
        if ($schema->project->speaks(WpGraphQL::NAME)) {
            $files[self::MANIFEST_PATH] = (new ManifestExporter())->export(
                (new ManifestBuilder())->build($schema),
            );
        }

        return $files;
    }
}
