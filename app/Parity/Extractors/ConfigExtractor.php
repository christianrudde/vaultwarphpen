<?php

namespace App\Parity\Extractors;

use App\Parity\RustSource;
use RuntimeException;

/**
 * Collects all settings of the make_config! block in src/config.rs, plus the
 * scheduled jobs that are configured there.
 */
final class ConfigExtractor
{
    /** @var list<string> */
    public private(set) array $warnings = [];

    public function __construct(
        private readonly RustSource $source,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function extract(): array
    {
        $content = $this->source->read('src/config.rs');
        $start = strrpos($content, "\nmake_config! {");
        if ($start === false) {
            throw new RuntimeException('Cannot find the make_config! block in src/config.rs.');
        }

        $offset = $start + strlen("\nmake_config! ");
        $firstLine = RustSource::lineAt($content, $offset);
        $lines = explode("\n", RustSource::block($content, $offset));

        $settings = [];
        $jobs = [];
        $group = null;
        $docs = [];
        $statement = null;

        foreach ($lines as $index => $line) {
            // Drop a trailing comment after a statement: `...; // TODO`.
            $trimmed = (string) preg_replace('/;\s*\/\/.*$/', ';', trim($line));

            if ($statement !== null) {
                $statement['text'] .= ' '.$trimmed;
            } elseif (preg_match('/^ {4}(\w+)(?:\s*:\s*\w+)?\s*\{$/', $line, $match)) {
                $group = $match[1];
                $docs = [];

                continue;
            } elseif (str_starts_with($trimmed, '///')) {
                $docs[] = trim(substr($trimmed, 3));

                continue;
            } elseif (preg_match('/^_?\w+\s*:/', $trimmed)) {
                $statement = ['text' => $trimmed, 'line' => $firstLine + $index];
            } else {
                $docs = [];

                continue;
            }

            if (! str_ends_with($trimmed, ';')) {
                continue;
            }

            if (! preg_match('/^(\w+)\s*:\s*([^,]+?)\s*,\s*(true|false)\s*,\s*(\w+)\s*(?:,\s*(.*?))?\s*;$/s', $statement['text'], $match)) {
                $this->warnings[] = "Cannot parse setting at src/config.rs:{$statement['line']}.";
                $statement = null;
                $docs = [];

                continue;
            }

            [$title, $description] = $this->describe($docs);
            $env = strtoupper($match[1]);

            $settings[] = array_filter([
                'id' => "config:{$env}",
                'kind' => 'config',
                'env' => $env,
                'group' => $group,
                'type' => $match[2],
                'editable' => $match[3] === 'true',
                'default_kind' => $match[4],
                'default' => $match[5] ?? null,
                'title' => $title,
                'description' => $description,
                'source' => "src/config.rs:{$statement['line']}",
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            if ($group === 'jobs' && str_ends_with($match[1], '_schedule')) {
                $jobs[] = array_filter([
                    'id' => 'job:'.substr($match[1], 0, -strlen('_schedule')),
                    'kind' => 'job',
                    'config' => $env,
                    'schedule' => preg_match('/^"([^"]*)"/', $match[5] ?? '', $schedule) ? $schedule[1] : null,
                    'title' => $title,
                    'source' => "src/config.rs:{$statement['line']}",
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }

            $statement = null;
            $docs = [];
        }

        return [...$settings, ...$jobs];
    }

    /**
     * Doc comments read "Title |> Description".
     *
     * @param  list<string>  $docs
     * @return array{string, string}
     */
    private function describe(array $docs): array
    {
        $text = implode(' ', $docs);

        if (str_contains($text, '|>')) {
            [$title, $description] = explode('|>', $text, 2);

            return [trim($title), trim($description)];
        }

        return [trim($text), ''];
    }
}
