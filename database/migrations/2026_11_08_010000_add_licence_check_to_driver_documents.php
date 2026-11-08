<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What FRSC records (through QoreID) say about a renewed driving licence:
 * whether it was found in the driver's name and the expiry date on record.
 * Kept on the document, not as a KYC verification, so a renewal never
 * changes the driver's KYC status.
 */
return new class extends Migration
{
    /**
     * Add the column.
     */
    public function up(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            $table->json('licence_check')->nullable()->after('document_number');
        });
    }

    /**
     * Drop it; operations check renewals by hand again.
     */
    public function down(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            $table->dropColumn('licence_check');
        });
    }
};
