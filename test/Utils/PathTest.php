<?php

namespace Tuchsoft\IssueReporter\Test\Utils;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tuchsoft\IssueReporter\Utils\Path;

#[CoversClass(Path::class)]
class PathTest extends TestCase
{
    /**
     * Data provider for testStripBasepath.
     */
    public static function stripBasepathProvider(): array
    {
        $s = DIRECTORY_SEPARATOR;
        return [
            'standard case' => ["var/www/project/file.php", "var/www/project", 'file.php'],
            'with subdirectory' => ["var/www/project/subdir/file.php", "var/www/project", "subdir/file.php"],
            'no match' => ["var/www/project/file.php", "var/www/other", "var/www/project/file.php"],
            'empty basepath' => ["var/www/project/file.php", '', "var/www/project/file.php"],
            'path becomes empty' => ["var/www/project", "var/www/project", '.'],
            'path becomes only separator' => ["var/www/project/", "var/www/project", '.'],
            'relative paths' => ["project/file.php", 'project', 'file.php'],
            'path with leading separator' => ["/var/www/project/file.php", "/var/www/project", 'file.php'],
            'windows path' => ["C:/Users/Test/file.php", "C:/Users/Test", 'file.php'],
        ];
    }

    /**
     * @param string $path The full path to be stripped.
     * @param string $basepath The base path to remove.
     * @param string $expected The expected result after stripping.
     */
    #[DataProvider('stripBasepathProvider')]
    public function testStripBasepath(string $path, string $basepath, string $expected): void
    {
        $this->assertEquals($expected, Path::stripBasepath($path, $basepath));
    }

    /**
     * Data provider for testFindCommonBasePath.
     */
    public static function commonBasePathProvider(): array
    {
        return [
            'empty array' => [[], '/'],
            'single path' => [['/var/www/project/file1.php'], '/var/www/project/'],
            'single relative path' => [['var/www/project/file1.php'], './var/www/project/'],
            'single short relative path' => [['src/File1.php'], './src/'],
            'two simple paths' => [['/var/www/project/file1.php', '/var/www/project/file2.php'], '/var/www/project/'],
            'different subdirectories' => [['/var/www/project/src/file1.php', '/var/www/project/tests/file2.php'], '/var/www/project/'],
            'deeper common path' => [['/var/www/project/src/x/y/z/file1.php', '/var/www/project/src/x/y/z/file2.php'], '/var/www/project/src/x/y/z/'],
            'partial common path' => [['/var/www/project/file1.php', '/var/lib/something/file2.php'], '/var/'],
            'relative paths' => [['src/file1.php', 'src/file2.php'], './src/'],
            'one path is subdirectory of another' => [['/a/b/c', '/a/b/c/d'], '/a/b/c/'],
            'identical paths' => [['/a/b', '/a/b'], '/a/b/'],
            'no commonality relative' => [['/a/b/c', '/x/y/z'], '/'],
            'windows paths' => [['C:/Users/A', 'C:/Users/B'], 'C:/Users/'],
            'path to be normalized' => [['/a/b/./c', '/a/x/y/z/../../.././b', '/a/b/../b/c'], '/a/b/'],
            'trailing slahs' => [['/a/b/c/', '/a/b/c/d/'], '/a/b/c/'],
        ];
    }

    /**
     * @param array<string> $paths An array of paths to find the common base for.
     * @param string $expected The expected common base path.
     */
    #[DataProvider('commonBasePathProvider')]
    public function testFindCommonBasePath(array $paths, string $expected): void
    {
        // On non-Unix systems, the test case for the Unix root path is not relevant.
        if ($expected === '/' && DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('Unix root path test is only for Unix-like systems.');
        }

        $this->assertEquals($expected, Path::findCommonBasePath($paths));
    }
}