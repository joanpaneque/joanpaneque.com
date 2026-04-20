<?php

namespace App\Support;

final class VectorSimilarity
{
    /**
     * Similitud coseno en [0, 1] para vectores no negativos habitualmente; en general [-1, 1].
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $v) {
            $bVal = $b[$i];
            $dot += $v * $bVal;
            $na += $v * $v;
            $nb += $bVal * $bVal;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
