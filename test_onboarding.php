<?php
require_once __DIR__ . '/lib/onboarding.php';
function checkOnboarding(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$rows = salaryBreakupFromPost(['Basic', 'HRA', ''], ['120000', '60000', '']);
checkOnboarding(count($rows) === 2 && $rows[0]['annual'] === 120000.0, 'Salary parsing failed');
checkOnboarding(str_contains(salaryBreakupHtml(json_encode($rows)), '1,80,000'), 'Salary total missing');
checkOnboarding(!str_contains(salaryBreakupHtml(json_encode([['label'=>'<script>x</script>', 'annual'=>1]])), '<script>'), 'Unsafe salary label');
foreach (['-1', 'invalid', '1e999', '1000000000001'] as $bad) {
    $rejected = false;
    try { salaryBreakupFromPost(['Basic'], [$bad]); } catch (RuntimeException $e) { $rejected = true; }
    checkOnboarding($rejected, 'Invalid salary accepted');
}
$items = array_map(fn($i) => $i + ['status'=>'submitted'], onboardingChecklist());
checkOnboarding(!onboardingProgress($items)['complete'], 'Submission must not imply verification');
foreach ($items as &$item) $item['status'] = 'verified';
unset($item);
checkOnboarding(onboardingProgress($items)['complete'], 'Verified checklist incomplete');
checkOnboarding(onboardingFilePath('../config/database.php') === null, 'Traversal accepted');
echo "Onboarding helper checks passed.\n";
