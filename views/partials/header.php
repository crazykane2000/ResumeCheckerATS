<?php
require_once __DIR__.'/../../lib/auth.php';requireAuth();require_once __DIR__.'/../../lib/branding.php';require_once __DIR__.'/../../lib/notifications.php';
$activePage=$activePage??'scan';$searchPlaceholder=$searchPlaceholder??'Search candidate, skill or role';$headerUser=currentUser();$brand=organizationBrand();$pageTitle=brandedPageTitle($pageTitle??null,$brand);$favicon=$brand['logo_path']?:'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="12" fill="#6f45ff"/><text x="32" y="42" text-anchor="middle" font-size="34" fill="white">N</text></svg>');$pageStyles='<link rel="icon" href="'.htmlspecialchars($favicon,ENT_QUOTES).'">'.($pageStyles??'');
$nav=[
  ['analytics','analytics.php','fa-chart-line','Analytics','Analytics — Hiring Metrics'],
  ['dashboard','dashboard.php','fa-chart-pie','Dashboard','Dashboard — Overview & Activity'],
  ['candidates','candidates.php','fa-users','Candidates','Candidates — Applicant Database'],
  ['calendar','interview_calendar.php','fa-calendar-days','Interviews','Interviews — Candidate Slots'],
  ['outreach','outreach.php','fa-paper-plane','Hiring Outreach','Hiring Outreach — Create Campaign'],
  ['outreach_history','outreach_history.php','fa-clock-rotate-left','Outreach History','Outreach History — Campaign Reports'],
  ['email_history','email_batch_history.php','fa-envelope-open-text','Email History & Resets','Email History — Dispatches & Batch Resets'],
  ['integrations','integrations.php','fa-plug','Integrations','Integrations — Email & SMTP'],
  ['scan','index.php','fa-file-arrow-up','Resume Upload','Resume Upload — Parse & Ingest Resume'],
  ['settings','settings.php','fa-gear','Settings','Settings — Account & Branding']
];
$wishlistCount=0;$profileCount=0;try{$s=db()->prepare("SELECT COUNT(*) items,COUNT(DISTINCT wi.profile_id) profiles FROM wishlist_items wi JOIN wishlist_profiles wp ON wp.id=wi.profile_id WHERE wp.user_id=? AND wi.disposition='wishlist'");$s->execute([$headerUser['id']]);$c=$s->fetch();$wishlistCount=(int)($c['items']??0);$profileCount=(int)($c['profiles']??0);}catch(Throwable $e){}
$headerNotifications = fetchRecruiterNotifications(db(), 8);
$headerUnreadCount = count($headerNotifications);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($pageTitle)?></title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/header-actions.css"><?=$pageStyles??''?>
<style>
.brand-icon img{width:30px;height:30px;object-fit:contain;border-radius:7px}
.side-btn{position:relative}
.side-btn[data-tooltip]:hover::before{content:attr(data-tooltip);position:absolute;left:calc(100% + 12px);top:50%;transform:translateY(-50%);background:#1e1b2e;color:#fff;font-size:11px;font-weight:600;white-space:nowrap;padding:6px 12px;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.18);pointer-events:none;z-index:120;letter-spacing:-0.01em}
.side-btn[data-tooltip]:hover::after{content:"";position:absolute;left:calc(100% + 4px);top:50%;transform:translateY(-50%);border-width:5px 6px 5px 0;border-style:solid;border-color:transparent #1e1b2e transparent transparent;pointer-events:none;z-index:120}

/* Top Notification Bell & Dropdown */
.top-notif-btn {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  border: 1px solid var(--line, #ececf3);
  background: #ffffff;
  color: #475569;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  cursor: pointer;
  position: relative;
  transition: all 0.15s ease;
}
.top-notif-btn:hover {
  background: #f8fafc;
  color: #7c3aed;
  border-color: #cbd5e1;
}
.notif-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  background: #ef4444;
  color: #ffffff;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 5px;
  border-radius: 10px;
  border: 2px solid #ffffff;
  line-height: 1;
}
.notif-dropdown-menu {
  position: absolute;
  right: 0;
  top: calc(100% + 10px);
  width: 360px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  box-shadow: 0 12px 36px rgba(15, 23, 42, 0.14);
  z-index: 200;
  overflow: hidden;
}
.notif-dropdown-header {
  padding: 14px 18px;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  color: #0f172a;
  background: #fafbfe;
}
.notif-count-pill {
  font-size: 11px;
  font-weight: 700;
  background: #f3e8ff;
  color: #7c3aed;
  padding: 3px 8px;
  border-radius: 12px;
}
.notif-dropdown-body {
  max-height: 380px;
  overflow-y: auto;
}
.notif-item {
  display: flex;
  gap: 12px;
  padding: 12px 16px;
  border-bottom: 1px solid #f1f5f9;
  text-decoration: none;
  color: inherit;
  transition: background 0.15s ease;
}
.notif-item:hover {
  background: #f8fafc;
}
.notif-icon {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.notif-content {
  flex: 1;
}
.notif-item-title {
  font-size: 12px;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 2px;
}
.notif-item-msg {
  font-size: 11px;
  color: #64748b;
  line-height: 1.4;
}
.notif-item-time {
  font-size: 10px;
  color: #94a3b8;
  margin-top: 4px;
  font-weight: 600;
}
.notif-empty {
  padding: 24px;
  text-align: center;
  color: #94a3b8;
  font-size: 12px;
}
.notif-dropdown-footer {
  padding: 10px;
  text-align: center;
  border-top: 1px solid #f1f5f9;
  background: #fafbfe;
  font-size: 12px;
  font-weight: 600;
}
.notif-dropdown-footer a {
  color: #7c3aed;
  text-decoration: none;
}
</style>
</head><body><div class="app"><aside class="sidebar"><a class="brand-icon" href="dashboard.php" aria-label="<?=htmlspecialchars($brand['name'])?>"><?php if($brand['logo_path']):?><img src="<?=htmlspecialchars($brand['logo_path'])?>" alt=""><?php else:?><i class="fa-solid fa-cube"></i><?php endif?></a><nav class="side-nav"><?php foreach($nav as [$key,$href,$icon,$label,$tooltip]):?><a class="side-btn <?=$activePage===$key?'active':''?>" href="<?=$href?>" title="<?=$label?>" data-tooltip="<?=htmlspecialchars($tooltip)?>"><i class="fa-solid <?=$icon?>"></i></a><?php endforeach?></nav><div class="side-bottom"><a class="side-btn" href="logout.php" title="Logout" data-tooltip="Logout — Exit Session"><i class="fa-solid fa-right-from-bracket"></i></a></div></aside><div class="main"><header class="topbar"><a class="logo" href="dashboard.php"><?=htmlspecialchars($brand['name'])?></a><form class="global-search" action="candidates.php" method="get" role="search"><button type="submit" aria-label="Search candidates"><i class="fa-solid fa-magnifying-glass"></i></button><input id="globalSearch" name="q" value="<?=htmlspecialchars(trim((string)($_GET['q']??'')))?>" placeholder="Name or skills, comma separated" autocomplete="off"></form><nav class="mobile-nav"><?php foreach($nav as [$key,$href,$icon,$label,$tooltip]):?><a class="<?=$activePage===$key?'active':''?>" href="<?=$href?>"><?=$label?></a><?php endforeach?></nav><div class="top-actions">
  <div class="top-notif-dropdown-wrap" style="position: relative;">
    <button class="top-notif-btn" id="notifBellBtn" type="button" aria-label="Notifications" title="Real-time Activity Notifications">
      <i class="fa-regular fa-bell"></i>
      <?php if ($headerUnreadCount > 0): ?>
        <span class="notif-badge"><?=$headerUnreadCount?></span>
      <?php endif; ?>
    </button>

    <div class="notif-dropdown-menu" id="notifDropdownMenu" style="display: none;">
      <div class="notif-dropdown-header">
        <strong>Notifications & Activity</strong>
        <span class="notif-count-pill"><?=$headerUnreadCount?> Active</span>
      </div>
      <div class="notif-dropdown-body">
        <?php if (empty($headerNotifications)): ?>
          <div class="notif-empty">No recent notifications.</div>
        <?php else: ?>
          <?php foreach ($headerNotifications as $n): ?>
            <a class="notif-item" href="<?=htmlspecialchars($n['url'])?>">
              <div class="notif-icon" style="background: <?=$n['color']?>15; color: <?=$n['color']?>;">
                <i class="fa-solid <?=htmlspecialchars($n['icon'])?>"></i>
              </div>
              <div class="notif-content">
                <div class="notif-item-title"><?=htmlspecialchars($n['title'])?></div>
                <div class="notif-item-msg"><?=htmlspecialchars($n['message'])?></div>
                <div class="notif-item-time"><?=relativeTimeAgo($n['at'])?></div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="notif-dropdown-footer">
        <a href="email_batch_history.php">View All Dispatches & Logs &rarr;</a>
      </div>
    </div>
  </div>

  <a class="top-wishlist" href="wishlist.php" title="<?=$profileCount?> wishlist profiles"><i class="fa-regular fa-heart"></i><span><?=$wishlistCount?></span></a><a class="account-chip" href="settings.php"><span class="avatar"><?=htmlspecialchars(strtoupper(substr($headerUser['name']??'RI',0,2)))?></span><span class="account-copy"><strong><?=htmlspecialchars($headerUser['name']??'Profile')?></strong><small>Profile & settings</small></span></a></div></header><main class="content">

<script>
document.addEventListener('DOMContentLoaded', function() {
  const btn = document.getElementById('notifBellBtn');
  const menu = document.getElementById('notifDropdownMenu');
  if (!btn || !menu) return;

  btn.addEventListener('click', function(e) {
    e.stopPropagation();
    const isVisible = menu.style.display === 'block';
    menu.style.display = isVisible ? 'none' : 'block';
  });

  document.addEventListener('click', function(e) {
    if (!menu.contains(e.target) && e.target !== btn) {
      menu.style.display = 'none';
    }
  });
});
</script>

