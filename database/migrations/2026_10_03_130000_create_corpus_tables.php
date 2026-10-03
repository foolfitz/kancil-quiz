<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 語言、教材對照、媒體與詞條（docs/SPEC.md 第 5 節）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 8)->primary();
            $table->string('name_zh');
            $table->string('name_native');
            $table->string('script');
            $table->boolean('word_spacing');
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
        });

        // 七種新住民語文（附錄 A），第一階段只開放印尼語與越南語（1.4）。
        DB::table('languages')->insert([
            ['code' => 'id', 'name_zh' => '印尼語', 'name_native' => 'Bahasa Indonesia', 'script' => 'Latn', 'word_spacing' => true, 'enabled' => true, 'sort' => 1],
            ['code' => 'vi', 'name_zh' => '越南語', 'name_native' => 'Tiếng Việt', 'script' => 'Latn', 'word_spacing' => true, 'enabled' => true, 'sort' => 2],
            ['code' => 'ms', 'name_zh' => '馬來語', 'name_native' => 'Bahasa Melayu', 'script' => 'Latn', 'word_spacing' => true, 'enabled' => false, 'sort' => 3],
            ['code' => 'fil', 'name_zh' => '菲律賓語', 'name_native' => 'Filipino', 'script' => 'Latn', 'word_spacing' => true, 'enabled' => false, 'sort' => 4],
            ['code' => 'th', 'name_zh' => '泰語', 'name_native' => 'ภาษาไทย', 'script' => 'Thai', 'word_spacing' => false, 'enabled' => false, 'sort' => 5],
            ['code' => 'km', 'name_zh' => '柬埔寨語', 'name_native' => 'ភាសាខ្មែរ', 'script' => 'Khmr', 'word_spacing' => false, 'enabled' => false, 'sort' => 6],
            ['code' => 'my', 'name_zh' => '緬甸語', 'name_native' => 'မြန်မာဘာသာ', 'script' => 'Mymr', 'word_spacing' => false, 'enabled' => false, 'sort' => 7],
        ]);

        Schema::create('curriculum_refs', function (Blueprint $table) {
            $table->id();
            $table->string('language_code', 8);
            $table->foreign('language_code')->references('code')->on('languages');
            $table->unsignedSmallInteger('volume');
            $table->unsignedSmallInteger('lesson');
            $table->string('title_zh')->nullable();
            $table->unique(['language_code', 'volume', 'lesson']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 8); // audio、image
            $table->string('path');
            $table->string('mime');
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->json('authors')->nullable();
            $table->string('license')->nullable();
            $table->string('source')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('language_code', 8);
            $table->foreign('language_code')->references('code')->on('languages');
            $table->text('text');
            $table->text('romanization')->nullable();
            $table->text('translation_zh');
            $table->json('tags')->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->json('authors')->nullable();
            $table->string('license')->nullable();
            $table->string('source')->nullable();
            $table->ulid('forked_from_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('item_media', function (Blueprint $table) {
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('media_id')->constrained('media');
            $table->string('role', 8); // audio、image
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['item_id', 'media_id']);
        });

        Schema::create('item_curriculum_ref', function (Blueprint $table) {
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_ref_id')->constrained()->cascadeOnDelete();
            $table->primary(['item_id', 'curriculum_ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_curriculum_ref');
        Schema::dropIfExists('item_media');
        Schema::dropIfExists('items');
        Schema::dropIfExists('media');
        Schema::dropIfExists('curriculum_refs');
        Schema::dropIfExists('languages');
    }
};
