<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/branding.php';

$brand = organizationBrand();
$pdo = db();
$user = currentUser();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'profile') {
            $name = trim($_POST['name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid name and email.');
            }
            $s = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>?');
            $s->execute([$email, $user['id']]);
            if ($s->fetchColumn()) {
                throw new RuntimeException('That email is already in use.');
            }
            $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name, $email, $user['id']]);
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;
            $user = currentUser();
            $message = 'Profile updated successfully.';
        } elseif ($action === 'password') {
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $s = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
            $s->execute([$user['id']]);
            $hash = $s->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) {
                throw new RuntimeException('Current password is incorrect.');
            }
            if (strlen($new) < 8) {
                throw new RuntimeException('New password must contain at least 8 characters.');
            }
            if ($new !== $confirm) {
                throw new RuntimeException('New passwords do not match.');
            }
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            $message = 'Password changed successfully.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stats = $pdo->query("SELECT (SELECT COUNT(*) FROM candidates) candidates,(SELECT COUNT(*) FROM wishlist_profiles) profiles,(SELECT COUNT(*) FROM email_batches) emails")->fetch();

$activePage = 'settings';
$pageTitle = 'Workspace Settings · NonceBlox ATS';

$pageStyles = '<style>
.settings-container {
  max-width: 1200px;
  margin: 0 auto;
}
.settings-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}
.settings-head h1 {
  font-size: 24px;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.settings-head p {
  color: #64748b;
  font-size: 13px;
  margin: 4px 0 0;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 24px;
}
.stat-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 18px 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: #f1f5f9;
  color: #7c3aed;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
}
.stat-info .num {
  font-size: 22px;
  font-weight: 800;
  color: #0f172a;
}
.stat-info .lbl {
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
}

.settings-layout {
  display: grid;
  grid-template-columns: 240px minmax(0, 1fr);
  gap: 24px;
}
@media (max-width: 900px) {
  .settings-layout { grid-template-columns: 1fr; }
  .stats-grid { grid-template-columns: 1fr; }
}

.settings-sidebar {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 12px;
  height: fit-content;
  position: sticky;
  top: 20px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.settings-nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 8px;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.15s ease;
  margin-bottom: 4px;
}
.settings-nav-item:hover, .settings-nav-item.active {
  background: #f3e8ff;
  color: #7c3aed;
}

.settings-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 24px;
  margin-bottom: 24px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.card-header-title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.card-header-sub {
  font-size: 12px;
  color: #64748b;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}
.form-grid-2 .full-width {
  grid-column: 1 / -1;
}
@media (max-width: 600px) {
  .form-grid-2 { grid-template-columns: 1fr; }
  .form-grid-2 .full-width { grid-column: auto; }
}

.form-label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: #334155;
  margin-bottom: 6px;
}
.form-input {
  width: 100%;
  padding: 10px 14px;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 13px;
  color: #0f172a;
  background: #fff;
  transition: all 0.15s ease;
}
.form-input:focus {
  outline: none;
  border-color: #7c3aed;
  box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.12);
}

.btn-purple {
  background: #7c3aed;
  color: #ffffff;
  border: 0;
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: background 0.15s;
}
.btn-purple:hover { background: #6d28d9; }

.btn-secondary {
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #cbd5e1;
  padding: 10px 18px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: background 0.15s;
}
.btn-secondary:hover { background: #e2e8f0; }

.alert-ok, .alert-err {
  padding: 12px 16px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.alert-ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.alert-err { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

.backup-banner {
  background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%);
  border: 1px solid #e9d5ff;
  border-radius: 10px;
  padding: 18px 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
}
.backup-banner-copy strong {
  display: block;
  font-size: 14px;
  color: #581c87;
  margin-bottom: 4px;
}
.backup-banner-copy span {
  font-size: 12px;
  color: #7e22ce;
}

.brand-preview-wrap {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 10px;
  padding: 14px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
.brand-preview-logo {
  max-height: 48px;
  max-width: 140px;
  object-fit: contain;
}
</style>';

require __DIR__ . '/views/partials/header.php';
?>

<div class="settings-container">
  <div class="settings-head">
    <div>
      <h1><i class="fa-solid fa-sliders" style="color: #7c3aed;"></i> Workspace Settings</h1>
      <p>Manage recruiter profile, organization branding, passwords, integrations, and database backups.</p>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert-ok"><i class="fa-solid fa-circle-check"></i> <?=htmlspecialchars($message)?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert-err"><i class="fa-solid fa-circle-exclamation"></i> <?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <?php if (isset($_GET['email_testing'])): ?>
    <div class="alert-ok"><i class="fa-solid fa-circle-check"></i> Email testing settings saved cleanly.</div>
  <?php endif; ?>
  <?php if (isset($_GET['invite_reset'])): ?>
    <div class="alert-ok"><i class="fa-solid fa-circle-check"></i> <?=(int)$_GET['invite_reset']?> candidate locks reset cleanly. Email history preserved.</div>
  <?php endif; ?>

  <!-- Stats Grid -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
      <div class="stat-info">
        <div class="num"><?=(int)$stats['candidates']?></div>
        <div class="lbl">Candidates Registered</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-heart"></i></div>
      <div class="stat-info">
        <div class="num"><?=(int)$stats['profiles']?></div>
        <div class="lbl">Wishlist Profiles</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fa-solid fa-envelope"></i></div>
      <div class="stat-info">
        <div class="num"><?=(int)$stats['emails']?></div>
        <div class="lbl">Email Batches Sent</div>
      </div>
    </div>
  </div>

  <div class="settings-layout">
    <!-- Sidebar Navigation -->
    <aside class="settings-sidebar">
      <a href="#profile" class="settings-nav-item active"><i class="fa-regular fa-user"></i> Recruiter Profile</a>
      <a href="#security" class="settings-nav-item"><i class="fa-solid fa-lock"></i> Change Password</a>
      <a href="#branding" class="settings-nav-item"><i class="fa-solid fa-brush"></i> Organization Branding</a>
      <a href="#integrations" class="settings-nav-item"><i class="fa-solid fa-plug"></i> Integrations & APIs</a>
      <a href="#backup" class="settings-nav-item"><i class="fa-solid fa-database"></i> Database Backup</a>
      <a href="#email-testing" class="settings-nav-item"><i class="fa-solid fa-vial"></i> Email Testing</a>
    </aside>

    <!-- Main Content Panels -->
    <main class="settings-main-content">
      <!-- Profile Card -->
      <section class="settings-card" id="profile">
        <div class="card-header-title"><i class="fa-regular fa-user" style="color: #7c3aed;"></i> Recruiter Profile</div>
        <div class="card-header-sub">Your recruiter name and email are associated with all interview dispatches and workspace audits.</div>
        
        <form method="post">
          <input type="hidden" name="csrf" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="profile">
          <div class="form-grid-2">
            <div>
              <label class="form-label">Recruiter Name</label>
              <input class="form-input" name="name" value="<?=htmlspecialchars($user['name'])?>" required>
            </div>
            <div>
              <label class="form-label">Email Address</label>
              <input class="form-input" type="email" name="email" value="<?=htmlspecialchars($user['email'])?>" required>
            </div>
          </div>
          <div style="margin-top: 18px;">
            <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save Profile</button>
          </div>
        </form>
      </section>

      <!-- Security / Change Password Card -->
      <section class="settings-card" id="security">
        <div class="card-header-title"><i class="fa-solid fa-lock" style="color: #7c3aed;"></i> Change Password</div>
        <div class="card-header-sub">Require your current password before creating a new password.</div>
        
        <form method="post">
          <input type="hidden" name="csrf" value="<?=csrfToken()?>">
          <input type="hidden" name="action" value="password">
          <div class="form-grid-2">
            <div class="full-width">
              <label class="form-label">Current Password</label>
              <input class="form-input" type="password" name="current_password" required autocomplete="current-password">
            </div>
            <div>
              <label class="form-label">New Password</label>
              <input class="form-input" type="password" name="new_password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters">
            </div>
            <div>
              <label class="form-label">Confirm New Password</label>
              <input class="form-input" type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
            </div>
          </div>
          <div style="margin-top: 18px;">
            <button type="submit" class="btn-purple"><i class="fa-solid fa-key"></i> Update Password</button>
          </div>
        </form>
      </section>

      <!-- Organization Branding Card -->
      <section class="settings-card" id="branding">
        <div class="card-header-title"><i class="fa-solid fa-brush" style="color: #7c3aed;"></i> Organization Branding</div>
        <div class="card-header-sub">Global workspace name, domain, and logo image used in email campaigns and header navigation.</div>
        
        <form method="post" action="branding_save.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?=csrfToken()?>">
          <div class="form-grid-2">
            <div>
              <label class="form-label">Organization Name</label>
              <input class="form-input" name="organization_name" value="<?=htmlspecialchars($brand['name'])?>" required>
            </div>
            <div>
              <label class="form-label">Organization Domain</label>
              <input class="form-input" name="organization_domain" value="<?=htmlspecialchars((function(){try{return db()->query('SELECT domain FROM organization_settings WHERE id=1')->fetchColumn()?:'';}catch(Throwable $e){return '';}})())?>" placeholder="nonceblox.com">
            </div>
            <div class="full-width">
              <label class="form-label">Upload Brand Logo (PNG, JPG, WebP — max 2 MB)</label>
              <input class="form-input" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
              <?php if (!empty($brand['logo_path'])): ?>
                <div class="brand-preview-wrap">
                  <img src="<?=htmlspecialchars($brand['logo_path'])?>" alt="Brand Logo" class="brand-preview-logo">
                  <span style="font-size: 12px; color: #64748b;">Current uploaded organization logo</span>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div style="margin-top: 18px;">
            <button type="submit" class="btn-purple"><i class="fa-solid fa-image"></i> Save Branding Settings</button>
          </div>
        </form>
      </section>

      <!-- Integrations & APIs Link -->
      <section class="settings-card" id="integrations">
        <div class="card-header-title"><i class="fa-solid fa-plug" style="color: #7c3aed;"></i> Integrations & Secure Credentials</div>
        <div class="card-header-sub">Configure Email APIs, SMTP credentials, Google Drive OAuth, AI provider keys, and remote MySQL sources.</div>
        <div>
          <a class="btn-secondary" href="integrations.php"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open Integrations Management Page &rarr;</a>
        </div>
      </section>

      <!-- Database Backup Card -->
      <section class="settings-card" id="backup">
        <div class="card-header-title"><i class="fa-solid fa-database" style="color: #7c3aed;"></i> Database Backup</div>
        <div class="card-header-sub">Download a complete SQL dump of candidate data, jobs, email logs, wishlists, and settings.</div>
        
        <div class="backup-banner">
          <div class="backup-banner-copy">
            <strong><?=htmlspecialchars($brand['name'])?> MySQL Database Backup</strong>
            <span>Contains candidates, jobs, wishlists, email batches, and integration configs. Store securely.</span>
          </div>
          <form method="post" action="backup_database.php" style="margin: 0;">
            <input type="hidden" name="csrf" value="<?=csrfToken()?>">
            <button type="submit" class="btn-purple" style="white-space: nowrap;"><i class="fa-solid fa-download"></i> Download SQL Backup</button>
          </form>
        </div>
      </section>

      <!-- Email Testing & Reset Card -->
      <?php
      require_once __DIR__ . '/lib/integrations.php';
      $emailTesting = integrationConfig('email_testing');
      $settingsOwnerId = (int)db()->query('SELECT MIN(id) FROM users')->fetchColumn();
      $canControlTesting = ((int)$user['id'] === $settingsOwnerId);
      ?>

      <?php if ($canControlTesting): ?>
        <section class="settings-card" id="email-testing">
          <div class="card-header-title"><i class="fa-solid fa-vial" style="color: #7c3aed;"></i> Email Testing & Candidate Lock Reset</div>
          <div class="card-header-sub">Send a copy of all test emails to a dedicated testing inbox or reset locked interview candidates.</div>
          
          <form method="post" action="email_testing_settings.php">
            <input type="hidden" name="csrf" value="<?=csrfToken()?>">
            <input type="hidden" name="action" value="save">
            <div class="form-grid-2">
              <div>
                <label class="form-label">Test-Copy Recipient Email</label>
                <input class="form-input" type="email" name="test_copy_email" value="<?=htmlspecialchars($emailTesting['email'] ?? 'kishan.sharma@nonceblox.com')?>" required>
              </div>
              <div style="display: flex; align-items: flex-end; padding-bottom: 8px;">
                <label style="font-size: 13px; color: #334155; font-weight: 600; cursor: pointer;">
                  <input type="checkbox" name="test_copy_enabled" value="1" <?=!array_key_exists('enabled', $emailTesting) || !empty($emailTesting['enabled']) ? 'checked' : ''?>> 
                  Send duplicate copy of every interview email here
                </label>
              </div>
            </div>
            <div style="margin-top: 14px;">
              <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save Testing Settings</button>
            </div>
          </form>

          <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid #f1f5f9;">
            <form method="post" action="email_testing_settings.php" onsubmit="return confirm('Unlock all invited candidates for repeated testing? Email history will remain intact.')">
              <input type="hidden" name="csrf" value="<?=csrfToken()?>">
              <input type="hidden" name="action" value="reset_invites">
              <button type="submit" class="btn-secondary" style="color: #dc2626; border-color: #fca5a5;"><i class="fa-solid fa-rotate-left"></i> Reset All Invited Candidate Locks</button>
            </form>
          </div>
        </section>
      <?php endif; ?>

      <!-- Interview Scheduling Settings Partial Include -->
      <?php require __DIR__ . '/views/partials/interview_scheduling_settings.php'; ?>
    </main>
  </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
