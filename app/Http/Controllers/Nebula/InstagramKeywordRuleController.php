<?php

namespace App\Http\Controllers\Nebula;

use App\Http\Controllers\Controller;
use App\Http\Requests\InstagramKeywordRuleRequest;
use App\Models\InstagramKeywordRule;
use App\Services\KeywordEmbeddingSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class InstagramKeywordRuleController extends Controller
{
    public function index(): Response
    {
        $rules = InstagramKeywordRule::query()
            ->orderBy('id')
            ->get();

        return Inertia::render('Nebula/InstagramRules/Index', [
            'rules' => $rules,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Nebula/InstagramRules/Create', [
            'defaults' => [
                'is_active' => true,
                'keywords' => [],
                'comment_reply_variants' => [''],
                'dm_phase1_text' => '',
                'dm_quick_replies' => [['title' => '', 'payload' => '']],
                'dm_phase2_reply_variants' => [''],
            ],
        ]);
    }

    public function store(InstagramKeywordRuleRequest $request, KeywordEmbeddingSync $embeddingSync): RedirectResponse
    {
        $data = $request->validated();
        if (! array_key_exists('is_active', $data)) {
            $data['is_active'] = true;
        }
        $rule = InstagramKeywordRule::query()->create($data);

        try {
            $embeddingSync->sync($rule);
        } catch (\Throwable $e) {
            Log::error('KeywordEmbeddingSync tras crear regla', [
                'rule_id' => $rule->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('nebula.instagram-rules.index')
                ->with('success', 'Regla creada.')
                ->with('warning', 'Los embeddings no se generaron: '.$e->getMessage());
        }

        return redirect()->route('nebula.instagram-rules.index')
            ->with('success', 'Regla creada.');
    }

    public function edit(InstagramKeywordRule $rule): Response
    {
        return Inertia::render('Nebula/InstagramRules/Edit', [
            'rule' => $rule,
        ]);
    }

    public function update(InstagramKeywordRuleRequest $request, InstagramKeywordRule $rule, KeywordEmbeddingSync $embeddingSync): RedirectResponse
    {
        $data = $request->validated();
        $rule->update($data);
        $rule->refresh();

        try {
            $embeddingSync->sync($rule);
        } catch (\Throwable $e) {
            Log::error('KeywordEmbeddingSync tras actualizar regla', [
                'rule_id' => $rule->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('nebula.instagram-rules.index')
                ->with('success', 'Regla actualizada.')
                ->with('warning', 'Los embeddings no se regeneraron: '.$e->getMessage());
        }

        return redirect()->route('nebula.instagram-rules.index')
            ->with('success', 'Regla actualizada.');
    }

    public function destroy(InstagramKeywordRule $rule): RedirectResponse
    {
        $rule->delete();

        return redirect()->route('nebula.instagram-rules.index')
            ->with('success', 'Regla eliminada.');
    }
}
