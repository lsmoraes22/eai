<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_historico_batch_master', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->bigIncrements('id_hist')->comment('ID da tabela');
            $table->string('codpro', 18)->comment('Material Number');
            $table->string('codlot', 20)->comment('Batch Number');
            $table->date('datava')->nullable()->comment('Availability date');
            $table->date('datfvi')->nullable()->comment('Shelf Life Expiration or Best-Before Date');
            $table->string('vendnum', 10)->nullable()->comment('Account Number of Vendor or Creditor');
            $table->string('batchnum', 15)->nullable()->comment('Vendor Batch Number');
            $table->date('fimdatrec')->nullable()->comment('Date of last goods receipt');
            $table->date('datfab')->nullable()->comment('Date of Manufacture');
            $table->char('lotimm', 1)->nullable()->comment('Batch in Restricted-Use Stock');
            $table->char('classtype', 3)->nullable()->comment('Class Type');
            $table->string('objectkey', 50)->nullable()->comment('Key of object to be classified');
            $table->string('objecttable', 30)->nullable()->comment('Name of database table for object');
            $table->integer('clobjectkey')->nullable()->comment('Configuration (internal object number)');
            $table->date('keydate')->nullable()->comment('Key date');
            $table->string('charact', 30)->nullable()->comment('Characteristic Name');
            $table->string('valuechar', 30)->nullable()->comment('Characteristic Value');
            $table->integer('instance')->nullable()->comment('Instance counter');
            $table->string('valueneutral', 30)->nullable()->comment('Characteristic Value');
            $table->string('charactdescr', 30)->nullable()->comment('Characteristic description');
            $table->string('majuti', 10)->nullable()->comment('Código do Usuário');
            $table->string('nomuti', 30)->nullable()->comment('Nome do Usuário');
            $table->date('dathis')->nullable()->comment('Data');
            $table->time('heuhis')->nullable()->comment('Hora');
            $table->enum('tipo', ['C', 'A'])->default('C')->comment('Tipo de Batch Master (C-Cadastro / A-Alteração)');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_historico_batch_master');
    }
};
