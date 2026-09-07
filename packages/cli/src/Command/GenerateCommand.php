<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Eleph\Cli\Codegen;
use Eleph\Cli\Installed;
use Eleph\Cli\ProjectConfig;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Wire\IrCodec;
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
 *
 * The generator is asked *twice*: once to describe what the project's builders provide,
 * before the specs can be read, and again to generate once they have been. What a spec
 * may say depends on what is installed — which integrations it may name, which driver
 * it may declare — and this half knows neither until it asks.
 */
#[AsCommand(
    name: 'generate',
    description: 'Compile the specs and generate every configured target. Use --check to verify without writing.',
)]
final class GenerateCommand extends Command
{
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
        $codegen = new Codegen($root, $config->codegen);

        try {
            // Before the specs are read, because what it answers is what they are read
            // against: which integrations may be named, which drivers exist.
            $installed = Installed::describedBy($codegen, $root);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $compiled = (new SchemaCompiler(integrations: $installed->integrations))->compile(
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

        if (!$installed->provides($schema->project->driver)) {
            $io->error(sprintf(
                'No installed builder generates for storage driver "%s" (available: %s). '
                . 'Add a target for it in eleph.json, or change the driver in project.yml.',
                $schema->project->driver,
                $installed->describeDrivers(),
            ));

            return Command::FAILURE;
        }

        try {
            $request = $this->request($schema);
        } catch (JsonException $exception) {
            $io->error('Could not encode the compiled spec: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        try {
            return $codegen->run($this->arguments($input, $root), $request, $output);
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
        return json_encode([
            'elephentity' => 1,
            'irVersion' => IrCodec::VERSION,
            'schema' => IrCodec::encode($schema),
            // Nothing any more. The manifests are produced by the wordpress and
            // wpgraphql builders, which is what lets a project install neither.
            'files' => (object) [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
