<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $directorId = DB::table('roles')->where('name', 'director')->value('id');

        if (! $directorId) {
            $directorId = DB::table('roles')->insertGetId([
                'name' => 'director',
                'description' => 'Director — system administration and organization-wide executive oversight',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('roles')->where('id', $directorId)->update([
            'description' => 'Director — system administration and organization-wide executive oversight',
            'updated_at' => now(),
        ]);

        $adminId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminId) {
            DB::table('users')->where('role_id', $adminId)->update(['role_id' => $directorId]);
            DB::table('roles')->where('id', $adminId)->delete();
        }
    }

    public function down(): void
    {
        if (! DB::table('roles')->where('name', 'admin')->exists()) {
            DB::table('roles')->insert([
                'name' => 'admin',
                'description' => 'System administrator — full access, all projects',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('roles')->where('name', 'director')->update([
            'description' => 'Director — organization-wide executive analytics',
            'updated_at' => now(),
        ]);
    }
};
