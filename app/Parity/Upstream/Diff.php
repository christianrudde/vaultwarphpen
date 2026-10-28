<?php

namespace App\Parity\Upstream;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads what changed upstream with git, in the reference checkout. parity:fetch
 * fetches the pinned commit alone; fetch() adds a branch, tag or commit with the
 * history back to the pin.
 */
final readonly class Diff
{
    /**
     * Where fetch() puts the fetched revision.
     */
    public const string REF = 'refs/parity/upstream';

    /**
     * Patches without context, so that the hunk headers name the item each
     * change lies in, and without rename detection, so that paths stay those of
     * the old side.
     */
    /**
     * Git's rust diff driver, which only applies when the attributes ask for it.
     */
    private const string ATTRIBUTES = '*.rs diff=rust';

    private const array DIFF_OPTIONS = ['--patch', '--unified=0', '--no-color', '--no-ext-diff', '--no-renames'];

    public function __construct(
        private string $checkout,
    ) {}

    /**
     * Fetch a revision of the reference repository ("main", a tag or a commit)
     * with the history since the day before the pinned commit.
     *
     * @return string The fetched commit
     */
    public function fetch(string $revision, string $pin): string
    {
        $committed = (int) trim($this->git(['show', '--no-patch', '--format=%ct', $pin]));
        $since = gmdate('Y-m-d', $committed - 86_400);

        $this->git(['fetch', '--quiet', "--shallow-since={$since}", 'origin', "+{$revision}:".self::REF], timeout: 600);

        return $this->resolve(self::REF) ?? throw new RuntimeException("Fetching {$revision} did not give a commit.");
    }

    /**
     * The commit of a revision the checkout has, or null.
     */
    public function resolve(string $revision): ?string
    {
        $result = Process::path($this->checkout)->run(['git', 'rev-parse', '--verify', '--quiet', "{$revision}^{commit}"]);

        return $result->successful() ? trim($result->output()) : null;
    }

    public function isAncestor(string $ancestor, string $revision): bool
    {
        return Process::path($this->checkout)->run(['git', 'merge-base', '--is-ancestor', $ancestor, $revision])->successful();
    }

    /**
     * A file at a revision, or null when it does not exist there.
     */
    public function show(string $revision, string $file): ?string
    {
        $result = Process::path($this->checkout)->run(['git', 'show', "{$revision}:{$file}"]);

        return $result->successful() ? $result->output() : null;
    }

    /**
     * The commits reachable from $to but not from $from, oldest first, without
     * merge commits (their changes are in the commits they merge).
     *
     * @return list<Commit>
     */
    public function commits(string $from, string $to): array
    {
        $log = $this->git(['log', '--reverse', '--no-merges', '--format=%x01%H%x09%s', ...self::DIFF_OPTIONS, "{$from}..{$to}"]);

        $commits = [];
        foreach (explode("\x01", $log) as $entry) {
            if ($entry === '') {
                continue;
            }

            [$header, $patch] = array_pad(explode("\n", $entry, 2), 2, '');
            [$sha, $subject] = array_pad(explode("\t", $header, 2), 2, '');
            $commits[] = new Commit($sha, $subject, self::parse($patch));
        }

        return $commits;
    }

    /**
     * Everything that changed between two revisions at once, with the lines
     * counted on the side of $from.
     */
    public function changes(string $from, string $to): Changes
    {
        return self::parse($this->git(['diff', ...self::DIFF_OPTIONS, $from, $to]));
    }

    /**
     * Read the files and hunks of a patch.
     */
    public static function parse(string $patch): Changes
    {
        $files = [];
        $hunks = [];
        $hunk = null;

        foreach (explode("\n", $patch) as $line) {
            if (preg_match('#^diff --git a/(\S+) b/(\S+)$#', $line, $match) === 1) {
                $hunks = self::add($hunks, $hunk);
                $hunk = null;
                $files[] = $match[1];

                continue;
            }

            if ($files !== [] && preg_match('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@ ?(.*)$/', $line, $match) === 1) {
                $hunks = self::add($hunks, $hunk);
                $start = (int) $match[1];
                $count = $match[2] === '' ? 1 : (int) $match[2];
                $scope = self::scope($match[3]);
                $hunk = [
                    'file' => $files[array_key_last($files)],
                    // A pure addition (count 0) follows line $start.
                    'start' => $start,
                    'end' => max($start, $start + $count - 1),
                    'scope' => $scope,
                    'functions' => $scope !== null && str_starts_with($scope, 'fn ') ? [substr($scope, 3)] : [],
                ];

                continue;
            }

            // Inside a hunk, every line that starts with + or - is a changed line.
            if ($hunk !== null && preg_match('/^[+-].*\bfn\s+(\w+)/', $line, $match) === 1
                && ! in_array($match[1], $hunk['functions'], true)) {
                $hunk['functions'][] = $match[1];
            }
        }

        return new Changes(array_values(array_unique($files)), self::add($hunks, $hunk));
    }

    /**
     * @param  list<Hunk>  $hunks
     * @param  array{file: string, start: int, end: int, scope: ?string, functions: list<string>}|null  $hunk
     * @return list<Hunk>
     */
    private static function add(array $hunks, ?array $hunk): array
    {
        if ($hunk !== null) {
            $hunks[] = new Hunk($hunk['file'], $hunk['start'], $hunk['end'], $hunk['scope'], $hunk['functions']);
        }

        return $hunks;
    }

    /**
     * The item a hunk header names, as "fn name", "struct Name" and so on.
     */
    private static function scope(string $header): ?string
    {
        if (preg_match('/\bfn\s+(\w+)/', $header, $match) === 1) {
            return "fn {$match[1]}";
        }

        if (preg_match('/\b(struct|enum|union|trait|mod|impl|macro_rules!)\s*(?:<[^>]*>\s*)?([\w:]+)/', $header, $match) === 1) {
            return "{$match[1]} {$match[2]}";
        }

        return null;
    }

    /**
     * Run git with the rust diff driver for .rs files, which names the item
     * around each change in the hunk header.
     *
     * @param  list<string>  $arguments
     */
    private function git(array $arguments, int $timeout = 120): string
    {
        $attributes = $this->checkout.'/.git/info/attributes';
        if (! str_contains((string) @file_get_contents($attributes), self::ATTRIBUTES)) {
            File::ensureDirectoryExists(dirname($attributes));
            File::append($attributes, self::ATTRIBUTES."\n");
        }

        $result = Process::path($this->checkout)->timeout($timeout)->run(['git', ...$arguments]);

        if (! $result->successful()) {
            throw new RuntimeException(sprintf('git %s failed: %s', $arguments[0], trim($result->errorOutput())));
        }

        return $result->output();
    }
}
