<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<int, string> $keywords
 * @property array<int, string> $comment_reply_variants
 * @property array<int, array{title: string, payload: string}>|null $dm_quick_replies
 * @property array<int, string>|null $dm_phase2_reply_variants
 */
class InstagramKeywordRule extends Model
{
    protected $fillable = [
        'is_active',
        'keywords',
        'comment_reply_variants',
        'dm_phase1_text',
        'dm_quick_replies',
        'dm_phase2_reply_variants',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'keywords' => 'array',
            'comment_reply_variants' => 'array',
            'dm_quick_replies' => 'array',
            'dm_phase2_reply_variants' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    /**
     * DM con quick replies configurado (fase 1 + 2).
     */
    public function hasDmAutomation(): bool
    {
        $q = $this->dm_quick_replies;
        $text = $this->dm_phase1_text;
        $p2 = $this->dm_phase2_reply_variants;

        return is_string($text) && trim($text) !== ''
            && is_array($q) && count($q) > 0
            && is_array($p2) && count($p2) > 0;
    }
}
