<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallpapers', function (Blueprint $table) {
            $table->boolean('is_published')->default(true);
            $table->string('preview_video')->nullable();
            $table->index(['is_published', 'id'], 'wallpapers_published_feed');
            $table->index(['category_id', 'is_published', 'id'], 'wallpapers_category_feed');
        });
    }

    public function down(): void
    {
        Schema::table('wallpapers', function (Blueprint $table) {
            $table->dropIndex('wallpapers_published_feed');
            $table->dropIndex('wallpapers_category_feed');
            $table->dropColumn(['is_published', 'preview_video']);
        });
    }
};
