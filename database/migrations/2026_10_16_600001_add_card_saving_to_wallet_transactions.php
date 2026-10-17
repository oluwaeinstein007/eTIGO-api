<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->boolean('save_card')->default(false)->after('amount');
            // Set once the top-up's card has been saved, so it is saved at
            // most once even if the passenger later removes it.
            $table->foreignUuid('payment_method_id')->nullable()->after('save_card')
                ->constrained('user_payment_methods')->nullOnDelete();
            $table->timestamp('card_saved_at')->nullable()->after('payment_method_id');
        });

        Schema::table('user_payment_methods', function (Blueprint $table) {
            // Paystack's authorization signature identifies the physical card.
            $table->string('gateway_signature')->nullable()->after('gateway_token');
            $table->unique(['user_id', 'gateway_signature']);
        });
    }

    public function down(): void
    {
        Schema::table('user_payment_methods', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'gateway_signature']);
            $table->dropColumn('gateway_signature');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn(['save_card', 'card_saved_at']);
        });
    }
};
