<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 題組、題組內容與題組版本（docs/SPEC.md 3.2、3.3、第 5 節）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 8); // vocab、quiz
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('language_code', 8);
            $table->foreign('language_code')->references('code')->on('languages');
            $table->foreignId('owner_id')->constrained('users');
            $table->string('visibility', 8)->default('private'); // private、unlisted、public
            $table->string('review_status', 8)->default('none'); // none、pending、approved、rejected
            $table->json('faces')->nullable(); // 只有詞彙組使用
            $table->ulid('forked_from_id')->nullable()->index();
            $table->string('license');
            $table->ulid('current_revision_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('set_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('set_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignUlid('item_id')->nullable()->constrained(); // 詞彙組使用
            $table->json('payload')->nullable(); // 問答組使用：交換格式中的 question
            $table->timestamps();
            $table->index(['set_id', 'position']);
        });

        Schema::create('set_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('set_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('content'); // 交換格式的 set.json，媒體為相對路徑
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at');
            $table->unique(['set_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_revisions');
        Schema::dropIfExists('set_entries');
        Schema::dropIfExists('sets');
    }
};
