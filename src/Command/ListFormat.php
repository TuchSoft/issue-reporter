<?php

namespace Tuchsoft\IssueReporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tuchsoft\IssueReporter\Command\Base\AbstractListCommand;
use Tuchsoft\IssueReporter\Factory;
use Tuchsoft\IssueReporter\Format\Base\NativeFormatInterface;
use Tuchsoft\IssueReporter\Format\Base\ParsableFormatInterface;

class ListFormat extends AbstractListCommand
{

    protected static $defaultName = 'list-format';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName(static::$defaultName)
            ->setDescription('List all available output formats and their options.')
            ->setHelp("The <info>%command.name%</info> command lists all available output formats:\n\n<info>%command.full_name%</info>")
            ->addArgument('search', InputArgument::OPTIONAL, 'Fuzzy-search formats by name.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->buildIo($input, $output);

        $formats = Factory::getRegistered(Factory::FORMAT);
        if (($search = $input->getArgument('search'))) {
            $names = $this->search($search, array_keys($formats));
            if (!$names) {
                $io->warning("No format found for: '$search'");
            }
            $formats = array_filter($formats, fn ($k) => in_array($k, $names), ARRAY_FILTER_USE_KEY);
            $io->title('Found output format (more available)');
        } else {
            $io->title('Available output format');
        }

        foreach ($formats as $format) {
            $parsable = is_subclass_of($format, ParsableFormatInterface::class);
            $io->text("<txt>Name:</txt> <cmd>{$format::getName()}</cmd>");
            $io->text("<txt>Description:</txt> {$format::getDesc()}");
            $io->text("<txt>Format:</txt> {$format::getFormat()}");
            $io->text('<txt>Parsable:</txt> ' . ($parsable ? 'yes' : 'no'));
            $io->text("<txt>Options:</txt> ");
            $this->renderOptions($io, $format::getOptionsDefinition());
            if (is_a($format, ParsableFormatInterface::class, true)) {
                $io->text("<txt>Features:</txt>");
                $this->renderItems($io, $format::supports());
                if (($extra = $format::supportsExtra())) {
                    $io->text("<txt>Extra features (not natively supported):</txt>");
                    $this->renderItems($io, $extra);
                }
            } else if (is_a($format, NativeFormatInterface::class, true)) {
                $io->text("<txt>Features:</txt>");
                $this->renderItems($io, ['Native format supports all features but are not parsable']);
            }

            $io->newLine();
            $io->text(str_repeat('-', 20));
            $io->newLine();
        }
        return Command::SUCCESS;
    }
}
