<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_interface_status', function (Blueprint $table) {
            $table->id('int_id')->comment('ID da tabela');

            $table->string('int_direcao', 10)->nullable()
                ->comment('Direção da interface (Entrada ou Saída)');

            $table->string('int_interface', 10)->nullable()
                ->comment('Nome ou código da interface');

            $table->string('int_arquivo', 255)->nullable()
                ->comment('Nome completo do arquivo processado');

            $table->string('int_idoc', 30)->nullable()
                ->comment('Número do IDoc associado à interface');

            $table->tinyInteger('int_status')->nullable()
                ->comment('Status numérico do processamento (ex: 0 - Pendente, 1 - Processado, etc.)');

            $table->dateTime('int_data_processamento')->nullable()
                ->comment('Data e hora do processamento da interface');

            $table->enum('int_envio', ['0', '1'])->nullable()
                ->comment('Indicador de envio (0 - Não enviado, 1 - Enviado)');

            $table->string('int_mensagem', 255)->nullable()
                ->comment('Mensagem de retorno ou erro do processamento');

            $table->dateTime('int_data_envio')->comment('Data e hora do envio da interface');

            // Índices
            $table->index('int_idoc');
            $table->index('int_interface');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_interface_status');
    }
};
