<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_batch_master', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->string('CODPRO', 18)->comment('Material Number');
            $table->string('CODLOT', 20)->comment('Batch Number');
            $table->date('DATAVA')->nullable()->comment('Availability date');
            $table->date('DATFVI')->nullable()->comment('Shelf Life Expiration or Best-Before Date');
            $table->string('VENDNUM', 10)->nullable()->comment('Account Number of Vendor or Creditor');
            $table->string('BATCHNUM', 15)->nullable()->comment('Vendor Batch Number');
            $table->date('FIMDATREC')->nullable()->comment('Date of last goods receipt');
            $table->date('DATFAB')->nullable()->comment('Date of Manufacture');
            $table->char('LOTIMM', 1)->nullable()->comment('Batch in Restricted-Use Stock');
            $table->char('CLASSTYPE', 3)->nullable()->comment('Class Type');
            $table->string('OBJECTKEY', 50)->nullable()->comment('Key of object to be classified');
            $table->string('OBJECTTABLE', 30)->nullable()->comment('Name of database table for object');
            $table->bigInteger('CLOBJECTKEY')->nullable()->comment('Configuration (internal object number)');
            $table->date('KEYDATE')->nullable()->comment('Key date');
            $table->string('CHARACT', 30)->nullable()->comment('Characteristic Name');
            $table->string('VALUECHAR', 30)->nullable()->comment('Characteristic Value');
            $table->integer('INSTANCE')->nullable()->comment('Instance counter');
            $table->string('VALUENEUTRAL', 30)->nullable()->comment('Characteristic Value');
            $table->string('CHARACTDESCR', 255)->nullable()->comment('Characteristic description');
	    $table->timestamps();
            $table->primary(['CODPRO', 'CODLOT']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_batch_master');
    }
};
