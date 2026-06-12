<?php

namespace App\Jobs;

use App\Models\ProductImportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinishProductImport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $importJobId
    ) {}

    public function handle(): void
    {
        ProductImportJob::where('id', $this->importJobId)->update([
            'status'     => 'completed',
            'updated_at' => now(),
        ]);
    }
}