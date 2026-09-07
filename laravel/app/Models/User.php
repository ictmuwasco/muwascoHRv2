<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Represents the legacy `users` table — owned by the inherited schema.
 * Maps to the live users table for Sanctum auth (password_hash column).
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = [
        'email',
        'employee_id',
        'first_name',
        'last_name',
        'surname',
        'gender',
        'password',
        'role',
        'designation',
        'phone',
        'address',
        'profile_image_url',
        'session_token',
        'login_identifier',
        'is_active',
        'last_activity',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'session_token',
        'remember_token',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active'       => 'boolean',
        'last_activity'   => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'email_verified_at' => 'datetime',
    ];

    /**
     * Relationship: the employee record this user account belongs to.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * Relationship: the user's direct department (denormalised read model).
     */
    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    /**
     * Relationship: the user's direct section (denormalised read model).
     */
    public function section()
    {
        return $this->belongsTo(Section::class, 'section_id', 'id');
    }

    /**
     * Relationship: the user's subsection (denormalised read model).
     */
    public function subsection()
    {
        return $this->belongsTo(Subsection::class, 'subsection_id', 'id');
    }

    /**
     * Relationship: role-permission assignments.
     */
    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class, 'role_id', 'id');
    }

    /**
     * Relationship: offices this user is assigned to (many-to-many).
     */
    public function offices()
    {
        return $this->belongsToMany(Office::class, 'employee_offices', 'user_id', 'office_id');
    }
}
