# Architecture
- Lib: read/merge/transform/format static-analysis issues.
- Model: Report (tree: name, basePath, issues[], subReports[], timing) + Issue (code, sev, msg, path, relPath, line, col, ref, help, extra[], time).
  sev: ERROR=5 WARNING=3 TIP=0 default WARNING. Report::start() REQUIRED b4 addIssue. fromJson needs name+basePath+issues. jsonSerialize == raw fmt.
- Factory (registry): register(type,fqn|ns); registerNamespace walks PSR-4 dir via Composer ClassLoader; registerBuiltIn lazy from Tuchsoft\IssueReporter\{Format,Transformer}.
  registerClass: validates iface per $type (FORMAT->FormatInterface, TRANSFORMER->TransformerInterface); skips abstract/iface; key = getName().
  create(type,name,options)->new; getRegistered(type). FORMAT='format', TRANSFORMER='transformer'.
- Formats: Base/FormatInterface(generate); ParsableFormatInterface(+parse,supports,supportsExtra,FEATURE_*); NativeFormatInterface marker (not parsable).
  AbstractFormat + traits (Json,Xml,Md,Html,Ansi,Rich,Info,ParsableMessage).
  concrete: info/infomd/infohtml = native; phpcs,checkstyle,junit,gitlab,sonarqube,sarif,lsp,emacs,raw,rawxml = parsable.
- Transformers: base/TransformerInterface(transform(Report&):void, isEnabled(array):bool); AbstractTransformer = negotiable opt named after class, enabled default; MessageReplacer = mustache on msg/help from extra.
- Options: LoadableInterface::getOptionsDefinition(returnType NORMAL|PREFIX|BOTH); LoadableTrait::newOption/setOptions; prefixed (<name>-<opt>) overrides generic.
  Reporter::getOptionsDefinition() aggregates+dedups all format opts.
- Pipeline: Reporter::printReport(report,fmt,out,opts): run enabled transformers, then fmt->generate -> file_put_contents(out).
- CLI: bin/console registers ListFormat,ListTransformer,Convert; default=list-format (NOT single-command). Command/Base/AbstractListCommand = styles/renderOptions/renderItems/search.
- Recipe (config file): src/Recipe/{RecipeLoader,RecipeBuilder,RecipeException}. JSON recipe -> Report tree. Convert uses it ONLY when --config given (recipe = single source of truth; all other flags/args ignored). Recipe keys: name/basePath/from/to/output/register/options/inputs. Node = one of file-leaf(path,+type) | group(inputs) | inline(issues); may combine issues+inputs. type/format alias; group type/format = default for descendants. Sibling subreport names must be unique. File-leaf: only name override (basePath from parsed report). register => Factory::register both types. bin/console normalizes `--config -`/`-c -` to = forms (Symfony won't bind lone '-').
