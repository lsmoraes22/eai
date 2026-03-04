<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('clients', function (Blueprint $table) {
            // Credenciais do App (Cada cliente pode ter o seu ou usar o seu global)
            $table->string('auth_url')->nullable()->after('active'); // URL do endpoint de autorizacao
            $table->string('token_url')->nullable()->after('active'); // URL do endpoint de token
            $table->string('app_client_id')->nullable()->after('auth_url');
            $table->string('app_client_secret')->nullable()->after('app_client_id');

            // Tokens Dinâmicos
            $table->text('access_token')->nullable()->after('app_client_secret');
            $table->text('refresh_token')->nullable()->after('access_token');
            $table->timestamp('expires_at')->nullable()->after('refresh_token');
            $table->string('account_id')->nullable()->after('expires_at');
        });
    }

    public function down() {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'auth_url', 'token_url', 'app_client_id', 'app_client_secret',
                'access_token', 'refresh_token', 'expires_at', 'account_id'
            ]);
        });
    }
};
