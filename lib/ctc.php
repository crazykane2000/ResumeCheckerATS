<?php
// Deterministic CTC normalisation and budget display helpers.
//
// Candidates type CTC in free text ("600000", "12", "18 lpa", "15,00,000",
// "$87K", "NA", "Fresher"). normalizeCtcLpa() converts only values whose
// meaning is clear into Indian LPA (lakhs per annum). Anything ambiguous or
// non-INR keeps its raw text and returns lpa=null so a recruiter can review it.
// Nothing is guessed or invented here.

const CTC_MIN_LPA = 0.5;
const CTC_MAX_LPA = 300.0;

/**
 * @return array{lpa: ?float, status: string, raw: string}
 *   status: stated | zero | empty | unclear | non_inr
 */
function normalizeCtcLpa(?string $raw, ?string $country = null): array
{
    $raw = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    $raw = mb_substr($raw, 0, 60);
    $out = static fn(?float $lpa, string $status): array => ['lpa' => $lpa, 'status' => $status, 'raw' => $raw];
    $v = mb_strtolower($raw);

    if ($v === '' || preg_match('/^(?:n\/?a|none|nil|null|no|not applicable|-+|\.+|nothing)$/', $v)) {
        return $out(null, 'empty');
    }
    if (preg_match('/\b(?:fresher|student|unemployed)\b/', $v)) {
        return $out(0.0, 'zero');
    }
    if (preg_match('/[$€£]|\b(?:usd|dollars?|eur|euros?|gbp|aed|dirhams?|kes|ksh|shillings?|sgd|cad|aud)\b/u', $v)) {
        return $out(null, 'non_inr');
    }
    if (!preg_match('/(\d[\d,]*(?:\.\d+)?)/', $v, $m)) {
        return $out(null, 'unclear');
    }
    $number = (float)str_replace(',', '', $m[1]);
    if ($number == 0.0) {
        return $out(0.0, 'zero');
    }

    // Units are often glued to digits ("6LPA", "45k"), so match on letter boundaries rather than \b.
    $has = static fn(string $alt): bool => (bool)preg_match('/(?<![a-z])(?:' . $alt . ')(?![a-z])/u', $v);
    $explicitInr = str_contains($v, '₹') || $has('inr|rs|rupees?|lpa|lakhs?|lacs?|crores?|cr');
    $countryName = mb_strtolower(trim((string)$country));
    if (!$explicitInr && $countryName !== '' && $countryName !== 'india') {
        // A bare number from outside India is probably local currency; do not convert.
        return $out(null, 'non_inr');
    }

    $lpa = null;
    $thousands = $has('k|thousand');
    if ($thousands) $number *= 1000;
    if ($has('crores?|cr')) {
        $lpa = $number * 100;
    } elseif ($has('lpa|lakhs?|lacs?|l')) {
        $lpa = $number;
    } elseif ($has('per month|monthly|pm|p\.m|month')) {
        $lpa = $number >= 1000 ? ($number * 12) / 100000 : null;
    } elseif ($thousands) {
        $lpa = $number / 100000;
    } elseif ($number >= 100000) {
        $lpa = $number / 100000;          // rupees per annum, e.g. 600000 or 15,00,000
    } elseif ($number <= 200) {
        $lpa = $number;                   // already in LPA, e.g. 12 or 7.5
    }
    // 200 < n < 100000 without a unit (e.g. 211, 1500, 20,000) is ambiguous: monthly? thousands?

    if ($lpa === null || $lpa < CTC_MIN_LPA || $lpa > CTC_MAX_LPA) {
        return $out(null, 'unclear');
    }
    return $out(round($lpa, 2), 'stated');
}

/** True when a stored LPA number is physically plausible (filters legacy parser noise such as years). */
function ctcIsPlausible(?float $lpa): bool
{
    return $lpa !== null && $lpa >= CTC_MIN_LPA && $lpa <= CTC_MAX_LPA;
}

function formatLpa(float $lpa): string
{
    return '₹' . rtrim(rtrim(number_format($lpa, 2, '.', ''), '0'), '.') . ' LPA';
}

/** Experience bands used for the approximate-budget estimate (years, inclusive lower bound). */
function ctcExperienceBand(?float $years): ?string
{
    if ($years === null) return null;
    return match (true) {
        $years < 1 => '0-1 yrs',
        $years < 3 => '1-3 yrs',
        $years < 5 => '3-5 yrs',
        $years < 8 => '5-8 yrs',
        default => '8+ yrs',
    };
}

/**
 * Build the estimate pools: median candidate-stated expected CTC per experience band
 * ("3-5 yrs"), per job ("job:12") and across all applicants ("all").
 * Only values the candidate stated themselves (source = form) are used.
 *
 * @param array<int, array{expected_ctc: ?float, ctc_source: ?string, years: ?float, job_id?: ?int}> $rows
 * @return array<string, array{median: float, count: int}>
 */
function ctcBudgetBands(array $rows, int $minSamples = 5): array
{
    $buckets = [];
    foreach ($rows as $row) {
        $lpa = $row['expected_ctc'] ?? null;
        if (($row['ctc_source'] ?? null) !== 'nonceblox_form' || !ctcIsPlausible($lpa)) continue;
        $buckets['all'][] = (float)$lpa;
        $band = ctcExperienceBand($row['years'] ?? null);
        if ($band !== null) $buckets[$band][] = (float)$lpa;
        if (!empty($row['job_id'])) $buckets['job:' . (int)$row['job_id']][] = (float)$lpa;
    }
    $bands = [];
    foreach ($buckets as $band => $values) {
        if (count($values) < $minSamples) continue;
        sort($values);
        $n = count($values);
        $median = $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
        $bands[$band] = ['median' => round($median, 1), 'count' => $n];
    }
    return $bands;
}

/**
 * Decide what the Budget column shows for one candidate.
 *
 * @return array{kind: string, lpa: ?float, label: string, detail: string}
 *   kind: expected | current | estimate | review | none
 */
function candidateBudget(array $c, array $bands): array
{
    $source = (string)($c['ctc_source'] ?? '');
    $fromLabel = $source === 'nonceblox_form' ? 'stated on NonceBlox application form' : 'extracted from resume text — verify';
    $expected = isset($c['expected_ctc']) ? (float)$c['expected_ctc'] : null;
    $current = isset($c['current_ctc']) ? (float)$c['current_ctc'] : null;

    if (ctcIsPlausible($expected)) {
        return ['kind' => 'expected', 'lpa' => $expected, 'label' => formatLpa($expected), 'detail' => 'Expected CTC, ' . $fromLabel];
    }
    if (ctcIsPlausible($current)) {
        return ['kind' => 'current', 'lpa' => $current, 'label' => formatLpa($current), 'detail' => 'Current CTC (no expected CTC given), ' . $fromLabel];
    }

    $rawParts = array_filter([
        trim((string)($c['expected_ctc_raw'] ?? '')) !== '' ? 'expected "' . $c['expected_ctc_raw'] . '"' : null,
        trim((string)($c['current_ctc_raw'] ?? '')) !== '' ? 'current "' . $c['current_ctc_raw'] . '"' : null,
    ]);

    // Approximate fallback, most specific pool first: same experience band, same job, all applicants.
    $years = isset($c['years']) ? (float)$c['years'] : null;
    $band = ctcExperienceBand($years);
    $jobKey = !empty($c['job_id']) ? 'job:' . (int)$c['job_id'] : null;
    $pools = [
        [$band, $band !== null ? 'applicants with ' . $band . ' experience' : null, 'experience'],
        [$jobKey, 'applicants for the same job', 'job'],
        ['all', 'all applicants', 'all'],
    ];
    foreach ($pools as [$key, $who, $basis]) {
        if ($key === null || !isset($bands[$key])) continue;
        $est = $bands[$key];
        $of = $basis === 'all' ? 'all ' . $est['count'] . ' applicants' : $est['count'] . ' ' . $who;
        $detail = 'Approximate only — median expected CTC of ' . $of . '. Not stated by this candidate.';
        if ($rawParts) $detail .= ' Candidate wrote: ' . implode(', ', $rawParts) . ' (needs review).';
        return ['kind' => 'estimate', 'basis' => $basis, 'lpa' => $est['median'], 'label' => '~' . formatLpa($est['median']), 'detail' => $detail];
    }
    // Only reached when no candidate-stated CTC exists at all (e.g. before the NonceBlox backfill).
    if ($rawParts) {
        return ['kind' => 'review', 'lpa' => null, 'label' => 'Review', 'detail' => 'Unclear or non-INR value: ' . implode(', ', $rawParts)];
    }
    return ['kind' => 'none', 'lpa' => null, 'label' => 'Not stated', 'detail' => 'No candidate-stated CTC data is available yet to estimate from.'];
}
