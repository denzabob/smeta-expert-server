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
        // Добавляем поле enabled в существующие текстовые блоки
        $projects = DB::table('projects')
            ->whereNotNull('text_blocks')
            ->get();
        
        foreach ($projects as $project) {
            $textBlocks = json_decode($project->text_blocks, true);
            
            if (is_array($textBlocks) && count($textBlocks) > 0) {
                // Обходим каждый блок и добавляем поле enabled если его нет
                $modified = false;
                foreach ($textBlocks as &$block) {
                    if (is_array($block) && !isset($block['enabled'])) {
                        $block['enabled'] = true;
                        $modified = true;
                    }
                }
                
                // Если были изменения, сохраняем обновлённые данные
                if ($modified) {
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'text_blocks' => json_encode($textBlocks, JSON_UNESCAPED_UNICODE)
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
        // Удаляем поле enabled из текстовых блоков при откате
        $projects = DB::table('projects')
            ->whereNotNull('text_blocks')
            ->get();
        
        foreach ($projects as $project) {
            $textBlocks = json_decode($project->text_blocks, true);
            
            if (is_array($textBlocks) && count($textBlocks) > 0) {
                $modified = false;
                foreach ($textBlocks as &$block) {
                    if (is_array($block) && isset($block['enabled'])) {
                        unset($block['enabled']);
                        $modified = true;
                    }
                }
                
                if ($modified) {
                    DB::table('projects')
                        ->where('id', $project->id)
                        ->update([
                            'text_blocks' => json_encode($textBlocks, JSON_UNESCAPED_UNICODE)
                        ]);
                }
            }
        }
    }
};
