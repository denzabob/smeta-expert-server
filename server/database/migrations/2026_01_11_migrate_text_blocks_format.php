<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Конвертируем существующие текстовые блоки из старого формата (массив строк)
        // в новый формат (массив объектов с title и text)
        
        $projects = DB::table('projects')
            ->whereNotNull('text_blocks')
            ->get();
        
        foreach ($projects as $project) {
            $textBlocks = json_decode($project->text_blocks, true);
            
            if (is_array($textBlocks) && count($textBlocks) > 0) {
                // Проверяем, нужна ли конвертация
                // Если это массив строк (старый формат), конвертируем в новый
                if (is_string($textBlocks[0] ?? null)) {
                    $newFormat = [];
                    foreach ($textBlocks as $text) {
                        $newFormat[] = [
                            'title' => '',
                            'text' => $text
                        ];
                    }
                    
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'text_blocks' => json_encode($newFormat, JSON_UNESCAPED_UNICODE)
                        ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Конвертируем обратно в старый формат при откате
        $projects = DB::table('projects')
            ->whereNotNull('text_blocks')
            ->get();
        
        foreach ($projects as $project) {
            $textBlocks = json_decode($project->text_blocks, true);
            
            if (is_array($textBlocks) && count($textBlocks) > 0) {
                // Если это массив объектов (новый формат), конвертируем в старый
                if (is_array($textBlocks[0] ?? null)) {
                    $oldFormat = [];
                    foreach ($textBlocks as $block) {
                        if (is_array($block) && isset($block['text'])) {
                            $oldFormat[] = $block['text'];
                        }
                    }
                    
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'text_blocks' => json_encode($oldFormat, JSON_UNESCAPED_UNICODE)
                        ]);
                }
            }
        }
    }
};
