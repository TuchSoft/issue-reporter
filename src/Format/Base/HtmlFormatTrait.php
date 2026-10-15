<?php

namespace Tuchsoft\IssueReporter\Format\Base;

use Tuchsoft\IssueReporter\Issue;

use Parsedown;

trait HtmlFormatTrait
{
    use MdFormatTrait;

    public function initHtml(array $options): void
    {
        $this->initMd($options);
    }

    protected function getSeverityIcon(int $severity): string
    {
        return $this->getSeverityEmoji($severity) .  match ($severity) {
            Issue::SEVERITY_ERROR => '<span style="color:red;">ERROR</span>',
            Issue::SEVERITY_WARNING => '<span style="color:orange;">WARNING</span>',
            Issue::SEVERITY_TIP => '<span style="color:yellowgreen;">TIP</span>',
        };
    }

    protected function writeHtml() {
        return (new Parsedown())->text($this->writeMd());
    }

    /**
     * Escapes user text for the Markdown source that is later converted to HTML
     * by Parsedown. The Markdown is rendered verbatim (inline HTML included), so
     * the text must be HTML-escaped before it reaches Parsedown to avoid XSS.
     *
     * Overrides the Markdown-only escaping of {@see MdFormatTrait::escapeTableCell()}.
     */
    protected function escapeTableCell(string $value): string
    {
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $value = str_replace('|', '\\|', $value);

        return str_replace(["\r\n", "\r", "\n"], '<br>', $value);
    }

    public static function getFormat(): string
    {
        return self::FORMAT_HTML;
    }

}