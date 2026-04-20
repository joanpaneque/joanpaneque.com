<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<float> $embedding
 */
class InstagramKeywordRuleEmbedding extends Model
{
    protected $fillable = [
        'instagram_keyword_rule_id',
        'keyword',
        'embedding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<InstagramKeywordRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(InstagramKeywordRule::class, 'instagram_keyword_rule_id');
    }
}
