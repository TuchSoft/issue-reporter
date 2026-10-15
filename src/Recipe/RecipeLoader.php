<?php

namespace Tuchsoft\IssueReporter\Recipe;

/**
 * Reads and validates a recipe/config file describing a full convert operation.
 *
 * A recipe is a JSON object; see docs/cli.md for the full schema. This class
 * only reads/validates/normalizes it — building the actual Report tree is the
 * job of {@see RecipeBuilder}.
 */
class RecipeLoader
{
    private const TOP_KEYS = ['name', 'basePath', 'from', 'to', 'output', 'register', 'options', 'inputs'];

    private const NODE_KEYS = ['type', 'format', 'path', 'name', 'basePath', 'inputs', 'issues'];

    private const ISSUE_REQUIRED_KEYS = ['code', 'severity', 'message', 'path', 'line'];

    /**
     * Loads a recipe from a file path, or from the given STDIN content when $fileOrDash is "-".
     *
     * @return array<string, mixed> The validated, normalized recipe.
     * @throws RecipeException
     */
    public function load(string $fileOrDash, ?string $stdin = null): array
    {
        $content = $this->read($fileOrDash, $stdin);

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RecipeException('Invalid JSON in recipe: ' . $e->getMessage());
        }

        if (!is_array($data) || !$this->isObject($data)) {
            throw new RecipeException('Recipe must be a JSON object.');
        }

        $this->validateTop($data);
        $this->normalize($data);

        return $data;
    }

    private function read(string $fileOrDash, ?string $stdin): string
    {
        if ($fileOrDash === '-') {
            if ($stdin === null) {
                throw new RecipeException('No STDIN content provided for recipe.');
            }
            return $stdin;
        }

        if (!is_file($fileOrDash) || !is_readable($fileOrDash)) {
            throw new RecipeException("Cannot read recipe file: $fileOrDash");
        }

        $content = file_get_contents($fileOrDash);
        if ($content === false) {
            throw new RecipeException("Cannot read recipe file: $fileOrDash");
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateTop(array $data): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::TOP_KEYS, true)) {
                throw new RecipeException(sprintf('Unknown key "%s" in recipe.', $key));
            }
        }

        foreach (['name', 'basePath', 'from', 'to', 'output'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                throw new RecipeException(sprintf('Recipe key "%s" must be a string.', $key));
            }
        }

        if (isset($data['register'])) {
            if (!is_array($data['register']) || !$this->isList($data['register'])) {
                throw new RecipeException('Recipe key "register" must be an array of class or namespace names.');
            }
            foreach ($data['register'] as $i => $entry) {
                if (!is_string($entry)) {
                    throw new RecipeException("Recipe key \"register\"[$i] must be a string.");
                }
            }
        }

        if (isset($data['options'])) {
            if (!is_array($data['options']) || !$this->isObject($data['options'])) {
                throw new RecipeException('Recipe key "options" must be an object of option name => value.');
            }
            foreach ($data['options'] as $opt => $value) {
                if (!is_scalar($value)) {
                    throw new RecipeException("Recipe option \"$opt\" must be a scalar (bool, string, int or float).");
                }
            }
        }

        if (isset($data['inputs'])) {
            if (!is_array($data['inputs']) || !$this->isList($data['inputs'])) {
                throw new RecipeException('Recipe key "inputs" must be an array of input nodes.');
            }
            foreach ($data['inputs'] as $i => $node) {
                $this->validateNode($node, "inputs[$i]");
            }
        }
    }

    /**
     * @param mixed $node
     */
    private function validateNode($node, string $where): void
    {
        if (!is_array($node) || !$this->isObject($node)) {
            throw new RecipeException("Recipe node \"$where\" must be an object.");
        }

        foreach (array_keys($node) as $key) {
            if (!in_array($key, self::NODE_KEYS, true)) {
                throw new RecipeException("Unknown key \"$key\" in recipe node \"$where\".");
            }
        }

        if (isset($node['type']) && isset($node['format'])) {
            throw new RecipeException("Recipe node \"$where\" defines both \"type\" and \"format\"; use only one.");
        }

        foreach (['type', 'format', 'path', 'name', 'basePath'] as $key) {
            if (isset($node[$key]) && !is_string($node[$key])) {
                throw new RecipeException("Recipe node \"$where\" key \"$key\" must be a string.");
            }
        }

        $hasPath = isset($node['path']);
        $hasInputs = isset($node['inputs']);
        $hasIssues = isset($node['issues']);

        if (!$hasPath && !$hasInputs && !$hasIssues) {
            throw new RecipeException("Recipe node \"$where\" must define at least one of \"path\", \"inputs\" or \"issues\".");
        }

        if ($hasPath && ($hasInputs || $hasIssues)) {
            throw new RecipeException("Recipe node \"$where\" cannot combine \"path\" with \"inputs\"/\"issues\".");
        }

        if ($hasInputs) {
            if (!is_array($node['inputs']) || !$this->isList($node['inputs'])) {
                throw new RecipeException("Recipe node \"$where\" key \"inputs\" must be an array of input nodes.");
            }
            foreach ($node['inputs'] as $i => $child) {
                $this->validateNode($child, "$where.inputs[$i]");
            }
        }

        if ($hasIssues) {
            if (!is_array($node['issues']) || !$this->isList($node['issues'])) {
                throw new RecipeException("Recipe node \"$where\" key \"issues\" must be an array of issues.");
            }
            foreach ($node['issues'] as $i => $issue) {
                if (!is_array($issue) || !$this->isObject($issue)) {
                    throw new RecipeException("Recipe node \"$where\".issues[$i] must be an object.");
                }
                foreach (self::ISSUE_REQUIRED_KEYS as $req) {
                    if (!isset($issue[$req])) {
                        throw new RecipeException("Recipe node \"$where\".issues[$i] is missing required key \"$req\".");
                    }
                }
            }
        }
    }

    /**
     * Resolves the `format` alias to the canonical `type` key, recursively.
     *
     * @param array<string, mixed> $data
     */
    private function normalize(array &$data): void
    {
        if (isset($data['inputs']) && is_array($data['inputs'])) {
            foreach ($data['inputs'] as &$node) {
                if (is_array($node)) {
                    $this->normalizeNode($node);
                }
            }
            unset($node);
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function normalizeNode(array &$node): void
    {
        if (isset($node['format'])) {
            $node['type'] = $node['format'];
            unset($node['format']);
        }

        if (isset($node['inputs']) && is_array($node['inputs'])) {
            foreach ($node['inputs'] as &$child) {
                if (is_array($child)) {
                    $this->normalizeNode($child);
                }
            }
            unset($child);
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function isObject(array $value): bool
    {
        if ($value === []) {
            // json_decode() cannot distinguish an empty object from an empty array.
            return true;
        }

        return !$this->isList($value);
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
