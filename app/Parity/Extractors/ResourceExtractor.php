<?php

namespace App\Parity\Extractors;

use App\Parity\RustSource;

/**
 * Collects the database tables of src/db/schema.rs and the templates and
 * static assets below src/static.
 */
final class ResourceExtractor
{
    public function __construct(
        private readonly RustSource $source,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function extract(): array
    {
        return [...$this->tables(), ...$this->templates(), ...$this->assets()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tables(): array
    {
        $schema = $this->source->read('src/db/schema.rs');
        preg_match_all('/table!\s*\{\s*(\w+)\s*\(([^)]*)\)\s*\{(.*?)\}\s*\}/s', $schema, $tables, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $items = [];
        foreach ($tables as $table) {
            preg_match_all('/^\s*(\w+)\s*->\s*([^,\n]+),/m', $table[3][0], $columns, PREG_SET_ORDER);

            $items[] = [
                'id' => "table:{$table[1][0]}",
                'kind' => 'table',
                'primary_key' => array_map(trim(...), explode(',', $table[2][0])),
                'columns' => array_column($columns, 2, 1),
                'source' => 'src/db/schema.rs:'.RustSource::lineAt($schema, $table[0][1]),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(): array
    {
        $items = [];

        foreach ($this->source->files('src/static/templates') as $file) {
            $name = substr($file, strlen('src/static/templates/'), -strlen('.hbs'));
            $items[] = ['id' => "template:{$name}", 'kind' => 'template', 'source' => $file];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assets(): array
    {
        $items = [];

        foreach ($this->source->files('src/static') as $file) {
            if (! str_starts_with($file, 'src/static/templates/')) {
                $items[] = ['id' => 'asset:'.substr($file, strlen('src/static/')), 'kind' => 'asset', 'source' => $file];
            }
        }

        return $items;
    }
}
