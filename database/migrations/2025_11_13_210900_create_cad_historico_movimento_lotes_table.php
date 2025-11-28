<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_historico_movimento_lote', function (Blueprint $table) {
            $table->bigIncrements('ID_HIST')->comment('ID DA TABELA');
            $table->string('IDOC', 20)->comment('IDoc number');
            $table->string('MATERIAL', 18)->nullable()->comment('Material Number');
            $table->string('LOTE', 10)->nullable()->comment('Batch Number');
            $table->char('STORAGE', 4)->nullable()->comment('Storage Location');
            $table->char('PLANTA', 4)->nullable()->comment('Plant');
            $table->string('REF_DOCNUM', 10)->nullable()->comment('Reference Document Number');
            $table->string('COD_TRANSACAO', 20)->nullable()->comment('Current Transaction Code');
            $table->char('TIPO_MOVIMENTO', 3)->nullable()->comment('Movement Type (Inventory Management)');
            $table->decimal('QUANTIDADE', 15, 3)->nullable()->comment('Quantity in unit of entry');
            $table->char('UNIDADE', 3)->nullable()->comment('Unit of Entry');
            $table->char('REC_STORAGE', 4)->nullable()->comment('Receiving/Issuing Storage Location');
            $table->char('MOTIVO', 4)->nullable()->comment('Reason for manual valuation of net assets');
            $table->date('DATA_VALIDADE')->nullable()->comment('Shelf Life Expiration or Best-Before Date');
            $table->enum('INDICADOR_MOVIMENTO', ['S', 'H'])->comment('INDICADOR MOVIMENTO (H - CREDITO / S - DEBITO)');
            $table->dateTime('DATA_INTEGRACAO')->comment('DATA/HORA DA INTEGRACAO');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_historico_movimento_lote');
    }
};
