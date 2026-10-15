<?php

namespace Tuchsoft\IssueReporter\Command\Base;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared base for the "list-*" commands.
 *
 * Provides output styling, option rendering and fuzzy search utilities so that
 * `list-format` and `list-transformer` stay consistent without duplicating code.
 */
abstract class AbstractListCommand extends Command
{
    /**
     * Builds a SymfonyStyle instance with the project's custom styles applied.
     */
    protected function buildIo(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        $io = new SymfonyStyle($input, $output);
        $io->getFormatter()->setStyle('cmd', new OutputFormatterStyle('bright-blue'));
        $io->getFormatter()->setStyle('txt', new OutputFormatterStyle('bright-white'));

        return $io;
    }

    /**
     * Renders a list of option definitions, one per line, de-duplicated by name.
     *
     * @param InputOption[] $options
     */
    protected function renderOptions(SymfonyStyle $io, array $options): void
    {
        $helper = new DescriptorHelper();
        $seen = [];
        /** @var InputOption $option */
        foreach ($options as $option) {
            if (isset($seen[$option->getName()])) {
                continue;
            }
            $seen[$option->getName()] = true;

            $helper->describe($io, $option, ['format' => 'txt']);
            if ($option->isNegatable()) {
                $default =
                    ($option->getDefault() ? '(true) ' : '(false) ') .
                    ($option->getDefault() ? '--' : '--no-') .
                    $option->getName();
                $io->write(" <comment>[default: $default]]</comment>");
            }
            $io->newLine();
        }
    }

    /**
     * Writes a bulleted list of raw feature/flag strings.
     *
     * @param string[] $items
     */
    protected function renderItems(SymfonyStyle $io, array $items): void
    {
        $io->write(array_map(fn ($e) => '      - ' . ucfirst(str_replace('-', ' ', $e)) . "\n", $items));
    }

    /**
     * Fuzzy-matches an input string against a list of candidate names.
     *
     * Falls back to the single most similar candidate when nothing crosses the threshold.
     *
     * @param string[] $candidates
     * @return string[]
     */
    protected function search(string $input, array $candidates): array
    {
        $mostSimilar = '';
        $highestSimilarity = 0;
        $threshold = 60;
        $similar = [];

        foreach ($candidates as $string) {
            similar_text($input, $string, $percent);
            if ($percent > $highestSimilarity) {
                $highestSimilarity = $percent;
                $mostSimilar = $string;
            }
            if ($percent >= $threshold) {
                $similar[] = $string;
            }
        }

        return !empty($similar) ? $similar : [$mostSimilar];
    }
}
