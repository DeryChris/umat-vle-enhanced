// ============================================================
// AMD module for the AI chat panel — calls the Moodle web service
// Build with: grunt amd --root=local/umat_ai
// ============================================================

define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/templates',
], function(Ajax, Notification, Str, Templates) {
    'use strict';

    const SELECTORS = {
        CHAT_CONTAINER: '[data-region="umat-chat-container"]',
        QUESTION_INPUT: '[data-region="umat-question-input"]',
        SEND_BUTTON:    '[data-region="umat-send-btn"]',
        LOADING_SPINNER:'[data-region="umat-loading"]',
        SOURCES_PANEL:  '[data-region="umat-sources"]',
    };

    let courseId = null;

    const escapeHtml = function(text) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    };

    const appendMessage = function(container, role, text, sources) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `chat-message ${role}`;
        messageDiv.innerHTML = `<p>${escapeHtml(text)}</p>`;

        if (sources && sources.length > 0 && role === 'ai') {
            const sourcesHtml = sources.map(s =>
                `<span class="badge">${escapeHtml(s)}</span>`
            ).join('');
            messageDiv.innerHTML += `<div class="source-tags"><small>Sources: </small>${sourcesHtml}</div>`;
        }

        container.appendChild(messageDiv);
        container.scrollTop = container.scrollHeight;
    };

    const sendQuestion = function(container, input) {
        const question = input.value.trim();
        if (!question) return;

        appendMessage(container, 'student', question, []);
        input.value = '';

        // Loading indicator
        const loadingMsg = document.createElement('div');
        loadingMsg.className = 'chat-message ai loading-msg';
        loadingMsg.innerHTML = '<p><i class="fa fa-spinner fa-spin"></i> Thinking...</p>';
        container.appendChild(loadingMsg);
        container.scrollTop = container.scrollHeight;

        Ajax.call([{
            methodname: 'local_umat_ai_ask_question',
            args: {
                courseid: courseId,
                question: question,
            },
        }])[0].done(function(response) {
            container.removeChild(loadingMsg);
            if (response.success) {
                appendMessage(container, 'ai', response.answer, response.sources);
            } else {
                appendMessage(container, 'ai', response.answer, []);
            }
        }).fail(function(ex) {
            container.removeChild(loadingMsg);
            appendMessage(container, 'ai', 'An error occurred. Please try again.', []);
            Notification.exception(ex);
        });
    };

    return {
        init: function(options) {
            courseId = options.courseId;

            const container = document.querySelector(SELECTORS.CHAT_CONTAINER);
            const input     = document.querySelector(SELECTORS.QUESTION_INPUT);
            const sendBtn   = document.querySelector(SELECTORS.SEND_BUTTON);

            if (!container || !input || !sendBtn) return;

            sendBtn.addEventListener('click', function() {
                sendQuestion(container, input);
            });

            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendQuestion(container, input);
                }
            });
        }
    };
});