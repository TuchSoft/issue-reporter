# IssueReporter

A PHP library for reading, aggregating, transforming and formatting **static code analysis issues**.

It reads reports produced by other tools (PHP_CodeSniffer, Checkstyle, SARIF, JUnit, GitLab, SonarQube, LSP, Emacs, ...), turns them into a common in-memory model (`Report` + `Issue`), optionally transforms them, and renders them into any supported output format (JSON, XML, Markdown, HTML, ANSI text, SARIF, ...).

- Package: `tuchsoft/issue-reporter`
- License: Apache-2.0
- PHP: `>=8.0` (uses `mixed`, promoted properties, typed properties, `match`)
- Main dependencies: `symfony/console`, `ext-dom`, `ext-simplexml`, `ext-libxml`, `davidbadura/markdown-builder`, `erusev/parsedown`, `riimu/kit-pathjoin`, `mustache/mustache`

The project is **dual-use**: a standalone CLI and a library. See the root `README.md` for the
short version; the files below are the detailed reference.

## Docs

- `index.md` — this file: overview and docs index.
- `cli.md` — the `bin/console` command-line interface: commands, options, examples.
- `architecture.md` — how the library is structured and how data flows.
- `todo.md` — roadmap, bugs and planned features (co-managed with humans).

## Quick start (CLI)

```bash
# install dependencies
composer install

# list available output/parsable formats and their options
bin/console list-format

# list available transformers
bin/console list-transformer

# convert/merge reports: read PHP_CodeSniffer JSON, output a pretty Markdown report
bin/console convert phpcs:phpcs.json --to infomd -o report.md --phpcs-pretty

# merge two reports (any parsable formats) into a single SARIF file
bin/console convert checkstyle:a.xml raw:b.json --to sarif -o merged.sarif

# pipe a report through STDIN
cat report.json | bin/console convert - --from raw --to emacs

# describe a whole (complex) run in one JSON recipe file, or pipe it in
bin/console convert --config recipe.json
cat recipe.json | bin/console convert --config -
```

## Quick start (library)

```php
use Tuchsoft\IssueReporter\Report;
use Tuchsoft\IssueReporter\Reporter;

$report = (new Report('My lint run', '/path/to/project'))->start();
$report->error('E101', 'Something broke', 'src/A.php', 10, 5, 'Fix it', 'https://example.com/e101');
$report->warning('W201', 'Be careful', 'src/B.php', 3);
$report->tip('T301', 'Could be nicer', 'src/B.php', 8);
$report->complete();

Reporter::printReport($report, 'infomd', 'report.md');
```
