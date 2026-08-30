<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_interfaces', function (Blueprint $table) {
            $table->bigIncrements('ci_id')->comment('ID da tabela');
            $table->string('ci_tipo', 5)->nullable()->comment('Tipo da interface ou categoria de integração');
            $table->unsignedBigInteger('ci_ot')->nullable()->comment('Número da Ordem de Transporte (zerofill no original)');
            $table->dateTime('ci_data')->nullable()->comment('Data e hora da criação ou processamento da interface');
            $table->string('ci_usuario', 10)->nullable()->comment('Usuário responsável pela interface');
            $table->boolean('ci_interface_gerada')->default(false)->comment('Indica se a interface foi gerada (0 = Não, 1 = Sim)');

            $table->unique(['ci_tipo', 'ci_ot'], 'cad_interface_tipo_ot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_interfaces');
    }
};
