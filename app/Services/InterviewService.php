<?php

namespace App\Services;

class InterviewService
{
    public static function calculateRecommendation(array $scorecard): string
    {
        if (empty($scorecard)) {
            return '';
        }

        $scores = array_map(fn ($c) => is_array($c) ? ($c['score'] ?? 0) : 0, $scorecard);
        $average = array_sum($scores) / count($scores);

        return match (true) {
            $average >= 4.5 => 'Excelente elección',
            $average >= 3.5 => 'Buena elección',
            $average >= 2.5 => 'Regular',
            default => 'No recomendado',
        };
    }
}
