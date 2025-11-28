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
        Schema::create('cad_processo_txt_layouts', function (Blueprint $table) {
            $table->id();
	    $table->foreignId('processo_id')->constrained('cad_processos')->onDelete('cascade');
            $table->enum('section_type', ['header', 'line', 'footer']);
            $table->enum('format', ['fixed', 'delimited'])->default('fixed');
            $table->string('delimiter')->nullable();
            $table->integer('line_order')->default(1);
            $table->integer('total_length')->nullable(); // para fixed width
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cad_processo_txt_layouts');
    }
};
