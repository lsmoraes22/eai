<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cad_itens_devolucao', function (Blueprint $table) {
            $table->id('id_item')->comment('ID TABELA');
            $table->string('nota_fiscal', 50)->comment('CHAVE NOTA FISCAL');
            $table->string('cod_prod', 50)->charset('latin1')->comment('CODIGO PRODUTO');
            $table->string('descr_prod', 150)->comment('DESCRICAO PRODUTO');
            $table->string('lote', 50)->charset('latin1')->comment('CODIGO DUN PRODUTO');
            $table->integer('quantidade')->comment('QUANTIDADE DEVOLVIDA');
            $table->date('data_validade')->comment('DATA DE VALIDADE PRODUTO DEVOLVIDO');
            $table->bigInteger('id_motivo')->default(0)->comment('ID DO MOTIVO DE DEVOLUCAO');
            $table->string('usuario', 50)->nullable()->comment('NOME DO USUARIO');
            $table->bigInteger('cod_palete')->default(0)->comment('NUMERO SEQUENCIAL DO PALETE');
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE cad_itens_devolucao ENGINE=InnoDB COMMENT='Itens de devolução'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_itens_devolucao');
    }
};
