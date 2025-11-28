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
        Schema::create('xsd_files', function (Blueprint $table) {
            $table->id();

            // Relacionamento com clientes
            $table->foreignId('client_id')
                ->constrained('clients')
                ->cascadeOnDelete();

            // Informações do arquivo
            $table->string('name');       // nome amigável
            $table->string('filename');   // nome do arquivo salvo
            $table->string('path');       // caminho no storage
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('xsd_files');
    }
};
