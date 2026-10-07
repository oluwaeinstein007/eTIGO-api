<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_token');
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedTinyInteger('card_expiry_month')->nullable();
            $table->unsignedSmallInteger('card_expiry_year')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_payment_methods');
    }
};
