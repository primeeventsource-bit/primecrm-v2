<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\UserRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasUuid;
    use Notifiable;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'users';

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'status',
        'phone',
        'extension',
        'timezone',
        'skills',
        'is_panama_based',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'skills' => 'array',
            'is_panama_based' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isAgent(): bool
    {
        return $this->role->canTakeCalls();
    }

    public function isSupervisor(): bool
    {
        return $this->role->canSupervise();
    }
}
