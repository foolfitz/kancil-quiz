<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 教材的課文句子（docs/SPEC.md 3.6、D-4）：每一課另有一個句子題組。
 *
 * - sets.unit：詞彙組的每個詞條是詞（word）還是句子（sentence），交換格式的 unit。
 * - curriculum_refs.sentence_set_id、sentence_imported_revision：這一課的句子題組，與詞彙題組的 set_id、imported_revision 對應。
 * - curriculum_plays.unit：試玩的計次分開算詞彙與句子（A-04）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sets', function (Blueprint $table) {
            $table->string('unit', 8)->default('word');
        });

        Schema::table('curriculum_refs', function (Blueprint $table) {
            $table->foreignUlid('sentence_set_id')->nullable()->unique()->constrained('sets')->nullOnDelete();
            $table->unsignedInteger('sentence_imported_revision')->nullable();
        });

        Schema::table('curriculum_plays', function (Blueprint $table) {
            $table->string('unit', 8)->default('word');
            $table->dropUnique(['curriculum_ref_id', 'game_id', 'date']);
            $table->unique(['curriculum_ref_id', 'game_id', 'unit', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_plays', function (Blueprint $table) {
            $table->dropUnique(['curriculum_ref_id', 'game_id', 'unit', 'date']);
            $table->dropColumn('unit');
            $table->unique(['curriculum_ref_id', 'game_id', 'date']);
        });

        Schema::table('curriculum_refs', function (Blueprint $table) {
            $table->dropForeign(['sentence_set_id']);
            $table->dropUnique(['sentence_set_id']);
            $table->dropColumn(['sentence_set_id', 'sentence_imported_revision']);
        });

        Schema::table('sets', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
