<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PaymentMethod enum already exists — this just documents that 'wallet'
        // is now a valid value. The column is a string, so no schema change needed.
        // The PaymentMethod enum will be updated to include the Wallet case.
    }

    public function down(): void
    {
        //
    }
};
