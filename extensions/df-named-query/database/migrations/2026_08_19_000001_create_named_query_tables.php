<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('named_query', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('service_id');
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('published_revision_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('created_date')->nullable();
            $table->timestamp('last_modified_date')->useCurrent();
            $table->unsignedInteger('created_by_id')->nullable();
            $table->unsignedInteger('last_modified_by_id')->nullable();

            $table->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
            $table->unique(['service_id', 'name']);
        });

        Schema::create('named_query_revision', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('named_query_id');
            $table->unsignedInteger('revision');
            $table->string('definition_type', 16);
            $table->longText('sql')->nullable();
            $table->json('parameters')->nullable();
            $table->json('output_schema')->nullable();
            $table->json('budgets')->nullable();
            $table->char('checksum', 64);
            $table->unsignedInteger('created_by_id')->nullable();
            $table->unsignedInteger('last_modified_by_id')->nullable();
            $table->timestamp('created_date')->nullable();
            $table->timestamp('last_modified_date')->useCurrent();

            $table->foreign('named_query_id')->references('id')->on('named_query')->onDelete('cascade');
            $table->unique(['named_query_id', 'revision']);
        });

        Schema::table('named_query', function (Blueprint $table) {
            $table->foreign('published_revision_id')
                ->references('id')->on('named_query_revision')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('named_query', function (Blueprint $table) {
            $table->dropForeign(['published_revision_id']);
        });
        Schema::dropIfExists('named_query_revision');
        Schema::dropIfExists('named_query');
    }
};
