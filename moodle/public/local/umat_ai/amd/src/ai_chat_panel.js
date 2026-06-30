// ============================================================
// AMD module for the AI chat panel — FAB + overlay
// ============================================================

define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/templates',
    'local_umat_ai/umatshared',
], function(Ajax, Notification, Str, Templates, S) {
    'use strict';

    const SELECTORS = {
        FAB: '#umat-ai-fab',
        OVERLAY: '#umat-ai-overlay',
        BACKDROP: '.umat-ai-backdrop',
        CLOSE_BTN: '[data-close]',
        CHAT_MESSAGES: '[data-region="umat-chat-messages"]',
        QUESTION_INPUT: '[data-region="umat-question-input"]',
        SEND_BUTTON: '[data-region="umat-send-btn"]',
        VOICE_BTN: '[data-region="umat-voice-btn"]',
        QUICK_ACTIONS: '.quick-action-btn',
        QUESTIONS_REMAINING: '[data-region="questions-remaining"]',
    };

    let courseId = null;
    let courseName = '';
    let hasCapability = false;
    let questionsRemaining = 10;
    let lastQuestionTime = 0;

    const escapeHtml = function(text) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    };

    const showOverlay = function() {
        const overlay = document.querySelector(SELECTORS.OVERLAY);
        if (overlay) {
            overlay.style.display = 'flex';
            // Focus the input
            setTimeout(() => {
                const input = document.querySelector(SELECTORS.QUESTION_INPUT);
                if (input) input.focus();
            }, 100);
        }
    };

    const hideOverlay = function() {
        const overlay = document.querySelector(SELECTORS.OVERLAY);
        if (overlay) {
            overlay.style.display = 'none';
        }
    };

    const appendMessage = function(role, text, sources) {
        const container = document.querySelector(SELECTORS.CHAT_MESSAGES);
        if (!container) return;

        const messageDiv = document.createElement('div');
        messageDiv.className = `chat-message ${role}`;

        var bubbleContent = role === 'ai' ? S._umatFormatAI(text) : escapeHtml(text);
        var innerTag = role === 'ai' ? 'div' : 'p';
        let bubbleHtml = '<div class="chat-bubble"><' + innerTag + ' class="umat-ai-content">' + bubbleContent + '</' + innerTag + '></div>';
        messageDiv.innerHTML = bubbleHtml;

        if (sources && sources.length > 0 && role === 'ai') {
            const tagsHtml = sources.map(s =>
                `<span class="source-tag">${escapeHtml(s)}</span>`
            ).join('');
            messageDiv.innerHTML += `<div class="chat-source-tags">${tagsHtml}</div>`;
        }

        container.appendChild(messageDiv);
        container.scrollTop = container.scrollHeight;
    };

    const showTypingIndicator = function() {
        const container = document.querySelector(SELECTORS.CHAT_MESSAGES);
        if (!container) return;

        const indicator = document.createElement('div');
        indicator.className = 'chat-message ai typing-indicator-wrapper';
        indicator.id = 'umat-typing-indicator';
        indicator.innerHTML = `
            <div class="umat-typing-indicator">
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
                <span class="typing-dot"></span>
            </div>
        `;
        container.appendChild(indicator);
        container.scrollTop = container.scrollHeight;
    };

    const hideTypingIndicator = function() {
        const indicator = document.getElementById('umat-typing-indicator');
        if (indicator) {
            indicator.remove();
        }
    };

    const updateQuestionsRemaining = function() {
        const now = Date.now();
        if (now - lastQuestionTime >= 60000) {
            questionsRemaining = 10;
        }
        const counter = document.querySelector(SELECTORS.QUESTIONS_REMAINING);
        if (counter) {
            counter.textContent = questionsRemaining + ' {{#str}}questions_remaining, local_umat_ai{{/str}}';
        }
    };

    const sendQuestion = function(question) {
        if (!question.trim() || questionsRemaining <= 0) return;

        appendMessage('student', question, []);
        questionsRemaining--;
        lastQuestionTime = Date.now();
        updateQuestionsRemaining();

        const input = document.querySelector(SELECTORS.QUESTION_INPUT);
        if (input) input.value = '';

        showTypingIndicator();

        Ajax.call([{
            methodname: 'local_umat_ai_ask_question',
            args: {
                courseid: courseId,
                question: question,
            },
        }])[0].done(function(response) {
            hideTypingIndicator();
            if (response.success) {
                appendMessage('ai', response.answer, response.sources);
            } else {
                appendMessage('ai', response.error || 'An error occurred. Please try again.', []);
            }
            updateQuestionsRemaining();
        }).fail(function(ex) {
            hideTypingIndicator();
            appendMessage('ai', '{{#str}}error_ai, local_umat_ai{{/str}}', []);
            Notification.exception(ex);
        });
    };

    const handleQuickAction = function(action) {
        const actions = {
            'summarize': '{{#str}}quick_summarize, local_umat_ai{{/str}}',
            'assignment': '{{#str}}quick_assignment, local_umat_ai{{/str}}',
            'explain': '{{#str}}quick_explain, local_umat_ai{{/str}}',
            'deadlines': '{{#str}}quick_deadlines, local_umat_ai{{/str}}'
        };
        const question = actions[action] || action;
        sendQuestion(question);
    };

    const setupEventListeners = function() {
        // FAB click
        const fab = document.querySelector(SELECTORS.FAB);
        if (fab && !fab.disabled) {
            fab.addEventListener('click', showOverlay);
        }

        // Backdrop/close buttons
        document.querySelectorAll(SELECTORS.BACKDROP + ', ' + SELECTORS.CLOSE_BTN).forEach(function(el) {
            el.addEventListener('click', hideOverlay);
        });

        // Send button
        const sendBtn = document.querySelector(SELECTORS.SEND_BUTTON);
        const input = document.querySelector(SELECTORS.QUESTION_INPUT);

        if (sendBtn) {
            sendBtn.addEventListener('click', function() {
                if (input) sendQuestion(input.value);
            });
        }

        if (input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendQuestion(input.value);
                }
            });
        }

        // Quick action buttons
        document.querySelectorAll(SELECTORS.QUICK_ACTIONS).forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                handleQuickAction(action);
            });
        });

        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideOverlay();
            }
        });
    };

    return {
        init: function(options) {
            courseId = options.courseId;
            courseName = options.courseName || '';
            hasCapability = options.hasCapability || false;

            if (!hasCapability) return;

            setupEventListeners();
            updateQuestionsRemaining();
        },

        // Initialize FAB on any page (called from lib.php navigation callback)
        initFab: function(courseId, courseName) {
            if (document.getElementById('umat-fab-btn')) return;

            // FAB Button
            var fab = document.createElement('button');
            fab.id = 'umat-fab-btn';
            fab.innerHTML = '<span class="material-symbols-outlined" style="font-size:32px">smart_toy</span>';
            fab.title = 'AI Assistant';
            fab.style.cssText = 'position:fixed;bottom:80px;right:24px;z-index:9999;width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#006b2f,#00873d);color:white;border:none;box-shadow:0 6px 20px rgba(0,107,47,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:transform 0.2s;';
            fab.onmouseover = function() { this.style.transform = 'scale(1.1)'; };
            fab.onmouseout = function() { this.style.transform = 'scale(1)'; };

            // Workspace Overlay
            var ws = document.createElement('div');
            ws.id = 'umat-workspace';
            ws.style.cssText = 'display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);';

            // Panel
            var panel = document.createElement('div');
            panel.style.cssText = 'position:absolute;right:0;top:0;bottom:0;width:400px;max-width:95vw;background:#f8faf7;box-shadow:-10px 0 40px rgba(0,0,0,0.2);display:flex;flex-direction:column;';

            panel.innerHTML =
                '<div style="background:linear-gradient(135deg,#006b2f,#00873d);padding:20px;color:white;">' +
                '<div style="display:flex;align-items:center;gap:12px;">' +
                '<div style="width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;">' +
                '<span class="material-symbols-outlined" style="font-size:28px;">smart_toy</span></div>' +
                '<div style="flex:1;"><h3 style="margin:0;font-size:18px;">AI Learning Assistant</h3>' +
                '<div style="font-size:12px;opacity:0.85;">' + courseName + '</div></div>' +
                '<button id="umat-close-btn" style="background:rgba(255,255,255,0.2);border:none;color:white;width:36px;height:36px;border-radius:50%;cursor:pointer;">X</button></div></div>' +
                '<div id="umat-messages" style="flex:1;overflow-y:auto;padding:16px;background:#eff6eb;display:flex;flex-direction:column;gap:12px;min-height:200px;">' +
                '<div style="padding:12px;background:white;border-left:3px solid #006b2f;border-radius:12px;font-size:13px;">Hello! I\'m your AI assistant. Ask me anything!</div></div>' +
                '<div style="padding:16px;background:white;border-top:1px solid #dee5da;">' +
                '<div style="display:flex;gap:8px;">' +
                '<textarea id="umat-input" placeholder="Ask a question..." rows="2" style="flex:1;padding:12px;border:1px solid #dee5da;border-radius:8px;font-size:13px;resize:none;"></textarea>' +
                '<button id="umat-send-btn" style="width:46px;height:46px;border-radius:8px;background:#006b2f;color:white;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;">' +
                '<span class="material-symbols-outlined" style="font-size:20px;">send</span></button></div></div>';

            ws.appendChild(panel);
            document.body.appendChild(fab);
            document.body.appendChild(ws);

            var closeBtn = document.getElementById('umat-close-btn');
            var input = document.getElementById('umat-input');
            var sendBtn = document.getElementById('umat-send-btn');
            var messages = document.getElementById('umat-messages');

            fab.addEventListener('click', function() { ws.style.display = 'block'; setTimeout(function(){input.focus();}, 100); });
            closeBtn.addEventListener('click', function() { ws.style.display = 'none'; });
            ws.addEventListener('click', function(e) { if(e.target === ws) ws.style.display = 'none'; });

            function sendQuestion() {
                var q = input.value.trim();
                if (!q) return;
                var userMsg = document.createElement('div');
                userMsg.style.cssText = 'align-self:flex-end;background:#d1fae5;padding:12px;border-radius:12px;max-width:85%;font-size:13px;';
                userMsg.textContent = q;
                messages.appendChild(userMsg);
                input.value = '';

                var typing = document.createElement('div');
                typing.id = 'umat-typing';
                typing.style.cssText = 'padding:12px;background:white;border-left:3px solid #006b2f;border-radius:12px;max-width:85%;font-size:13px;';
                typing.innerHTML = '<em>Thinking...</em>';
                messages.appendChild(typing);
                messages.scrollTop = messages.scrollHeight;

                Ajax.call([{methodname: 'local_umat_ai_ask_question', args: {courseid: courseId, question: q}}])[0].done(function(r) {
                    typing.remove();
                    var aiMsg = document.createElement('div');
                    aiMsg.style.cssText = 'padding:12px;background:white;border-left:3px solid #006b2f;border-radius:12px;max-width:85%;font-size:13px;';
                    aiMsg.textContent = r.success ? (r.answer || 'Got response') : 'Error';
                    messages.appendChild(aiMsg);
                    messages.scrollTop = messages.scrollHeight;
                }).fail(function() {
                    typing.remove();
                });
            }

            sendBtn.addEventListener('click', sendQuestion);
            input.addEventListener('keypress', function(e) { if(e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuestion(); }});

            console.log('UMaT FAB loaded for course', courseId);
        }
    };
});