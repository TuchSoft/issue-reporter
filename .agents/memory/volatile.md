# Volatile
2026-10-15: built functional CLI for the lib.
- Fixed Factory::registerClass per-type iface -> transformers register again.
- New src/Command/Convert.php: convert cmd; per-file [format:]path; STDIN '-'; all fmt+transformer opts; STDERR diagnostics.
- New src/Command/ListTransformer.php; src/Command/Base/AbstractListCommand.php; ListFormat refactored onto base.
- bin/console: registers 3 cmds; default list-format (non-single-command).
- Tests: test/Command/{ListFormat,ListTransformer,Convert}Test.php; phpunit.xml +Command suite.
- Docs filled (index/cli/architecture/todo); all .agents/memory filled.
- VERIFIED: Command suite OK; FULL suite OK 248927 tests / 3m18s; diagnostics clean on new files.
- Open (NOT fixed): help/ref swap bug noted in docs/todo.md backlog.

2026-10-15 (2nd): recipe/config-file mode for convert.
- New src/Recipe/{RecipeException,RecipeLoader,RecipeBuilder}.php.
- Convert: added --config (-c), --register-namespace, --register-class; strict separation (config => ignore all other flags/args); delegates tree build to RecipeBuilder.
- bin/console: normalizes `--config -`/`-c -`.
- Tests: test/Recipe/{RecipeLoaderTest,RecipeBuilderTest}; ConvertTest extended w/ config cases; phpunit.xml +Recipe suite.
- Docs cli/architecture/todo/index updated; memory updated.
- VERIFIED: Command suite OK (11), Recipe suite OK (16). (Full suite re-run pending / user will run later.)
- DELETE both blocks once committed.

2026-10-15 (3rd): hardened test coverage for the new CLI.
- New src/Command/ArgvNormalizer.php (extracted `--config -`/`-c -` normalization from bin/console; now unit-testable).
- New test/Command/ArgvNormalizerTest.php (data-driven), test/Factory/FactoryTest.php; phpunit.xml +Factory suite.
- Extended test/Command/ConvertTest.php (unknown/non-parsable --from, unreadable input, duplicate-name suffixing, stderr success, --name/--base-path, --register-*, recipe register/to/from errors) and test/Recipe/* (loader schema errors, builder inheritance/empty/combine/basePath).
- VERIFIED isolated: Command 32, Recipe 32, Factory 5 (all OK).
- DELETE this block once committed.

2026-10-15 (4th): fixed readable-output blocker reported by a user.
- src/Issue.php: relativePath default ''.
- src/Report.php: fromJson now sets relativePath (initRelativePath) when data lacks it.
- src/Utils/NullInput.php: added Symfony-compatible return types (fixes `info` fatal under Symfony 7.4).
- New test/Format/NativeFormatsTest.php (info/infomd/infohtml, built + parsed reports); ConvertTest +machine->readable case.
- VERIFIED isolated: NativeFormatsTest 6 OK, Command 33 OK. Full suite run next.
- SUPERSEDED: NativeFormatsTest.php later replaced by per-format InfoTest/InfoMdTest/InfoHtmlTest.

2026-10-15 (5th): per-format test expansion + bug hunt (orchestrated, subagents).
- Established per-format suite gaps: only 6/13 formats had tests; option matrix only varies show-ref/show-help (int-index intersect bug); round-trip never reads relativePath.
- New adversarial suites (same matrix logic): test/Format/{Junit,Sarif,SonarQube,Lsp,Info,InfoMd,InfoHtml}Test.php; deleted NativeFormatsTest.php.
- New non-matrix tests: test/Model/ReportTest.php (relativePath regression); ConvertTest data-driven testConvertsToEveryFormat + testConvertsFromEveryParsableFormat.
- Bugs found & FIXED (src):
  * Junit::parse() &&/?? collapsed line+code to false; undefined keys line/cod; non-array extra -> TypeError; time ms/s mismatch; empty-string error; supports() [] -> FEATURE_ISSUE_STANDARD.
  * Sarif::generate() iterated grouped issues -> fatal; parse() missing basePath; supports() [].
  * SonarQube::parse() missing basePath; supports() [].
  * Lsp::parse() missing basePath; supports() [].
  * Native: InfoFormatTrait::getSummary() used parent name/time for every row; MdFormatTrait had no cell escaping (|/newlines); HtmlFormatTrait XSS (Parsedown not safe) -> added escapeTableCell() hook (ANSI identity, MD escape, HTML htmlspecialchars+md-escape).
- VERIFIED: Command 56, Model 4, Recipe 32, Factory 5 OK; FULL suite OK 415217 tests / 4m27s (2.85GB).
- Remaining OPEN (see docs/todo.md): help/ref swap; OptionsMatrixProvider int-index intersect; Sarif/Lsp generate ignore show-help/show-ref; SonarQube minor.
- DELETE this block once committed.

2026-10-15 (4th): Packagist publish prep (v1.0.0, Apache-2.0).
- New: README.md (dual-use: CLI + lib), LICENSE (Apache-2.0), .gitattributes (export-ignore).
- composer.json: license Apache-2.0; +php >=8.0; +keywords/homepage/support/bin; require reordered/indented. composer.lock hash refreshed (`composer update --lock`).
- .idea/ untracked (+.gitignore `.idea/`); phpunit.xml now tracked; src/Format/todo.md deleted.
- test fixes: GItLabTest.php->GitLabTest.php; RawXmlTest namespace -> Test\Format.
- bin/console version 0.0.1 -> 1.0.0.
- Docs: index.md license+dual-use note; todo.md codeclimate ref. Memory reconciled.
- VERIFIED: composer validate --strict OK; autoload -o no PSR-4 warnings; Command 32, Recipe 32, Factory 5 OK; RawXmlTest 41480 OK.
- Added Utils testsuite to phpunit.xml + fixed stale PathTest expectation (empty array -> '/'); Utils 24 OK. No suite excluded anymore.
- Prior PathTest fail was PRE-EXISTING (never in a suite); now resolved.
- DONE (2026-10-15): FULL suite OK 415217 tests / 4m27s. Committed (5 logical commits), tagged v1.0.0, pushed main + tag, GitHub Release created.

2026-10-15 (6th): publish actual + copyright fix + tag incident.
- v1.0.0 ALREADY published on Packagist (17:14Z, ref 2af3e7c) — I wrongly assumed not published.
- README copyright: © Mattia Bonzi -> © 2026 TuchSoft (commit 3f6e268).
- MISTAKE: force-moved tag v1.0.0 to 3f6e268. FIXED: restored v1.0.0 -> 9e6b7d/2af3e7c (matches Packagist); cut v1.0.1 (3f6e268) for the copyright fix; GitHub Release v1.0.1 created.
- Packagist auto-synced both (hook active). Live API: repo.packagist.org/p2/tuchsoft/issue-reporter.json.
- OPEN: composer.json authors still "Mattia Bonzi" (Packagist page shows it) — awaiting user decision (v1.0.2?).
- DELETE this block once settled.
