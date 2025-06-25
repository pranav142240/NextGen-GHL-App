<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'token_type',
        'active_status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'active_status' => 'boolean',
    ];
}