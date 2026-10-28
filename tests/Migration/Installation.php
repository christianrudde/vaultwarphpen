<?php

namespace Tests\Migration;

use RuntimeException;

/**
 * What the phases of the migration test hand on to each other: the ids,
 * tokens and secrets of the installation they fill, and what they saw of
 * it. Each phase runs in a process of its own, so this lives in a folder
 * (VW_MIGRATION_STATE): installation.json, and the views of each phase in
 * views-<phase>.json, which CI keeps when a phase fails.
 */
final class Installation
{
    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(
        private readonly string $folder,
        private array $values,
    ) {}

    public static function open(string $folder): self
    {
        $saved = is_file("{$folder}/installation.json") ? json_decode((string) file_get_contents("{$folder}/installation.json"), true) : [];
        $values = [];
        foreach (is_array($saved) ? $saved : [] as $key => $value) {
            $values[(string) $key] = $value;
        }

        return new self($folder, $values);
    }

    public function save(): void
    {
        file_put_contents("{$this->folder}/installation.json", json_encode($this->values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Whether something other than null is saved under the key.
     */
    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    public function get(string $key): mixed
    {
        return $this->has($key) ? $this->values[$key] : throw new RuntimeException("Nothing saved as {$key}.");
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : throw new RuntimeException("{$key} is no string.");
    }

    /**
     * @return array<mixed>
     */
    public function list(string $key): array
    {
        $value = $this->has($key) ? $this->values[$key] : [];

        return is_array($value) ? $value : throw new RuntimeException("{$key} is no list.");
    }

    /**
     * The entries of a list of lists, such as [cipher, attachment] pairs, with
     * what is no string as null.
     *
     * @return list<list<string|null>>
     */
    public function records(string $key): array
    {
        return array_values(array_map(static fn (mixed $record): array => array_values(array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null,
            is_array($record) ? $record : [])), $this->list($key)));
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function push(string $key, mixed $value): void
    {
        $this->values[$key] = [...$this->list($key), $value];
    }

    /**
     * The headers of a user's requests, with the access token saved last, from
     * a client recent enough to see every cipher type.
     *
     * @return array<string, string>
     */
    public function auth(string $user): array
    {
        return ['Authorization' => 'Bearer '.$this->string("{$user}.token"), 'Accept' => 'application/json', 'Bitwarden-Client-Version' => '2026.8.0'];
    }

    /**
     * @param  array<string, string>  $views
     */
    public function saveViews(string $phase, array $views): void
    {
        file_put_contents("{$this->folder}/views-{$phase}.json", json_encode($views, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    public function views(string $phase): array
    {
        $saved = json_decode((string) @file_get_contents("{$this->folder}/views-{$phase}.json"), true);
        if (! is_array($saved)) {
            throw new RuntimeException("No views of the {$phase} phase.");
        }
        $views = [];
        foreach ($saved as $name => $view) {
            $views[(string) $name] = is_string($view) ? $view : '';
        }

        return $views;
    }
}
