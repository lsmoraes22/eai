<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_interface_planejamento', function (Blueprint $table) {
            $table->bigIncrements('cip_id')->comment('ID da tabela');

            $table->string('cip_ot', 10)
                ->nullable()
                ->comment('Número da Ordem de Transporte (OT) relacionada ao planejamento');

            $table->unsignedBigInteger('cip_po')
                ->default(0)
                ->comment('Número do Pedido de Compra (zerofill no original)');

            $table->string('cip_idoc', 30)
                ->nullable()
                ->comment('Número do IDoc associado à interface de planejamento');

            $table->string('cip_arq', 100)
                ->nullable()
                ->comment('Nome do arquivo de interface processado');

            $table->dateTime('cip_data')
                ->nullable()
                ->comment('Data e hora da geração da interface de planejamento');

            // Índices e chaves
            $table->unique(['cip_ot', 'cip_po'], 'NewIndex1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_interface_planejamento');
    }
};
