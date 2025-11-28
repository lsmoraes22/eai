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
        Schema::create('cad_processos_deparas', function (Blueprint $table) {
            $table->id();
	    $table->foreignId('processo_id')->constrained('cad_processos')->onDelete('cascade');
            $table->string('input_path');    // Ex: xml path, json path, coluna do txt
            $table->string('output_path');   // Ex: campo final no JSON
            $table->string('data_type')->default('string');
            $table->string('default_value')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_processos_deparas');
    }
};
