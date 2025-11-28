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
        Schema::create('cad_processos', function (Blueprint $table) {
            $table->id();
	    $table->string('name',100)->comment('NOME AMIGÁVEL DO PROCESSO');
	    $table->string('initial_format', 10)->default('xml')->comment('FORMATO INICIAL');
	    $table->string('final_format', 10)->default('json')->comment('FORMATO_FINAL');
	    $table->bigInteger('user_create_id')->comment('USUARIO QUE CRIOU O PROCESSO');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_processos');
    }
};
