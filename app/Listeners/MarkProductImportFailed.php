<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class MarkProductImportFailed
{
    public function __construct(
        protected int $importJobId
    ) {}

    public function __invoke(ImportFailed $event): void
    {
        DB::table('product_import_jobs')
            ->where('id', $this->importJobId)
            ->update([
                'status'        => 'failed',
                'error_message' => 'Import gagal diproses oleh queue.',
                'updated_at'    => now(),
            ]);
    }
}
