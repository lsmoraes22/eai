<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_bloqueio_triagem_infolog', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id')->comment('ID da tabela');
            $table->integer('cod_bloqueio_triagem')->comment('ID do bloqueio na tabela de bloqueios da triagem');
            $table->char('cod_bloqueio_infolog', 3)->comment('Código do bloqueio no Infolog');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_bloqueio_triagem_infolog');
    }
};
