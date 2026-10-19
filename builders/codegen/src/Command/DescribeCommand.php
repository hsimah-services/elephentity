<?php

declare(strict_types=1);

namespace Eleph\Codegen\Command;

use Eleph\Codegen\Builders;
use Eleph\Codegen\Config\ProjectConfig;
use Eleph\Codegen\External\ExternalTarget;
use Eleph\Codegen\Protocol\Description;
use Eleph\Codegen\Protocol\Envelope;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Asks every configured builder what it provides, and prints the answers as JSON.
 *
 * This runs *before* a spec is compiled, and that ordering is the reason it exists.
 * The compiler cannot validate `integrations: { wpgraphql: { singular: … } }` until it
 * knows what keys `wpgraphql` accepts, and the package that owns that answer is the one
 * that also generates the GraphQL manifest — a builder. So the compiler asks first and
 * compiles second.
 *
 * The answers are pooled and forwarded, never interpreted. This program does not know
 * what an integration is, exactly as it does not know what an entity is, and a builder
 * that starts providing some new kind of thing needs a new compiler and no release of
 * this. See docs/PROTOCOL.md.
 *
 * Machine output, so it goes to stdout unadorned and nothing else does. Failures go to
 * stderr and set the exit code, because a compiler that carried on with a partial
 * answer would report "unknown integration" for one that is installed and merely
 * unreachable.
 */
#[AsCommand(
    name: 'describe',
    description: 'Print what every configured builder provides, as JSON.',
)]
final class DescribeCommand extends Command
{
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getOption('project');

        if (!is_string($directory)) {
            $this->fail($output, '--project must be a directory path.');

            return Command::INVALID;
        }

        $config = ProjectConfig::load($directory);
        $root = rtrim($directory, '/');
        $builders = new Builders($root, $config->buildersDirectory);

        $described = [];
        $errors = [];

        foreach ($config->targets as $name => $target) {
            try {
                $description = (new ExternalTarget($name, $builders->resolve($target)))->describe();
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();

                continue;
            }

            foreach ($description->errors as $problem) {
                $errors[] = sprintf('[%s] %s', $name, $problem);
            }

            if ($description->isSuccess()) {
                $described[$name] = $description;
            }
        }

        if ([] !== $errors) {
            // Every builder is asked before any failure is reported, so a project with
            // two unreachable builders learns about both in one run.
            $this->fail($output, implode("\n", $errors));

            return Command::FAILURE;
        }

        try {
            $output->writeln($this->json($described));
        } catch (JsonException $exception) {
            $this->fail($output, 'Could not encode the descriptions: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, Description> $described
     *
     * @throws JsonException
     */
    private function json(array $described): string
    {
        $targets = [];

        foreach ($described as $name => $description) {
            $targets[$name] = (object) $description->provides;
        }

        return Envelope::toJson([
            'elephentity' => Envelope::VERSION,
            'irVersion' => Envelope::IR_VERSION,
            'targets' => (object) $targets,
        ]);
    }

    /**
     * Problems go to stderr, so stdout stays parseable.
     *
     * A caller pipes this straight into a JSON decoder; a diagnostic on the same stream
     * would corrupt the one output the command exists to produce.
     */
    private function fail(OutputInterface $output, string $message): void
    {
        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)
            ->writeln($message);
    }
}
