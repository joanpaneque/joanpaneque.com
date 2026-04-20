<?php

namespace App\Services;

use App\Models\InstagramKeywordRule;
use App\Models\InstagramKeywordRuleEmbedding;
use Illuminate\Support\Facades\DB;

final class KeywordEmbeddingSync
{
    /**
     * Regenera filas de embedding para todas las keywords de la regla.
     */
    public function sync(InstagramKeywordRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            InstagramKeywordRuleEmbedding::query()
                ->where('instagram_keyword_rule_id', $rule->id)
                ->delete();

            foreach ($rule->keywords as $kw) {
                if (! is_string($kw)) {
                    continue;
                }
                $t = trim($kw);
                if ($t === '') {
                    continue;
                }
                $t = mb_substr($t, 0, 500);
                $vector = OpenRouter::createEmbedding($t);
                InstagramKeywordRuleEmbedding::create([
                    'instagram_keyword_rule_id' => $rule->id,
                    'keyword' => $t,
                    'embedding' => $vector,
                ]);
            }
        });
    }
}
