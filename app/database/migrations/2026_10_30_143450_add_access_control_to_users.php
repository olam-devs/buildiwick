<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user access: `permissions` (null = the role's defaults, see App\Support\Access) and
     * `all_projects` (sees every project) — otherwise only projects in project_assignments.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('permissions')->nullable()->after('role_id');
            $table->boolean('all_projects')->default(false)->after('permissions');
        });

        $roleIds = DB::table('roles')->whereIn('name', ['director', 'admin', 'accounts', 'accountant'])->pluck('id');
        DB::table('users')->whereIn('role_id', $roleIds)->update(['all_projects' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['permissions', 'all_projects']);
        });
    }
};
