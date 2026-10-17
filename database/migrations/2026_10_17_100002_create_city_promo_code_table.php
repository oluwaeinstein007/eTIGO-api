<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A promo can now run in several cities. No rows means every city, as a
     * null city_id did before.
     */
    public function up(): void
    {
        Schema::create('city_promo_code', function (Blueprint $table) {
            $table->foreignUuid('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('city_id')->constrained()->cascadeOnDelete();

            $table->primary(['promo_code_id', 'city_id']);
            $table->index('city_id');
        });

        DB::table('promo_codes')
            ->whereNotNull('city_id')
            ->orderBy('id')
            ->select(['id', 'city_id'])
            ->chunk(500, function ($promos) {
                DB::table('city_promo_code')->insert($promos->map(fn ($promo) => [
                    'promo_code_id' => $promo->id,
                    'city_id' => $promo->city_id,
                ])->all());
            });

        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->foreignUuid('city_id')->nullable()->after('off_peak_only')->constrained()->nullOnDelete();
        });

        // A promo in several cities keeps only one of them when rolled back.
        DB::table('city_promo_code')
            ->select(['promo_code_id', DB::raw('MIN(city_id::text) AS city_id')])
            ->groupBy('promo_code_id')
            ->orderBy('promo_code_id')
            ->get()
            ->each(fn ($row) => DB::table('promo_codes')
                ->where('id', $row->promo_code_id)
                ->update(['city_id' => $row->city_id]));

        Schema::dropIfExists('city_promo_code');
    }
};
