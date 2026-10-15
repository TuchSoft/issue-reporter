# Todo

Co-managed with humans. Only open items — move finished work out (agent memory's `volatile.md` tracks recently-done, this file should not).

## In progress

*none*

## Planned

1. `convert`: transformer/format options are global — the same option set applies to the whole run
   (CLI flags or recipe `options`). Consider per-input/per-node overrides in the recipe if a real need appears.
2. Document and expose the `raw` round-trip guarantees (which fields survive each format).

## Backlog

1. **Bug — help/ref swapped when adding issues via `Report`.** `Report::error()/warning()/tip()` pass
   `($help, $ref)` into `Report::issue()` and then into `new Issue(...)`, but the `Issue` constructor's
   7th/8th parameters are `$ref, $help` — so `help` and `ref` are swapped. `Report::issue()` itself is
   consistent; the swap happens at the `new Issue(...)` call site inside `Report::issue()`, whose argument
   order does not match the constructor signature. Needs a decision on which side to fix
   (constructor order vs. call site) to avoid breaking `Issue::fromJson`.
2. `OptionsMatrixProvider::optionsMatrixProvider()` intersects option sets by **integer index**
   (`array_intersect_key`), so the round-trip matrix only ever varies `show-ref`/`show-help`; per-format
   options never enter the matrix.
3. `Sarif::generate()` and `Lsp::generate()` emit `help`/`ref` **unconditionally**, ignoring the
   `show-help`/`show-ref` options — so those formats cannot advertise `FEATURE_ISSUE_HELP`/`_REF`
   (the round-trip matrix would fail when those options are off). Make generation honor the options, then
   declare the features.
4. `SonarQube::generate()` passes JSON flags to `jsonEncode()` which ignores them; and a *missing* severity
   defaults to `MINOR`/TIP while an *unknown* one defaults to `WARNING`. Minor/consistency.
5. New format: `codeclimate`.
6. `MessageReplacer` runs on the whole report by default; verify behavior with issues that have no
   `extra` data (Mustache of missing keys) if it ever causes surprises.
