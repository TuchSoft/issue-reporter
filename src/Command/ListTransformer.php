<?php

namespace Tuchsoft\IssueReporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Tuchsoft\IssueReporter\Command\Base\AbstractListCommand;
use Tuchsoft\IssueReporter\Factory;

class ListTransformer extends AbstractListCommand
{

    protected static $defaultName = 'list-transformer';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName(static::$defaultName)
            ->setDescription('List all available report transformers and their options.')
            ->setHelp("The <info>%command.name%</info> command lists all available report transformers:\n\n<info>%command.full_name%</info>")
            ->addArgument('search', InputArgument::OPTIONAL, 'Fuzzy-search transformers by name.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->buildIo($input, $output);

        $transformers = Factory::getRegistered(Factory::TRANSFORMER);
        if (($search = $input->getArgument('search'))) {
            $names = $this->search($search, array_keys($transformers));
            if (!$names) {
                $io->warning("No transformer found for: '$search'");
            }
            $transformers = array_filter($transformers, fn ($k) => in_array($k, $names), ARRAY_FILTER_USE_KEY);
            $io->title('Found transformer (more available)');
        } else {
            $io->title('Available transformer');
        }

        /** @var class-string $transformer */
        foreach ($transformers as $transformer) {
            $io->text("<txt>Name:</txt> <cmd>{$transformer::getName()}</cmd>");
            $io->text("<txt>Description:</txt> {$transformer::getDesc()}");
            $io->text('<txt>Enabled by default:</txt> ' . ($transformer::isEnabled([]) ? 'yes' : 'no'));
            $io->text("<txt>Options:</txt> ");
            $this->renderOptions($io, $transformer::getOptionsDefinition());

            $io->newLine();
            $io->text(str_repeat('-', 20));
            $io->newLine();
        }
        return Command::SUCCESS;
    }
}
