<?php

namespace App\Models;

use App\Models\InteractionLog;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_number',
        'session_id',
        'wasender_api_key',
        'webhook_secret',
        'config',
        'permissions',
        'support_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function interactionLogs(): HasMany
    {
        return $this->hasMany(InteractionLog::class);
    }
}