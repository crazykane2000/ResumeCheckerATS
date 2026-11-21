<?php
/**
 * Shared recruiter stage-action modal (move stage / reject with reason / branded stage email).
 * Used by candidates.php and candidate_detail.php. Talks to pipeline_lifecycle_api.php.
 *
 * JS API:  StageAction.open(candidateId, {template: 'rejected', name: 'Jane', onDone: (stage, res) => {}})
 *
 * Email is only sent when the recruiter presses "Send email & move"; "Move only" never mails.
 */
?>
<style>
.sam-backdrop{position:fixed;inset:0;z-index:120;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.6);backdrop-filter:blur(3px)}
.sam-backdrop.open{display:flex}
.sam-card{width:100%;max-width:980px;max-height:94vh;display:flex;flex-direction:column;overflow:hidden;background:#fff;border-radius:12px;box-shadow:0 25px 50px -12px rgba(0,0,0,.28)}
.sam-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line,#e2e8f0)}
.sam-head h3{margin:0;font-size:16px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px}
.sam-head p{margin:3px 0 0;font-size:12px;color:#64748b}
.sam-x{border:0;background:transparent;font-size:18px;color:#64748b;cursor:pointer;padding:4px 8px}
.sam-body{display:grid;grid-template-columns:minmax(0,46%) minmax(0,54%);flex:1;min-height:0;overflow:hidden}
.sam-form{padding:16px 20px;overflow-y:auto;display:flex;flex-direction:column;gap:12px;border-right:1px solid var(--line,#e2e8f0)}
.sam-form label{display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:5px}
.sam-form .control,.sam-form textarea,.sam-form select,.sam-form input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px 10px;font:inherit;font-size:12.5px;color:#0f172a;background:#fff}
.sam-form textarea{resize:vertical;line-height:1.55}
.sam-form input[readonly]{background:#f8fafc;color:#64748b}
.sam-hint{font-size:10.5px;color:#64748b;margin-top:4px}
.sam-hint code{background:#f1f5f9;padding:0 4px;border-radius:4px;font-size:10px}
.sam-reject{padding:12px;border:1px solid #fecaca;border-radius:8px;background:#fff5f5}
.sam-reject label{color:#991b1b}
.sam-warn{padding:8px 10px;border-radius:6px;background:#fff7e6;border:1px solid #f5d08a;color:#8a5a00;font-size:11.5px;display:none}
.sam-eval{border:1px solid #ede9fe;border-radius:8px;background:#fbfaff;padding:10px 12px}
.sam-eval summary{cursor:pointer;font-size:12px;font-weight:700;color:#6f45ff}
.sam-eval .sam-grid2{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
.sam-preview{display:flex;flex-direction:column;min-height:0;background:#f8fafc}
.sam-preview-head{padding:9px 16px;background:#fff;border-bottom:1px solid var(--line,#e2e8f0);font-size:11.5px;font-weight:700;color:#64748b}
.sam-preview iframe{flex:1;width:100%;min-height:380px;border:0;background:#f8fafc}
.sam-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 20px;border-top:1px solid var(--line,#e2e8f0)}
.sam-foot .sam-right{display:flex;gap:8px;flex-wrap:wrap}
.sam-btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:12.5px;font-weight:700;cursor:pointer}
.sam-btn.primary{background:#6f45ff;border-color:#6f45ff;color:#fff}
.sam-btn.danger{background:#dc2626;border-color:#dc2626;color:#fff}
.sam-btn:disabled{opacity:.55;cursor:not-allowed}
@media(max-width:820px){.sam-body{grid-template-columns:1fr;overflow:auto}.sam-form{border-right:0;overflow:visible}.sam-preview iframe{min-height:320px}}
</style>

<div class="sam-backdrop" id="samModal" role="dialog" aria-modal="true" aria-labelledby="samTitle">
  <div class="sam-card">
    <div class="sam-head">
      <div>
        <h3 id="samTitle"><i class="fa-solid fa-arrows-turn-to-dots" style="color:#6f45ff"></i> <span>Move candidate</span></h3>
        <p id="samSubtitle">Loading…</p>
      </div>
      <button type="button" class="sam-x" onclick="StageAction.close()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="sam-body">
      <div class="sam-form">
        <div>
          <label for="samTemplate">Move to stage &amp; email template</label>
          <select id="samTemplate" onchange="StageAction.templateChanged()">
            <option value="screening" data-stage="Screening">Screening · Profile shortlisted</option>
            <option value="round1" data-stage="Interview">Interview · Invite to Round 1</option>
            <option value="round2" data-stage="Round 2">Round 2 · Invite to Round 2</option>
            <option value="offer" data-stage="Offer">Offer · Selection &amp; offer</option>
            <option value="rejected" data-stage="Rejected">Rejected · Not moving forward</option>
            <option value="welcome" data-stage="Applied">Applied · Application received</option>
          </select>
        </div>

        <div class="sam-reject" id="samRejectBox" hidden>
          <label for="samReason">Rejection reason (internal, shown on timeline) *</label>
          <select id="samReason"><option value="">Select a reason…</option></select>
          <label for="samReasonNote" style="margin-top:8px">Rejection note (internal)</label>
          <textarea id="samReasonNote" rows="2" placeholder="e.g. Strong React, but no production Solidity experience required for this role."></textarea>
          <div class="sam-hint">The reason is never included in the candidate email.</div>
        </div>

        <div class="sam-warn" id="samWarn"></div>

        <div>
          <label for="samEmail">Recipient email</label>
          <input type="email" id="samEmail" readonly>
        </div>
        <div>
          <label for="samSubject">Subject line</label>
          <input type="text" id="samSubject" oninput="StageAction.livePreview()">
        </div>
        <div>
          <label for="samBody">Email message</label>
          <textarea id="samBody" rows="7" oninput="StageAction.livePreview()"></textarea>
          <div class="sam-hint">Auto-filled: <code>[Candidate Name]</code> <code>[First Name]</code> <code>[Job Title]</code> <code>[Company Name]</code> (also <code>{{candidate_name}}</code> style).</div>
        </div>

        <details class="sam-eval" id="samEval">
          <summary><i class="fa-solid fa-clipboard-check"></i> Add interview evaluation note (optional)</summary>
          <label for="samEvalFeedback" style="margin-top:8px">What happened / round feedback ("Kya hua")</label>
          <textarea id="samEvalFeedback" rows="2"></textarea>
          <div class="sam-grid2">
            <div><label for="samEvalStrengths">Strengths ("Kya achha tha")</label><input type="text" id="samEvalStrengths"></div>
            <div><label for="samEvalRec">Recommendation</label>
              <select id="samEvalRec"><option value="Advance">Advance</option><option value="Hire">Strong hire</option><option value="Hold">Hold / discuss</option><option value="Reject">Reject</option></select>
            </div>
          </div>
        </details>
      </div>

      <div class="sam-preview">
        <div class="sam-preview-head"><i class="fa-solid fa-eye" style="color:#6f45ff"></i> Live branded preview</div>
        <iframe id="samPreview" title="Email preview" sandbox="allow-same-origin"></iframe>
      </div>
    </div>

    <div class="sam-foot">
      <button type="button" class="sam-btn" onclick="StageAction.close()">Cancel</button>
      <div class="sam-right">
        <button type="button" class="sam-btn" id="samMoveOnly" onclick="StageAction.submit(false)"><i class="fa-solid fa-arrows-turn-to-dots"></i> Move only (no email)</button>
        <button type="button" class="sam-btn primary" id="samSend" onclick="StageAction.submit(true)"><i class="fa-solid fa-paper-plane"></i> Send email &amp; move</button>
      </div>
    </div>
  </div>
</div>

<script>
window.StageAction = (() => {
  const CSRF = <?=json_encode(csrfToken())?>;
  const $ = id => document.getElementById(id);
  let state = { id: null, onDone: null, placeholders: {}, reasonsLoaded: false };

  const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fill = text => {
    const p = state.placeholders || {};
    const first = String(p.candidate_name || '').trim().split(/\s+/)[0] || '';
    const map = {
      candidate_name: ['candidate name','candidate_name','name','candidate'],
      first_name: ['first name','first_name'],
      job_title: ['job title','job_title','position','role','job','position name','role name'],
      company_name: ['company name','company_name','company','organization','organisation']
    };
    const values = { ...p, first_name: first };
    let out = String(text ?? '');
    for (const [key, aliases] of Object.entries(map)) {
      const value = String(values[key] || '').trim();
      if (!value) continue;
      for (const a of aliases) {
        const q = a.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        out = out.replace(new RegExp('(\\{\\{\\s*' + q + '\\s*\\}\\}|\\[\\s*' + q + '\\s*\\]|\\{\\s*' + q + '\\s*\\})', 'gi'), () => value);
      }
    }
    return out;
  };
  const selectedStage = () => $('samTemplate').selectedOptions[0]?.dataset.stage || 'Interview';

  function syncRejectUi() {
    const rejecting = selectedStage() === 'Rejected';
    $('samRejectBox').hidden = !rejecting;
    $('samSend').classList.toggle('danger', rejecting);
    $('samSend').classList.toggle('primary', !rejecting);
    $('samSend').innerHTML = rejecting
      ? '<i class="fa-solid fa-paper-plane"></i> Reject &amp; send rejection email'
      : '<i class="fa-solid fa-paper-plane"></i> Send email &amp; move';
    $('samMoveOnly').innerHTML = rejecting
      ? '<i class="fa-solid fa-ban"></i> Reject only (no email)'
      : '<i class="fa-solid fa-arrows-turn-to-dots"></i> Move only (no email)';
    $('samTitle').querySelector('span').textContent = 'Move to ' + selectedStage();
  }

  async function loadPreview() {
    const tpl = $('samTemplate').value;
    const res = await fetch(`pipeline_lifecycle_api.php?action=get_preview&candidate_id=${encodeURIComponent(state.id)}&stage=${encodeURIComponent(selectedStage())}&template_key=${encodeURIComponent(tpl)}`);
    const data = await res.json().catch(() => ({ ok: false, error: 'Unexpected server response.' }));
    if (!data.ok) throw new Error(data.error || 'Could not load the email preview.');
    state.placeholders = data.placeholders || {};
    $('samSubtitle').textContent = `${data.candidate.name} · ${data.candidate.job_title} · current stage: ${data.candidate.current_stage || 'Applied'}`;
    $('samEmail').value = data.candidate.email || '';
    $('samSubject').value = data.subject || '';
    $('samBody').value = data.body_text || '';
    $('samPreview').srcdoc = data.html || '';
    $('samSend').disabled = !data.candidate.email;
    $('samSend').title = data.candidate.email ? '' : 'No email address on file';
    if (!state.reasonsLoaded && data.rejection_reasons) {
      const sel = $('samReason');
      Object.entries(data.rejection_reasons).forEach(([k, v]) => sel.add(new Option(v, k)));
      state.reasonsLoaded = true;
    }
    const warn = $('samWarn');
    if (data.already_sent) {
      warn.textContent = `This email template was already sent to the candidate on ${data.already_sent}. Sending again will ask for confirmation.`;
      warn.style.display = 'block';
    } else {
      warn.style.display = 'none';
    }
  }

  function livePreview() {
    const doc = $('samPreview').contentDocument;
    if (!doc) return;
    const holder = doc.querySelector('div[style*="line-height:1.8"]');
    const msg = $('samBody').value;
    if (holder && msg.trim() !== '') holder.innerHTML = escapeHtml(fill(msg)).replace(/\n/g, '<br>');
  }

  async function open(candidateId, opts = {}) {
    state = { ...state, id: candidateId, onDone: opts.onDone || null };
    $('samTemplate').value = opts.template || 'screening';
    $('samReason').value = '';
    $('samReasonNote').value = '';
    $('samEvalFeedback').value = '';
    $('samEvalStrengths').value = '';
    $('samEvalRec').value = opts.template === 'rejected' ? 'Reject' : 'Advance';
    $('samEval').open = false;
    $('samSubtitle').textContent = opts.name ? opts.name + ' · loading…' : 'Loading…';
    syncRejectUi();
    $('samModal').classList.add('open');
    try { await loadPreview(); } catch (e) { alert(e.message); close(); }
  }

  function close() { $('samModal').classList.remove('open'); }

  async function templateChanged() {
    syncRejectUi();
    if (selectedStage() === 'Rejected') $('samEvalRec').value = 'Reject';
    try { await loadPreview(); } catch (e) { alert(e.message); }
  }

  async function submit(sendEmail, allowResend = false) {
    const stage = selectedStage();
    if (stage === 'Rejected' && !$('samReason').value) {
      alert('Select a rejection reason first. It is stored on the candidate timeline.');
      $('samReason').focus();
      return;
    }
    if (stage === 'Rejected' && $('samReason').value === 'other' && !$('samReasonNote').value.trim()) {
      alert('Add a short rejection note when the reason is "Other".');
      $('samReasonNote').focus();
      return;
    }
    const buttons = [$('samSend'), $('samMoveOnly')];
    buttons.forEach(b => b.disabled = true);
    try {
      const response = await fetch('pipeline_lifecycle_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: CSRF,
          candidate_id: state.id,
          stage,
          send_email: sendEmail ? 1 : 0,
          allow_resend: allowResend ? 1 : 0,
          template_key: $('samTemplate').value,
          subject: $('samSubject').value,
          message_text: $('samBody').value,
          rejection_reason: $('samReason').value,
          rejection_note: $('samReasonNote').value,
          eval_feedback: $('samEvalFeedback').value,
          eval_strengths: $('samEvalStrengths').value,
          eval_recommendation: $('samEvalRec').value
        })
      });
      const res = await response.json().catch(() => ({ ok: false, error: 'Unexpected server response.' }));
      if (res.duplicate && sendEmail && !allowResend) {
        if (confirm(res.error + '\n\nSend it again anyway?')) {
          buttons.forEach(b => b.disabled = false);
          return submit(true, true);
        }
        return;
      }
      if (!res.ok) throw new Error(res.error || 'The stage change failed.');
      close();
      let note = `Moved to ${res.stage}.`;
      if (sendEmail) note += res.email_result && res.email_result.ok ? ' Email sent.' : ' Email NOT delivered — check mail settings.';
      if (typeof state.onDone === 'function') state.onDone(res.stage, res, note);
      else alert(note);
    } catch (e) {
      alert('Error: ' + e.message);
    } finally {
      buttons.forEach(b => b.disabled = false);
      if (sendEmail && !$('samEmail').value) $('samSend').disabled = true;
    }
  }

  document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('samModal').classList.contains('open')) close(); });
  return { open, close, templateChanged, submit, livePreview };
})();
</script>
