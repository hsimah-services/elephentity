<?php

declare(strict_types=1);

namespace Eleph\Cli\Command;

use Closure;
use Eleph\Cli\ClassMap;
use Eleph\Cli\Codegen;
use Eleph\Cli\ProjectConfig;
use Eleph\Runtime\Conformance\Severity;
use Eleph\Runtime\Conformance\Signal;
use Eleph\Runtime\Conformance\Verifier;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Proves the generated tree is internally coherent.
 *
 * Distinct from `generate --check`, which asks whether the tree matches the spec. This
 * asks whether the tree agrees with *itself*: every integration ships a verifier that
 * knows what coherence means for its own surface — "every GraphQL field resolves to a
 * method that exists," for WPGraphQL — and this walks the tree collecting what they
 * find. Conformance is therefore a question about an integration, never about this
 * command, which knows no integration's vocabulary and compiles no spec.
 *
 * A lazy integration ships no verifier and this is silent for it, which is the correct
 * behaviour for a project with no integrations at all: nothing to check is not a
 * failure to check it.
 */
#[AsCommand(
    name: 'check',
    description: 'Verify every generated integration is internally coherent.',
)]
final class CheckCommand extends Command
{
    /**
     * Conformance is a statement about generated PHP classes, so it names the PHP
     * target specifically: a project generating only TypeScript has no classes for a
     * verifier to resolve against.
     */
    private const PHP_TARGET = 'php';

    protected function configure(): void
    {
        $this->addOption(
            'project',
            'p',
            InputOption::VALUE_REQUIRED,
            'Directory holding eleph.json.',
            '.',
        );
    }

    /**
     * Where the PHP target writes, according to the program that writes it.
     *
     * @throws RuntimeException When the generator cannot be found or cannot answer.
     */
    private function outputDirectory(string $root, ProjectConfig $config): ?string
    {
        $json = (new Codegen($root, $config->codegen))->capture(['targets', '--project', $root]);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf(
                'The generator did not describe its targets: %s',
                $exception->getMessage(),
            ));
        }

        $targets = is_array($decoded) ? ($decoded['targets'] ?? null) : null;
        $php = is_array($targets) ? ($targets[self::PHP_TARGET] ?? null) : null;
        $output = is_array($php) ? ($php['output'] ?? null) : null;

        if (null !== $php && (!is_string($output) || '' === $output)) {
            throw new RuntimeException(sprintf(
                'The generator described the "%s" target without an output directory.',
                self::PHP_TARGET,
            ));
        }

        return is_string($output) ? $output : null;
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

        // The generator parses the targets block, so it is asked rather than second
        // guessed. Two parsers of one file eventually disagree about something small,
        // and the disagreement surfaces as a gate checking the wrong directory.
        try {
            $outputDirectory = $this->outputDirectory($root, $config);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (null === $outputDirectory) {
            $io->error(sprintf(
                'No "%s" target in %s; there are no generated classes to check against.',
                self::PHP_TARGET,
                ProjectConfig::FILENAME,
            ));

            return Command::FAILURE;
        }

        $tree = $root . '/' . $outputDirectory;

        // The tree says what it contains. Asking the generator for that too would mean
        // this gate could only run where the PHP builder is installed, which is exactly
        // the coupling a builder is meant not to have.
        try {
            $classes = ClassMap::load($tree);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $classes->autoload();

        $verifiers = glob($tree . '/*/verify.php') ?: [];
        $signals = [];

        foreach ($verifiers as $file) {
            foreach ($this->runVerifier($file, $classes->classFor(...)) as $signal) {
                $signals[] = $signal;
            }
        }

        foreach ($signals as $signal) {
            $line = sprintf('  [%s] %s', $signal->severity->value, $signal->message);

            match ($signal->severity) {
                Severity::Error => $io->writeln('<error>' . $line . '</error>'),
                Severity::Warning => $io->writeln('<comment>' . $line . '</comment>'),
                Severity::Info => $io->writeln($line),
            };
        }

        $errors = array_values(array_filter(
            $signals,
            static fn (Signal $signal): bool => Severity::Error === $signal->severity,
        ));

        if ([] !== $errors) {
            $io->error(sprintf('%d conformance error(s).', count($errors)));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Conformant: %d verifier(s), %d signal(s).',
            count($verifiers),
            count($signals),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param Closure(string): string $classFor
     *
     * @return list<Signal>
     */
    private function runVerifier(string $file, Closure $classFor): array
    {
        try {
            /** @var mixed $verifier */
            $verifier = require $file;
        } catch (Throwable $exception) {
            return [new Signal(
                Severity::Error,
                sprintf('%s could not be loaded: %s. Run `eleph generate`.', $file, $exception->getMessage()),
            )];
        }

        if (!$verifier instanceof Verifier) {
            return [new Signal(
                Severity::Error,
                sprintf('%s did not return a verifier. Run `eleph generate`.', $file),
            )];
        }

        return $verifier->verify($classFor);
    }
}
