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
        Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->bigInteger('timer')->nullable();
	    $table->timestamp('next_run')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->dropColumn('timer');
	    $table->dropColumn('next_run');
        });
    }
};
