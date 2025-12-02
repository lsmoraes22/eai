<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_interface_status', function (Blueprint $table) {
            $table->string('int_interface', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cad_interface_status', function (Blueprint $table) {
            // Aqui volta ao tamanho anterior (supondo que era 50, ajuste se necessário)
            $table->string('int_interface', 10)->nullable()->change();
        });
    }
};
