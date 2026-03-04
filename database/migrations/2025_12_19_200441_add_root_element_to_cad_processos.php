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
        Schema::table('cad_processos', function (Blueprint $table) {
            // Define o nome da tag principal (ex: <pedido>)
            $table->string('root_element')->nullable()->default('root')->after('name');
            
            // Define o nome da tag para itens de listas (ex: <item> ou <produto>)
            $table->string('item_element')->nullable()->default('item')->after('root_element');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cad_processos', function (Blueprint $table) {
            $table->dropColumn(['root_element', 'item_element']);
        });
    }
};

