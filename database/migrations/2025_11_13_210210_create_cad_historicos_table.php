<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_historico', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('ch_id')->comment('ID do histórico');
            $table->dateTime('ch_data')->nullable()->comment('Data do evento');
            $table->string('ch_site', 10)->nullable()->comment('Código do site');
            $table->string('ch_direcao', 10)->nullable()->comment('Direção do processo');
            $table->string('ch_arquivo', 100)->nullable()->comment('Nome do arquivo');
            $table->string('ch_descricao', 255)->nullable()->comment('Descrição do evento');
            $table->string('ch_dirwork', 14)->nullable()->comment('Diretório de trabalho');

            $table->timestamps();

            $table->index(['ch_site', 'ch_direcao'], 'idx_site_direcao');
            $table->index('ch_data', 'idx_data');
            $table->index('ch_arquivo', 'idx_arquivo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_historico');
    }
};
