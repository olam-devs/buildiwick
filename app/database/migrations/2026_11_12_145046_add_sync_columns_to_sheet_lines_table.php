<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-way sync: what the system side looked like at the last sync (system_hash for saved lines, draft_hash for
     * draft lines), and a change typed in the sheet to a saved line that waits for approval (pending).
     * Hashes are now taken over normalised values, so existing sheet hashes are cleared to be read again.
     */
    public function up(): void
    {
        Schema::table('sheet_lines', function (Blueprint $table) {
            $table->string('system_hash', 40)->nullable()->after('sheet_hash');
            $table->string('draft_hash', 40)->nullable()->after('system_hash');
            $table->json('pending')->nullable()->after('expense_request_id');
            $table->string('pending_hash', 40)->nullable()->after('pending');
            $table->timestamp('pending_at')->nullable()->after('pending_hash');
        });
        DB::table('sheet_lines')->update(['sheet_hash' => '']);
    }

    public function down(): void
    {
        Schema::table('sheet_lines', function (Blueprint $table) {
            $table->dropColumn(['system_hash', 'draft_hash', 'pending', 'pending_hash', 'pending_at']);
        });
    }
};
