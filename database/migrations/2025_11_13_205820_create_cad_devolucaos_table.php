<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_devolucao', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id_dev')->comment('ID TABELA');
            $table->string('num_ocorrencia', 255)->comment('NUMERO DAS OCORRENCIAS');
            $table->string('chave_nota', 50)->comment('CHAVE NOTA FISCAL');
            $table->string('nota_serie', 15)->comment('NUMERO DA NOTA + SERIE');
            $table->string('transportadora', 255)->comment('NOME TRANSPORTADORA');
            $table->string('motorista', 255)->comment('NOME MOTORISTA');
            $table->string('placa', 10)->comment('PLACA VEICULO');
            $table->dateTime('data_cadastro')->comment('DATA CADASTRO DEVOLUÇÃO');
            $table->string('usuario_cadastro', 50)->nullable()->comment('USUARIO DO CADASTRO');
            $table->dateTime('data_cancelamento')->nullable()->comment('DATA DO CANCELAMENTO DA DEVOLUÇÃO');
            $table->string('usuario_cancelamento', 50)->nullable()->comment('USUARIO DO CANCELAMENTO');

            $table->enum('status', ['P', 'I', 'F', 'C'])
                ->default('P')
                ->comment('STATUS DA DEVOLUÇÃO (P-PENDENTE / I-INICIADO / F-FINALIZADO / C-CANCELADO)');

            $table->enum('interface', ['P', 'F'])
                ->default('P')
                ->comment('(P - PENDENTE / F - RECEBIDA)');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_devolucao');
    }
};
