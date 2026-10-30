<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Access;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role_id', 'is_active', 'permissions', 'all_projects'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /** New accounts get their role's project reach unless it was set explicitly (User Management always sets it). */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! array_key_exists('all_projects', $user->getAttributes())) {
                $user->all_projects = in_array(Role::query()->whereKey($user->role_id)->value('name'), Access::ALL_PROJECTS_BY_DEFAULT, true);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'permissions' => 'array',
            'all_projects' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_assignments');
    }

    public function preparedEstimates(): HasMany
    {
        return $this->hasMany(ProjectEstimate::class, 'prepared_by');
    }

    public function createdBudgets(): HasMany
    {
        return $this->hasMany(InternalBudget::class, 'created_by');
    }

    public function requestedExpenses(): HasMany
    {
        return $this->hasMany(ExpenseRequest::class, 'requested_by');
    }

    public function expenseDecisions(): HasMany
    {
        return $this->hasMany(ExpenseRequestApproval::class, 'decided_by');
    }

    public function expensePaymentsRecorded(): HasMany
    {
        return $this->hasMany(ExpensePayment::class, 'recorded_by');
    }

    public function clientPaymentsRecorded(): HasMany
    {
        return $this->hasMany(ClientPayment::class, 'recorded_by');
    }

    public function uploadedProjectDocuments(): HasMany
    {
        return $this->hasMany(ProjectDocument::class, 'uploaded_by');
    }

    /** Ledger permission check: Directors have everything; others use their own list or their role's defaults. */
    public function permits(string $permission): bool
    {
        return in_array($permission, $this->effectivePermissions(), true);
    }

    /** @return array<int, string> the permissions this user effectively has */
    public function effectivePermissions(): array
    {
        return $this->hasRole('admin') ? array_keys(Access::PERMISSIONS) : ($this->permissions ?? Access::defaultsFor($this->role?->name));
    }

    public function seesAllProjects(): bool
    {
        return $this->hasRole('admin') || (bool) $this->all_projects;
    }

    public function canAccessProject(Project|int $project): bool
    {
        $id = $project instanceof Project ? $project->id : $project;

        return $this->seesAllProjects() || $this->assignedProjects()->whereKey($id)->exists();
    }

    public function hasRole(string $name): bool
    {
        // "Admin" was an early internal label for the Director's system-wide
        // authority. Keep legacy authorization checks working while exposing
        // Director as the single real role in the product.
        if ($name === 'admin') {
            return in_array($this->role?->name, ['director', 'admin'], true);
        }

        return $this->role?->name === $name;
    }
}
