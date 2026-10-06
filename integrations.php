<?php
require_once __DIR__ . '/lib/auth.php';
requireAuth();
require_once __DIR__ . '/lib/integrations.php';
require_once __DIR__ . '/lib/ai.php';

$message = '';
$error = '';

$providers = ['gemini' => 'Gemini', 'grok' => 'Grok', 'openai' => 'OpenAI', 'openrouter' => 'OpenRouter'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf'] ?? '')) {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'google') {
            $old = integrationConfig('google_drive');
            $client = trim($_POST['client_id'] ?? '');
            $secret = trim($_POST['client_secret'] ?? '');
            if (!$client) {
                throw new RuntimeException('Google client ID is required.');
            }
            $config = [
                'client_id' => $client,
                'client_secret' => $secret !== '' ? encryptSecret($secret) : ($old['client_secret'] ?? ''),
                'redirect_uri' => 'http://127.0.0.1:8001/drive_callback.php'
            ];
            saveIntegration('google_drive', 'configured', $config);
            $message = 'Google OAuth credentials saved securely.';
        } elseif ($action === 'ai') {
            $order = array_values(array_intersect(array_keys($providers), $_POST['fallback_order'] ?? []));
            if (!$order) {
                throw new RuntimeException('Choose at least one AI provider.');
            }
            foreach ($providers as $key => $label) {
                $old = integrationConfig('ai_' . $key);
                $apiKey = trim($_POST['keys'][$key] ?? '');
                $model = trim($_POST['models'][$key] ?? '');
                $enabled = in_array($key, $order, true);
                $config = [
                    'api_key' => $apiKey !== '' ? encryptSecret($apiKey) : ($old['api_key'] ?? ''),
                    'model' => $model,
                    'enabled' => $enabled
                ];
                saveIntegration('ai_' . $key, $enabled && !empty($config['api_key']) ? 'configured' : 'disconnected', $config);
            }
            saveIntegration('ai_fallback', 'configured', ['order' => $order]);
            $message = 'AI providers and fallback order saved.';
        } elseif ($action === 'external_api') {
            $name = trim($_POST['source_name'] ?? '');
            $url = trim($_POST['base_url'] ?? '');
            $auth = $_POST['auth_type'] ?? 'basic';
            if (!$name || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new RuntimeException('Enter a valid source name and HTTP/HTTPS URL.');
            }
            $old = integrationConfig('external_source');
            $password = trim($_POST['password'] ?? '');
            $token = trim($_POST['token'] ?? '');
            saveIntegration('external_source', 'configured', [
                'name' => $name,
                'base_url' => $url,
                'auth_type' => $auth,
                'username' => trim($_POST['username'] ?? ''),
                'password' => $password !== '' ? encryptSecret($password) : ($old['password'] ?? ''),
                'token' => $token !== '' ? encryptSecret($token) : ($old['token'] ?? ''),
                'data_path' => trim($_POST['data_path'] ?? '')
            ]);
            $message = 'External data API saved securely.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$google = integrationConfig('google_drive');
$fallback = integrationConfig('ai_fallback');
$order = $fallback['order'] ?? array_keys($providers);
$aiConfigs = [];
foreach ($providers as $key => $label) {
    $aiConfigs[$key] = integrationConfig('ai_' . $key);
}
$source = integrationConfig('external_source');
$emailApi = integrationConfig('email_api');

$activePage = 'integrations';
$pageTitle = 'Integrations & Credentials · NonceBlox ATS';

$pageStyles = '<style>
.integrations-container {
  width: 100%;
}
.integrations-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}
.integrations-head h1 {
  font-size: 24px;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.integrations-head p {
  color: #64748b;
  font-size: 13px;
  margin: 4px 0 0;
}

.integration-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
  margin-bottom: 24px;
}
.integration-grid-2 .full-span {
  grid-column: 1 / -1;
}
@media (max-width: 900px) {
  .integration-grid-2 { grid-template-columns: 1fr; }
  .integration-grid-2 .full-span { grid-column: auto; }
}

.integration-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.card-header-flex {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}
.card-title-text {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 8px;
}
.card-subtitle-text {
  font-size: 12px;
  color: #64748b;
  margin-bottom: 20px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f1f5f9;
  line-height: 1.5;
}

.status-badge-configured {
  background: #dcfce7;
  color: #15803d;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 12px;
  text-transform: uppercase;
}
.status-badge-saved {
  background: #e0e7ff;
  color: #4338ca;
  font-size: 10px;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 4px;
  margin-left: 4px;
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
  text-decoration: none;
}
.btn-purple:hover { background: #6d28d9; }

.btn-secondary {
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #cbd5e1;
  padding: 9px 16px;
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

.provider-drag-row {
  display: grid;
  grid-template-columns: 36px 120px 1fr 1fr;
  gap: 12px;
  align-items: center;
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 10px;
  cursor: grab;
}
.provider-drag-row:active { cursor: grabbing; }
.provider-badge-num {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: #7c3aed;
  color: #fff;
  font-weight: 700;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

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
</style>';

require __DIR__ . '/views/partials/header.php';
?>

<div class="integrations-container">
  <div class="integrations-head">
    <div>
      <h1><i class="fa-solid fa-plug" style="color: #7c3aed;"></i> Integrations & API Credentials</h1>
      <p>Configure credentials once. All sensitive tokens and keys are encrypted server-side.</p>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="alert-ok"><i class="fa-solid fa-circle-check"></i> <?=htmlspecialchars($message)?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert-err"><i class="fa-solid fa-circle-exclamation"></i> <?=htmlspecialchars($error)?></div>
  <?php endif; ?>

  <div class="integration-grid-2">
    <!-- NonceBlox Email API Card -->
    <article class="integration-card full-span">
      <div class="card-header-flex">
        <div class="card-title-text"><i class="fa-solid fa-paper-plane" style="color: #7c3aed;"></i> NonceBlox Email API</div>
        <?php if (($emailApi['status'] ?? '') === 'configured'): ?>
          <span class="status-badge-configured">Configured</span>
        <?php endif; ?>
      </div>
      <div class="card-subtitle-text">
        Send controlled HTML interview & outreach invitations through the NonceBlox multi-recipient email service endpoint. An API key is optional and stored encrypted.
      </div>
      <form method="post" action="integration_config_save.php">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="type" value="email_api">
        <div class="form-grid-2">
          <div class="full-width">
            <label class="form-label">Email API URL</label>
            <input class="form-input" type="url" name="url" required value="<?=htmlspecialchars($emailApi['url'] ?? 'https://hrms-api.nonceblox.com/api/emails/send-multi-recipient')?>">
          </div>
          <div class="full-width">
            <label class="form-label">Bearer API Key (Optional) <?=!empty($emailApi['api_key']) ? '<span class="status-badge-saved">Saved</span>' : ''?></label>
            <input class="form-input" type="password" name="api_key" placeholder="<?=!empty($emailApi['api_key']) ? 'Leave blank to keep saved key' : 'Only if the API requires authentication'?>">
          </div>
        </div>
        <div style="margin-top: 18px; display: flex; gap: 10px;">
          <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save Email API Config</button>
          <a class="btn-secondary" href="interview_invite.php?job_id=<?=htmlspecialchars((string)(db()->query('SELECT id FROM jobs ORDER BY id LIMIT 1')->fetchColumn() ?: 0))?>"><i class="fa-solid fa-envelope-open-text"></i> Open Interview Invitations</a>
        </div>
      </form>
    </article>

    <!-- Google Drive OAuth Card -->
    <article class="integration-card">
      <div class="card-header-flex">
        <div class="card-title-text"><i class="fa-brands fa-google-drive" style="color: #059669;"></i> Google Drive OAuth</div>
        <?php if (!empty($google['client_secret'])): ?>
          <span class="status-badge-configured">Configured</span>
        <?php endif; ?>
      </div>
      <div class="card-subtitle-text">
        OAuth credentials for candidate resume file imports from Google Drive.
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="action" value="google">
        <div class="form-grid-2">
          <div class="full-width">
            <label class="form-label">Client ID</label>
            <input class="form-input" name="client_id" value="<?=htmlspecialchars($google['client_id'] ?? '')?>" required>
          </div>
          <div class="full-width">
            <label class="form-label">Client Secret</label>
            <input class="form-input" type="password" name="client_secret" placeholder="<?=!empty($google['client_secret']) ? 'Saved — leave blank to keep' : 'Enter client secret'?>">
          </div>
          <div class="full-width">
            <label class="form-label">Redirect URI</label>
            <input class="form-input" value="http://127.0.0.1:8001/drive_callback.php" readonly style="background: #f8fafc;">
          </div>
        </div>
        <div style="margin-top: 18px; display: flex; gap: 10px;">
          <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save Google Config</button>
          <?php if (!empty($google['client_secret'])): ?>
            <a class="btn-secondary" href="drive_connect.php"><i class="fa-brands fa-google-drive"></i> Connect Drive</a>
          <?php endif; ?>
        </div>
      </form>
    </article>

    <!-- External Data API Card -->
    <article class="integration-card">
      <div class="card-header-flex">
        <div class="card-title-text"><i class="fa-solid fa-cloud-arrow-down" style="color: #2563eb;"></i> External ATS / Data API</div>
        <?php if (($source['status'] ?? '') === 'configured'): ?>
          <span class="status-badge-configured">Configured</span>
        <?php endif; ?>
      </div>
      <div class="card-subtitle-text">
        Connect external candidate source APIs via Basic Auth, Bearer token, or API Key.
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="action" value="external_api">
        <div class="form-grid-2">
          <div>
            <label class="form-label">Source Name</label>
            <input class="form-input" name="source_name" value="<?=htmlspecialchars($source['name'] ?? '')?>" placeholder="e.g. NonceBlox Careers">
          </div>
          <div>
            <label class="form-label">Authentication Type</label>
            <select class="form-input" name="auth_type">
              <option value="basic">Username + Password</option>
              <option value="bearer" <?=($source['auth_type'] ?? '') === 'bearer' ? 'selected' : ''?>>Bearer Token</option>
              <option value="api_key" <?=($source['auth_type'] ?? '') === 'api_key' ? 'selected' : ''?>>API Key</option>
            </select>
          </div>
          <div class="full-width">
            <label class="form-label">Base API URL</label>
            <input class="form-input" type="url" name="base_url" value="<?=htmlspecialchars($source['base_url'] ?? '')?>" placeholder="https://api.example.com/candidates">
          </div>
          <div>
            <label class="form-label">Username</label>
            <input class="form-input" name="username" value="<?=htmlspecialchars($source['username'] ?? '')?>">
          </div>
          <div>
            <label class="form-label">Password</label>
            <input class="form-input" type="password" name="password" placeholder="<?=!empty($source['password']) ? 'Saved — leave blank' : 'Password'?>">
          </div>
          <div>
            <label class="form-label">Token / API Key</label>
            <input class="form-input" type="password" name="token" placeholder="<?=!empty($source['token']) ? 'Saved — leave blank' : 'Optional Token'?>">
          </div>
          <div>
            <label class="form-label">Candidate Data Path</label>
            <input class="form-input" name="data_path" value="<?=htmlspecialchars($source['data_path'] ?? '')?>" placeholder="e.g. data.candidates">
          </div>
        </div>
        <div style="margin-top: 18px;">
          <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save API Source</button>
        </div>
      </form>
    </article>

    <!-- AI Provider Fallback Chain Card -->
    <article class="integration-card full-span">
      <div class="card-header-flex">
        <div class="card-title-text"><i class="fa-solid fa-brain" style="color: #7c3aed;"></i> AI Provider Fallback Priority Chain</div>
      </div>
      <div class="card-subtitle-text">
        Drag rows to re-order priority. If provider 1 fails, processing automatically falls back to 2, then 3. All API keys are stored encrypted.
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="action" value="ai">
        
        <?php foreach ($order as $i => $key): if (!isset($providers[$key])) continue; $cfg = $aiConfigs[$key]; ?>
          <div class="provider-drag-row" draggable="true">
            <span class="provider-badge-num badge"><?=$i + 1?></span>
            <strong style="font-size: 13px; color: #0f172a;"><?=$providers[$key]?></strong>
            <input type="hidden" name="fallback_order[]" value="<?=$key?>">
            <div>
              <label class="form-label" style="margin-bottom: 2px;">
                API Key <?=!empty($cfg['api_key']) ? '<span class="status-badge-saved">Saved</span>' : ''?>
              </label>
              <input class="form-input" type="password" name="keys[<?=$key?>]" placeholder="<?=!empty($cfg['api_key']) ? 'Leave blank to keep saved key' : 'Enter API Key'?>">
            </div>
            <div>
              <label class="form-label" style="margin-bottom: 2px;">Model ID</label>
              <input class="form-input" name="models[<?=$key?>]" value="<?=htmlspecialchars($cfg['model'] ?? '')?>" placeholder="Provider model ID">
            </div>
          </div>
        <?php endforeach; ?>

        <div style="margin-top: 18px;">
          <button type="submit" class="btn-purple"><i class="fa-solid fa-shield-halved"></i> Save AI Fallback Chain</button>
        </div>
      </form>
    </article>

    <!-- SMTP Delivery Card -->
    <article class="integration-card">
      <div class="card-header-flex">
        <div class="card-title-text"><i class="fa-solid fa-server" style="color: #d97706;"></i> SMTP Email Delivery</div>
      </div>
      <div class="card-subtitle-text">
        Configure custom socket-based SMTP server dispatches. Credentials are encrypted server-side.
      </div>
      <form method="post" action="integration_config_save.php">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="type" value="smtp">
        <div class="form-grid-2">
          <div>
            <label class="form-label">SMTP Host</label>
            <input class="form-input" name="host" required placeholder="smtp.example.com">
          </div>
          <div>
            <label class="form-label">Port</label>
            <input class="form-input" type="number" name="port" value="587">
          </div>
          <div>
            <label class="form-label">Username</label>
            <input class="form-input" name="username">
          </div>
          <div>
            <label class="form-label">Password</label>
            <input class="form-input" type="password" name="password">
          </div>
          <div>
            <label class="form-label">Encryption</label>
            <select class="form-input" name="encryption">
              <option value="tls">TLS</option>
              <option value="ssl">SSL</option>
              <option value="none">None</option>
            </select>
          </div>
          <div>
            <label class="form-label">From Email</label>
            <input class="form-input" type="email" name="from_email" placeholder="hr@nonceblox.com">
          </div>
        </div>
        <div style="margin-top: 18px; display: flex; gap: 10px;">
          <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save SMTP Settings</button>
          <a class="btn-secondary" href="wishlist.php">Email Preview</a>
        </div>
      </form>
    </article>

    <!-- NonceBlox MySQL Source Card -->
    <article class="integration-card">
      <div class="card-header-flex">
        <div class="card-title-text"><i class="fa-solid fa-database" style="color: #0284c7;"></i> NonceBlox MySQL Source</div>
      </div>
      <div class="card-subtitle-text">
        Save remote MySQL career applicants database source securely.
      </div>
      <form method="post" action="integration_config_save.php">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="type" value="nonceblox_mysql">
        <div class="form-grid-2">
          <div class="full-width">
            <label class="form-label">Database Host</label>
            <input class="form-input" name="host" placeholder="MySQL Hostname" required>
          </div>
          <div>
            <label class="form-label">Port</label>
            <input class="form-input" type="number" name="port" value="3306">
          </div>
          <div>
            <label class="form-label">Database Name</label>
            <input class="form-input" name="database" value="u157534802_Nonceblox" required>
          </div>
          <div>
            <label class="form-label">Username</label>
            <input class="form-input" name="username" required>
          </div>
          <div>
            <label class="form-label">Password</label>
            <input class="form-input" type="password" name="password" required>
          </div>
        </div>
        <div style="margin-top: 18px; display: flex; gap: 10px;">
          <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save Database Source</button>
          <a class="btn-secondary" href="source_sync.php"><i class="fa-solid fa-eye"></i> Preview Source</a>
        </div>
      </form>
    </article>

    <!-- Gemini OAuth Credentials Card -->
    <article class="integration-card full-span">
      <div class="card-header-flex">
        <div class="card-title-text"><i class="fa-solid fa-key" style="color: #7c3aed;"></i> Gemini OAuth & Service Credentials</div>
      </div>
      <div class="card-subtitle-text">
        Gemini API Key and Google Client ID/Secret credentials for direct structured LLM calls.
      </div>
      <form method="post" action="integration_config_save.php">
        <input type="hidden" name="csrf" value="<?=csrfToken()?>">
        <input type="hidden" name="type" value="gemini_oauth">
        <div class="form-grid-2">
          <div>
            <label class="form-label">Google Client ID</label>
            <input class="form-input" name="client_id">
          </div>
          <div>
            <label class="form-label">Google Client Secret</label>
            <input class="form-input" type="password" name="client_secret">
          </div>
          <div>
            <label class="form-label">Gemini API Key</label>
            <input class="form-input" type="password" name="api_key">
          </div>
          <div>
            <label class="form-label">Gemini Model</label>
            <input class="form-input" name="model" placeholder="e.g. gemini-1.5-pro">
          </div>
        </div>
        <div style="margin-top: 18px;">
          <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save Gemini Credentials</button>
        </div>
      </form>
    </article>
  </div>
</div>

<script>
// JS Drag and Drop Handler for AI Provider Priority Chain
const providerRows = [...document.querySelectorAll('.provider-drag-row')];
providerRows.forEach(row => {
  row.title = 'Drag to change fallback priority';
  row.addEventListener('dragstart', () => row.classList.add('dragging'));
  row.addEventListener('dragend', () => {
    row.classList.remove('dragging');
    [...document.querySelectorAll('.provider-drag-row .badge')].forEach((b, i) => b.textContent = i + 1);
  });
  row.addEventListener('dragover', event => {
    event.preventDefault();
    const dragging = document.querySelector('.provider-drag-row.dragging');
    if (!dragging || dragging === row) return;
    const box = row.getBoundingClientRect();
    row.parentNode.insertBefore(dragging, event.clientY < box.top + box.height / 2 ? row : row.nextSibling);
  });
});

// Private Source Toggle Script
document.querySelectorAll('.integration-card').forEach(card => {
  const title = card.querySelector('.card-title-text');
  if (!title || !title.textContent.includes('NonceBlox MySQL source')) return;
  const form = card.querySelector('form');
  if (!form) return;
  form.hidden = true;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn-secondary';
  button.style.marginBottom = '14px';
  button.innerHTML = '<i class="fa-solid fa-lock"></i> Configure Private Source';
  button.onclick = () => {
    form.hidden = !form.hidden;
    button.innerHTML = form.hidden ? '<i class="fa-solid fa-lock"></i> Configure Private Source' : '<i class="fa-solid fa-lock-open"></i> Hide Configuration';
  };
  card.insertBefore(button, form);
});
</script>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
