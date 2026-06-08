<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashDrawerSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'user_id',
        'opening_balance',
        'expected_cash',
        'actual_closing_balance',
        'cash_sales_total',
        'cash_refunds_total',
        'cash_in_total',
        'cash_out_total',
        'difference',
        'status',
        'opening_note',
        'closing_note',
        'opened_at',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'expected_cash' => 'integer',
            'actual_closing_balance' => 'integer',
            'cash_sales_total' => 'integer',
            'cash_refunds_total' => 'integer',
            'cash_in_total' => 'integer',
            'cash_out_total' => 'integer',
            'difference' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashDrawerMovement::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function recordMovement(
        string $type,
        int $amount,
        int $balanceBefore,
        int $balanceAfter,
        ?User $user = null,
        ?string $note = null,
        ?int $referenceId = null,
        ?string $referenceType = null
    ): CashDrawerMovement {
        return $this->movements()->create([
            'user_id' => $user?->id,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'note' => $note,
        ]);
    }
}
