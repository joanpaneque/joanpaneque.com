<?php

namespace App\Http\Requests;

use App\Models\InstagramKeywordRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InstagramKeywordRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:500'],
            'comment_reply_variants' => ['required', 'array', 'min:1'],
            'comment_reply_variants.*' => ['required', 'string', 'max:8000'],
            'dm_phase1_text' => ['nullable', 'string', 'max:8000'],
            'dm_quick_replies' => ['nullable', 'array', 'max:13'],
            'dm_quick_replies.*.title' => ['required', 'string', 'max:20'],
            'dm_quick_replies.*.payload' => ['nullable', 'string', 'max:1000'],
            'dm_phase2_reply_variants' => ['nullable', 'array'],
            'dm_phase2_reply_variants.*' => ['nullable', 'string', 'max:8000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $keywords = $this->input('keywords', []);
        if (is_array($keywords)) {
            $keywords = array_values(array_filter(
                array_map(fn ($k) => is_string($k) ? trim($k) : '', $keywords),
                fn ($k) => $k !== '',
            ));
            $this->merge(['keywords' => $keywords]);
        }

        $commentVariants = $this->input('comment_reply_variants', []);
        if (is_array($commentVariants)) {
            $commentVariants = array_values(array_filter(
                array_map(fn ($k) => is_string($k) ? trim($k) : '', $commentVariants),
                fn ($k) => $k !== '',
            ));
            $this->merge(['comment_reply_variants' => $commentVariants]);
        }

        $p2 = $this->input('dm_phase2_reply_variants', []);
        if (is_array($p2)) {
            $p2 = array_values(array_filter(
                array_map(fn ($k) => is_string($k) ? trim($k) : '', $p2),
                fn ($k) => $k !== '',
            ));
            $this->merge(['dm_phase2_reply_variants' => $p2]);
        }

        $qr = $this->input('dm_quick_replies');
        if (is_array($qr)) {
            $clean = [];
            foreach ($qr as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $title = isset($row['title']) && is_string($row['title']) ? trim($row['title']) : '';
                if ($title === '') {
                    continue;
                }
                $payload = isset($row['payload']) && is_string($row['payload']) ? trim($row['payload']) : '';
                if ($payload === '') {
                    $payload = $title;
                }
                $clean[] = [
                    'title' => mb_substr($title, 0, 20),
                    'payload' => mb_substr($payload, 0, 1000),
                ];
                if (count($clean) >= 13) {
                    break;
                }
            }
            $this->merge(['dm_quick_replies' => $clean]);
        }

        $finalQr = $this->input('dm_quick_replies');
        if (! is_array($finalQr) || count($finalQr) === 0) {
            $this->merge([
                'dm_quick_replies' => null,
                'dm_phase1_text' => null,
                'dm_phase2_reply_variants' => null,
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validateKeywordsUniqueAcrossRules($v);
        });

        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $qrs = $data['dm_quick_replies'] ?? [];
            $hasQr = is_array($qrs) && count($qrs) > 0;
            if (! $hasQr) {
                return;
            }
            $phase1 = isset($data['dm_phase1_text']) && is_string($data['dm_phase1_text'])
                ? trim($data['dm_phase1_text'])
                : '';
            if ($phase1 === '') {
                $v->errors()->add('dm_phase1_text', 'Si hay botones en el DM, el texto del primer mensaje es obligatorio.');
            }
            $p2 = $data['dm_phase2_reply_variants'] ?? [];
            $p2f = is_array($p2)
                ? array_values(array_filter($p2, fn ($x) => is_string($x) && trim($x) !== ''))
                : [];
            if (count($p2f) < 1) {
                $v->errors()->add('dm_phase2_reply_variants', 'Si hay DM con botones, añade al menos una respuesta para la fase 2.');
            }
        });
    }

    private function validateKeywordsUniqueAcrossRules(Validator $v): void
    {
        $data = $v->getData();
        $keywords = $data['keywords'] ?? null;
        if (! is_array($keywords)) {
            return;
        }

        $seen = [];
        foreach ($keywords as $kwRaw) {
            if (! is_string($kwRaw)) {
                continue;
            }
            $n = mb_strtolower(trim($kwRaw));
            if ($n === '') {
                continue;
            }
            if (isset($seen[$n])) {
                $v->errors()->add('keywords', 'No puedes repetir la misma keyword en esta regla.');

                return;
            }
            $seen[$n] = true;
        }

        $excludeId = $this->route('rule') instanceof InstagramKeywordRule
            ? $this->route('rule')->id
            : null;

        $others = InstagramKeywordRule::query()
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();

        foreach ($keywords as $kwRaw) {
            if (! is_string($kwRaw)) {
                continue;
            }
            $n = mb_strtolower(trim($kwRaw));
            if ($n === '') {
                continue;
            }
            foreach ($others as $rule) {
                foreach ($rule->keywords as $existing) {
                    if (! is_string($existing)) {
                        continue;
                    }
                    if (mb_strtolower(trim($existing)) === $n) {
                        $v->errors()->add('keywords', 'La keyword "'.trim($kwRaw).'" ya está en otra regla.');

                        return;
                    }
                }
            }
        }
    }
}
