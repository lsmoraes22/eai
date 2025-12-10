<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_interface_m40', function (Blueprint $table) {
            $table->bigIncrements('cim40_id')->comment('ID da tabela');
            $table->unsignedBigInteger('cim40_ot')->nullable()->comment('Número da Ordem de Transporte (zerofill no original)');
            $table->unsignedBigInteger('cim40_po')->nullable()->comment('Número do Pedido de Compra (zerofill no original)');
            $table->unsignedBigInteger('cim40_delivery')->nullable()->comment('Número da Entrega (zerofill no original)');
            $table->unsignedBigInteger('cim40_nfe')->nullable()->comment('Número da Nota Fiscal Eletrônica');
            $table->string('cim40_chave_nfe', 44)->nullable()->comment('Chave de acesso da Nota Fiscal Eletrônica');
            $table->string('cim40_idoc', 30)->nullable()->comment('Número do IDoc associado à interface M40');
            $table->string('cim40_nome_arquivo', 100)->nullable()->comment('Nome do arquivo processado na interface M40');
            $table->longText('cim40_arquivo')->nullable()->comment('Conteúdo completo do arquivo XML da interface M40');
            $table->dateTime('cim40_data_interface')->nullable()->comment('Data e hora em que a interface M40 foi criada');
            $table->enum('cim40_envio', ['0', '1', '2'])->nullable()->comment('Status de envio: 0 - Não enviado, 1 - Enviado, 2 - Reprocessado');
            $table->dateTime('cim40_data')->nullable()->comment('Data de criação do registro');
            $table->string('cim40_usuario', 10)->nullable()->comment('Usuário responsável pela criação do registro');
            $table->string('cim40_usuario_envio', 10)->nullable()->comment('Usuário responsável pelo envio do M40');
            $table->dateTime('cim40_data_envio')->nullable()->comment('Data e hora do envio do M40');
            $table->dateTime('cim40_data_envio_m41')->nullable()->comment('Data e hora do envio do M41 (interface de retorno)');

            $table->unique(['cim40_po', 'cim40_delivery'], 'NewIndex1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_interface_m40');
    }
};
