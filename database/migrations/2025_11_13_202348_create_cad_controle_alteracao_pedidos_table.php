<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_controle_alteracao_pedido', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('cap_id');
            $table->unsignedBigInteger('cap_shipment')->nullable()->comment('Shipment number');
            $table->unsignedBigInteger('cap_delivery')->nullable()->comment('Delivery number');
            $table->string('cap_idoc', 30)->nullable()->comment('IDOC number');
            $table->string('cap_arquivo', 255)->nullable()->comment('Arquivo relacionado');
            $table->dateTime('cap_data_alteracao')->nullable()->comment('Data da alteração');
            $table->boolean('cap_atualizacao')->nullable()->comment('Flag de atualização');
            $table->tinyInteger('cap_etaliv_infolog')->nullable()->comment('Flag ETA/LIV do Infolog');
            $table->text('cap_obs')->nullable()->comment('Observações');

            $table->timestamps();

            $table->index(['cap_shipment', 'cap_delivery'], 'idx_shipment_delivery');
            $table->index(['cap_idoc', 'cap_arquivo', 'cap_data_alteracao', 'cap_atualizacao'], 'idx_idoc_arquivo_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_controle_alteracao_pedido');
    }
};
