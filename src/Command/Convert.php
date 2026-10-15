<?php

namespace Tuchsoft\IssueReporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tuchsoft\IssueReporter\Base\LoadableInterface;
use Tuchsoft\IssueReporter\Factory;
use Tuchsoft\IssueReporter\Format\Base\ParsableFormatInterface;
use Tuchsoft\IssueReporter\Recipe\RecipeBuilder;
use Tuchsoft\IssueReporter\Recipe\RecipeException;
use Tuchsoft\IssueReporter\Recipe\RecipeLoader;
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Reporter;

/**
 * The `convert` command exposes the full read/merge/transform/format pipeline.
 *
 * It reads one or more input reports (each from any parsable format), merges them
 * into a single report, runs all enabled transformers, then generates the output
 * in the requested format using every available option.
 *
 * For complex operations a single recipe/config file can describe everything
 * (inputs, tree, options, output) via `--config`.
 */
class Convert extends Command
{

    protected static $defaultName = 'convert';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName(static::$defaultName)
            ->setDescription('Read, merge and transform issue reports, then output in the chosen format.')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command reads one or more issue reports, merges them into a
single report, applies the enabled transformers and renders the result in the output format.

Inputs are passed as arguments using the <info>[format:]path</info> syntax. When the <info>format</info>
prefix is omitted, the <info>--from</info> format is used. Use <info>-</info> to read from STDIN.

<info>%command.full_name% checkstyle.xml -t info</info>
<info>%command.full_name% phpcs:a.json raw:b.json --to sarif -o report.sarif --phpcs-pretty</info>
<info>cat report.json | %command.full_name% - --from raw --to infomd</info>

For complex operations use a recipe/config file that describes the whole run:

<info>%command.full_name% --config recipe.json</info>
<info>cat recipe.json | %command.full_name% --config -</info>

When <info>--config</info> is given, <comment>all other flags and arguments are ignored</comment>; the recipe is the
single source of truth. See docs/cli.md for the recipe schema.

Every format and transformer option is available; format-specific options may be
addressed either generically (<info>--pretty</info>) or per format (<info>--phpcs-pretty</info>),
the latter taking precedence when both are given.
HELP)
            ->addArgument(
                'input',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Input report(s), as "[format:]path". Use "-" to read STDIN. Format defaults to --from.'
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Recipe/config file describing the whole operation ("-" for STDIN). When given, all other flags/arguments are ignored.')
            ->addOption('register-namespace', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Register every format/transformer in a namespace (repeatable).')
            ->addOption('register-class', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Register a single format/transformer class (repeatable).')
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Default input format for inputs without an explicit "format:" prefix.', 'raw')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED, 'Output format. Defaults to the --from format.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file. Defaults to STDOUT.', 'php://stdout')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the resulting report.', 'Report')
            ->addOption('base-path', null, InputOption::VALUE_REQUIRED, 'Base path of the resulting report. Defaults to the current working directory.');

        // Expose every format option (generic + per-format prefixed).
        foreach (Reporter::getOptionsDefinition(LoadableInterface::OPTIONS_BOTH) as $option) {
            if (!$this->getDefinition()->hasOption($option->getName())) {
                $this->getDefinition()->addOption($option);
            }
        }

        // Expose every transformer option.
        foreach (Factory::getRegistered(Factory::TRANSFORMER) as $transformer) {
            foreach ($transformer::getOptionsDefinition(LoadableInterface::OPTIONS_BOTH) as $option) {
                if (!$this->getDefinition()->hasOption($option->getName())) {
                    $this->getDefinition()->addOption($option);
                }
            }
        }
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $err = $io->getErrorStyle();

        $config = $input->getOption('config');
        if ($config !== null) {
            return $this->runRecipe($err, $config);
        }

        return $this->runCli($input, $err);
    }

    /**
     * Runs the operation described by a recipe/config file.
     *
     * The recipe is the single source of truth: all other CLI flags and arguments are ignored.
     */
    private function runRecipe(SymfonyStyle $err, string $config): int
    {
        $stdin = null;
        if ($config === '-') {
            $stdin = $this->readStdin($err);
            if ($stdin === null) {
                return Command::FAILURE;
            }
        }

        $loader = new RecipeLoader();
        $builder = new RecipeBuilder();

        try {
            $recipe = $loader->load($config, $stdin);

            // Register any external classes declared in the recipe.
            $this->register([], $recipe['register'] ?? []);

            $from = $recipe['from'] ?? 'raw';
            $to = $recipe['to'] ?? $from;
            $output = $recipe['output'] ?? 'php://stdout';
            $options = $recipe['options'] ?? [];

            $registered = Factory::getRegistered(Factory::FORMAT);
            if (!isset($registered[$to])) {
                throw new RecipeException("Unknown output format: '$to'. Use `list-format` to see available formats.");
            }
            if (!isset($registered[$from]) || !is_a($registered[$from], ParsableFormatInterface::class, true)) {
                throw new RecipeException("Input format '$from' is unknown or not parsable. Use `list-format` to see parsable formats.");
            }

            $report = $builder->build($recipe, $options, $config !== '-');
        } catch (RecipeException $e) {
            $err->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $err->error("Failed to build report: {$e->getMessage()}");
            return Command::FAILURE;
        }

        try {
            Reporter::printReport($report, $to, $output, $options);
        } catch (\Throwable $e) {
            $err->error("Failed to generate '$to' output: {$e->getMessage()}");
            return Command::FAILURE;
        }

        if ($output !== 'php://stdout') {
            $err->success(sprintf("Report written to '%s' (%s).", $output, $to));
        }

        return Command::SUCCESS;
    }

    /**
     * Runs the operation described by CLI flags/arguments.
     */
    private function runCli(InputInterface $input, SymfonyStyle $err): int
    {
        $this->register($input->getOption('register-namespace'), $input->getOption('register-class'));

        $options = $input->getOptions();
        $from = $input->getOption('from');
        $to = $input->getOption('to') ?: $from;
        $target = $input->getOption('output');

        $registered = Factory::getRegistered(Factory::FORMAT);

        if (!isset($registered[$from])) {
            $err->error("Unknown input format: '$from'. Use `list-format` to see available formats.");
            return Command::FAILURE;
        }
        if (!is_a($registered[$from], ParsableFormatInterface::class, true)) {
            $err->error("The '$from' format cannot parse input (not parsable). Use `list-format` to see parsable formats.");
            return Command::FAILURE;
        }
        if (!isset($registered[$to])) {
            $err->error("Unknown output format: '$to'. Use `list-format` to see available formats.");
            return Command::FAILURE;
        }

        $basePath = $input->getOption('base-path') ?: getcwd();
        $root = new Report($input->getOption('name'), $basePath);
        $root->start();

        $reports = [];
        $usedNames = [];
        foreach ($input->getArgument('input') as $spec) {
            [$formatName, $path] = $this->resolveInput($spec, $from, $registered);

            $fqn = $registered[$formatName] ?? null;
            if ($fqn === null || !is_a($fqn, ParsableFormatInterface::class, true)) {
                $err->error("Cannot parse input '$spec': format '$formatName' is unknown or not parsable.");
                return Command::FAILURE;
            }

            try {
                $content = $this->readInput($path);
                $format = Factory::create(Factory::FORMAT, $formatName, $options);
                if (!$format instanceof ParsableFormatInterface) {
                    throw new \RuntimeException("Format '$formatName' is not parsable.");
                }
                $report = $format->parse($content, null);
            } catch (\Throwable $e) {
                $err->error("Failed to parse '$spec': {$e->getMessage()}");
                return Command::FAILURE;
            }

            // Avoid sub-report name collisions when merging.
            $name = $report->getName();
            if (isset($usedNames[$name])) {
                $name .= ' (' . (++$usedNames[$report->getName()]) . ')';
                $report->setName($name);
            }
            $usedNames[$name] = 1;

            $reports[] = $report;
        }

        $root->mergeIn(...$reports);
        $root->complete();

        try {
            Reporter::printReport($root, $to, $target, $options);
        } catch (\Throwable $e) {
            $err->error("Failed to generate '$to' output: {$e->getMessage()}");
            return Command::FAILURE;
        }

        if ($target !== 'php://stdout') {
            $err->success(sprintf("Report written to '%s' (%s).", $target, $to));
        }

        return Command::SUCCESS;
    }

    /**
     * Registers external format/transformer classes or namespaces for both types.
     *
     * @param string[] $namespaces
     * @param string[] $classes
     */
    private function register(array $namespaces, array $classes): void
    {
        foreach ([Factory::FORMAT, Factory::TRANSFORMER] as $type) {
            foreach ($namespaces as $namespace) {
                Factory::register($type, $namespace);
            }
            foreach ($classes as $class) {
                Factory::register($type, $class);
            }
        }
    }

    /**
     * Resolves an input spec into a [format, path] pair.
     *
     * Recognizes an optional "format:" prefix, but only when the prefix matches
     * a registered format name (so Windows drive letters are not misinterpreted).
     *
     * @param array<string, class-string> $registered
     * @return array{0: string, 1: string}
     */
    private function resolveInput(string $spec, string $defaultFormat, array $registered): array
    {
        if ($spec === '-') {
            return [$defaultFormat, '-'];
        }

        $pos = strpos($spec, ':');
        if ($pos !== false) {
            $maybeFormat = substr($spec, 0, $pos);
            if (isset($registered[$maybeFormat])) {
                return [$maybeFormat, substr($spec, $pos + 1)];
            }
        }

        return [$defaultFormat, $spec];
    }

    /**
     * Reads the raw report content from a file path, or STDIN when the path is "-".
     */
    private function readInput(string $path): string
    {
        if ($path === '-') {
            $content = $this->readStdin(null);
            if ($content === null) {
                throw new \RuntimeException('Unable to read from STDIN.');
            }
            return $content;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Cannot read input file: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read input file: $path");
        }

        return $content;
    }

    /**
     * Reads all of STDIN. Returns null (and reports the error) on failure.
     */
    private function readStdin(?SymfonyStyle $err): ?string
    {
        $content = stream_get_contents(STDIN);
        if ($content === false) {
            $err?->error('Unable to read from STDIN.');
            return null;
        }

        return $content;
    }
}
