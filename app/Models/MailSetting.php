<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SMTP account used for outgoing email. The password is encrypted with APP_KEY and never serialized. */
class MailSetting extends Model
{
    protected $fillable = ['host', 'port', 'username', 'password', 'from_address', 'from_name', 'updated_by'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
