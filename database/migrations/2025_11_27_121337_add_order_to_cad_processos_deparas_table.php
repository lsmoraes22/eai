<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cad_processos_deparas', function (Blueprint $table) {
            $table->unsignedInteger('order')
                ->default(0)
                ->after('output_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cad_processos_deparas', function (Blueprint $table) {
            $table->dropColumn('order');
        });
    }
};
