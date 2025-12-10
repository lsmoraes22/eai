<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela cad_upload_nota.
     */
    public function up(): void
    {
        Schema::create('cad_upload_nota', function (Blueprint $table) {
            $table->bigIncrements('cun_id')->comment('ID da tabela');
            $table->string('cun_refliv', 30)->nullable()->comment('Referência LIV associada à nota');
            $table->string('cun_nota', 10)->nullable()->comment('Número da nota fiscal');
            $table->string('cun_arquivo', 255)->nullable()->comment('Nome do arquivo enviado');
            $table->dateTime('cun_data')->nullable()->comment('Data e hora do upload');
            $table->string('cun_usuario', 10)->nullable()->comment('Usuário responsável pelo upload');

            $table->unique(['cun_refliv', 'cun_nota'], 'NewIndex1');
        });
    }

    /**
     * Remove a tabela cad_upload_nota.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_upload_nota');
    }
};
