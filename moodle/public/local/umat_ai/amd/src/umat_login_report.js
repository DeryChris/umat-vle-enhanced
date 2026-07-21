/**
 * AMD module for the login-page issue report toggle.
 *
 * Handles the toggle between the login form and the inline report card.
 * The toggle link is already injected below the login submit button by
 * an inline <script> in the HTML — this module manages the interactivity.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    return {
        init: function() {
            var toggle   = document.getElementById('lr-show-form');
            var backBtn  = document.getElementById('lr-report-back');
            var closeBtn = document.getElementById('lr-close-btn');
            var wrapper  = document.getElementById('lr-wrapper');
            var reportCard = document.getElementById('lr-report-card');
            if (!toggle || !reportCard) return;

            // Find the login form — it's the sibling before our wrapper.
            var loginForm = wrapper ? wrapper.previousElementSibling : null;
            if (!loginForm || !loginForm.closest) {
                loginForm = document.querySelector('.loginform') ||
                            document.querySelector('#page-login-index form');
            }
            if (!loginForm) loginForm = document.querySelector('form[action*="login"]');

            /* ---- Toggle: show report form ---- */
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                if (loginForm) loginForm.style.display = 'none';
                if (wrapper) wrapper.style.display = 'none';
                reportCard.style.display = '';
                resetForm();
            });

            /* ---- Back: show login form ---- */
            function showLoginForm() {
                reportCard.style.display = 'none';
                if (loginForm) loginForm.style.display = '';
                if (wrapper) wrapper.style.display = '';
                resetForm();
            }

            if (backBtn) backBtn.addEventListener('click', function(e) {
                e.preventDefault();
                showLoginForm();
            });

            if (closeBtn) closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                showLoginForm();
            });

            /* ---- Form elements ---- */
            var usernameInp = document.getElementById('lr-username');
            var lookupBtn   = document.getElementById('lr-lookup-btn');
            var msgEl       = document.getElementById('lr-msg');
            var courseSel   = document.getElementById('lr-course');
            var nameInp     = document.getElementById('lr-name');
            var descInp     = document.getElementById('lr-desc');
            var submitBtn   = document.getElementById('lr-submit-btn');
            var submitMsg   = document.getElementById('lr-submit-msg');
            var loader      = document.getElementById('lr-loader');
            var stepIdentify = document.getElementById('lr-step-identify');
            var stepReport  = document.getElementById('lr-step-report');
            var stepDone    = document.getElementById('lr-step-done');

            var cachedCourses = [];
            var cachedUsername = '';

            function resetForm() {
                if (stepIdentify) stepIdentify.style.display = '';
                if (stepReport) stepReport.style.display = 'none';
                if (stepDone) stepDone.style.display = 'none';
                if (msgEl) { msgEl.textContent = ''; msgEl.className = 'umat-login-report-msg'; }
                if (submitMsg) { submitMsg.textContent = ''; submitMsg.className = 'umat-login-report-msg'; }
                if (loader) loader.style.display = 'none';
                if (courseSel) courseSel.innerHTML = '';
                if (usernameInp) usernameInp.value = '';
                if (nameInp) nameInp.value = '';
                if (descInp) descInp.value = '';
                cachedCourses = [];
                cachedUsername = '';
                if (lookupBtn) lookupBtn.disabled = false;
                if (submitBtn) submitBtn.disabled = false;
            }

            function showMsg(el, text, isError) {
                if (!el) return;
                el.textContent = text;
                el.className = 'umat-login-report-msg' + (isError ? ' error' : ' success');
            }

            function showLoader(show) {
                if (loader) loader.style.display = show ? 'flex' : 'none';
            }

            /* ---- Step 1: Lookup courses ---- */
            function doLookup() {
                var u = (usernameInp ? usernameInp.value : '').trim();
                if (u.length < 2) {
                    showMsg(msgEl, 'Please enter your student ID or username.', true);
                    return;
                }

                showLoader(true);
                if (lookupBtn) lookupBtn.disabled = true;
                if (msgEl) msgEl.textContent = '';

                fetch(M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + M.cfg.sesskey + '&info=local_umat_ai_login_lookup_courses', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify([{
                        index: 0,
                        methodname: 'local_umat_ai_login_lookup_courses',
                        args: {username: u}
                    }])
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    showLoader(false);
                    if (lookupBtn) lookupBtn.disabled = false;

                    var result = data && data[0] && data[0].data;
                    if (!result || !result.success) {
                        var errMsg = (result && result.message) || 'Could not find your courses. Please check your ID and try again.';
                        showMsg(msgEl, errMsg, true);
                        return;
                    }

                    cachedCourses = result.courses || [];
                    cachedUsername = u;

                    if (cachedCourses.length === 0) {
                        showMsg(msgEl, 'No courses found for this account.', true);
                        return;
                    }

                    if (courseSel) {
                        courseSel.innerHTML = '<option value="">-- Select a course --</option>';
                        cachedCourses.forEach(function(c) {
                            var opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = c.shortname + ' - ' + c.fullname;
                            courseSel.appendChild(opt);
                        });
                    }

                    if (stepIdentify) stepIdentify.style.display = 'none';
                    if (stepReport) stepReport.style.display = '';
                    showMsg(msgEl, 'Found ' + cachedCourses.length + ' course(s). Select one and describe the issue.', false);
                })
                .catch(function() {
                    showLoader(false);
                    if (lookupBtn) lookupBtn.disabled = false;
                    showMsg(msgEl, 'Network error. Please try again.', true);
                });
            }

            if (lookupBtn) lookupBtn.addEventListener('click', doLookup);
            if (usernameInp) usernameInp.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    doLookup();
                }
            });

            /* ---- Step 2: Submit report ---- */
            function doSubmit() {
                var courseId = courseSel ? parseInt(courseSel.value) : 0;
                var desc     = descInp ? descInp.value.trim() : '';
                var name     = nameInp ? nameInp.value.trim() : '';

                if (!courseId) {
                    showMsg(submitMsg, 'Please select a course.', true);
                    return;
                }
                if (desc.length < 10) {
                    showMsg(submitMsg, 'Please describe the issue in more detail (at least 10 characters).', true);
                    return;
                }

                showLoader(true);
                if (submitBtn) submitBtn.disabled = true;
                if (submitMsg) submitMsg.textContent = '';

                fetch(M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + M.cfg.sesskey + '&info=local_umat_ai_login_submit_issue', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify([{
                        index: 0,
                        methodname: 'local_umat_ai_login_submit_issue',
                        args: {
                            username: cachedUsername,
                            courseid: courseId,
                            description: desc,
                            name: name
                        }
                    }])
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    showLoader(false);
                    if (submitBtn) submitBtn.disabled = false;

                    var result = data && data[0] && data[0].data;
                    if (!result || !result.success) {
                        var errMsg = (result && result.message) || 'Failed to submit. Please try again.';
                        showMsg(submitMsg, errMsg, true);
                        return;
                    }

                    if (stepReport) stepReport.style.display = 'none';
                    if (stepDone) stepDone.style.display = '';
                })
                .catch(function() {
                    showLoader(false);
                    if (submitBtn) submitBtn.disabled = false;
                    showMsg(submitMsg, 'Network error. Please try again.', true);
                });
            }

            if (submitBtn) submitBtn.addEventListener('click', doSubmit);
        }
    };
});
