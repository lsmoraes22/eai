<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('nome')->comment('O slug usado na URL: webhook/{id}/{nome}');
            $table->boolean('ativo')->default(true);

            // Segurança HMAC
            $table->boolean('webhook_verify')->default(false);
            $table->string('webhook_header')->default('X-Bling-Signature-256');
            $table->string('webhook_algo')->default('sha256');

            $table->string('descricao')->nullable();
            $table->timestamps();

            // Garante que o mesmo cliente não tenha dois webhooks com o mesmo nome
            $table->unique(['client_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_webhooks');
    }
};
