<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

#[Fillable(['name', 'email', 'phone', 'password', 'role_id', 'is_active'])]
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
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
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
