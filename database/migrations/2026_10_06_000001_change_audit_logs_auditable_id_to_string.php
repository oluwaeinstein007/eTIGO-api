<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('auditable_id')->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This migration cannot be reversed because UUID values in auditable_id cannot be converted to unsignedBigInteger.'
        );
    }
};
