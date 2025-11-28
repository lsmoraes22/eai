<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_depara', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('cdp_id');
            $table->string('cdp_codpro', 20)->comment('Código do produto');
            $table->string('cdp_unipro', 5)->nullable()->comment('Unidade de produto');
            $table->integer('cdp_pcbpro')->nullable()->comment('PCB do produto');
            $table->tinyInteger('cdp_cvtuvc')->nullable()->comment('Conversão UVC');

            $table->timestamps();

            $table->unique('cdp_codpro', 'idx_unique_codpro');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_depara');
    }
};
