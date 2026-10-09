<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_a_user_with_role_and_temporary_password(): void
    {
        $admin = $this->user('director');
        $role = Role::where('name', 'project_manager')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Project Manager One',
            'email' => 'pm.one@example.test',
            'phone' => '+255700000001',
            'role_id' => $role->id,
            'password' => 'Temporary123!',
            'password_confirmation' => 'Temporary123!',
            'is_active' => '1',
        ])->assertRedirect(route('admin.users.index'));

        $created = User::where('email', 'pm.one@example.test')->firstOrFail();
        $this->assertSame($role->id, $created->role_id);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('Temporary123!', $created->password));
    }

    public function test_admin_can_change_role_deactivate_and_reset_password(): void
    {
        $admin = $this->user('director');
        $managed = $this->user('site_operator');
        $newRole = Role::where('name', 'accounts')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.users.update', $managed), [
            'name' => $managed->name,
            'email' => $managed->email,
            'phone' => '+255700000002',
            'role_id' => $newRole->id,
            'password' => 'Replacement123!',
            'password_confirmation' => 'Replacement123!',
            'is_active' => '0',
        ])->assertRedirect(route('admin.users.index'));

        $managed->refresh();
        $this->assertSame($newRole->id, $managed->role_id);
        $this->assertFalse($managed->is_active);
        $this->assertTrue(Hash::check('Replacement123!', $managed->password));
    }

    public function test_admin_cannot_deactivate_or_demote_their_own_account(): void
    {
        $admin = $this->user('director');
        $otherRole = Role::where('name', 'accounts')->firstOrFail();

        $this->actingAs($admin)->from(route('admin.users.edit', $admin))->put(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $otherRole->id,
            'password' => '',
            'password_confirmation' => '',
            'is_active' => '0',
        ])->assertRedirect(route('admin.users.edit', $admin))->assertSessionHasErrors('is_active');

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasRole('admin'));
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $this->actingAs($this->user('project_manager'))->get(route('admin.users.index'))->assertForbidden();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('name', $role)->value('id'),
            'is_active' => true,
        ]);
    }
}
