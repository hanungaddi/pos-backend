<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImportJob extends Model
{
    //
    protected $fillable = [
        'user_id',
        'file_name',
        'total_rows',
        'processed_rows',
        'imported_rows',
        'skipped_rows',
        'status',
        'error_message',
    ];
}
