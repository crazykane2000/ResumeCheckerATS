(()=>{
const form=document.getElementById('inviteForm'),lockScreen=document.getElementById('sendLock');
const inviteActions=document.querySelector('.invite-actions');if(inviteActions&&!inviteActions.querySelector('[href="interview_calendar.php"]')){const calendarLink=document.createElement('a');calendarLink.className='btn';calendarLink.href='interview_calendar.php';calendarLink.innerHTML='<i class="fa-regular fa-calendar"></i> Calendar';inviteActions.append(calendarLink)}
let submitting=false;if(form)form.addEventListener('submit',event=>{if(submitting){event.preventDefault();return}submitting=true;const button=event.submitter||form.querySelector('.send-row button');if(button){button.disabled=true;button.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Sending...'}lockScreen.classList.add('show');lockScreen.setAttribute('aria-hidden','false');document.body.style.overflow='hidden'});
})();
