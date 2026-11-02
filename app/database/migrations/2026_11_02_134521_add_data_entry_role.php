<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** A person who only enters expenses on a simple form; the entries wait for Accounts to confirm. */
    public function up(): void
    {
        if (! DB::table('roles')->where('name', 'data_entry')->exists()) {
            DB::table('roles')->insert([
                'name' => 'data_entry',
                'description' => 'Data Entry — picks a project and enters expenses on one form; Accounts confirms them',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'data_entry')->whereNotExists(fn ($query) => $query->from('users')->whereColumn('users.role_id', 'roles.id'))->delete();
    }
};
