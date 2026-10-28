<?php

namespace App\Parity\Upstream;

use App\Parity\Matrix;
use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Where a place in Vaultwarden's source lives on our side: the parity items
 * whose source it is, and the PHP functions named like the Rust function.
 */
final class Counterparts
{
    /**
     * PHP functions by their lowercased name without underscores, so that
     * post_attachment_v2 finds postAttachmentV2.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $functions = null;

    /**
     * @param  array<string, array<string, mixed>>  $items  The parity matrix, keyed by id
     * @param  string  $basePath  The project, whose app/ is searched
     */
    public function __construct(
        private readonly array $items,
        private readonly string $basePath,
    ) {}

    /**
     * The items whose source is in the file and is one of the functions (by
     * handler name), lies within the lines, or is the file itself.
     *
     * @param  list<string>  $functions
     * @param  list<array{int, int}>  $lines
     * @return list<string>
     */
    public function items(string $file, array $functions = [], array $lines = []): array
    {
        $ids = [];
        foreach ($this->items as $id => $item) {
            [$sourceFile, $sourceLine] = array_pad(explode(':', Matrix::string($item['source'] ?? ''), 2), 2, null);
            if ($sourceFile !== $file) {
                continue;
            }

            $handler = Matrix::string($item['handler'] ?? '');

            if ($sourceLine === null
                || ($handler !== '' && in_array(Str::afterLast($handler, '::'), $functions, true))
                || $this->within((int) $sourceLine, $lines)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The items whose handler is the function, in any file.
     *
     * @return list<string>
     */
    public function handlers(string $function): array
    {
        $ids = [];
        foreach ($this->items as $id => $item) {
            $handler = Matrix::string($item['handler'] ?? '');
            if ($handler !== '' && Str::afterLast($handler, '::') === $function) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The PHP functions named like a Rust function, as "path:line".
     *
     * @return list<string>
     */
    public function php(string $function): array
    {
        return $this->functions()[self::key($function)] ?? [];
    }

    public function status(string $id): string
    {
        return Matrix::string($this->items[$id]['status'] ?? '');
    }

    /**
     * @param  list<array{int, int}>  $lines
     */
    private function within(int $line, array $lines): bool
    {
        foreach ($lines as [$start, $end]) {
            if ($line >= $start && $line <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private function functions(): array
    {
        if ($this->functions !== null) {
            return $this->functions;
        }

        $this->functions = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->basePath.'/app', FilesystemIterator::SKIP_DOTS));
        $paths = [];
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        foreach ($paths as $path) {
            $code = (string) file_get_contents($path);
            preg_match_all('/\bfunction\s+(\w+)\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$name, $offset]) {
                $line = substr_count($code, "\n", 0, $offset) + 1;
                $this->functions[self::key($name)][] = substr($path, strlen($this->basePath) + 1).':'.$line;
            }
        }

        return $this->functions;
    }

    private static function key(string $name): string
    {
        return strtolower(str_replace('_', '', $name));
    }
}
