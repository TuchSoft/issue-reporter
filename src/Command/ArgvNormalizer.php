<?php

namespace Tuchsoft\IssueReporter\Command;

/**
 * Small argv pre-processor for the CLI entry point.
 *
 * Symfony's ArgvInput never binds a lone "-" as an option value, so the
 * documented "read a recipe from STDIN" spelling `--config -` (and its short
 * form `-c -`) is normalized to the equivalent "=" spelling Symfony accepts.
 */
class ArgvNormalizer
{
    /**
     * @param string[] $argv
     * @return string[]
     */
    public static function normalize(array $argv): array
    {
        for ($i = 1, $n = count($argv); $i < $n - 1; $i++) {
            if ($argv[$i + 1] !== '-') {
                continue;
            }

            if ($argv[$i] === '--config') {
                $argv[$i] = '--config=-';
            } elseif ($argv[$i] === '-c') {
                $argv[$i] = '-c-';
            } else {
                continue;
            }

            array_splice($argv, $i + 1, 1);
            $n--;
        }

        return $argv;
    }
}
