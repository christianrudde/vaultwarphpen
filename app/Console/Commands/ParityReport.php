<?php

namespace App\Console\Commands;

use App\Parity\Matrix;
use App\Parity\MatrixReport;
use App\Parity\TestReferences;
use Illuminate\Console\Command;

class ParityReport extends Command
{
    /**
     * @var string
     */
    protected $signature = 'parity:report
                            {--mark= : Set the status of the items matching this id or prefix*}
                            {--status=done : The status for --mark}
                            {--note= : Notes for the items marked with --mark}';

    /**
     * @var string
     */
    protected $description = 'Write PARITY.md from parity/matrix.json and the #[Parity] test attributes';

    public function handle(): int
    {
        $file = base_path('parity/matrix.json');
        $matrix = Matrix::load($file);

        $pattern = $this->option('mark');
        if (is_string($pattern) && $pattern !== '') {
            $note = $this->option('note');
            $count = $matrix->mark($pattern, Matrix::string($this->option('status')), is_string($note) ? $note : null);
            $matrix->save($file);
            $this->components->info("{$count} items marked as {$this->option('status')}.");
        }

        $report = new MatrixReport($matrix, TestReferences::scan(base_path('tests')));
        file_put_contents(base_path('PARITY.md'), $report->render());

        $this->components->info('PARITY.md written.');

        return self::SUCCESS;
    }
}
