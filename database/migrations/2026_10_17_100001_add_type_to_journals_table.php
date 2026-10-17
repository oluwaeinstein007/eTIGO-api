<?php

use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->string('type')->nullable()->after('reference')->index();
        });

        // Every posting site uses a fixed idempotency-key prefix, so journals
        // posted before this column existed are classified from that prefix.
        foreach (TransactionType::keyPrefixes() as $prefix => $type) {
            DB::table('journals')
                ->whereNull('type')
                ->where('idempotency_key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
                ->update(['type' => $type->value]);
        }
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
