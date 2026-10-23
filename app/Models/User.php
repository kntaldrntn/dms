<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'sex',            // Added: Exists in your migration
        'signature_path',
        'password',
        'state',         // Added: Exists in your migration
        'department_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        // Where the signer's .p12 is stored; set only by SigningCertificateService, never mass-assigned.
        'certificate_path',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

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
            'two_factor_confirmed_at' => 'datetime',
            'state' => 'integer', // Cast to ensure strict 0/1 output
            'certificate_valid_until' => 'datetime',
        ];
    }

    /** Roles that approve and sign documents with their own PNPKI certificate. */
    public const SIGNER_ROLES = ['city_administrator', 'city_mayor', 'department_head', 'system_administrator'];

    public function hasValidCertificate(): bool
    {
        return $this->certificate_path !== null && $this->certificate_valid_until?->isFuture() === true;
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
    public function loginHistories()
    {
        return $this->hasMany(LoginHistory::class)->orderBy('login_at', 'desc');
    }

    public function latestLogin()
    {
        return $this->hasOne(LoginHistory::class)->latestOfMany('login_at');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'model_id')->where('model_type', static::class)->latest();
    }
}
