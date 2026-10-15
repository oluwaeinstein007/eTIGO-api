<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tip can be paid with a different method than the ride (a
     * bank-transfer ride is tipped from the wallet or the saved card), so
     * record how each tip was paid; earlier tips were always paid like the
     * ride. A card tip also keeps its charge reference, so an attempt whose
     * outcome is unknown is checked with the provider before charging again.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('tip_method', 20)->nullable()->after('tip_amount');
            $table->string('tip_reference', 64)->nullable()->after('tip_method');
        });

        DB::table('payments')->where('tip_amount', '>', 0)->update(['tip_method' => DB::raw('method')]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['tip_method', 'tip_reference']);
        });
    }
};
