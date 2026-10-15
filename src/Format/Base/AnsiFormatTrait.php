<?php

namespace Tuchsoft\IssueReporter\Format\Base;

use Tuchsoft\IssueReporter\Issue;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tuchsoft\IssueReporter\Utils\Formatter;
use Tuchsoft\IssueReporter\Utils\NullInput;

trait AnsiFormatTrait
{
    use RichFormatTrait;

    protected BufferedOutput $buffer;
    protected SymfonyStyle $builder;

    public function initAnsi():void
    {
        $this->buffer = new BufferedOutput();
        $this->buffer->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        $this->buffer->setFormatter(new Formatter($this->options['color'], maxWidth: $this->options['max-width']));
        $this->builder = new SymfonyStyle(new NullInput(),  $this->buffer);
    }

    /**
     * ANSI output renders cells as-is; no Markdown/HTML escaping is required.
     */
    protected function escapeTableCell(string $value): string
    {
        return $value;
    }

    protected function getSeverityIcon(int $severity): string
    {
        return $this->getSeverityEmoji($severity) .  match ($severity) {
                Issue::SEVERITY_ERROR => '<error>ERROR</error>',
                Issue::SEVERITY_WARNING => '<warning>WARNING</warning>',
                Issue::SEVERITY_TIP => '<tip>TIP</tip>'
            };
    }

    public static function getFormat(): string
    {
        return self::FORMAT_TXT;
    }

}