<?php

namespace Tuchsoft\IssueReporter\Recipe;

use Tuchsoft\IssueReporter\Factory;
use Tuchsoft\IssueReporter\Format\Base\ParsableFormatInterface;
use Tuchsoft\IssueReporter\Issue;
use Tuchsoft\IssueReporter\Report;

/**
 * Builds a Report tree from a validated recipe array.
 *
 * See docs/cli.md for the recipe schema. File leaves are parsed with their
 * format, group nodes become subreports and inline nodes carry literal issues.
 */
class RecipeBuilder
{
    /**
     * @param array<string, mixed> $recipe  A recipe returned by {@see RecipeLoader::load()}.
     * @param array<string, mixed> $options Format/transformer options applied to every parsed input.
     * @param bool                 $allowStdin Whether a leaf may read its report from STDIN.
     * @throws RecipeException
     */
    public function build(array $recipe, array $options, bool $allowStdin = true): Report
    {
        $name = $recipe['name'] ?? 'Report';
        $basePath = $recipe['basePath'] ?? getcwd();
        $from = $recipe['from'] ?? 'raw';

        $root = new Report($name, $basePath);
        $root->start();

        $children = $this->buildNodes($recipe['inputs'] ?? [], $from, $options, $basePath, $allowStdin);
        $root->mergeIn(...$children);
        $root->complete();

        return $root;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, mixed> $options
     * @return Report[]
     */
    private function buildNodes(array $nodes, string $inheritedType, array $options, string $parentBasePath, bool $allowStdin): array
    {
        $reports = [];
        $names = [];
        foreach ($nodes as $node) {
            $report = $this->buildNode($node, $inheritedType, $options, $parentBasePath, $allowStdin);
            $name = $report->getName();
            if (isset($names[$name])) {
                throw new RecipeException("Duplicate report name \"$name\"; give each recipe node a unique \"name\".");
            }
            $names[$name] = true;
            $reports[] = $report;
        }

        return $reports;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $options
     */
    private function buildNode(array $node, string $inheritedType, array $options, string $parentBasePath, bool $allowStdin): Report
    {
        if (isset($node['path'])) {
            $type = $node['type'] ?? $inheritedType;

            $fqn = Factory::getRegistered(Factory::FORMAT)[$type] ?? null;
            if ($fqn === null || !is_a($fqn, ParsableFormatInterface::class, true)) {
                throw new RecipeException("Cannot parse \"{$node['path']}\": format \"$type\" is unknown or not parsable.");
            }

            $content = $this->readInput($node['path'], $allowStdin);

            $format = Factory::create(Factory::FORMAT, $type, $options);
            if (!$format instanceof ParsableFormatInterface) {
                throw new RecipeException("Format \"$type\" is not parsable.");
            }

            $report = $format->parse($content, null);
            if (isset($node['name'])) {
                $report->setName($node['name']);
            }

            return $report;
        }

        // Group and/or inline node.
        $basePath = $node['basePath'] ?? $parentBasePath;
        $report = new Report($node['name'] ?? 'Report', $basePath);
        $report->start();

        foreach ($node['issues'] ?? [] as $issueData) {
            $report->addIssue(Issue::fromJson($issueData));
        }

        if (isset($node['inputs'])) {
            $type = $node['type'] ?? $inheritedType;
            $children = $this->buildNodes($node['inputs'], $type, $options, $basePath, $allowStdin);
            $report->mergeIn(...$children);
        }

        $report->complete();

        return $report;
    }

    private function readInput(string $path, bool $allowStdin): string
    {
        if ($path === '-') {
            if (!$allowStdin) {
                throw new RecipeException('Cannot read a report from STDIN: STDIN is already used for the recipe (--config -).');
            }

            $content = stream_get_contents(STDIN);
            if ($content === false) {
                throw new RecipeException('Unable to read from STDIN.');
            }

            return $content;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RecipeException("Cannot read input file: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RecipeException("Cannot read input file: $path");
        }

        return $content;
    }
}
