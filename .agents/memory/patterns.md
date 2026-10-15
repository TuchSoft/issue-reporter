# Patterns
- New format: class in src/Format, extends AbstractFormat, implements ParsableFormatInterface OR NativeFormatInterface, use correct trait(s), impl generate() (+parse/supports/supportsExtra if parsable), getDesc(); options via trait/AbstractFormat.
- Options always via newOption(name,mode,desc,default,returnType). Raw InputOption only for transformers (option name == getName()).
- CLI list cmds: extend AbstractListCommand; use buildIo / renderOptions / renderItems / search.
- convert exposes ALL opts = Reporter::getOptionsDefinition(OPTIONS_BOTH) + transformer opts; add to definition guarded by hasOption().
- Recipe/config: validate schema in RecipeLoader (unknown keys error), build Report in RecipeBuilder; file-leaves parsed via Factory::create(FORMAT)->parse; groups via Report::mergeIn; inline via Issue::fromJson. When --config given: ignore ALL other CLI flags/args (strict separation). Recipe options passed as the $options array (setOptions fills defaults).
- CLI diagnostics/errors -> STDERR (io->getErrorStyle()) so STDOUT stays pipeable. Only print success when output != stdout.
- Report must be start()ed before addIssue.
- BEFORE any release/publish action, verify LIVE state, never trust memory: `git --no-pager ls-remote --tags origin` and `curl https://repo.packagist.org/p2/<vendor>/<pkg>.json`. Session state goes stale; time passes between turns.
- Test: heavy matrix in test/Format (huge). CLI tests in test/Command via Symfony CommandTester. phpunit.xml has Format + Command suites.
