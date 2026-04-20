<?php

use App\Models\Operation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('operation_kind', 20)->nullable()->after('category');
        });

        DB::table('operations')
            ->select(['id', 'exclusion_group', 'category'])
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                DB::table('operations')
                    ->where('id', $row->id)
                    ->update([
                        'operation_kind' => Operation::inferOperationKind(
                            $row->exclusion_group,
                            $row->category,
                        ),
                    ]);
            });

        DB::statement("ALTER TABLE operations MODIFY operation_kind VARCHAR(20) NOT NULL");
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('operation_kind');
        });
    }
};
