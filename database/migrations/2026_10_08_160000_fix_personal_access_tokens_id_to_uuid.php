<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')->delete();

        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN id DROP DEFAULT');
        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN id TYPE uuid USING gen_random_uuid()');
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')->delete();

        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN id TYPE bigint USING 0');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS personal_access_tokens_id_seq');
        DB::statement("ALTER TABLE personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('personal_access_tokens_id_seq')");
    }
};
