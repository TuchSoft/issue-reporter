# Index (mirror of docs/index.md)
- Package tuchsoft/issue-reporter; Apache-2.0; PHP >=8.0. Dual-use: CLI + library.
- Reads static-analysis issue reports -> common Report/Issue model -> optional transform -> render any format.
- Root README.md = overview (both use modes); docs: docs/{index,cli,architecture,todo}.md.
- CLI: bin/console { list-format, list-transformer, convert }. convert supports a JSON recipe/config file (--config FILE|-).
- Lib entry: Reporter::printReport(Report, fmt, output, options); Report+Issue model.
- All 13 formats + CLI + Recipe + Model + Factory + Utils covered by tests (see phpunit.xml suites). Parse: Factory::create(FORMAT,name)->parse(str).
