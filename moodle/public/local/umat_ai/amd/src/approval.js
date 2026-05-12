// ============================================================
// AMD module for the AI output approval page
// Handles approve/reject actions via the Moodle web service
// ============================================================

define([
    'core/ajax',
    'core/notification',
    'core/str',
], function(Ajax, Notification, Str) {
    'use strict';

    const init = function(options) {
        const courseId = options.courseId;

        // --- Approve button handler ---
        document.querySelectorAll('.umat-approve-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const outputId = btn.dataset.outputId;
                handleAction(outputId, 'approve', '', btn);
            });
        });

        // --- Reject button handler ---
        document.querySelectorAll('.umat-reject-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const outputId = btn.dataset.outputId;
                const outputEl = btn.closest('.umat-approval-output');
                const commentEl = outputEl ? outputEl.querySelector('.umat-rejection-comment') : null;

                // Toggle comment textarea visibility on first click.
                if (commentEl && commentEl.classList.contains('d-none')) {
                    commentEl.classList.remove('d-none');
                    commentEl.focus();
                    return;
                }

                // Second click submits the rejection.
                const comment = commentEl ? commentEl.value.trim() : '';
                handleAction(outputId, 'reject', comment, btn);
            });
        });
    };

    /**
     * Call the web service and update the UI on success/failure.
     *
     * @param {number} outputId
     * @param {string} action   'approve' or 'reject'
     * @param {string} comment   Rejection comment (may be empty)
     * @param {HTMLElement} btn  The button that triggered the action
     */
    function handleAction(outputId, action, comment, btn) {
        // Disable button to prevent double-submit.
        btn.disabled = true;

        Ajax.call([{
            methodname: 'local_umat_ai_approve_output',
            args: {
                outputid: parseInt(outputId, 10),
                courseid: parseInt(btn.dataset.courseId, 10),
                action:   action,
                comment:  comment,
            },
        }])[0].done(function(response) {
            const outputEl = btn.closest('.umat-approval-output');
            const feedbackEl = outputEl ? outputEl.querySelector('.umat-approval-feedback') : null;

            if (response.success) {
                // Show success message on the row.
                if (feedbackEl) {
                    feedbackEl.classList.remove('d-none');
                    if (action === 'approve') {
                        feedbackEl.innerHTML =
                            '<span class="text-success"><i class="fa fa-check-circle"></i> ' +
                            response.message + '</span>';
                    } else {
                        feedbackEl.innerHTML =
                            '<span class="text-muted"><i class="fa fa-info-circle"></i> ' +
                            response.message + '</span>';
                    }
                }

                // Hide action buttons on this output row.
                const actionsEl = outputEl ? outputEl.querySelector('.umat-output-actions') : null;
                if (actionsEl) {
                    actionsEl.style.display = 'none';
                }

                // Hide the entire output card if all outputs for this session are handled.
                // Check if any visible approve/reject buttons remain for this session.
                const sessionEl = outputEl ? outputEl.closest('.umat-approval-session') : null;
                if (sessionEl) {
                    const remainingBtns = sessionEl.querySelectorAll('.umat-output-actions:not([style*="none"])');
                    if (remainingBtns.length === 0) {
                        sessionEl.style.opacity = '0.5';
                        sessionEl.style.pointerEvents = 'none';
                    }
                }
            }
        }).fail(function(ex) {
            btn.disabled = false;
            Notification.exception(ex);
        });
    }

    return { init: init };
});