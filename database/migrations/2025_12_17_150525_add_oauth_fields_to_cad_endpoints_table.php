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
            $table->enum('type_storage_token', ['client_token', 'file', 'fixed'])
                  ->default('fixed')
                  ->after('autenticacao')
                  ->comment('Define de onde o sistema deve extrair o token de acesso');

            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE cad_endpoints MODIFY COLUMN autenticacao ENUM('nenhum','basic','bearer','api_key','oauth2') NOT NULL DEFAULT 'nenhum'");
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->dropColumn('type_storage_token');
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement("ALTER TABLE cad_endpoints MODIFY COLUMN autenticacao ENUM('nenhum','basic','bearer','api_key') NOT NULL DEFAULT 'nenhum'");
            }
        });
    }
};
