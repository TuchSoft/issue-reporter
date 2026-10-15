<?php

namespace Tuchsoft\IssueReporter;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use Tuchsoft\IssueReporter\Format\Base\FormatInterface;
use Tuchsoft\IssueReporter\Base\LoadableInterface;
use Tuchsoft\IssueReporter\Transformer\base\TransformerInterface;
use Tuchsoft\IssueReporter\Utils\Path;

class Factory {

    public const FORMAT = 'format';
    public const TRANSFORMER = 'transformer';
    protected static array $format = [];
    protected static array $transformer = [];
    protected static ?ClassLoader $autoloader = null;
    protected static bool $buildInLoaded = false;

    private static function getComposerAutoloader(): ClassLoader
    {
        if (self::$autoloader === null) {
            foreach (spl_autoload_functions() as $function) {
                if (is_array($function) && $function[0] instanceof ClassLoader) {
                    self::$autoloader = $function[0];
                    break;
                }
            }
        }

        if (self::$autoloader === null) {
            throw new \Exception('Composer autoloader not found.');
        }

        return self::$autoloader;
    }

    public static function register(string $type, string $fqnOrNamespace): void {
        if (class_exists($fqnOrNamespace)) {
            self::registerClass($type, $fqnOrNamespace);
        } else {
            self::registerNamespace($type, $fqnOrNamespace);
        }
    }

    protected static function registerClass(string $type, string $fqn): void {
        $interface = match ($type) {
            self::FORMAT => FormatInterface::class,
            self::TRANSFORMER => TransformerInterface::class,
            default => null,
        };

        if ($interface === null || !is_a($fqn, $interface, true)) {
            return;
        }

        $reflectionClass = new ReflectionClass($fqn);
        if ($reflectionClass->isAbstract()) {
            return;
        }

        $name = $fqn::getName();
        self::${$type}[$name] = $fqn;
    }

    protected static function registerBuiltIn(): void {
        if (self::$buildInLoaded) return;
        self::$buildInLoaded = true;
        self::registerNamespace(self::FORMAT,'Tuchsoft\\IssueReporter\\Format');
        self::registerNamespace(self::TRANSFORMER,'Tuchsoft\\IssueReporter\\Transformer');
    }

    protected static function registerNamespace(string $type, string $namespace): void {
        $autoloader = self::getComposerAutoloader();
        $psr4Prefixes = $autoloader->getPrefixesPsr4();
        $namespace = rtrim($namespace, '\\') . '\\';
        $paths = [];

        // Find the longest matching PSR-4 prefix and its corresponding paths
        $matchingPrefix = '';
        foreach (array_keys($psr4Prefixes) as $prefix) {
            if (str_starts_with($namespace, $prefix) && strlen($prefix) > strlen($matchingPrefix)) {
                $matchingPrefix = $prefix;
                $paths = $psr4Prefixes[$prefix];
            }
        }

        if (empty($paths)) {
            // Namespace not found in Composer's autoloader configuration.
            return;
        }

        $subNamespace = substr($namespace, strlen($matchingPrefix));
        $subPath = str_replace('\\', DIRECTORY_SEPARATOR, $subNamespace);

        foreach ($paths as $path) {
            $fullPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $subPath;

            if (!is_dir($fullPath)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fullPath));

            foreach ($files as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relativePath = Path::stripBasepath($file->getPathname(), $fullPath);
                $className = str_replace(DIRECTORY_SEPARATOR, '\\', substr($relativePath, 0, -4));
                $fqn = $namespace . $className;

                if (class_exists($fqn)) {
                    self::registerClass($type, $fqn);
                }
            }
        }
    }

    /**
     * @param string $type
     * @param string $name
     * @param array $options
     */
    public static function create(string $type, string $name, array $options): ?LoadableInterface {
        self::registerBuiltIn();
        if (!isset(self::${$type}[$name])) {
            throw new \Exception('Format ' . $name . ' does not exist.');
        }
        $fqn = self::${$type}[$name];
        return new $fqn($options);
    }


    public static function getRegistered(string $type): array {
        self::registerBuiltIn();
        return self::${$type};

    }
}