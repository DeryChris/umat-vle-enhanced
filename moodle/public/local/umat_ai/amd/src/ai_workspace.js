// ============================================================
// AMD module for the AI workspace — full-page video + transcript + AI panel
// ============================================================

define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/templates',
], function(Ajax, Notification, Str, Templates) {
    'use strict';

    const SELECTORS = {
        WORKSPACE: '.umat-ai-workspace',
        VIDEO: '#umat-lecture-video',
        VIDEO_PROGRESS: '#video-progress',
        CURRENT_TIME: '#current-time',
        DURATION: '#duration',
        TRANSCRIPT_SEARCH: '#transcript-search',
        TRANSCRIPT_CONTENT: '#transcript-content',
        TRANSCRIPT_SEGMENT: '.transcript-segment',
        TIMESTAMP_BTN: '.timestamp-btn',
        TAB_BTN: '.tab-btn',
        TAB_CONTENT: '.tab-content',
        CHAT_MESSAGES: '#workspace-chat-messages',
        QUESTION_INPUT: '#workspace-question-input',
        SEND_BTN: '#workspace-send-btn',
        TYPING_INDICATOR: '#workspace-typing',
        SUGGESTION_CHIPS: '#suggestion-chips',
        SUGGESTION_CHIP: '.suggestion-chip',
        ACTION_BTN: '.action-btn',
        NOTES_CONTENT: '#notes-content',
        RESOURCES_LIST: '#resources-list',
    };

    let courseId = null;
    let sessionId = null;
    let courseName = '';
    let currentVideoTime = 0;
    let questionsRemaining = 10;
    let lastQuestionTime = 0;

    const escapeHtml = function(text) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    };

    // Format seconds to MM:SS
    const formatTime = function(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = Math.floor(seconds % 60);
        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    };

    // Video Controls
    const setupVideoPlayer = function() {
        const video = document.querySelector(SELECTORS.VIDEO);
        if (!video) return;

        video.addEventListener('timeupdate', function() {
            currentVideoTime = video.currentTime;
            const progress = document.querySelector(SELECTORS.VIDEO_PROGRESS);
            if (progress) {
                progress.value = (video.currentTime / video.duration) * 100 || 0;
            }
            const timeDisplay = document.querySelector(SELECTORS.CURRENT_TIME);
            if (timeDisplay) {
                timeDisplay.textContent = formatTime(video.currentTime);
            }
            highlightTranscriptSegment(video.currentTime);
        });

        video.addEventListener('loadedmetadata', function() {
            const durationDisplay = document.querySelector(SELECTORS.DURATION);
            if (durationDisplay) {
                durationDisplay.textContent = formatTime(video.duration);
            }
        });

        // Custom controls
        document.querySelectorAll('[data-action="play-pause"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (video.paused) {
                    video.play();
                    btn.querySelector('span').textContent = 'pause';
                } else {
                    video.pause();
                    btn.querySelector('span').textContent = 'play_arrow';
                }
            });
        });

        document.querySelectorAll('[data-action="rewind-30"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                video.currentTime = Math.max(0, video.currentTime - 30);
            });
        });

        document.querySelectorAll('[data-action="forward-30"]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                video.currentTime = Math.min(video.duration, video.currentTime + 30);
            });
        });

        const progressBar = document.querySelector(SELECTORS.VIDEO_PROGRESS);
        if (progressBar) {
            progressBar.addEventListener('input', function() {
                video.currentTime = (this.value / 100) * video.duration;
            });
        }
    };

    // Highlight current transcript segment based on video time
    const highlightTranscriptSegment = function(currentTime) {
        document.querySelectorAll(SELECTORS.TRANSCRIPT_SEGMENT).forEach(function(segment) {
            const start = parseFloat(segment.dataset.start) || 0;
            const end = parseFloat(segment.dataset.end) || Infinity;
            if (currentTime >= start && currentTime < end) {
                segment.classList.add('active');
            } else {
                segment.classList.remove('active');
            }
        });
    };

    // Transcript: click timestamp to seek video
    const setupTranscript = function() {
        const video = document.querySelector(SELECTORS.VIDEO);

        document.querySelectorAll(SELECTORS.TIMESTAMP_BTN).forEach(function(btn) {
            btn.addEventListener('click', function() {
                const segment = btn.closest(SELECTORS.TRANSCRIPT_SEGMENT);
                const startTime = parseFloat(segment.dataset.start) || 0;
                if (video) {
                    video.currentTime = startTime;
                    video.play();
                }
            });
        });

        // Search transcript
        const searchInput = document.querySelector(SELECTORS.TRANSCRIPT_SEARCH);
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                document.querySelectorAll(SELECTORS.TRANSCRIPT_SEGMENT).forEach(function(segment) {
                    const text = segment.querySelector('.transcript-text').textContent.toLowerCase();
                    if (query === '' || text.includes(query)) {
                        segment.style.display = '';
                    } else {
                        segment.style.display = 'none';
                    }
                });
            });
        }
    };

    // Tab switching
    const setupTabs = function() {
        document.querySelectorAll(SELECTORS.TAB_BTN).forEach(function(btn) {
            btn.addEventListener('click', function() {
                const tab = this.dataset.tab;

                // Update buttons
                document.querySelectorAll(SELECTORS.TAB_BTN).forEach(function(b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');

                // Update content
                document.querySelectorAll(SELECTORS.TAB_CONTENT).forEach(function(content) {
                    content.classList.remove('active');
                });
                document.getElementById('tab-' + tab).classList.add('active');
            });
        });
    };

    // Chat functions
    const appendChatMessage = function(role, text, sources) {
        const container = document.querySelector(SELECTORS.CHAT_MESSAGES);
        if (!container) return;

        const messageDiv = document.createElement('div');
        messageDiv.className = 'chat-message ' + role;

        let bubbleHtml = '<div class="chat-bubble"><p>' + escapeHtml(text) + '</p></div>';
        messageDiv.innerHTML = bubbleHtml;

        if (sources && sources.length > 0 && role === 'ai') {
            const tagsHtml = sources.map(function(s) {
                return '<span class="source-tag">' + escapeHtml(s) + '</span>';
            }).join('');
            messageDiv.innerHTML += '<div class="chat-source-tags">' + tagsHtml + '</div>';
        }

        container.appendChild(messageDiv);
        container.scrollTop = container.scrollHeight;
    };

    const showTypingIndicator = function() {
        const indicator = document.querySelector(SELECTORS.TYPING_INDICATOR);
        if (indicator) {
            indicator.style.display = 'flex';
            const container = document.querySelector(SELECTORS.CHAT_MESSAGES);
            if (container) container.scrollTop = container.scrollHeight;
        }
    };

    const hideTypingIndicator = function() {
        const indicator = document.querySelector(SELECTORS.TYPING_INDICATOR);
        if (indicator) {
            indicator.style.display = 'none';
        }
    };

    const showSuggestions = function(suggestions) {
        const container = document.querySelector(SELECTORS.SUGGESTION_CHIPS);
        if (!container || !suggestions || suggestions.length === 0) return;

        container.innerHTML = suggestions.map(function(s) {
            return '<button class="suggestion-chip" data-suggestion="' + s.action + '">' + s.label + '</button>';
        }).join('');
        container.style.display = 'flex';

        container.querySelectorAll(SELECTORS.SUGGESTION_CHIP).forEach(function(chip) {
            chip.addEventListener('click', function() {
                handleSuggestion(this.dataset.suggestion);
            });
        });
    };

    const hideSuggestions = function() {
        const container = document.querySelector(SELECTORS.SUGGESTION_CHIPS);
        if (container) {
            container.style.display = 'none';
        }
    };

    const handleSuggestion = function(action) {
        const suggestions = {
            'explain': '{{#str}}suggest_explain_text, local_umat_ai{{/str}}',
            'elaborate': '{{#str}}suggest_elaborate_text, local_umat_ai{{/str}}',
            'compare': '{{#str}}suggest_compare_text, local_umat_ai{{/str}}'
        };
        const question = suggestions[action] || action;
        sendQuestion(question);
    };

    const sendQuestion = function(question) {
        if (!question.trim() || questionsRemaining <= 0) return;

        hideSuggestions();
        appendChatMessage('student', question, []);
        questionsRemaining--;
        lastQuestionTime = Date.now();

        const input = document.querySelector(SELECTORS.QUESTION_INPUT);
        if (input) input.value = '';

        showTypingIndicator();

        Ajax.call([{
            methodname: 'local_umat_ai_ask_question',
            args: {
                courseid: courseId,
                question: question,
                context: {
                    sessionid: sessionId,
                    video_time: currentVideoTime
                }
            },
        }])[0].done(function(response) {
            hideTypingIndicator();
            if (response.success) {
                appendChatMessage('ai', response.answer, response.sources);

                // Show contextual suggestions
                if (response.suggestions && response.suggestions.length > 0) {
                    showSuggestions(response.suggestions);
                }
            } else {
                appendChatMessage('ai', response.error || '{{#str}}error_ai, local_umat_ai{{/str}}', []);
            }
        }).fail(function(ex) {
            hideTypingIndicator();
            appendChatMessage('ai', '{{#str}}error_ai, local_umat_ai{{/str}}', []);
            Notification.exception(ex);
        });
    };

    // Header action buttons
    const setupHeaderActions = function() {
        document.querySelectorAll(SELECTORS.ACTION_BTN).forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;

                if (action === 'generate-summary') {
                    const question = '{{#str}}generate_summary_prompt, local_umat_ai{{/str}}';
                    sendQuestion(question);
                } else if (action === 'attach-material') {
                    // TODO: Open material picker modal
                    Notification.alert('{{#str}}attach_material, local_umat_ai{{/str}}', '{{#str}}material_picker_coming, local_umat_ai{{/str}}');
                }
            });
        });
    };

    // Chat input handlers
    const setupChatInput = function() {
        const input = document.querySelector(SELECTORS.QUESTION_INPUT);
        const sendBtn = document.querySelector(SELECTORS.SEND_BTN);

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
    };

    // Notes tab - generate from video context
    const loadNotes = function() {
        // TODO: Load existing notes from API
        const notesContent = document.querySelector(SELECTORS.NOTES_CONTENT);
        if (notesContent && notesContent.querySelector('.empty-state')) {
            // Notes will be loaded via API
        }
    };

    // Resources tab - load course materials
    const loadResources = function() {
        // TODO: Load course resources from API
        const resourcesList = document.querySelector(SELECTORS.RESOURCES_LIST);
        if (resourcesList && resourcesList.querySelector('.empty-state')) {
            // Resources will be loaded via API
        }
    };

    return {
        init: function(options) {
            courseId = options.courseId;
            sessionId = options.sessionId;
            courseName = options.courseName || '';
            questionsRemaining = 10;

            if (!options.hasCapability) return;

            setupVideoPlayer();
            setupTranscript();
            setupTabs();
            setupHeaderActions();
            setupChatInput();

            // Initial load for Notes/Resources tabs
            loadNotes();
            loadResources();
        }
    };
});