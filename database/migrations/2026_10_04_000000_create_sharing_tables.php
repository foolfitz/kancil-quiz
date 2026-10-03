<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 共備（docs/SPEC.md M2）：題組層級的冊課與標籤、複製來源的作者、分享連結（T-17）、
// 公開申請與審核紀錄（T-12、C-01），以及審核者負責的語言（第 2 節）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->json('tags')->nullable();
            $table->json('authors')->nullable(); // 複製來源的作者；擁有者在組成交換格式時加在最後
            $table->string('share_token', 40)->nullable()->unique(); // 給同事的分享連結，收回時清空
        });

        Schema::create('set_curriculum_ref', function (Blueprint $table) {
            $table->foreignUlid('set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_ref_id')->constrained()->cascadeOnDelete();
            $table->primary(['set_id', 'curriculum_ref_id']);
        });

        Schema::create('set_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('set_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('set_revision_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('action', 12); // requested、withdrawn、approved、rejected、unpublished
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['set_id', 'created_at']);
        });

        Schema::create('language_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('language_code', 8);
            $table->foreign('language_code')->references('code')->on('languages')->cascadeOnDelete();
            $table->primary(['user_id', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('language_user');
        Schema::dropIfExists('set_reviews');
        Schema::dropIfExists('set_curriculum_ref');
        Schema::table('sets', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['tags', 'authors', 'share_token']);
        });
    }
};
