<?php
require_once __DIR__.'/lib/auth.php';
requireAuth();
require_once __DIR__.'/config/database.php';

$pdo = db();
$user = currentUser();
$ownerId = (int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
$isWorkspaceOwner = (int)($user['id'] ?? 0) === $ownerId;

$candidates = $pdo->query(
    "SELECT c.id,c.name,c.email,c.phone,c.role_title,c.source_name,c.score,c.stage,c.created_at,c.updated_at,c.job_id,j.title AS job_title
     FROM candidates c
     LEFT JOIN jobs j ON j.id=c.job_id
     ORDER BY c.created_at DESC,c.id DESC"
)->fetchAll();

$count = count($candidates);
$parent = range(0, max(0, $count - 1));
$find = function (int $item) use (&$parent, &$find): int {
    if ($parent[$item] !== $item) {
        $parent[$item] = $find($parent[$item]);
    }
    return $parent[$item];
};
$union = function (int $left, int $right) use (&$parent, &$find): void {
    $leftRoot = $find($left);
    $rightRoot = $find($right);
    if ($leftRoot !== $rightRoot) {
        $parent[$rightRoot] = $leftRoot;
    }
};

$emailMap = [];
$phoneMap = [];
$nameJobMap = [];
foreach ($candidates as $index => $candidate) {
    $email = strtolower(trim((string)$candidate['email']));
    if ($email !== '' && str_contains($email, '@')) {
        isset($emailMap[$email]) ? $union($index, $emailMap[$email]) : $emailMap[$email] = $index;
    }
    $phone = preg_replace('/\D+/', '', (string)$candidate['phone']);
    if (strlen($phone) >= 7) {
        $phone = substr($phone, -10);
        isset($phoneMap[$phone]) ? $union($index, $phoneMap[$phone]) : $phoneMap[$phone] = $index;
    }
    $name = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$candidate['name'])));
    if (strlen($name) >= 5 && $candidate['job_id'] !== null) {
        $nameJob = $name.'|'.(int)$candidate['job_id'];
        isset($nameJobMap[$nameJob]) ? $union($index, $nameJobMap[$nameJob]) : $nameJobMap[$nameJob] = $index;
    }
}

$groups = [];
foreach ($candidates as $index => $candidate) {
    $groups[$find($index)][] = $candidate;
}
$groups = array_values(array_filter($groups, fn($group) => count($group) > 1));

function duplicateReasons(array $group): array
{
    $values = ['Email'=>[], 'Phone'=>[], 'Name + same job'=>[]];
    foreach ($group as $candidate) {
        $email = strtolower(trim((string)$candidate['email']));
        if ($email !== '' && str_contains($email, '@')) {
            $values['Email'][$email] = ($values['Email'][$email] ?? 0) + 1;
        }
        $phone = preg_replace('/\D+/', '', (string)$candidate['phone']);
        if (strlen($phone) >= 7) {
            $phone = substr($phone, -10);
            $values['Phone'][$phone] = ($values['Phone'][$phone] ?? 0) + 1;
        }
        $name = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$candidate['name'])));
        if (strlen($name) >= 5 && $candidate['job_id'] !== null) {
            $key = $name.'|'.(int)$candidate['job_id'];
            $values['Name + same job'][$key] = ($values['Name + same job'][$key] ?? 0) + 1;
        }
    }
    $reasons = [];
    foreach ($values as $label => $matches) {
        if (array_filter($matches, fn($occurrences) => $occurrences > 1)) {
            $reasons[] = $label;
        }
    }
    return $reasons;
}

$duplicateRecords = array_sum(array_map('count', $groups));
$activePage = 'duplicates';
$pageTitle = 'Duplicate Candidates · NonceBlox ATS';
$pageStyles = <<<'CSS'
<style>
.duplicate-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;margin-bottom:18px;padding:20px 22px;background:linear-gradient(135deg,#f8f7ff,#eefbf5);border:1px solid #e5e7eb;border-radius:12px}
.duplicate-hero h1{margin:0 0 5px;font-size:24px}.duplicate-hero p{margin:0;color:#64748b;font-size:12px}
.duplicate-stats{display:flex;gap:8px}.duplicate-stat{min-width:92px;text-align:center;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:9px}.duplicate-stat strong{display:block;font-size:19px}.duplicate-stat span{font-size:10px;color:#64748b}
.duplicate-tools{display:grid;grid-template-columns:minmax(240px,1fr) 180px auto;gap:10px;align-items:end;margin-bottom:14px;padding:14px;background:#fff;border:1px solid #e5e7eb;border-radius:10px}
.duplicate-tools label{display:block;font-size:10px;font-weight:800;text-transform:uppercase;color:#64748b;margin-bottom:4px}.duplicate-tools .control{width:100%}
.duplicate-group{background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:14px;overflow:hidden}
.duplicate-group-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;background:#f8fafc;border-bottom:1px solid #e5e7eb}
.duplicate-group-head strong{font-size:13px}.match-reason{display:inline-flex;margin-left:6px;padding:3px 7px;background:#ede9fe;color:#6d28d9;border-radius:6px;font-size:10px}
.duplicate-table-wrap{overflow:auto}.duplicate-table{width:100%;border-collapse:collapse;font-size:12px}.duplicate-table th,.duplicate-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #eef2f7;white-space:nowrap}.duplicate-table th{font-size:10px;text-transform:uppercase;color:#64748b;background:#fff}.duplicate-table tr:last-child td{border-bottom:0}.candidate-name{font-weight:800;color:#111827}.candidate-contact{display:block;color:#64748b;font-size:10px;margin-top:2px}.keep-choice{color:#166534;font-weight:700}.delete-choice{color:#b91c1c;font-weight:700}.empty-state{text-align:center;padding:50px 20px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;color:#64748b}.notice{padding:12px 14px;margin-bottom:14px;border-radius:8px;background:#dcfce7;color:#166534;border:1px solid #86efac}
@media(max-width:760px){.duplicate-hero{align-items:flex-start;flex-direction:column}.duplicate-tools{grid-template-columns:1fr}.duplicate-stats{width:100%}.duplicate-stat{flex:1}}
</style>
CSS;
require __DIR__.'/views/partials/header.php';
?>

<section class="duplicate-hero">
  <div>
    <h1><i class="fa-solid fa-clone" style="color:#6f45ff"></i> Duplicate Candidates</h1>
    <p>Review-only detection. Nothing is merged or deleted automatically.</p>
  </div>
  <div class="duplicate-stats">
    <div class="duplicate-stat"><strong><?= count($groups) ?></strong><span>Duplicate groups</span></div>
    <div class="duplicate-stat"><strong><?= $duplicateRecords ?></strong><span>Records to review</span></div>
  </div>
</section>

<?php if (isset($_GET['deleted'])): ?>
  <div class="notice"><i class="fa-solid fa-circle-check"></i> <?= (int)$_GET['deleted'] ?> duplicate record(s) deleted. Kept records remain unchanged.</div>
<?php endif; ?>

<div class="duplicate-tools">
  <div>
    <label for="duplicateSearch">Search details</label>
    <input class="control" type="search" id="duplicateSearch" placeholder="Candidate, email, phone, source or job">
  </div>
  <div>
    <label for="duplicateJob">Job profile</label>
    <select class="control" id="duplicateJob">
      <option value="">All profiles</option>
      <?php
      $jobs = [];
      foreach ($groups as $group) foreach ($group as $candidate) if ($candidate['job_id'] !== null) $jobs[(int)$candidate['job_id']] = $candidate['job_title'] ?: 'Job #'.$candidate['job_id'];
      asort($jobs, SORT_NATURAL | SORT_FLAG_CASE);
      foreach ($jobs as $jobId => $jobTitle):
      ?><option value="<?= $jobId ?>"><?= htmlspecialchars($jobTitle) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div style="font-size:11px;color:#64748b"><strong id="visibleGroups"><?= count($groups) ?></strong> groups shown</div>
</div>

<?php if (!$groups): ?>
  <div class="empty-state"><i class="fa-regular fa-circle-check" style="font-size:30px;color:#10b981"></i><h2>No likely duplicates found</h2><p>Email, phone, and same-name/same-job checks found no duplicate groups.</p></div>
<?php else: ?>
<form method="post" action="candidate_delete.php" id="duplicateDeleteForm">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
  <input type="hidden" name="return_to" value="duplicates">
  <?php foreach ($groups as $groupIndex => $group):
    $reasons = duplicateReasons($group);
    $groupSearch = strtolower(implode(' ', array_map(fn($candidate) => implode(' ', [$candidate['name'],$candidate['email'],$candidate['phone'],$candidate['source_name'],$candidate['job_title'],$candidate['id']]), $group)));
    $jobIds = implode(',', array_unique(array_filter(array_map(fn($candidate) => $candidate['job_id'] === null ? null : (string)(int)$candidate['job_id'], $group))));
  ?>
  <section class="duplicate-group" data-search="<?= htmlspecialchars($groupSearch, ENT_QUOTES) ?>" data-jobs=",<?= htmlspecialchars($jobIds, ENT_QUOTES) ?>,">
    <div class="duplicate-group-head">
      <strong>Group <?= $groupIndex + 1 ?> · <?= count($group) ?> possible duplicate records
        <?php foreach ($reasons as $reason): ?><span class="match-reason"><?= htmlspecialchars($reason) ?></span><?php endforeach; ?>
      </strong>
      <span style="font-size:10px;color:#64748b">Choose one to keep, then review delete selections</span>
    </div>
    <div class="duplicate-table-wrap">
      <table class="duplicate-table">
        <thead><tr><th>Keep</th><th>Delete</th><th>Candidate details</th><th>Job profile</th><th>Source</th><th>Score / stage</th><th>Arrived</th><th>Open</th></tr></thead>
        <tbody>
        <?php foreach ($group as $candidate): ?>
          <tr>
            <td><label class="keep-choice"><input type="radio" name="keep_group_<?= $groupIndex ?>" value="<?= htmlspecialchars($candidate['id'], ENT_QUOTES) ?>" data-group="<?= $groupIndex ?>"> Keep</label></td>
            <td><label class="delete-choice"><input class="duplicate-delete" type="checkbox" name="candidate_ids[]" value="<?= htmlspecialchars($candidate['id'], ENT_QUOTES) ?>" data-group="<?= $groupIndex ?>"> Delete</label></td>
            <td><span class="candidate-name"><?= htmlspecialchars($candidate['name']) ?></span><span class="candidate-contact"><?= htmlspecialchars($candidate['email'] ?: 'No email') ?> · <?= htmlspecialchars($candidate['phone'] ?: 'No phone') ?></span><span class="candidate-contact">ID: <?= htmlspecialchars($candidate['id']) ?></span></td>
            <td><?= htmlspecialchars($candidate['job_title'] ?: 'Unassigned') ?></td>
            <td><?= htmlspecialchars($candidate['source_name'] ?: 'Unknown') ?></td>
            <td><?= (int)$candidate['score'] ?>% · <?= htmlspecialchars($candidate['stage']) ?></td>
            <td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($candidate['created_at']))) ?></td>
            <td><a class="btn" href="candidate_detail.php?id=<?= urlencode($candidate['id']) ?>" target="_blank" rel="noopener">Details</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endforeach; ?>
  <?php if ($isWorkspaceOwner): ?>
    <div style="position:sticky;bottom:12px;display:flex;justify-content:flex-end"><button class="btn" type="submit" style="background:#fff;color:#b91c1c;border-color:#fca5a5;box-shadow:0 8px 24px rgba(15,23,42,.12)"><i class="fa-solid fa-trash"></i> Delete selected duplicates</button></div>
  <?php else: ?>
    <div class="empty-state" style="padding:16px">Only the workspace owner can delete candidate records.</div>
  <?php endif; ?>
</form>
<?php endif; ?>

<script>
const duplicateGroups = Array.from(document.querySelectorAll('.duplicate-group'));
document.querySelectorAll('input[type="radio"][data-group]').forEach(radio => {
  radio.addEventListener('change', function () {
    document.querySelectorAll('.duplicate-delete[data-group="' + this.dataset.group + '"]').forEach(box => {
      box.checked = box.value !== this.value;
    });
  });
});
function filterDuplicates() {
  const query = document.getElementById('duplicateSearch').value.trim().toLowerCase();
  const job = document.getElementById('duplicateJob').value;
  let visible = 0;
  duplicateGroups.forEach(group => {
    const matches = (!query || group.dataset.search.includes(query)) && (!job || group.dataset.jobs.includes(',' + job + ','));
    group.hidden = !matches;
    if (matches) visible++;
  });
  document.getElementById('visibleGroups').textContent = visible;
}
document.getElementById('duplicateSearch').addEventListener('input', filterDuplicates);
document.getElementById('duplicateJob').addEventListener('change', filterDuplicates);
const duplicateDeleteForm = document.getElementById('duplicateDeleteForm');
if (duplicateDeleteForm) duplicateDeleteForm.addEventListener('submit', function (event) {
  const selected = Array.from(document.querySelectorAll('.duplicate-delete:checked'));
  if (!selected.length) {
    event.preventDefault();
    alert('Select at least one duplicate record to delete.');
    return;
  }
  const affectedGroups = [...new Set(selected.map(box => box.dataset.group))];
  for (const groupId of affectedGroups) {
    if (!document.querySelector('input[type="radio"][data-group="' + groupId + '"]:checked')) {
      event.preventDefault();
      alert('Choose which record to keep in every group where you selected deletion.');
      return;
    }
  }
  if (!confirm('Delete ' + selected.length + ' selected duplicate record(s) from ResumeIQ? Original remote source records remain unchanged and may sync again.')) event.preventDefault();
});
</script>
<?php require __DIR__.'/views/partials/footer.php'; ?>
