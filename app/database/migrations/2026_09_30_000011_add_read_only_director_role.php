<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['name' => 'director'],
            ['description' => 'Director — organization-wide read-only executive analytics', 'updated_at' => now(), 'created_at' => now()]
        );

        $directorRoleId = DB::table('roles')->where('name', 'director')->value('id');
        DB::table('users')->where('email', 'director@buildiwick.test')->update(['role_id' => $directorRoleId]);
    }

    public function down(): void
    {
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $directorRoleId = DB::table('roles')->where('name', 'director')->value('id');

        if ($adminRoleId && $directorRoleId) {
            DB::table('users')->where('role_id', $directorRoleId)->update(['role_id' => $adminRoleId]);
            DB::table('roles')->where('id', $directorRoleId)->delete();
        }
    }
};