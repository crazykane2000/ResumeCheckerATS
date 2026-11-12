<?php
// Post-offer onboarding: document checklist uploaded by the candidate on the offer portal,
// verified by recruiters. Files live under uploads/onboarding/<offer id>/ (never web-served;
// downloaded only through onboarding_file.php with authentication and an audit event).
// Bank account numbers are encrypted at rest and shown masked.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/secrets.php';

const ONBOARDING_MAX_BYTES = 5 * 1024 * 1024;
const ONBOARDING_MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
const ONBOARDING_STATUS = ['pending' => 'Pending', 'submitted' => 'Submitted', 'verified' => 'Verified', 'rejected' => 'Needs re-upload'];

/** Checklist definition (order = display order). kind: file | bank */
function onboardingChecklist(): array
{
    return [
        'id_proof' => ['label' => 'Government photo ID (Aadhaar, passport or national ID)', 'kind' => 'file', 'required' => true],
        'tax_id' => ['label' => 'Tax ID (PAN card or local equivalent)', 'kind' => 'file', 'required' => true],
        'education' => ['label' => 'Highest education certificate', 'kind' => 'file', 'required' => true],
        'experience' => ['label' => 'Relieving / experience letter from last employer', 'kind' => 'file', 'required' => false],
        'payslips' => ['label' => 'Last 3 months payslips (single PDF)', 'kind' => 'file', 'required' => false],
        'bank' => ['label' => 'Bank account details for salary', 'kind' => 'bank', 'required' => true],
    ];
}

function onboardingTablesReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try { $pdo->query('SELECT 1 FROM onboarding_items LIMIT 1'); $ready = true; } catch (Throwable $e) { $ready = false; }
    return $ready;
}

/** Items for an offer, keyed by item_key, merged with the checklist (missing rows = pending). */
function onboardingItems(PDO $pdo, int $offerId): array
{
    $rows = [];
    if (onboardingTablesReady($pdo)) {
        $s = $pdo->prepare('SELECT * FROM onboarding_items WHERE offer_id=?');
        $s->execute([$offerId]);
        foreach ($s->fetchAll() as $r) $rows[$r['item_key']] = $r;
    }
    $out = [];
    foreach (onboardingChecklist() as $key => $def) {
        $out[$key] = $def + ['key' => $key, 'row' => $rows[$key] ?? null, 'status' => $rows[$key]['status'] ?? 'pending'];
    }
    return $out;
}

/** @return array{total:int, required:int, submitted:int, verified:int, required_verified:int, complete:bool} */
function onboardingProgress(array $items): array
{
    $p = ['total' => count($items), 'required' => 0, 'submitted' => 0, 'verified' => 0, 'required_verified' => 0];
    foreach ($items as $i) {
        if ($i['required']) $p['required']++;
        if (in_array($i['status'], ['submitted', 'verified'], true)) $p['submitted']++;
        if ($i['status'] === 'verified') {
            $p['verified']++;
            if ($i['required']) $p['required_verified']++;
        }
    }
    $p['complete'] = $p['required_verified'] === $p['required'];
    return $p;
}

function onboardingUploadDir(int $offerId): string
{
    return __DIR__ . '/../uploads/onboarding/' . $offerId;
}

/**
 * Validate and store an uploaded onboarding file (extension AND server-detected MIME).
 * @return array{path:string, original:string, mime:string, size:int}
 */
function onboardingStoreUpload(array $file, int $offerId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The file did not upload. Please try again.');
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('The file is empty.');
    if ($size > ONBOARDING_MAX_BYTES) throw new RuntimeException('The file is larger than 5 MB.');
    $tmp = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Invalid upload.');
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!isset(ONBOARDING_MIME[$mime])) throw new RuntimeException('Only PDF, JPG or PNG files are accepted.');
    $expected = ONBOARDING_MIME[$mime];
    if (!in_array($ext, $expected === 'jpg' ? ['jpg', 'jpeg'] : [$expected], true)) throw new RuntimeException('The file extension does not match its content.');
    $dir = onboardingUploadDir($offerId);
    if (!is_dir($dir) && !mkdir($dir, 0770, true)) throw new RuntimeException('Could not store the file.');
    $name = bin2hex(random_bytes(16)) . '.' . $expected;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) throw new RuntimeException('Could not store the file.');
    $original = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string)$file['name'])), 0, 180);
    return ['path' => 'onboarding/' . $offerId . '/' . $name, 'original' => $original, 'mime' => $mime, 'size' => $size];
}

/** Resolve a stored relative path safely inside uploads/onboarding. */
function onboardingFilePath(?string $relative): ?string
{
    $relative = (string)$relative;
    if (!preg_match('#^onboarding/\d+/[a-f0-9]{32}\.(pdf|jpg|png)$#', $relative)) return null;
    $base = realpath(__DIR__ . '/../uploads/onboarding');
    $full = realpath(__DIR__ . '/../uploads/' . $relative);
    return ($base && $full && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) ? $full : null;
}

/** Bank details from the portal form; account number encrypted. */
function onboardingBankData(array $post): array
{
    $holder = trim(preg_replace('/\s+/u', ' ', (string)($post['account_holder'] ?? '')));
    $bank = trim(preg_replace('/\s+/u', ' ', (string)($post['bank_name'] ?? '')));
    $account = preg_replace('/[\s-]+/', '', (string)($post['account_number'] ?? ''));
    $code = strtoupper(preg_replace('/\s+/', '', (string)($post['bank_code'] ?? '')));
    if (mb_strlen($holder) < 2 || mb_strlen($holder) > 120) throw new RuntimeException('Enter the account holder name.');
    if (mb_strlen($bank) < 2 || mb_strlen($bank) > 120) throw new RuntimeException('Enter the bank name.');
    if (!preg_match('/^[A-Za-z0-9]{6,34}$/', $account)) throw new RuntimeException('Enter a valid account number or IBAN (6-34 letters/digits).');
    if (!preg_match('/^[A-Z0-9]{4,15}$/', $code)) throw new RuntimeException('Enter the IFSC / SWIFT / routing code.');
    return ['account_holder' => $holder, 'bank_name' => $bank, 'account_number_enc' => encryptSecret($account), 'account_last4' => substr($account, -4), 'bank_code' => $code];
}

function onboardingMaskedBank(?string $json): string
{
    $d = json_decode((string)$json, true) ?: [];
    if (!$d) return '';
    return trim(($d['account_holder'] ?? '') . ' · ' . ($d['bank_name'] ?? '') . ' · A/C ••••' . ($d['account_last4'] ?? '') . ' · ' . ($d['bank_code'] ?? ''));
}

// ---------------------------------------------------------------------------
// Salary breakup annexure (stored on offer_letters.salary_breakup_json)
// ---------------------------------------------------------------------------

/** @return array<int, array{label:string, annual:float}> cleaned rows */
function salaryBreakupFromPost(array $labels, array $amounts): array
{
    $rows = [];
    foreach ($labels as $i => $label) {
        $label = mb_substr(trim((string)$label), 0, 80);
        $raw = str_replace([',', ' '], '', (string)($amounts[$i] ?? ''));
        if ($label === '' && $raw === '') continue;
        if ($label === '' || !is_numeric($raw) || (float)$raw < 0) throw new RuntimeException('Each salary component needs a name and a non-negative annual amount.');
        $rows[] = ['label' => $label, 'annual' => round((float)$raw, 2)];
    }
    return array_slice($rows, 0, 20);
}

function formatRupees(float $v): string
{
    // Indian digit grouping: 12,34,567
    $n = (string)round($v);
    $last3 = substr($n, -3);
    $rest = substr($n, 0, -3);
    if ($rest !== '') $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',';
    return '₹' . $rest . $last3;
}

/** Annexure HTML (allow-listed tags only) appended to the letter body for print, Word and portal. */
function salaryBreakupHtml(?string $json): string
{
    $rows = json_decode((string)$json, true) ?: [];
    if (!$rows) return '';
    $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $total = 0.0;
    $html = '<hr><h3>Annexure A — Salary breakup</h3><table><tr><th>Component</th><td><strong>Annual</strong> · Monthly</td></tr>';
    foreach ($rows as $r) {
        $total += (float)$r['annual'];
        $html .= '<tr><th>' . $e($r['label']) . '</th><td>' . $e(formatRupees((float)$r['annual'])) . ' · ' . $e(formatRupees((float)$r['annual'] / 12)) . '</td></tr>';
    }
    $html .= '<tr><th><strong>Total (CTC)</strong></th><td><strong>' . $e(formatRupees($total)) . '</strong> · ' . $e(formatRupees($total / 12)) . '</td></tr></table>';
    $html .= '<p>Statutory deductions (such as provident fund, professional tax and income tax) apply as per law. Components may be restructured within the same CTC as per company policy.</p>';
    return $html;
}
