// ============================================================
// AMD module: local_umat_ai/approval
// Handles approve / reject button logic on the approval page.
// ============================================================

define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    /**
     * Wire approve/reject buttons to the external API.
     * Called from the approval.mustache {{#js}} block.
     */
    function init() {

        // ---- approve buttons ---- //
        document.querySelectorAll('.umat-approve-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var outputId = parseInt(btn.dataset.outputId);
                var courseId = parseInt(btn.dataset.courseId);
                handleAction(outputId, courseId, 'approve', '');
            });
        });

        // ---- reject buttons — show comment field first ---- //
        document.querySelectorAll('.umat-reject-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var outputId = parseInt(btn.dataset.outputId);
                var courseId = parseInt(btn.dataset.courseId);
                var commentEl = document.getElementById('comment-' + outputId);

                if (!commentEl) {
                    handleAction(outputId, courseId, 'reject', '');
                    return;
                }

                // First click: reveal the comment box.
                if (commentEl.style.display === 'none' || commentEl.style.display === '') {
                    commentEl.style.display = 'block';
                    commentEl.focus();
                    btn.innerHTML = '<span class="material-symbols-outlined">send</span> Confirm Rejection';
                    return;
                }

                // Second click: submit the rejection.
                handleAction(outputId, courseId, 'reject', commentEl.value);
            });
        });
    }

    /**
     * Call the external API and update the UI.
     *
     * @param {number} outputId
     * @param {number} courseId
     * @param {string} action  - 'approve' or 'reject'
     * @param {string} comment - optional rejection comment
     */
    function handleAction(outputId, courseId, action, comment) {
        var actionsEl   = document.getElementById('actions-' + outputId);
        var feedbackEl  = document.getElementById('feedback-' + outputId);
        var statusBadge = document.getElementById('status-badge-' + outputId);

        // Disable buttons during request.
        if (actionsEl) {
            actionsEl.querySelectorAll('button').forEach(function(b) { b.disabled = true; });
        }

        Ajax.call([{
            methodname: 'local_umat_ai_approve_output',
            args: {
                outputid: outputId,
                courseid: courseId,
                action:   action,
                comment:  comment || '',
            },
        }])[0].done(function(response) {
            if (response.success) {
                // Show feedback.
                if (feedbackEl) {
                    feedbackEl.textContent = response.message;
                    feedbackEl.className   = 'umat-action-feedback feedback-ok';
                    feedbackEl.style.display = 'block';
                }

                // Update status badge.
                if (statusBadge) {
                    if (action === 'approve') {
                        statusBadge.textContent = '✅ Approved & Published';
                        statusBadge.className   = 'umat-status-badge status-approved';
                    } else {
                        statusBadge.textContent = '❌ Rejected';
                        statusBadge.className   = 'umat-status-badge status-rejected';
                    }
                }

                // Fade out action buttons.
                if (actionsEl) {
                    actionsEl.style.opacity = '0.4';
                    actionsEl.style.pointerEvents = 'none';
                }

                // If all outputs on the page are resolved, show the "all done" banner
                // after a short delay.
                setTimeout(checkAllDone, 600);

            } else {
                showError(feedbackEl, 'Action failed. Please try again.');
                if (actionsEl) {
                    actionsEl.querySelectorAll('button').forEach(function(b) { b.disabled = false; });
                }
            }
        }).fail(function(ex) {
            showError(feedbackEl, 'Network error. Please check your connection.');
            if (actionsEl) {
                actionsEl.querySelectorAll('button').forEach(function(b) { b.disabled = false; });
            }
            Notification.exception(ex);
        });
    }

    function showError(el, msg) {
        if (!el) return;
        el.textContent    = msg;
        el.className      = 'umat-action-feedback feedback-err';
        el.style.display  = 'block';
    }

    function checkAllDone() {
        var allResolved = true;
        document.querySelectorAll('.umat-output-card').forEach(function(card) {
            var badge = card.querySelector('.umat-status-badge');
            if (badge && badge.classList.contains('status-pending')) {
                allResolved = false;
            }
        });
        if (allResolved) {
            var wrap = document.querySelector('.umat-approve-wrap');
            if (wrap) {
                var blocks = wrap.querySelectorAll('.umat-session-block');
                blocks.forEach(function(b) { b.style.display = 'none'; });
                // Insert success banner.
                var banner = document.createElement('div');
                banner.className = 'umat-all-done';
                banner.innerHTML =
                    '<span class="material-symbols-outlined">check_circle</span>' +
                    '<h3>All caught up!</h3>' +
                    '<p>All AI outputs have been reviewed. Check back after the next lecture session.</p>';
                wrap.appendChild(banner);
            }
        }
    }

    return { init: init };
});
