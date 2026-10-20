<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Project matching by meaning (pgvector): created only where the extension is installed. Without it the AI matching
     * stays off and nothing else changes; run the migrations again after installing pgvector.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || Schema::hasTable('embeddings')
            || ! DB::selectOne("select 1 as ok from pg_available_extensions where name = 'vector'")) {
            return;
        }

        DB::statement('create extension if not exists vector');
        DB::statement('create table embeddings (
            id bigserial primary key,
            subject_type varchar(20) not null,
            subject_id bigint not null,
            model varchar(80) not null,
            embedding vector not null,
            updated_at timestamp(0) without time zone,
            unique (subject_type, subject_id)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
