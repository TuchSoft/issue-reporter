<?php

namespace Tuchsoft\IssueReporter\Utils;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;

/**
 * A no-op InputInterface used to drive a SymfonyStyle without a real console input.
 *
 * Parameter types are intentionally left untyped so the class stays compatible
 * with symfony/console ^5, ^6 and ^7 (whose InputInterface declares different
 * parameter types); return types are declared to satisfy the newer versions.
 */
class NullInput implements InputInterface {

    public function setOption(string $name, $value): void
    {
    }

    public function getFirstArgument(): ?string
    {
        return null;
    }

    public function hasParameterOption($values, bool $onlyParams = false): bool
    {
        return false;
    }

    public function getParameterOption($values, $default = false, bool $onlyParams = false): mixed
    {
        return null;
    }

    public function bind(InputDefinition $definition): void
    {
    }

    public function validate(): void
    {
    }

    public function getArguments(): array
    {
        return [];
    }

    public function getArgument(string $name): mixed
    {
        return null;
    }

    public function setArgument(string $name, $value): void
    {
    }

    public function hasArgument(string $name): bool
    {
        return false;
    }

    public function getOptions(): array
    {
        return [];
    }

    public function getOption(string $name): mixed
    {
        return null;
    }

    public function hasOption(string $name): bool
    {
        return false;
    }

    public function isInteractive(): bool
    {
        return false;
    }

    public function setInteractive(bool $interactive): void
    {
    }

    public function __toString(): string
    {
        return '';
    }
}
