<?php

namespace App\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeBase extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'content',
        'document_key',
        'chunk_index',
        'embedding_vector',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'embedding_vector' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}