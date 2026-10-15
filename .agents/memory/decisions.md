# Decisions (append-only)
2026-10-15: Factory::registerClass validates iface per $type (was hardcoded FormatInterface) — why: transformers never registered, made Reporter transformer pipeline dead code.
2026-10-15: CLI drops single-command mode -> setDefaultCommand('list-format') (no 2nd arg) + registers convert/list-transformer — why: single-command shadowed `list` and every other command; CLI was effectively non-functional.
2026-10-15: Core pipeline cmd named `convert` only (no `report`/aliases) and NO `sample` cmd — user choice.
2026-10-15: convert accepts per-file `[format:]path`; prefix treated as format ONLY if it's a registered format name (Windows drive-letter safe) — user choice.
2026-10-15: convert writes diagnostics to STDERR; success msg only when output != stdout — why: keep report on stdout clean/pipable.
2026-10-15: Filled docs/{index,cli,architecture,todo}.md + all .agents/memory files — why: docs were unresolved stubs, memory empty.
2026-10-15: Added recipe/config-file mode to convert (--config FILE|-) as single JSON describing inputs/tree/options/output/register — user wants one file for complex runs, flags for simple ones.
2026-10-15: With --config, ALL other CLI flags/args are IGNORED (strict separation, recipe = single source of truth) — user choice (vs CLI-overrides-recipe).
2026-10-15: Recipe keeps JSON only (no symfony/yaml dep) — user choice.
2026-10-15: Recipe file-leaf basePath override NOT supported (only name) — a parsed report's basePath is format-derived; changing it desyncs Issue::relativePath — user OK.
2026-10-15: Runtime-registered external formats usable as I/O + via recipe `options`, but no CLI flags (definition built in configure() before registration) — accepted limitation.
2026-10-15: bin/console normalizes `--config -`/`-c -` -> `--config=-`/`-c-` — why: Symfony never binds a lone '-' as an option value, but `--config -` is the documented stdin UX.
2026-10-15: Fixed Issue::$relativePath init (Report::fromJson) — why: parsed reports (all machine formats) crashed readable renderers; the whole point of the tool is a readable output.
2026-10-15: Fixed NullInput return types (Symfony 7.4 compatibility) — why: `info` format fatally errored; kept params untyped for ^5||^6||^7 support.
2026-10-15: Added test/Format/NativeFormatsTest.php — why: native readable formats were untested, letting both bugs ship.
2026-10-15: Expanded per-format coverage to ALL formats via adversarial suites (junit/sarif/sonarqube/lsp/info/infomd/infohtml), same matrix base — why: 7/13 formats were untested; option matrix only varied show-ref/show-help.
2026-10-15: Junit timing chosen as milliseconds for the `time` attribute (parse divides by 1000) — matches generate() using getTotalTime() (ms).
2026-10-15: Sarif/SonarQube/Lsp supports() declare only FEATURE_ISSUE_STANDARD (not HELP/REF) — why: their generate() emits help/ref unconditionally, so declaring those features fails the round-trip matrix when show-help/show-ref are off (see docs/todo).
2026-10-15: Native cell escaping via escapeTableCell() hook per trait (ANSI identity / MD escape / HTML htmlspecialchars+MD) — why: fixes XSS + broken markdown tables without touching format classes.
2026-10-15: Deleted NativeFormatsTest.php; superseded by per-format InfoTest/InfoMdTest/InfoHtmlTest.
2026-10-15: License GPL-3.0-or-later -> Apache-2.0 — user choice (pre-publish).
2026-10-15: First release tagged v1.0.0 (not 0.x) — user choice.
2026-10-15: phpunit.xml now TRACKED (removed `/phpunit.xml` from .gitignore) — why: library repo must ship its test config; was invisible to contributors. `/app/phpunit.xml` still ignored.
2026-10-15: .idea/ untracked + .gitignore `.idea/` — why: IDE noise, not part of the package.
2026-10-15: .gitattributes export-ignore for /.idea /.agents /.complex_plans /CLAUDE.md /test /phpunit.xml /.gitattributes /.gitignore — why: slim Packagist dist. docs/ + README + LICENSE still shipped.
2026-10-15: Added `Utils` testsuite to phpunit.xml + fixed stale PathTest expectation (`findCommonBasePath([])` -> '/', was '') — why: PathTest was excluded/unrun, hiding a stale expectation against an intentional implementation behavior.
2026-10-15: Released v1.0.0 — 5 logical commits on main, annotated tag v1.0.0 pushed, GitHub Release created. Packagist submission still PENDING (no Packagist API token on the machine). Known limitations deferred to 1.1 (see docs/todo.md).
