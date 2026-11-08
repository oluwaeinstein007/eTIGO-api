<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Papers a city requires from its drivers on top of the standard ones.
 * Lagos asks for the LASDRI and LASSRA cards (client, 8 Nov 2026). Drivers
 * already working there are reminded to upload them, never blocked.
 */
return new class extends Migration
{
    /**
     * Add the columns and turn the Lagos cards on for Lagos.
     */
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->json('required_driver_documents')->nullable()->after('readiness_confirmations');
        });

        Schema::table('drivers', function (Blueprint $table) {
            // Last reminder to upload papers the city requires, while missing.
            $table->timestamp('city_documents_reminded_at')->nullable()->after('documents_block_reason');
        });

        // Lagos by its slug. Only when no city has that slug, a single city
        // named Lagos; if the name is ambiguous, operations set it by hand.
        $lagos = DB::table('cities')->where('slug', 'lagos')->pluck('id');

        if ($lagos->isEmpty()) {
            $named = DB::table('cities')->whereRaw('LOWER(name) = ?', ['lagos'])->pluck('id');
            $lagos = $named->count() === 1 ? $named : collect();
        }

        DB::table('cities')->whereIn('id', $lagos)
            ->update(['required_driver_documents' => json_encode(['lasdri_card', 'lassra_card'])]);
    }

    /**
     * Drop them; uploaded LASDRI/LASSRA documents stay.
     */
    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('city_documents_reminded_at');
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('required_driver_documents');
        });
    }
};
