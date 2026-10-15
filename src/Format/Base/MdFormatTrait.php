<?php

namespace Tuchsoft\IssueReporter\Format\Base;

use Tuchsoft\IssueReporter\Issue;

use DavidBadura\MarkdownBuilder\MarkdownBuilder;
use Symfony\Component\Console\Output\BufferedOutput;

trait MdFormatTrait
{
    use RichFormatTrait;

    protected BufferedOutput $buffer;
    protected MarkdownBuilder $builder;

    public function initMd(array $options): void
    {
        $this->builder = new MarkdownBuilder();
    }

    protected function getSeverityIcon(int $severity): string
    {
        return $this->getSeverityEmoji($severity) .  match ($severity) {
            Issue::SEVERITY_ERROR => 'ERROR',
            Issue::SEVERITY_WARNING => 'WARNING',
            Issue::SEVERITY_TIP => 'TIP'
        };
    }

    protected function writeMd(): string {
        return $this->builder->getMarkdown().PHP_EOL;
    }

    /**
     * Makes arbitrary user text safe for a single Markdown table cell.
     *
     * Pipes would otherwise introduce extra columns and raw newlines would
     * split the row in two, breaking the table layout.
     */
    protected function escapeTableCell(string $value): string
    {
        $value = str_replace('|', '\\|', $value);

        return str_replace(["\r\n", "\r", "\n"], '<br>', $value);
    }


    public static function getFormat(): string
    {
        return self::FORMAT_MD;
    }

}