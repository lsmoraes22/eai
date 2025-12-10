<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela cad_movimento_motivo_bloqueio.
     */
    public function up(): void
    {
        Schema::create('cad_movimento_motivo_bloqueio', function (Blueprint $table) {
            $table->bigIncrements('id_movimento')->comment('ID DA TABELA');
            $table->char('movimento_sap', 3)->collation('latin1_general_ci')->comment('CÓDIGO DO MOVIMENTO SAP');
            $table->char('motivo_sap', 4)->nullable()->collation('latin1_general_ci')->comment('CÓDIGO DO MOTIVO DE MOVIMENTO SAP');
            $table->string('descricao_motivo', 255)->collation('latin1_general_ci')->comment('DESCRIÇÃO DO MOTIVO DO MOVIMENTO');
            $table->char('bloqueio_infolog', 3)->collation('latin1_general_ci')->comment('CÓDIGO DO BLOQUEIO NO INFOLOG (MOTIMM)');
        });
    }

    /**
     * Remove a tabela cad_movimento_motivo_bloqueio.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_movimento_motivo_bloqueio');
    }
};
