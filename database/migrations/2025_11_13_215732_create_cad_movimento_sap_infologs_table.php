<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela cad_movimento_sap_infolog.
     */
    public function up(): void
    {
        Schema::create('cad_movimento_sap_infolog', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('ID TABELA');
            $table->integer('cod_mov_sap')->comment('CÓDIGO DO MOVIMENTO SAP');
            $table->string('descricao_movimento', 255)->comment('DESCRIÇÃO DO MOVIMENTO');
            $table->char('codact_infolog', 5)->comment('CODACT DO INFOLOG');
        });
    }

    /**
     * Remove a tabela cad_movimento_sap_infolog.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_movimento_sap_infolog');
    }
};
