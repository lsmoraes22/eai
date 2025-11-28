<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cad_motivos_bloqueio_desbloqueio', function (Blueprint $table) {
            $table->increments('blo_id')->unsigned()->comment('ID do motivo de bloqueio/desbloqueio');
            $table->string('blo_sap', 10)->collation('latin1_general_ci')->comment('Código SAP do motivo');
            $table->string('blo_wms', 50)->collation('latin1_general_ci')->nullable()->comment('Código WMS do motivo');
            $table->string('blo_origem_sap', 10)->collation('latin1_general_ci')->nullable()->comment('Origem SAP');
            $table->string('blo_origem_wms', 50)->collation('latin1_general_ci')->nullable()->comment('Origem WMS');
            $table->string('blo_destino_sap', 50)->collation('latin1_general_ci')->nullable()->comment('Destino SAP');
            $table->string('blo_destino_wms', 50)->collation('latin1_general_ci')->nullable()->comment('Destino WMS');
            $table->string('blo_descricao', 255)->collation('latin1_general_ci')->nullable()->comment('Descrição do motivo');
            $table->string('blo_motivo_sap', 50)->collation('latin1_general_ci')->comment('Motivo SAP associado');
        });

        // Define engine e comentário da tabela
        DB::statement("ALTER TABLE cad_motivos_bloqueio_desbloqueio ENGINE=InnoDB COMMENT='Tabela de motivos de bloqueio e desbloqueio entre SAP e WMS'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_motivos_bloqueio_desbloqueio');
    }
};
