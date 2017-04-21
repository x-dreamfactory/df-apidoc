<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateApiDocTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('service_doc')) {
            // Service API Docs
            Schema::create(
                'service_doc',
                function (Blueprint $t) {
                    $t->integer('service_id')->unsigned()->primary();
                    $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                    $t->integer('format')->unsigned()->default(0);
                    $t->mediumText('content')->nullable();
                    $t->timestamp('created_date')->nullable();
                    $t->timestamp('last_modified_date')->useCurrent();
                }
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Service Docs
        Schema::dropIfExists('service_doc');
    }
}
