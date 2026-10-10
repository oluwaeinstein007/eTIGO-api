<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReconciliationReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'report_date',
        'gateway_charges_total',
        'ledger_credits_total',
        'gateway_transfers_total',
        'ledger_payouts_total',
        'mismatches_count',
        'mismatches',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'gateway_charges_total' => 'integer',
            'ledger_credits_total' => 'integer',
            'gateway_transfers_total' => 'integer',
            'ledger_payouts_total' => 'integer',
            'mismatches_count' => 'integer',
            'mismatches' => 'array',
        ];
    }
}
