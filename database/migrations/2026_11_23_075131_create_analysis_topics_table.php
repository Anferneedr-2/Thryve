<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The managed topic list. The report agent may only choose from the active ones. */
    private const TOPICS = [
        ['Business writing', 'Emails, letters, proposals and reports', false],
        ['Document summarizing and extraction', 'Summarize a document or pull facts out of it', false],
        ['Spreadsheet and data analysis', 'Tables, calculations and trends', false],
        ['Image and screenshot reading', 'Interpret images, screenshots and text in them', false],
        ['File creation', 'Produce documents, sheets, slides or PDFs', false],
        ['Translation and rewriting', 'Translate or improve written communication', false],
        ['Research and fact-finding', 'Gather and summarize information', false],
        ['Planning and scheduling', 'Checklists, schedules and action plans', false],
        ['Comparing options and recommendations', 'Compare choices and recommend a practical one', false],
        ['Out-of-scope request', 'Coding, personal or non-work requests the assistant declines', false],
        ['Other work question', 'Work-related, but none of the other topics (also the fallback)', true],
    ];

    public function up(): void
    {
        Schema::create('analysis_topics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description', 200);
            $table->unsignedSmallInteger('priority')->default(100); // lower comes first
            $table->boolean('is_active')->default(true); // never deleted: old analyses keep pointing at it
            $table->boolean('is_fallback')->default(false); // the topic used when nothing else fits
            $table->timestamps();
            $table->index(['is_active', 'priority']);
        });

        foreach (self::TOPICS as $i => [$name, $description, $fallback]) {
            DB::table('analysis_topics')->insertOrIgnore([
                'name' => $name, 'description' => $description, 'priority' => ($i + 1) * 10, 'is_active' => true, 'is_fallback' => $fallback,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_topics');
    }
};
