<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InteractionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sender_phone',
        'wasender_message_id',
        'intent',
        'detected_language',
        'was_resolved',
        'was_fallback',
        'processing_ms',
    ];

    protected function casts(): array
    {
        return [
            'was_resolved' => 'boolean',
            'was_fallback' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}