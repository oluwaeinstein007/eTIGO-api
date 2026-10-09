<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_type');
            $table->uuid('owner_id');
            $table->string('type');
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('active');
            $table->bigInteger('balance')->default(0);
            $table->unsignedInteger('balance_version')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'type']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
