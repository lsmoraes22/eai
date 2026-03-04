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
        Schema::table('cad_endpoints', function (Blueprint $table) {
            // Adicionado 's3' e 'cloud' para suportar armazenamentos externos
            $table->enum('type_storage_payload', ['local', 'database', 'fixed', 's3', 'cloud'])
                  ->default('fixed')
                  ->after('type_storage_token');

            // Alterando payload para text para suportar payloads estáticos grandes
            $table->text('payload')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cad_endpoints', function (Blueprint $table) {
            $table->dropColumn('type_storage_payload');

            // Revertendo para string (padrão anterior)
            $table->string('payload', 255)->nullable()->change();
        });
    }
};
