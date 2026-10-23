<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->json('pagination')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->dropColumn('pagination');
        });
    }
};
