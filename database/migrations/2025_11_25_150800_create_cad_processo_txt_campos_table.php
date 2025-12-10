<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cad_processo_txt_campos', function (Blueprint $table) {
            $table->id();
	    $table->unsignedBigInteger('layout_id');

    	    $table->string('campo_origem', 200)
          	->comment('Nome do campo no XML/JSON original');

    	    $table->string('campo_destino', 200)
          	->nullable()
          	->comment('Nome do campo no TXT final');

    	    $table->integer('posicao_inicial')->comment('Posição inicial fixa (1-based)');
    	    $table->integer('tamanho')->comment('Tamanho fixo do campo no TXT');

    	    $table->enum('align', ['LEFT', 'RIGHT'])
          	->default('LEFT')
          	->comment('Direção do padding');

    	    $table->char('mask', 1)
          	->default(' ')
          	->comment('Caractere de preenchimento: " " ou "0"');

    	    $table->boolean('uppercase')->default(false)
          	->comment('Converte o campo para maiúsculas?');

    	    $table->integer('ordem')->default(1);
            $table->timestamps();
	    $table->foreign('layout_id')->references('id')->on('cad_processo_txt_layouts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_processo_txt_campos');
    }
};
