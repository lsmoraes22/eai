<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria a tabela interface_error.
     */
    public function up(): void
    {
        Schema::create('interface_error', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('ID DA TABELA');
            $table->string('interface', 5)->comment('TIPO DE INTERFACE');
            $table->string('oc', 150)->default('0')->comment('NÚMERO DA OC (Ordem de Compra ou referência)');
            $table->string('tipo_erro', 50)->comment('TIPO DE ERRO');
            $table->longText('xml')->comment('XML relacionado ao erro');
            $table->dateTime('data_erro')->comment('DATA DO ERRO DE ENVIO DA INTERFACE');
            $table->text('desc_envio')->nullable()->comment('DESCRIÇÃO DO RETORNO DE ENVIO DO WS');
            $table->dateTime('data_envio')->nullable()->comment('DATA DE ENVIO DA INTERFACE');
            $table->enum('status', ['E', 'F', 'S'])->nullable()->comment('E -> ERRO / F -> FALHA / S -> SUCESSO');
        });
    }

    /**
     * Remove a tabela interface_error.
     */
    public function down(): void
    {
        Schema::dropIfExists('interface_error');
    }
};
