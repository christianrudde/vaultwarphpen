<?php

namespace App\Parity;

/**
 * Finds the #[Parity('...')] attributes in the test suite. An attribute names
 * a matrix id, or a prefix ending in "*" such as 'table:*'.
 */
final class TestReferences
{
    /**
     * @param  array<string, list<string>>  $references  Pattern => test locations
     */
    public function __construct(
        public readonly array $references,
    ) {}

    public static function scan(string $directory): self
    {
        $references = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            preg_match_all('/#\[Parity\(\s*([\'"])(.+?)\1\s*\)\]/', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $location = substr($file->getPathname(), strlen(dirname($directory)) + 1).':'.RustSource::lineAt($content, $match[0][1]);
                $references[$match[2][0]][] = $location;
            }
        }

        ksort($references);

        return new self($references);
    }

    /**
     * Test locations covering a matrix id.
     *
     * @return list<string>
     */
    public function for(string $id): array
    {
        $locations = [];

        foreach ($this->references as $pattern => $tests) {
            if (self::matches($pattern, $id)) {
                array_push($locations, ...$tests);
            }
        }

        return $locations;
    }

    public static function matches(string $pattern, string $id): bool
    {
        return str_ends_with($pattern, '*')
            ? str_starts_with($id, substr($pattern, 0, -1))
            : $pattern === $id;
    }
}
