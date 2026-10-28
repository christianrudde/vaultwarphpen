<?php

namespace App\Console\Commands;

use App\Parity\Extractors\ConfigExtractor;
use App\Parity\Extractors\ResourceExtractor;
use App\Parity\Extractors\RouteExtractor;
use App\Parity\Matrix;
use App\Parity\Reference;
use App\Parity\RustSource;
use Illuminate\Console\Command;

class ParityGenerate extends Command
{
    /**
     * @var string
     */
    protected $signature = 'parity:generate';

    /**
     * @var string
     */
    protected $description = 'Regenerate parity/matrix.json from the reference checkout';

    public function handle(): int
    {
        $reference = Reference::load(base_path());
        $reference->assertCheckedOut();
        $reference->assertWebVaultMatches();

        $source = new RustSource($reference->checkout);
        $routes = new RouteExtractor($source);
        $config = new ConfigExtractor($source);
        $extracted = [...$routes->extract(), ...$config->extract(), ...new ResourceExtractor($source)->extract()];

        $file = base_path('parity/matrix.json');
        $matrix = Matrix::load($file);
        $changes = $matrix->merge($reference->commit, $extracted);
        $matrix->save($file);

        foreach ([...$routes->warnings, ...$config->warnings] as $warning) {
            $this->components->warn($warning);
        }

        $counts = array_count_values(array_map(static fn (array $item): string => Matrix::string($item['kind']), $extracted));
        foreach (Matrix::KINDS as $kind) {
            $this->components->twoColumnDetail($kind, (string) ($counts[$kind] ?? 0));
        }

        foreach ($changes['added'] as $id) {
            $this->line("  <fg=green>+</> {$id}");
        }
        foreach ($changes['removed'] as $id) {
            $this->line("  <fg=red>-</> {$id} (removed upstream)");
        }

        $this->components->info(sprintf('%d added, %d removed upstream.', count($changes['added']), count($changes['removed'])));

        return $this->call('parity:report');
    }
}
