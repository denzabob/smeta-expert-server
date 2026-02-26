<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * operation_group_links - связь supplier_operations с operation_groups.
     * Пока пользователь не связал supplier_operation с operation_group,
     * система не считает это "тем же типом операции" для медианы.
     */
    public function up(): void
    {
        Schema::create('operation_group_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_group_id')->constrained('operation_groups')->onDelete('cascade');
            $table->foreignId('supplier_operation_id')->constrained('supplier_operations')->onDelete('cascade');
            $table->timestamps();
            
            // Уникальность связи
            $table->unique(['operation_group_id', 'supplier_operation_id'], 'group_supplier_op_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_group_links');
    }
};
