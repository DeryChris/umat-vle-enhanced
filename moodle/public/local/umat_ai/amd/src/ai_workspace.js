// ============================================================
// AMD module: local_umat_ai/ai_workspace
// Powers the expanded AI workspace page (index.php).
// Handles: video sync, transcript highlighting, tabs,
//          chat Q&A, suggestion chips, generate-summary action.
// ============================================================

define(['core/ajax', 'core/notification', 'local_umat_ai/umatshared'], function(Ajax, Notification, Shared) {
    'use strict';

    var courseId   = 0;
    var sessionId  = 0;
    var courseName = '';
    var sessionKey = '';
    var streamUrl  = '';
    var sesskey    = '';

    // ---- Video player ------------------------------------------------- //

    function initVideo() {
        var video    = document.getElementById('umat-lecture-video');
        var playBtn  = document.getElementById('ws-play-btn');
        var progress = document.getElementById('video-progress');
        var curTime  = document.getElementById('current-time');
        var durEl    = document.getElementById('duration');

        if (!video) return;

        function fmtTime(s) {
            var m = Math.floor(s / 60);
            var sec = Math.floor(s % 60);
            return m + ':' + (sec < 10 ? '0' : '') + sec;
        }

        video.addEventListener('loadedmetadata', function() {
            if (durEl) durEl.textContent = fmtTime(video.duration);
            if (progress) progress.max = Math.floor(video.duration);
        });

        video.addEventListener('timeupdate', function() {
            if (curTime)  curTime.textContent = fmtTime(video.currentTime);
            if (progress) progress.value = Math.floor(video.currentTime);
            highlightTranscriptSegment(video.currentTime);
        });

        if (playBtn) {
            playBtn.addEventListener('click', function() {
                if (video.paused) {
                    video.play();
                    playBtn.querySelector('.material-symbols-outlined').textContent = 'pause';
                } else {
                    video.pause();
                    playBtn.querySelector('.material-symbols-outlined').textContent = 'play_arrow';
                }
            });
        }

        if (progress) {
            progress.addEventListener('input', function() {
                video.currentTime = parseInt(progress.value);
            });
        }

        // Control buttons (rewind / forward).
        document.querySelectorAll('.umat-vc-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = btn.dataset.action;
                if (action === 'rewind-30')  video.currentTime = Math.max(0, video.currentTime - 30);
                if (action === 'forward-30') video.currentTime = Math.min(video.duration, video.currentTime + 30);
                if (action === 'play-pause') {
                    if (video.paused) video.play(); else video.pause();
                }
            });
        });
    }

    // ---- Transcript --------------------------------------------------- //

    function highlightTranscriptSegment(currentTime) {
        document.querySelectorAll('.umat-ts-segment').forEach(function(seg) {
            var start = parseFloat(seg.dataset.start) || 0;
            var end   = parseFloat(seg.dataset.end) || 0;
            if (currentTime >= start && currentTime <= end) {
                seg.classList.add('active');
                seg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                seg.classList.remove('active');
            }
        });
    }

    function initTranscript() {
        // Timestamp click → seek video.
        document.querySelectorAll('.timestamp-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var seg   = btn.closest('.umat-ts-segment');
                var start = parseFloat(seg.dataset.start) || 0;
                var video = document.getElementById('umat-lecture-video');
                if (video) {
                    video.currentTime = start;
                    video.play();
                }
            });
        });

        // Search filter.
        var searchInput = document.getElementById('transcript-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var q = searchInput.value.toLowerCase().trim();
                document.querySelectorAll('.umat-ts-segment').forEach(function(seg) {
                    var text = seg.querySelector('.umat-ts-text');
                    if (!q || (text && text.textContent.toLowerCase().includes(q))) {
                        seg.style.display = '';
                    } else {
                        seg.style.display = 'none';
                    }
                });
            });
        }
    }

    // ---- Tab switching ------------------------------------------------ //

    function initTabs() {
        document.querySelectorAll('.tab-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var name = btn.dataset.tab;
                document.querySelectorAll('.umat-ws-tab').forEach(function(t) { t.classList.remove('active'); });
                document.querySelectorAll('.umat-ws-pane').forEach(function(p) { p.classList.remove('active'); });
                btn.classList.add('active');
                var pane = document.getElementById('tab-' + name);
                if (pane) pane.classList.add('active');
            });
        });
    }

    // ---- Chat --------------------------------------------------------- //

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function appendAiBubble(text, sources) {
        var msgs = document.getElementById('workspace-chat-messages');
        if (!msgs) return;

        var typing = document.getElementById('workspace-typing');
        if (typing) msgs.removeChild(typing);

        var sourcesHtml = '';
        if (sources && sources.length > 0) {
            sourcesHtml = '<div class="umat-ws-sources">' +
                sources.map(function(s) {
                    return '<span class="umat-ws-source">' + escHtml(s) + '</span>';
                }).join('') + '</div>';
        }

        var div = document.createElement('div');
        div.innerHTML =
            '<div class="umat-ws-msg-ai">' +
              '<div class="umat-ws-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>' +
              '<div class="umat-ws-bubble-ai"><p>' + escHtml(text) + '</p></div>' +
            '</div>' + sourcesHtml;
        msgs.appendChild(div);

        // Re-append typing indicator at the bottom.
        if (typing) msgs.appendChild(typing);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function appendUserBubble(text) {
        var msgs = document.getElementById('workspace-chat-messages');
        if (!msgs) return;
        var div = document.createElement('div');
        div.className = 'umat-ws-msg-student';
        div.innerHTML = '<div class="umat-ws-bubble-student"><p>' + escHtml(text) + '</p></div>';
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function showTyping() {
        var el = document.getElementById('workspace-typing');
        if (el) el.style.display = '';
        var msgs = document.getElementById('workspace-chat-messages');
        if (msgs) msgs.scrollTop = msgs.scrollHeight;
    }

    function hideTyping() {
        var el = document.getElementById('workspace-typing');
        if (el) el.style.display = 'none';
    }

    function sendQuestion(q) {
        q = (q || '').trim();
        if (!q || !courseId) return;

        Shared._umatAppendUser('workspace-chat-messages', q);
        showTyping();

        var inputEl = document.getElementById('workspace-question-input');
        if (inputEl) inputEl.value = '';

        var msgsId = 'workspace-chat-messages';
        var tid = 'workspace-typing';

        Shared._umatStreamChat({
            url: streamUrl,
            sesskey: sesskey,
            courseid: courseId,
            question: q,
            session_key: sessionKey,
            material_ids: [],
            msgsId: msgsId,
            typingId: tid,
            label: 'AI ASSISTANT',
            onMeta: function(meta){ hideTyping(); },
            onDone: function(meta){ hideTyping(); },
            onError: function(err){
                hideTyping();
                Shared._umatAppendAi(msgsId, err.message || 'Sorry, an error occurred. Please try again.', []);
            }
        });
    }

    function initChat() {
        var input   = document.getElementById('workspace-question-input');
        var sendBtn = document.getElementById('workspace-send-btn');

        if (sendBtn) sendBtn.addEventListener('click', function() { sendQuestion(input.value); });
        if (input) {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendQuestion(input.value);
                }
            });
        }

        // Suggestion chips.
        var chipMap = {
            'explain':   'Can you explain the concept that was just discussed in the video?',
            'elaborate': 'Can you elaborate on that topic with more detail and examples?',
            'compare':   'How does this concept compare to what was covered earlier in the course?',
        };
        document.querySelectorAll('.suggestion-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                var key = chip.dataset.suggestion;
                if (chipMap[key]) sendQuestion(chipMap[key]);
            });
        });
    }

    // ---- Action buttons (header) -------------------------------------- //

    function initActions() {
        document.querySelectorAll('.action-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = btn.dataset.action;
                if (action === 'generate-summary') generateSummary();
                if (action === 'attach-material')  handleAttachMaterial();
            });
        });
    }

    function generateSummary() {
        var notesPane = document.getElementById('tab-notes');
        var notesContent = document.getElementById('notes-content');

        // Switch to notes tab.
        document.querySelectorAll('.umat-ws-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.umat-ws-pane').forEach(function(p) { p.classList.remove('active'); });
        var notesTab = document.querySelector('[data-tab="notes"]');
        if (notesTab) notesTab.classList.add('active');
        if (notesPane) notesPane.classList.add('active');

        if (notesContent) {
            notesContent.innerHTML =
                '<div class="umat-ws-msg-ai" style="max-width:100%;">' +
                  '<div class="umat-ws-msg-ai-icon"><span class="material-symbols-outlined">smart_toy</span></div>' +
                  '<div class="umat-ws-bubble-ai" style="flex:1;">' +
                    '<div class="umat-ws-typing"><span></span><span></span><span></span></div>' +
                    '<p style="font-size:12px;color:var(--ol);margin:6px 0 0;">Generating notes…</p>' +
                  '</div>' +
                '</div>';
        }

        Ajax.call([{
            methodname: 'local_umat_ai_get_session_outputs',
            args: { sessionid: sessionId, courseid: courseId },
        }])[0].done(function(r) {
            if (notesContent && r.outputs && r.outputs.length > 0) {
                var html = '';
                r.outputs.forEach(function(o) {
                    html +=
                        '<div style="background:var(--sflo);border:1px solid var(--olv);border-radius:var(--r12);padding:16px;margin-bottom:12px;">' +
                          '<h4 style="margin:0 0 10px;font-size:13px;font-weight:700;color:var(--p);text-transform:capitalize;">' + o.type + '</h4>' +
                          '<div style="font-size:13px;line-height:1.65;color:var(--ons);white-space:pre-wrap;">' + escHtml(o.content) + '</div>' +
                        '</div>';
                });
                notesContent.innerHTML = html;
            } else if (notesContent) {
                notesContent.innerHTML = '<div style="text-align:center;padding:32px;color:var(--ol);font-size:13px;">No AI notes are available for this session yet. They will appear once the lecturer approves the AI-generated content.</div>';
            }
        }).fail(function() {
            if (notesContent) {
                notesContent.innerHTML = '<div style="text-align:center;padding:32px;color:var(--ter);font-size:13px;">Failed to load notes. Please try again.</div>';
            }
        });
    }

    function handleAttachMaterial() {
        appendAiBubble('To attach materials for AI indexing, please ask your lecturer to upload course documents through the course admin area.', []);
    }

    // ---- Entry point -------------------------------------------------- //

    function init(cfg) {
        courseId   = cfg.courseId   || 0;
        sessionId  = cfg.sessionId  || 0;
        courseName = cfg.courseName || '';
        sessionKey = 'ws_' + Math.random().toString(36).substr(2, 16);
        streamUrl  = cfg.streamUrl  || '';
        sesskey    = cfg.sesskey    || '';

        initVideo();
        initTranscript();
        initTabs();
        initChat();
        initActions();
    }

    return { init: init };
});
