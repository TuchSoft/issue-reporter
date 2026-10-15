# CLI reference (`bin/console`)

The CLI exposes the full capability of the library: list formats/transformers, and
read → merge → transform → format reports in any supported output format with any option.

Running `bin/console` with no arguments runs `list-format` (the configured default command).
All Symfony standard options are available (`--help`, `--quiet`, `--verbose`, `--ansi`,
`--no-interaction`, `--silent`, `--version`).

## Commands

1) `list-format [<search>]`
   - Lists every registered format, its description, output `Format:` (json/xml/markdown/html/txt),
     whether it is `Parsable:`, its options, and the parsing features it supports.
   - `<search>` fuzzy-matches format names.

2) `list-transformer [<search>]`
   - Lists every registered transformer, its description, whether it is enabled by default, and its options.
   - `<search>` fuzzy-matches transformer names.

3) `convert`
   - Reads one or more input reports, parses them, merges them into a single report,
     runs the enabled transformers, then renders the result in the output format.
   - This is the "full pipeline" command.
   - For complex runs it accepts a JSON **recipe/config file** (`--config`) describing the entire operation
     (inputs, tree, options, output, registration); when used, all other flags/arguments are ignored.

## `convert`

### Arguments

- `input...` — zero or more inputs. Each input uses the `[format:]path` syntax:
  - `path` — read `path` and parse it with the `--from` format.
  - `format:path` — parse `path` with the explicit `format` (only accepted when `format` is a registered format name).
  - `-` — read the report from STDIN (parsed with `--from`).
  - With no inputs, an empty report is produced (useful to emit an empty skeleton).

### Options

- `-c, --config=FILE` — recipe/config file describing the whole operation. Use `-` to read it from STDIN. When given, **all other flags and arguments are ignored** (the recipe is the single source of truth).
- `--register-namespace=NS` — register every format/transformer found in a namespace (repeatable).
- `--register-class=FQN` — register a single format/transformer class (repeatable).
- `-f, --from=FORMAT` — default input format for inputs without a `format:` prefix. Default: `raw`.
- `-t, --to=FORMAT` — output format. Defaults to the `--from` format.
- `-o, --output=FILE` — output file. Default: `php://stdout` (STDOUT).
- `--name=NAME` — name of the resulting (root) report. Default: `Report`.
- `--base-path=PATH` — base path of the resulting report. Default: current working directory.
- Plus **every format option** and **every transformer option** (see below).

### Option model: generic vs per-format

Every format's options are exposed both:

1) generically (unprefixed), e.g. `--pretty`, `--show-ref`, `--max-width`, `--parse-message`;
2) per-format (prefixed with the format name), e.g. `--phpcs-pretty`, `--infomd-max-width`, `--raw-pretty`.

When both the generic and the prefixed option are provided, the **prefixed value wins**
for that specific format. Unprefixed options are shared by all formats that define an option
with that name (last definition wins when formats disagree on the default).

Example: set `--pretty` for the output format, but force `--no-raw-pretty` for the raw format
used as input:

```bash
bin/console convert raw:in.json --to phpcs --pretty --no-raw-pretty
```

Transformer options are unprefixed (one per transformer), e.g. `--messagereplacer` / `--no-messagereplacer`.

### Config file (recipe)

For complex runs, a single JSON recipe can describe the whole operation — inputs (with per-input format),
the subreport tree, inline issues, every option, output and class registration. Pass it with `--config FILE`,
or pipe it in with `--config -`.

When `--config` is given, **all other flags and arguments are ignored**; the recipe is the single source of truth.
Plain flags/arguments keep working for simple cases (you just can't express everything with them).

#### Schema

```jsonc
{
  "name": "CI report",                 // root report name        (default: "Report")
  "basePath": "/project",              // root report base path   (default: cwd)
  "from": "raw",                       // default input format    (default: "raw")
  "to": "infomd",                      // output format           (default: same as "from")
  "output": "report.md",               // output file             (default: STDOUT)
  "register": ["Vendor\\MyFormats"],   // FQNs or namespaces, registered before parsing
  "options": {                         // any option name => value
    "pretty": true,                    //   generic option
    "phpcs-pretty": true,              //   per-format option
    "messagereplacer": false           //   transformer option
  },
  "inputs": [                          // ordered; builds the subreport tree
    { "type": "phpcs", "path": "phpcs.json" },
    { "name": "backend", "basePath": "/project/backend",
      "inputs": [
        { "type": "checkstyle", "path": "backend/cs.xml" }
      ]
    },
    { "name": "manual", "basePath": "/project",
      "issues": [
        { "code": "E1", "severity": 5, "message": "boom", "path": "src/A.php", "line": 10 }
      ]
    }
  ]
}
```

#### Node kinds

Each node under `inputs` is exactly one of:

1) **file leaf** — has `path` (plus optional `type`/`format`): the file is parsed with that format (or the inherited default). `path: "-"` reads the report from STDIN (not allowed when the recipe itself came from STDIN).
2) **group** — has `inputs`: becomes a subreport; its children are merged into it.
3) **inline** — has `issues`: literal issues (same keys as `Issue::fromJson`: `code`, `severity`, `message`, `path`, `line` required; `column`, `ref`, `help`, `extra` optional).

A node may combine `issues` **and** `inputs` (issues are added first, then children are merged).
A node may not combine `path` with `inputs`/`issues`.

`name`, `basePath` are optional on any node: a group/inline node's `name` is the subreport name (default `Report`),
and `basePath` defaults to the parent's. On a file leaf only `name` is honored (the base path comes from the parsed report).
A node (or a group) may set `type`/`format` to set the default format for the file leaves below it (a leaf's own value wins).
`format` is an accepted alias of `type`.

Subreport names must be **unique among siblings** (a parsed report keeps its own name unless the node sets `name`),
so give each node an explicit `name` when in doubt.

#### Examples

```bash
# a full run described by a file
bin/console convert --config recipe.json

# the same recipe piped in from STDIN (note the "=" spelling is also valid: --config=-)
cat recipe.json | bin/console convert --config -
```

### Examples

```bash
# single file, PHP_CodeSniffer JSON -> Markdown
bin/console convert phpcs:phpcs.json --to infomd

# merge two different input formats -> SARIF file (pretty JSON)
bin/console convert checkstyle:a.xml raw:b.json --to sarif --pretty -o merged.sarif

# STDIN -> Emacs
cat report.json | bin/console convert - --from raw --to emacs

# disable a transformer explicitly
bin/console convert in.json --to raw --no-messagereplacer

# empty report skeleton in LSP format
bin/console convert --to lsp

# everything from a recipe file
bin/console convert --config recipe.json
```

### Diagnostics

Progress/status messages and errors are written to **STDERR**, so the report on STDOUT stays clean
and can be piped. A non-zero exit code is returned on failure (unknown/non-parsable input format,
unreadable input, unknown output format, parse/generation errors).

## Formats

| Name | Output kind | Parsable |
| --- | --- | --- |
| `info` | txt | native |
| `infohtml` | html | native |
| `infomd` | markdown | native |
| `emacs` | txt | parsable |
| `phpcs` | json | parsable |
| `gitlab` | json | parsable |
| `lsp` | json | parsable |
| `raw` | json | parsable |
| `sarif` | json | parsable |
| `sonarqube` | json | parsable |
| `checkstyle` | xml | parsable |
| `junit` | xml | parsable |
| `rawxml` | xml | parsable |

- **parsable** formats can both generate and `parse` a report (usable as `--from`).
- **native** formats are rich human-readable renderers; they cannot be used as input.
- `raw` is the library's own complete JSON representation (round-trips everything).

## Transformers

| Name | Description | Enabled by default |
| --- | --- | --- |
| `messagereplacer` | Replaces Mustache syntax in the `message`/`help` fields using the issue `extra` data. | yes |
