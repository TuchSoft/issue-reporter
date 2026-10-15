# IssueReporter

A PHP library for reading, aggregating, transforming and formatting **static code analysis issues**.

IssueReporter reads reports produced by many tools (PHP_CodeSniffer, Checkstyle, SARIF, JUnit, GitLab,
SonarQube, LSP, Emacs, ...), normalizes them into a single in-memory model (`Report` + `Issue`),
optionally transforms them, and renders them into any supported output format
(JSON, XML, Markdown, HTML, ANSI text, SARIF, ...).

It is **dual-use**: use it as a **library** inside your own PHP code, or as a standalone
**CLI** (`bin/console`) to convert and merge report files in a pipeline.

- Package: [`tuchsoft/issue-reporter`](https://packagist.org/packages/tuchsoft/issue-reporter)
- License: Apache-2.0
- PHP: `>=8.0`

## Features

- **Read** many report formats into one model (`phpcs`, `checkstyle`, `junit`, `gitlab`, `sonarqube`, `sarif`, `lsp`, `emacs`, `raw`, `rawxml`).
- **Merge** several reports into a single hierarchical `Report` tree.
- **Transform** the model (e.g. Mustache message interpolation from issue `extra` data).
- **Format** the result as JSON, XML, Markdown, HTML, ANSI text or SARIF.
- **Extend**: register your own formats and transformers at runtime.
- A **JSON recipe** file can describe a whole complex run (inputs, tree, options, output).

## Requirements

- PHP `>= 8.0`
- Extensions: `ext-dom`, `ext-simplexml`, `ext-libxml`

## Installation

```bash
composer require tuchsoft/issue-reporter
```

## Usage

### As a library

```php
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Reporter;

// Build a report programmatically…
$report = (new Report('My lint run', '/path/to/project'))->start();
$report->error('E101', 'Something broke', 'src/A.php', 10, 5);
$report->warning('W201', 'Be careful', 'src/B.php', 3);
$report->tip('T301', 'Could be nicer', 'src/B.php', 8);
$report->complete();

// …then render it in any supported format.
Reporter::printReport($report, 'infomd', 'report.md');
```

`Report::error()`, `warning()` and `tip()` are shorthands for `Report::issue()` with the matching
severity; they accept `($code, $message, $path, $line, $column, $help, $ref)`. `Report::start()` must
be called before adding issues.

### Parsing an existing report

Any *parsable* format can turn a raw report string into a `Report`:

```php
use Tuchsoft\IssueReporter\Factory;

$report = Factory::create(Factory::FORMAT, 'phpcs')->parse(file_get_contents('phpcs.json'));
Reporter::printReport($report, 'sarif', 'merged.sarif');
```

### As a CLI

```bash
# list available output/parsable formats and their options
bin/console list-format

# list available transformers
bin/console list-transformer

# read PHP_CodeSniffer JSON, output a pretty Markdown report
bin/console convert phpcs:phpcs.json --to infomd -o report.md --phpcs-pretty

# merge two reports (any parsable formats) into a single SARIF file
bin/console convert checkstyle:a.xml raw:b.json --to sarif -o merged.sarif

# pipe a report through STDIN
cat report.json | bin/console convert - --from raw --to emacs

# describe a whole (complex) run in one JSON recipe file, or pipe it in
bin/console convert --config recipe.json
cat recipe.json | bin/console convert --config -
```

Each input uses a `[format:]path` syntax; prefix the path with a registered format name to parse it
with that format, or omit it to use `--from`. Diagnostics go to STDERR so the report on STDOUT stays
pipeable. When `--config` is given, all other flags/arguments are ignored (the recipe is the single
source of truth).

## Supported formats

| Name | Output kind | Parsable |
| --- | --- | --- |
| `info` | ANSI text | no (native renderer) |
| `infohtml` | HTML | no (native renderer) |
| `infomd` | Markdown | no (native renderer) |
| `emacs` | text | yes |
| `phpcs` | JSON | yes |
| `gitlab` | JSON | yes |
| `lsp` | JSON | yes |
| `raw` | JSON | yes |
| `sarif` | JSON | yes |
| `sonarqube` | JSON | yes |
| `checkstyle` | XML | yes |
| `junit` | XML | yes |
| `rawxml` | XML | yes |

*Parsable* formats can both generate and parse a report (usable as input).
*Native* formats are rich human-readable renderers; they cannot be used as input.
`raw` is the library's own complete JSON representation (round-trips everything).

Run `bin/console list-format` for the full list, descriptions and options.

## Transformers

| Name | Description | Enabled by default |
| --- | --- | --- |
| `messagereplacer` | Replaces Mustache syntax in the `message`/`help` fields using the issue `extra` data. | yes |

Run `bin/console list-transformer` for the full list and options.

## Extending

Register your own formats/transformers with the `Factory`:

```php
use Tuchsoft\IssueReporter\Factory;

Factory::register(Factory::FORMAT, My\Format::class);
Factory::register(Factory::TRANSFORMER, My\Transformer::class);
// or a whole PSR-4 namespace:
Factory::register(Factory::FORMAT, 'My\\Formats\\');
```

Any class implementing `FormatInterface` (and optionally `ParsableFormatInterface`) or
`TransformerInterface` is discovered and instantiated with its own declared options.

## Documentation

Extended documentation lives in [`docs/`](docs/index.md):

- [`docs/index.md`](docs/index.md) — overview and quick start.
- [`docs/cli.md`](docs/cli.md) — full CLI reference: commands, options, recipe/config file schema.
- [`docs/architecture.md`](docs/architecture.md) — how the library is structured and how data flows.
- [`docs/todo.md`](docs/todo.md) — roadmap, planned features and known issues.

## License

Released under the [Apache License 2.0](LICENSE) — © 2026 TuchSoft.
