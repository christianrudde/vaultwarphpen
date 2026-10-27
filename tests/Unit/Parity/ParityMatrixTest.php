<?php

namespace Tests\Unit\Parity;

use App\Parity\Matrix;
use App\Parity\Reference;
use App\Parity\TestReferences;
use PHPUnit\Framework\TestCase;

/**
 * Keeps parity/matrix.json honest: it belongs to the pinned commit, and
 * everything marked as done is backed by a test.
 */
class ParityMatrixTest extends TestCase
{
    private Matrix $matrix;

    private TestReferences $tests;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->matrix = Matrix::load($root.'/parity/matrix.json');
        $this->tests = TestReferences::scan($root.'/tests');
    }

    public function test_matrix_was_generated_from_the_pinned_commit(): void
    {
        $this->assertSame(Reference::load(dirname(__DIR__, 3))->commit, $this->matrix->commit, 'Run `php artisan parity:generate`.');
    }

    public function test_every_item_has_a_known_kind_and_status(): void
    {
        foreach ($this->matrix->items as $id => $item) {
            $this->assertContains($item['kind'] ?? null, Matrix::KINDS, $id);
            $this->assertContains($item['status'] ?? null, Matrix::STATUSES, $id);
        }
    }

    public function test_every_done_item_is_covered_by_a_test(): void
    {
        $untested = array_keys(array_filter(
            $this->matrix->items,
            fn (array $item, string $id): bool => ($item['status'] ?? null) === 'done' && $this->tests->for($id) === [],
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertSame([], $untested, 'Items marked as done need a test with #[Parity(...)].');
    }

    public function test_every_parity_attribute_names_existing_items(): void
    {
        foreach ($this->tests->references as $pattern => $locations) {
            $matches = array_filter(array_keys($this->matrix->items), static fn (string $id): bool => TestReferences::matches($pattern, $id));

            $this->assertNotEmpty($matches, "Parity attribute '{$pattern}' in ".implode(', ', $locations).' matches no matrix item.');
        }
    }
}
