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
        	$table->enum('direcao', ['entrada', 'saida','auth'])
                	->default('entrada')
			->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
	    	$table->enum('direcao', ['entrada', 'saida'])
                	->default('entrada')
			->change();
        });
    }
};
