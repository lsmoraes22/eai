<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
            // 1. Adiciona a nova coluna de estratégia de busca do token
            $table->string('auth_api_way', 20)
                  ->default('header')
                  ->after('next_run')
                  ->comment('Define onde vai o token de acesso no header ou no body: client_token = busca token no cadastro do cliente, file = busca token em arquivo no storage, fixed = token fixo cadastrado no campo auth_token');

            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->dropColumn('auth_api_way');            
        });
    }
};
