<?php

namespace App\Parity;

use RuntimeException;

/**
 * Read access to the Vaultwarden source tree with the few parsing helpers
 * the extractors share.
 */
final class RustSource
{
    /** @var array<string, string> */
    private array $files = [];

    public function __construct(
        public readonly string $root,
    ) {}

    public function read(string $file): string
    {
        if (! isset($this->files[$file])) {
            $contents = @file_get_contents($this->root.'/'.$file);

            if ($contents === false) {
                throw new RuntimeException("Cannot read {$file} in the reference checkout.");
            }

            $this->files[$file] = $contents;
        }

        return $this->files[$file];
    }

    /**
     * The file of a module path below src/, e.g. ["api", "core"].
     *
     * @param  list<string>  $module
     */
    public function moduleFile(array $module): ?string
    {
        $path = 'src/'.implode('/', $module);

        foreach ([$path.'.rs', $path.'/mod.rs'] as $file) {
            if (is_file($this->root.'/'.$file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * All files below a directory, relative to the root and sorted.
     *
     * @return list<string>
     */
    public function files(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root.'/'.$directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The text between the bracket at $open and its matching closing bracket.
     */
    public static function block(string $content, int $open): string
    {
        $pairs = ['{' => '}', '(' => ')', '[' => ']'];
        $opening = $content[$open];
        $closing = $pairs[$opening] ?? throw new RuntimeException("No bracket at offset {$open}.");
        $depth = 0;
        $length = strlen($content);

        for ($i = $open; $i < $length; $i++) {
            $char = $content[$i];

            if (($end = self::endOfLiteral($content, $i)) !== null) {
                $i = $end;

                continue;
            }

            if ($char === $opening) {
                $depth++;
            } elseif ($char === $closing && --$depth === 0) {
                return substr($content, $open + 1, $i - $open - 1);
            }
        }

        throw new RuntimeException("Unbalanced bracket at offset {$open}.");
    }

    /**
     * Split at a separator that is not nested in brackets, generics or strings.
     *
     * @return list<string>
     */
    public static function splitTopLevel(string $content, string $separator = ','): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if (($end = self::endOfLiteral($content, $i)) !== null) {
                if (! str_starts_with(substr($content, $i, 2), '//')) {
                    $current .= substr($content, $i, $end - $i + 1);
                }
                $i = $end;

                continue;
            }

            if (in_array($char, ['{', '(', '[', '<'], true)) {
                $depth++;
            } elseif (in_array($char, ['}', ')', ']'], true) || ($char === '>' && ($content[$i - 1] ?? '') !== '-')) {
                $depth--;
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    public static function lineAt(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    /**
     * The last offset of a string literal, char literal or line comment that
     * starts at $start, or null when none starts there.
     */
    private static function endOfLiteral(string $content, int $start): ?int
    {
        $length = strlen($content);
        $char = $content[$start];

        if ($char === '/' && ($content[$start + 1] ?? '') === '/') {
            $end = strpos($content, "\n", $start);

            return $end === false ? $length - 1 : $end - 1;
        }

        if ($char === "'") {
            // A char literal like '"' or '\n'; anything else is a lifetime.
            if (($content[$start + 2] ?? '') === "'") {
                return $start + 2;
            }
            if (($content[$start + 1] ?? '') === '\\' && ($content[$start + 3] ?? '') === "'") {
                return $start + 3;
            }

            return null;
        }

        if ($char !== '"') {
            return null;
        }

        for ($i = $start + 1; $i < $length; $i++) {
            if ($content[$i] === '\\') {
                $i++;
            } elseif ($content[$i] === '"') {
                return $i;
            }
        }

        return $length - 1;
    }
}
