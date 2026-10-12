<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptureGbpo extends Model
{
    protected $table = 'capture_gbpo';
    public $timestamps = false;

    protected $fillable = [
        'phonenumber', 'did', 'campaign', 'agent', 'product_2', 'uniqueid',
        'disposition', 'disposition_code', 'media_type',
        'duration_call', 'recording_id', 'created_date',
    ];
}