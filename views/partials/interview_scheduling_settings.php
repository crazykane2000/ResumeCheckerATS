<?php 
$scheduleSettings = integrationConfig('interview_scheduling');
$scheduleEmails = implode(', ', $scheduleSettings['admin_emails'] ?? ['chinka.gupta@nonceblox.com', 'hr@nonceblox.com']);
?>
<?php if (isset($_GET['interview_scheduling'])): ?>
  <div class="alert-ok"><i class="fa-solid fa-circle-check"></i> Interview scheduling settings saved cleanly.</div>
<?php endif; ?>

<?php if ($canControlTesting): ?>
  <section class="settings-card" id="scheduling">
    <div class="card-header-title">
      <i class="fa-solid fa-calendar-check" style="color: #6f45ff;"></i> Interview Scheduling Notifications
    </div>
    <div class="card-header-sub">
      Candidate confirmations are sent to the candidate and every comma-separated admin address below. Booking links use the approved NonceBlox HTTPS page.
    </div>

    <form method="post" action="interview_scheduling_settings.php">
      <input type="hidden" name="csrf" value="<?=csrfToken()?>">
      <div class="form-grid-2">
        <div class="full-width">
          <label class="form-label">Admin Confirmation Email Addresses (Comma Separated)</label>
          <input class="form-input" name="admin_emails" value="<?=htmlspecialchars($scheduleEmails)?>" required>
        </div>
        <div class="full-width">
          <label class="form-label">External Candidate Booking Page URL</label>
          <input class="form-input" type="url" name="external_booking_url" value="<?=htmlspecialchars($scheduleSettings['external_booking_url'] ?? 'https://nonceblox.com/interview-schedule.php')?>" required>
        </div>
      </div>
      
      <div style="margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <button type="submit" class="btn-purple"><i class="fa-solid fa-floppy-disk"></i> Save Scheduling Settings</button>
        <a href="interview_calendar.php" class="btn-secondary"><i class="fa-regular fa-calendar"></i> Open Calendar &rarr;</a>
      </div>
    </form>
  </section>
<?php endif; ?>
