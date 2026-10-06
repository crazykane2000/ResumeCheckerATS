<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../vendor/autoload.php';

$file = __DIR__ . '/../uploads/resume_6ac501fc775d92.19211335.pdf';
$parser = new Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($file);
$rawText = $pdf->getText();

function analyzeExpSmart(string $text, array $skills = []): array {
    $monthsMap = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    
    // Isolate WORK EXPERIENCE section if present
    $targetText = $text;
    if (preg_match('/(?:WORK EXPERIENCE|EMPLOYMENT HISTORY|PROFESSIONAL EXPERIENCE|EXPERIENCE)\b/i', $text, $secMatch, PREG_OFFSET_CAPTURE)) {
        $startPos = $secMatch[0][1];
        $subText = substr($text, $startPos);
        // Find next major section header
        if (preg_match('/\n\s*(?:EDUCATION|SKILLS|PERSONAL PROJECTS|PROJECTS|ACHIEVEMENTS|LANGUAGES|CERTIFICATIONS|INTERESTS|DECLARATION)\b/i', $subText, $endMatch, PREG_OFFSET_CAPTURE)) {
            $targetText = substr($subText, 0, $endMatch[0][1]);
        } else {
            $targetText = $subText;
        }
    }

    $pattern = '/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|0?[1-9]|1[0-2])\s*[\/.\- ]\s*(20\d{2}|19\d{2})\s*(?:-|–|—|to)\s*(?:(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|0?[1-9]|1[0-2])\s*[\/.\- ]\s*(20\d{2}|19\d{2})|Present|Current|Ongoing|Till Date)/i';
    
    preg_match_all($pattern, $targetText, $matches, PREG_OFFSET_CAPTURE);
    $jobs = [];
    $now = new DateTimeImmutable('first day of this month');

    foreach ($matches[0] as $i => $full) {
        $m1Str = $matches[1][$i][0];
        $sy = (int)$matches[2][$i][0];
        $sm = is_numeric($m1Str) ? (int)$m1Str : ($monthsMap[strtolower(substr($m1Str,0,3))] ?? 1);
        $start = (new DateTimeImmutable())->setDate($sy, max(1, min(12, $sm)), 1)->setTime(0,0);

        if (!empty($matches[4][$i][0])) {
            $m2Str = $matches[3][$i][0];
            $ey = (int)$matches[4][$i][0];
            $em = is_numeric($m2Str) ? (int)$m2Str : ($monthsMap[strtolower(substr($m2Str,0,3))] ?? 1);
            $end = (new DateTimeImmutable())->setDate($ey, max(1, min(12, $em)), 1)->modify('last day of this month')->setTime(0,0);
        } else {
            $end = $now;
        }

        if ($end < $start) continue;

        // Find label by checking preceding lines before date match position
        $beforeMatchText = substr($targetText, 0, $full[1]);
        $precedingLines = array_values(array_filter(array_map('trim', preg_split('/\R/', $beforeMatchText))));
        $label = '';
        if (count($precedingLines) >= 2) {
            $l1 = $precedingLines[count($precedingLines)-2];
            $l2 = $precedingLines[count($precedingLines)-1];
            if (!preg_match('/WORK EXPERIENCE|EMPLOYMENT|EDUCATION|SKILLS|PROJECTS/i', $l1) && mb_strlen($l1) < 80) {
                $label = $l1;
            }
            if (!preg_match('/WORK EXPERIENCE|EMPLOYMENT|EDUCATION|SKILLS|PROJECTS/i', $l2) && mb_strlen($l2) < 80) {
                $label = $label ? ($label . ' — ' . $l2) : $l2;
            }
        } elseif (count($precedingLines) === 1) {
            $label = $precedingLines[0];
        }

        if (empty($label)) {
            $next = $matches[0][$i+1][1] ?? min(strlen($targetText), $full[1]+300);
            $block = substr($targetText, $full[1], max(0, $next - $full[1]));
            $label = trim(preg_replace('/\s+/', ' ', substr($block, 0, 80)));
        }

        $duration = max(1, ($end->format('Y') - $start->format('Y')) * 12 + (int)$end->format('n') - (int)$start->format('n') + 1);

        $jobs[] = [
            'label' => $label,
            'start' => $start->format('Y-m'),
            'end'   => $end === $now ? 'Present' : $end->format('Y-m'),
            'start_ts' => $start->getTimestamp(),
            'end_ts'   => $end->getTimestamp(),
            'months'   => $duration,
            'skills'   => []
        ];
    }

    usort($jobs, fn($a, $b) => $a['start_ts'] <=> $b['start_ts']);
    $merged = [];
    foreach ($jobs as $job) {
        if (!$merged || $job['start_ts'] > $merged[count($merged)-1][1] + 2678400) {
            $merged[] = [$job['start_ts'], $job['end_ts']];
        } else {
            $merged[count($merged)-1][1] = max($merged[count($merged)-1][1], $job['end_ts']);
        }
    }

    $totalMonths = 0;
    foreach ($merged as $range) {
        $totalMonths += (int)round(($range[1] - $range[0]) / 2629800) + 1;
    }

    return [
        'jobs' => $jobs,
        'total_months' => $totalMonths,
        'years' => round($totalMonths / 12, 1),
        'confidence' => count($jobs) ? 'estimated_from_dated_roles' : 'insufficient_dated_roles'
    ];
}

print_r(analyzeExpSmart($rawText));
