<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Eleph\Cli\Builders;
use Eleph\Cli\Integrations;
use Eleph\Cli\ProjectConfig;
use Eleph\Cli\TargetConfig;
use Eleph\Codegen\External\ExternalTarget;
use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Output\Writer;
use Eleph\Codegen\Output\WriteReport;
use Eleph\Codegen\Signing\Signer;
use Eleph\Codegen\TargetRequest;
use Eleph\Schema\Ir\Schema;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\WordPress\Manifest\StorageManifestBuilder;
use Eleph\WordPress\Manifest\StorageManifestExporter;
use Eleph\WPGraphQL\Integration\WpGraphQL;
use Eleph\WPGraphQL\Manifest\ManifestBuilder;
use Eleph\WPGraphQL\Manifest\ManifestExporter;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compiles the specs and writes each configured target's tree.
 *
 * With --check nothing is written: every tree is regenerated in memory and compared
 * against disk. That is the CI gate, and it catches all three ways a tree can drift —
 * a hand-edited file, a stale file the schema no longer produces, and a deleted one.
 *
 * Targets run before anything is written, and their problems are pooled with the
 * config's, so a project with two misconfigured targets is told about both at once.
 */
#[AsCommand(
    name: 'generate',
    description: 'Generate every configured target from the specs. Use --check to verify without writing.',
)]
final class GenerateCommand extends Command
{
    public const MANIFEST_PATH = 'graphql-manifest.php';

    public const STORAGE_MANIFEST_PATH = 'storage-manifest.php';

    /**
     * The target the manifests are written alongside.
     *
     * A bare string rather than a constant borrowed from the generator: the manifests
     * are PHP that the WordPress adaptor loads by path at boot, so what this names is
     * the target whose output directory they belong in, not a generator the core knows.
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
        $check = true === $input->getOption('check');
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
            $selected = $this->selected($input, $config);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $errors = [];
        /** @var array<string, array{directory: string, files: list<GeneratedFile>, signer: Signer, extensions: list<string>}> $plan */
        $plan = [];

        $builders = new Builders($root, $config->buildersDirectory);

        foreach ($selected as $name => $targetConfig) {
            try {
                $target = new ExternalTarget($name, $builders->resolve($targetConfig));
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();

                continue;
            }

            $outputDirectory = $root . '/' . $targetConfig->outputDirectory;
            $response = $target->generate(
                TargetRequest::of($outputDirectory, $targetConfig->settings),
                $schema,
            );

            foreach ($response->errors as $problem) {
                $errors[] = sprintf('[%s] %s', $name, $problem);
            }

            if (!$response->isSuccess()) {
                continue;
            }

            $files = $response->files;

            if (self::PHP_TARGET === $name) {
                foreach ($this->manifests($schema) as $manifest) {
                    $files[] = $manifest;
                }
            }

            $plan[$name] = [
                'directory' => $outputDirectory,
                'files' => $files,
                'signer' => new Signer($response->headerStyle),
                'extensions' => $response->extensions,
            ];
        }

        if ([] !== $errors) {
            $io->error(sprintf('%d problem(s) with the targets; nothing was generated.', count($errors)));

            foreach ($errors as $error) {
                $io->writeln('  ' . $error);
            }

            return Command::FAILURE;
        }

        $reports = [];

        foreach ($plan as $name => $step) {
            $writer = new Writer($step['directory'], $step['signer'], $step['extensions']);
            $reports[$name] = $check ? $writer->check($step['files']) : $writer->write($step['files']);
        }

        return $check
            ? $this->reportCheck($io, $reports)
            : $this->reportWrite($io, $reports, $plan);
    }

    /**
     * The targets this run covers, in the order eleph.json declares them.
     *
     * Narrowing is for iterating on one generator without waiting for the rest; CI
     * should pass no --targets at all, because a check that skips a target is a check
     * that stops noticing it has drifted.
     *
     * An unknown name is refused rather than ignored. A typo that silently generates
     * nothing looks exactly like a target that had nothing to do.
     *
     * @return array<string, TargetConfig>
     */
    private function selected(InputInterface $input, ProjectConfig $config): array
    {
        $requested = $input->getOption('targets');

        if (null === $requested) {
            return $config->targets;
        }

        if (!is_string($requested) || '' === trim($requested)) {
            throw new InvalidArgumentException('--targets needs at least one target name.');
        }

        $names = array_values(array_filter(
            array_map(trim(...), explode(',', $requested)),
            static fn (string $name): bool => '' !== $name,
        ));
        $selected = [];
        $unknown = [];

        foreach ($names as $name) {
            $target = $config->target($name);

            if (null === $target) {
                $unknown[] = $name;

                continue;
            }

            $selected[$name] = $target;
        }

        if ([] !== $unknown) {
            throw new InvalidArgumentException(sprintf(
                'Unknown target(s): %s. This project configures: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys($config->targets)),
            ));
        }

        return $selected;
    }

    /**
     * The manifests the runtime needs at boot, which the IR that produced them does not
     * survive to answer.
     *
     * Still emitted alongside the PHP target rather than as targets of their own, which
     * is where docs/PLAN.md §15 says they end up. Folding them in is a separate change
     * from moving the generator, and doing both at once would make the diff unreadable.
     *
     * @return list<GeneratedFile>
     */
    private function manifests(Schema $schema): array
    {
        $files = [];

        // The physical schema, for whichever driver the project declared. The adaptor
        // needs it at run time.
        if ('wordpress' === $schema->project->driver) {
            $files[] = new GeneratedFile(
                self::STORAGE_MANIFEST_PATH,
                (new StorageManifestExporter())->export(
                    (new StorageManifestBuilder())->build($schema),
                ),
            );
        }

        // Only when the project speaks GraphQL: a project that does not should not find
        // a manifest in its tree wondering where it came from.
        if ($schema->project->speaks(WpGraphQL::NAME)) {
            $files[] = new GeneratedFile(
                self::MANIFEST_PATH,
                (new ManifestExporter())->export((new ManifestBuilder())->build($schema)),
            );
        }

        return $files;
    }

    /**
     * @param array<string, WriteReport> $reports
     */
    private function reportCheck(SymfonyStyle $io, array $reports): int
    {
        $dirty = array_filter($reports, static fn (WriteReport $report) => !$report->isClean());

        if ([] === $dirty) {
            $unchanged = array_sum(array_map(
                static fn (WriteReport $report) => count($report->unchanged),
                $reports,
            ));

            $io->success(sprintf(
                'Every target is up to date (%d target(s), %d files).',
                count($reports),
                $unchanged,
            ));

            return Command::SUCCESS;
        }

        $io->error(sprintf(
            '%d of %d target(s) are out of date.',
            count($dirty),
            count($reports),
        ));

        foreach ($dirty as $name => $report) {
            $io->section(sprintf('%s — %d file(s) differ', $name, $report->changeCount()));

            $this->list($io, 'Hand-edited', $report->tampered);
            $this->list($io, 'Would be created', $report->created);
            $this->list($io, 'Would be updated', $report->updated);
            $this->list($io, 'No longer produced by the schema', $report->deleted);
        }

        $io->writeln('Run <info>eleph generate</info> and commit the result.');

        return Command::FAILURE;
    }

    /**
     * @param array<string, WriteReport>                                                       $reports
     * @param array<string, array{directory: string, files: list<GeneratedFile>, signer: Signer, extensions: list<string>}> $plan
     */
    private function reportWrite(SymfonyStyle $io, array $reports, array $plan): int
    {
        foreach ($reports as $name => $report) {
            foreach ($report->tampered as $path) {
                $io->warning(sprintf('[%s] %s had been edited by hand; it has been regenerated.', $name, $path));
            }
        }

        foreach ($reports as $name => $report) {
            $io->writeln(sprintf(
                '<info>%s</info>: %d file(s): %d created, %d updated, %d unchanged, %d removed.',
                $name,
                count($plan[$name]['files']),
                count($report->created),
                count($report->updated),
                count($report->unchanged),
                count($report->deleted),
            ));
        }

        $io->success(sprintf('%d target(s) generated.', count($reports)));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $paths
     */
    private function list(SymfonyStyle $io, string $heading, array $paths): void
    {
        if ([] === $paths) {
            return;
        }

        $io->writeln(sprintf('<comment>%s:</comment>', $heading));

        foreach ($paths as $path) {
            $io->writeln('  ' . $path);
        }

        $io->writeln('');
    }
}
