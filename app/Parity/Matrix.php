<?php

namespace App\Parity;

use JsonException;
use RuntimeException;

/**
 * The parity matrix (parity/matrix.json): every route, setting, job, table,
 * template and asset of the reference, each with a maintained status.
 *
 * The extracted fields are regenerated from the reference, while "status"
 * and "notes" are maintained by hand and survive regeneration.
 */
final class Matrix
{
    public const array STATUSES = ['todo', 'partial', 'done', 'wontfix'];

    public const array KINDS = ['route', 'config', 'job', 'table', 'template', 'asset'];

    /**
     * @param  array<string, array<string, mixed>>  $items  Keyed by id
     */
    public function __construct(
        public private(set) string $commit,
        public private(set) array $items,
    ) {}

    /**
     * @throws JsonException
     */
    public static function load(string $file): self
    {
        if (! is_file($file)) {
            return new self('', []);
        }

        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_string($data['commit'] ?? null) || ! is_array($data['items'] ?? null)) {
            throw new RuntimeException("{$file} is not a parity matrix.");
        }

        $items = [];
        foreach ($data['items'] as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            if (! is_array($item) || ! is_string($id)) {
                throw new RuntimeException("{$file} contains an item without id.");
            }

            // JSON object keys are strings, even where PHP turned them into integers.
            $fields = [];
            foreach ($item as $key => $value) {
                $fields[(string) $key] = $value;
            }
            $items[$id] = $fields;
        }

        return new self($data['commit'], $items);
    }

    /**
     * One item per line keeps diffs and hand edits of the status readable.
     *
     * @throws JsonException
     */
    public function save(string $file): void
    {
        $lines = array_map(
            static fn (array $item): string => '    '.json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            array_values($this->items),
        );

        file_put_contents($file, sprintf(
            "{\n  \"commit\": %s,\n  \"items\": [\n%s\n  ]\n}\n",
            json_encode($this->commit, JSON_THROW_ON_ERROR),
            implode(",\n", $lines),
        ));
    }

    /**
     * Replace the extracted fields with a fresh extraction of the reference.
     *
     * @param  list<array<string, mixed>>  $extracted
     * @return array{added: list<string>, removed: list<string>}
     */
    public function merge(string $commit, array $extracted): array
    {
        $items = [];
        $added = [];

        foreach ($extracted as $item) {
            $id = $item['id'];
            if (! is_string($id)) {
                throw new RuntimeException('Extracted item without id.');
            }

            $previous = $this->items[$id] ?? null;
            if ($previous === null) {
                $added[] = $id;
            }

            $items[$id] = [
                ...$item,
                'status' => $previous['status'] ?? 'todo',
                'notes' => $previous['notes'] ?? '',
            ];
        }

        // Items that vanished upstream stay until their implementation is cleaned up.
        $removed = [];
        foreach ($this->items as $id => $item) {
            if (! isset($items[$id])) {
                $removed[] = $id;
                $items[$id] = [...$item, 'upstream' => 'removed'];
            }
        }

        $this->commit = $commit;
        $this->items = $items;

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Set the status (and optionally the notes) of all items whose id matches
     * a pattern (see TestReferences).
     */
    public function mark(string $pattern, string $status, ?string $notes = null): int
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new RuntimeException("Unknown status [{$status}].");
        }

        $count = 0;
        foreach (array_keys($this->items) as $id) {
            if (TestReferences::matches($pattern, $id)) {
                $this->items[$id]['status'] = $status;
                if ($notes !== null) {
                    $this->items[$id]['notes'] = $notes;
                }
                $count++;
            }
        }

        return $count;
    }

    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
