<?php
/**
 * Candidate master-data export (CSV that opens directly in Excel).
 *
 * Export is owner-controlled: allowed for the workspace owner (role "owner", or the first
 * account when no owner role exists) and for users whose role is explicitly "admin_export".
 * Raw resume text, file paths and internal analysis JSON are never exported.
 */

function candidateExportAllowed(PDO $pdo, ?array $user): bool {
    if (!$user) {
        return false;
    }
    $role = strtolower((string)($user['role'] ?? ''));
    if (in_array($role, ['owner', 'admin_export'], true)) {
        return true;
    }
    static $firstUserId = null;
    if ($firstUserId === null) {
        try {
            $firstUserId = (int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        } catch (Throwable $e) {
            $firstUserId = 0;
        }
    }
    return $firstUserId > 0 && (int)($user['id'] ?? 0) === $firstUserId;
}

/** Neutralise spreadsheet formulas (CSV injection) while keeping the visible text. */
function candidateExportCell($value): string {
    $text = trim(preg_replace('/\s+/u', ' ', (string)($value ?? '')) ?? '');
    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $text = "'" . $text;
    }
    return $text;
}

function candidateExportHeaders(): array {
    return ['Candidate ID', 'Name', 'Email', 'Phone', 'Country', 'Applied job', 'Stage', 'Source', 'Source URL',
            'Experience (years)', 'Experience confidence', 'Current CTC (LPA)', 'Expected CTC (LPA)', 'Skills', 'Applied on'];
}

function candidateExportRow(array $c): array {
    $exp = json_decode((string)($c['experience_json'] ?? ''), true) ?: [];
    $skills = json_decode((string)($c['skills_json'] ?? ''), true) ?: [];
    $months = (int)($exp['total_months'] ?? 0);
    return array_map('candidateExportCell', [
        $c['id'] ?? '',
        $c['name'] ?? '',
        $c['email'] ?? '',
        $c['phone'] ?? '',
        $c['country_name'] ?? '',
        $c['job_title'] ?? ($c['role_title'] ?? ''),
        $c['stage'] ?? '',
        $c['source_name'] ?? '',
        $c['source_url'] ?? '',
        $months > 0 ? round($months / 12, 1) : 'Unknown',
        $exp['confidence'] ?? '',
        $c['current_ctc'] ?? '',
        $c['expected_ctc'] ?? '',
        implode(', ', array_map('strval', is_array($skills) ? $skills : [])),
        $c['created_at'] ?? '',
    ]);
}
