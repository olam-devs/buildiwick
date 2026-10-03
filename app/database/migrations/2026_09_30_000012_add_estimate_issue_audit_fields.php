<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_estimates', function (Blueprint $table): void {
            $table->foreignId('issued_by')->nullable()->after('prepared_by')->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable()->after('prepared_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_estimates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn('issued_at');
        });
    }
};