<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('cad_endpoints', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('nome', 100)
                  ->comment('NOME AMIGÁVEL DA INTERFACE');

            $table->enum('tipo', ['REST', 'SOAP', 'IDOC'])
                  ->default('REST')
                  ->comment('TIPO DE INTEGRAÇÃO: REST, SOAP OU IDOC');

            $table->enum('metodo', ['GET', 'POST', 'PUT'])
                  ->default('POST')
                  ->comment('MÉTODO HTTP USADO NA REQUISIÇÃO');

            $table->text('url')
                  ->comment('URL DO ENDPOINT DE INTEGRAÇÃO');

            $table->json('headers')
                  ->nullable()
                  ->comment('HEADERS CUSTOMIZADOS EM FORMATO JSON');

            $table->enum('autenticacao', ['nenhum', 'basic', 'bearer', 'api_key'])
                  ->default('nenhum')
                  ->comment('TIPO DE AUTENTICAÇÃO NECESSÁRIA PARA O ENDPOINT');

            $table->string('auth_user', 100)
                  ->nullable()
                  ->comment('USUÁRIO PARA AUTENTICAÇÃO BASIC');

            $table->string('auth_pass', 255)
                  ->nullable()
                  ->comment('SENHA PARA AUTENTICAÇÃO BASIC');

            $table->text('auth_token')
                  ->nullable()
                  ->comment('TOKEN PARA AUTENTICAÇÃO BEARER OU API KEY');

            $table->boolean('ativo')
                  ->default(true)
                  ->comment('DEFINE SE A INTERFACE ESTÁ ATIVA');

            $table->integer('timeout')
                  ->default(30)
                  ->comment('TIMEOUT EM SEGUNDOS PARA A REQUISIÇÃO');

            $table->integer('tentativas')
                  ->default(3)
                  ->comment('QUANTIDADE MÁXIMA DE TENTATIVAS DE ENVIO');

            $table->string('descricao', 255)
                  ->nullable()
                  ->comment('DESCRIÇÃO DO ENDPOINT / INTERFACE');

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('cad_endpoints');
    }

};
