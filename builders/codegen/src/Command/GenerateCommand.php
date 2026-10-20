<?php

declare(strict_types=1);

namespace Eleph\Codegen\Command;

use Eleph\Codegen\Builders;
use Eleph\Codegen\Config\ProjectConfig;
use Eleph\Codegen\Config\TargetConfig;
use Eleph\Codegen\External\ExternalTarget;
use Eleph\Codegen\GeneratedFile;
use Eleph\Codegen\Output\Writer;
use Eleph\Codegen\Output\WriteReport;
use Eleph\Codegen\Protocol\CompilerRequest;
use Eleph\Codegen\Protocol\Envelope;
use Eleph\Codegen\Protocol\ProtocolException;
use Eleph\Codegen\Signing\Signer;
use Eleph\Codegen\TargetRequest;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs every configured builder and writes what they return.
 *
 * The compiled spec arrives on stdin; this command owns everything after it. It
 * resolves each target's builder, runs it, pools the errors, and only then signs and
 * writes — so a project with two broken builders is told about both, and a build that
 * is going to fail writes nothing at all.
 *
 * With --check nothing is written: every tree is regenerated in memory and compared
 * against disk. That is the CI gate, and it catches all three ways a tree can drift —
 * a hand-edited file, a stale file the spec no longer produces, and a deleted one.
 */
#[AsCommand(
    name: 'generate',
    description: 'Run every configured builder and write its tree. Use --check to verify without writing.',
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
        $check = true === $input->getOption('check');
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $io->error('--project must be a directory path.');

            return Command::INVALID;
        }

        try {
            $request = $this->read();
        } catch (ProtocolException|RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $config = ProjectConfig::load($directory);
        $root = rtrim($directory, '/');

        try {
            $selected = $this->selected($input, $config);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $errors = [];
        /** @var array<string, array{directory: string, files: list<GeneratedFile>, signer: Signer, extensions: list<string>, reserved: list<string>}> $plan */
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
                $request->schema,
            );

            foreach ($response->errors as $problem) {
                $errors[] = sprintf('[%s] %s', $name, $problem);
            }

            if (!$response->isSuccess()) {
                continue;
            }

            $files = $response->files;

            // What the compiler produced for this target is written with it, signed the
            // same way and swept by the same rules. Nothing downstream can tell which
            // side of the pipe a file came from, which is the point.
            foreach ($request->contributedTo($name) as $contributed) {
                $files[] = $contributed;
            }

            $plan[$name] = [
                'directory' => $outputDirectory,
                'files' => $files,
                'signer' => new Signer($response->headerStyle),
                'extensions' => $response->extensions,
                'reserved' => $config->reservedIn($name),
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
            $writer = new Writer(
                $step['directory'],
                $step['signer'],
                $step['extensions'],
                $step['reserved'],
            );
            $reports[$name] = $check ? $writer->check($step['files']) : $writer->write($step['files']);
        }

        return $check
            ? $this->reportCheck($io, $reports)
            : $this->reportWrite($io, $reports, $plan);
    }

    /**
     * The compiled spec, from stdin.
     *
     * A file rather than an argument because an IR of any size is larger than a command
     * line will hold, and a path would mean deciding who cleans it up.
     */
    private function read(): CompilerRequest
    {
        $stdin = file_get_contents('php://stdin');

        if (false === $stdin || '' === trim($stdin)) {
            throw new RuntimeException(
                'Expected a compiled spec on stdin. `eleph-codegen generate` is run by '
                . '`eleph generate`, which compiles the specs and pipes them in.',
            );
        }

        return CompilerRequest::decode(Envelope::fromJson($stdin));
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
     * @param array<string, WriteReport>                                                                                     $reports
     * @param array<string, array{directory: string, files: list<GeneratedFile>, signer: Signer, extensions: list<string>, reserved: list<string>}> $plan
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
