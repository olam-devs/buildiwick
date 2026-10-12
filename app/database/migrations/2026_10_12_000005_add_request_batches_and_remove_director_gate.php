<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->string('request_batch_code')->nullable()->after('request_code')->index();
            $table->unsignedSmallInteger('request_line_number')->default(1)->after('request_batch_code');
        });

        DB::table('expense_requests')->whereNull('request_batch_code')->orderBy('id')->eachById(function ($request): void {
            DB::table('expense_requests')->where('id', $request->id)->update([
                'request_batch_code' => $request->request_code,
                'request_line_number' => 1,
            ]);
        });

        // Requests already cleared by a PM no longer wait for a second approval.
        DB::table('expense_requests')->where('status', 'pending_director_approval')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('expense_requests', function (Blueprint $table) {
            $table->dropIndex(['request_batch_code']);
            $table->dropColumn(['request_batch_code', 'request_line_number']);
        });
    }
};
