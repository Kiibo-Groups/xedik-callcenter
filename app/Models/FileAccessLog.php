<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'crm_file_id', 'user_id',
        'action', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function crmFile()
    {
        return $this->belongsTo(CrmFile::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
