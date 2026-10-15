# Architecture

## Overview

IssueReporter has three concerns:

1. **Model** — a common in-memory representation of a report and its issues.
2. **Registry** — dynamic discovery/instantiation of formats and transformers, driven by their own option definitions.
3. **Pipeline** — parse inputs into the model, transform it, generate output.

```
                 ┌──────────────┐
 input files ──▶ │ Format::parse│ ──▶ Report (Issue[]) ──▶ Transformer::transform ──▶ Format::generate ──▶ output
 (STDIN/files)   └──────────────┘            ▲                     ▲                        ▲
                                             │                     │                        │
                                          Factory ─────────────── Reporter ───────────── Factory
```

## Model

- `src/Report.php` — a report node: a name, a `basePath`, a list of `Issue` and child `Report`s
  (`mergeIn`). Tracks timing (`start`/`complete`) and computes recursive totals
  (`getTotalsRecursive`). Implements `JsonSerializable` (the `raw` format is a direct serialization).
  `Report::fromJson()` reconstructs a report tree and requires `name`, `basePath` and `issues`.
- `src/Issue.php` — a single issue: `code`, `severity`, `message`, `path`, `relativePath`, `line`,
  `column`, `ref`, `help`, `extra[]`, `time`. Severity is an int; `SEVERITY_ERROR = 5`,
  `SEVERITY_WARNING = 3`, `SEVERITY_TIP = 0`, `SEVERITY_DEFAULT = WARNING`.
  `Report::addIssue()` normalizes the path against the report `basePath` and computes `relativePath`.

## Registry (`src/Factory.php`)

- `Factory::register($type, $fqnOrNamespace)` registers either a concrete class or every class in a namespace.
- `Factory::registerNamespace()` resolves the namespace to filesystem paths via the Composer PSR-4
  autoloader, walks the directory and registers every instantiable class.
- Built-in formats/transformers are registered lazily by `registerBuiltIn()` from the
  `Tuchsoft\IssueReporter\Format` and `Tuchsoft\IssueReporter\Transformer` namespaces.
- `Factory::registerClass()` validates the class against the interface matching `$type`
  (`FormatInterface` for `FORMAT`, `TransformerInterface` for `TRANSFORMER`), skips abstract classes/interfaces,
  and keys the registry by `Class::getName()`.
- `Factory::create($type, $name, $options)` instantiates the registered class with the option array.
- `Factory::getRegistered($type)` returns the registry for `FORMAT` or `TRANSFORMER`.

## Formats (`src/Format`)

- `Format/Base/FormatInterface` — `getFormat()`, `getDefaultReportName()`, `generate(Report): string`.
- `Format/Base/ParsableFormatInterface` — adds `parse(string): Report`, `supports()`, `supportsExtra()`,
  and the `FEATURE_*` constants describing what a format preserves.
- `Format/Base/NativeFormatInterface` — marker for rich native renderers (not parsable).
- `Format/Base/AbstractFormat` — base with the shared `show-ref`/`show-help` options and severity mapping.
- Reusable traits: `JsonFormatTrait`, `XmlFormatTrait`, `MdFormatTrait`, `HtmlFormatTrait`,
  `AnsiFormatTrait`, `RichFormatTrait`, `InfoFormatTrait`, `ParsableMessageFormatTrait`.
- Concrete formats: `Info` (ANSI text), `InfoMd`, `InfoHtml` (native); `PhpCs`, `Checkstyle`, `Junit`,
  `GitLab`, `SonarQube`, `Sarif`, `Lsp`, `Emacs`, `Raw`, `RawXml` (parsable).

## Transformers (`src/Transformer`)

- `Transformer/base/TransformerInterface` — `transform(Report &$report): void`, `isEnabled(array $options): bool`.
- `Transformer/base/AbstractTransformer` — negation option named after the transformer, enabled by default.
- `Transformer/MessageReplacer` — renders Mustache templates in `message`/`help` from `Issue::extra`.

## Options model (`src/Base`)

- `LoadableInterface::getOptionsDefinition($returnType)` returns `InputOption[]` (Symfony).
  `$returnType` is `OPTIONS_NORMAL` (unprefixed), `OPTIONS_PREFIX` (`<name>-<option>`) or `OPTIONS_BOTH`.
- `LoadableTrait::newOption()` builds the (optionally prefixed) `InputOption`.
- `LoadableTrait::setOptions()` applies input values over defaults, letting a prefixed option override
  the generic one for that specific loadable.
- `Reporter::getOptionsDefinition()` aggregates and de-duplicates option definitions across all formats
  (by option name) — used by the `convert` command to expose every option.

## Pipeline (`src/Reporter.php`)

- `Reporter::registerFormats(...)` registers additional format classes.
- `Reporter::printReport(Report $report, string $format, string $output, array $options)`:
  1. runs every transformer whose `isEnabled($options)` is true,
  2. creates the output format with `$options` and writes `generate()` to `$output`.

## CLI (`src/Command`, `bin/console`)

- `bin/console` registers `ListFormat`, `ListTransformer`, `Convert`; `list-format` is the default command.
- `Command/Base/AbstractListCommand` — shared styling, option rendering, and fuzzy search for the list commands.
- `Command/ListFormat`, `Command/ListTransformer` — introspection.
- `Command/Convert` — the read→merge→transform→format pipeline, exposing every format/transformer option.

## Recipe / config file (`src/Recipe`)

An optional, CLI-facing layer that turns a single JSON "recipe" into a `Report` tree:

- `Recipe/RecipeLoader` — reads the recipe from a file or STDIN, decodes JSON, validates the schema
  (known keys, node kinds, required issue keys) and normalizes the `format`→`type` alias. Throws `RecipeException`.
- `Recipe/RecipeBuilder` — walks the validated tree and builds a `Report`:
  file leaves are parsed via `Factory::create(FORMAT)->parse()`, group nodes become subreports (`mergeIn`),
  inline nodes carry literal issues (`Issue::fromJson`). Sibling subreport names must be unique.
- `Recipe/RecipeException` — the dedicated error type.

`Convert` uses this layer only when `--config` is given; the recipe is then the single source of truth
(all other CLI flags/arguments are ignored) and it also drives output (`to`/`output`), root `name`/`basePath`,
options and external class registration (`register`).

## Utils (`src/Utils`)

- `Path` — path normalization, `stripBasepath()`, `findCommonBasePath()` (used when parsing).
- `Formatter` — a Symfony `OutputFormatter` with issue-severity styles.
- `NullInput` — a no-op `InputInterface` for loading formats/transformers without a real console input.
