/*
 * AI Tutor — Notebook-style three-panel workspace.
 *
 * Layout: topbar | [ left: session history | center: chat | right: Studio ]
 * Rendered by overlay_helper.php :: ai_tutor_workspace()
 * Styled by styles/umat-ai-tutor.css
 *
 * Used in two modes:
 *   mode='course' — inside the student workspace "AI Tutor" tab (fixed course).
 *   mode='hub'    — inside the hub "AI Tutor" tab (cross-course selector).
 *
 * Reuses the shared chat stack from umatshared.js:
 *   _umatStreamChat / _umatAppendUser / _umatAppendAi / _umatShowTyping /
 *   _umatHideTyping / _umatFormatAI / _umatInitAttachDrawer /
 *   _umatRenderMatsBar / ChatVoiceInput / ajax
 */
define(['core/ajax', 'local_umat_ai/umatshared', 'local_umat_ai/material_viewer'], function(Ajax, S, M) {
    'use strict';

    function esc(t) {
        return S._umatEsc(String(t == null ? '' : t));
    }

    /** Local bindChat (same contract as umat_hub.js). */
    function bindChat(input, sendButton, messages, onSend) {
        if (!input || !sendButton || !messages) {
            console.warn('[ait] Chat controls are missing; chat was not initialized.');
            return null;
        }
        if (sendButton._aitChatControl) return sendButton._aitChatControl;
        function sync() {
            if (sendButton.getAttribute('aria-busy') !== 'true') {
                sendButton.disabled = !input.value.trim();
            }
        }
        function submit() {
            if (sendButton.getAttribute('aria-busy') === 'true') return;
            var q = input.value.trim();
            if (!q) { sync(); return; }
            onSend(q);
            sync();
        }
        sendButton.addEventListener('click', submit);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); }
        });
        input.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 200) + 'px';
            sync();
        });
        sendButton._aitChatControl = { submit: submit, sync: sync };
        sync();
        return sendButton._aitChatControl;
    }

    return {
        init: function(data) {
            // Expose shared helpers globally (mirrors umat_hub/umat_student).
            for (var k in S) window[k] = S[k];
            window.umatMaterialViewer = M;

            var UD = typeof data.userData === 'string' ? JSON.parse(data.userData || '{}') : (data.userData || {});
            var streamUrl = data.streamUrl;
            var moodleSesskey = data.moodleSesskey;
            var MODE = data.mode || 'course';
            var FIXED_CID = parseInt(data.courseId) || 0;
            var FIXED_CNAME = data.courseName || 'your course';
            var WWW = window.location.origin || '';

            var root = document.getElementById('ait-root');
            var msgs = document.getElementById('ait-msgs');
            var input = document.getElementById('ait-input');
            var sendBtn = document.getElementById('ait-send');
            var sessList = document.getElementById('ait-sess-list');
            if (!root || !msgs || !input || !sendBtn || !sessList) return;

            var courseSel = document.getElementById('ait-course-sel');
            var left = document.getElementById('ait-left');
            var studio = document.getElementById('ait-studio');

            /* ── Panel persistence (localStorage keys per plan) ─────────── */
            var LS_LEFT = 'ait-left-panel-open';
            var LS_STUDIO = 'ait-studio-panel-open';
            var LS_TAB = 'ait-active-tab';
            var LS_SID = 'ait-last-session-id';
            var LS_CID = 'ait-last-course-id';
            var LS_WIDTHS = 'ait-panel-widths';
            function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
            function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

            /* ── Resizable panels ──────────────────────────────────────── */
            (function initResizable() {
                var div1 = document.getElementById('ait-div-1');
                var div2 = document.getElementById('ait-div-2');
                var leftEl = document.getElementById('ait-left');
                var studioEl = document.getElementById('ait-studio');
                if (!div1 || !div2 || !leftEl || !studioEl) return;

                // Restore saved widths
                var saved = lsGet(LS_WIDTHS);
                if (saved) {
                    try {
                        var w = JSON.parse(saved);
                        if (w.left && w.left > 180) { leftEl.style.flexBasis = w.left + 'px'; leftEl.style.width = w.left + 'px'; }
                        if (w.studio && w.studio > 180) { studioEl.style.flexBasis = w.studio + 'px'; studioEl.style.width = w.studio + 'px'; }
                    } catch (e) {}
                }

                function startDrag(div, target, side, ev) {
                    var startX = ev.clientX;
                    var startW = parseInt(target.style.width) || (side === 'left' ? 262 : 330);
                    // Disable transitions during drag for instant feedback
                    target.style.transition = 'none';

                    function onMove(e) {
                        e.preventDefault();
                        var dx = e.clientX - startX;
                        var newW = side === 'left' ? startW + dx : startW - dx;
                        newW = Math.max(200, Math.min(600, newW));
                        target.style.flexBasis = newW + 'px';
                        target.style.width = newW + 'px';
                    }
                    function onUp() {
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        document.removeEventListener('selectstart', preventSelect);
                        div.classList.remove('dragging');
                        root.classList.remove('resizing');
                        // Re-enable transitions
                        target.style.transition = '';
                        // Save widths
                        lsSet(LS_WIDTHS, JSON.stringify({
                            left: parseInt(leftEl.style.width) || 262,
                            studio: parseInt(studioEl.style.width) || 330
                        }));
                    }
                    function preventSelect(e) { e.preventDefault(); }
                    div.classList.add('dragging');
                    root.classList.add('resizing');
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                    document.addEventListener('selectstart', preventSelect);
                }
                div1.addEventListener('mousedown', function(e) { e.preventDefault(); startDrag(div1, leftEl, 'left', e); });
                div2.addEventListener('mousedown', function(e) { e.preventDefault(); startDrag(div2, studioEl, 'right', e); });
            })();

            /* ── Session state ──────────────────────────────────────────── */
            var sessKey = lsGet(LS_SID) || 'ait_' + Math.random().toString(36).substr(2, 18);
            var selMat = [];
            var activeCID = FIXED_CID || parseInt(lsGet(LS_CID) || '0') || 0;
            var busy = false;
            var lastSrcCount = 0; // sources reported in the stream meta event

            /* ── Rate limit (10 questions / minute, mirroring Moodle) ──── */
            var qTimes = [];
            function qRemaining() {
                var now = Date.now();
                qTimes = qTimes.filter(function(t) { return now - t < 60000; });
                return Math.max(0, 10 - qTimes.length);
            }

            function currentCID() {
                if (MODE === 'course') return FIXED_CID;
                var v = parseInt(courseSel && courseSel.value) || 0;
                return v || activeCID || 0;
            }

            /* ── Toast ─────────────────────────────────────────────────── */
            var toastEl = document.getElementById('ait-toast');
            var toastTimer = null;
            function toast(msg) {
                if (!toastEl) return;
                toastEl.textContent = msg;
                toastEl.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function() { toastEl.classList.remove('show'); }, 2600);
            }

            /* ── Message rendering ─────────────────────────────────────── */
            function appendMsg(q, isUser, o) {
                o = o || {};
                var d = document.createElement('div');
                if (isUser) {
                    var chipHtml = '';
                    if (o.selMat && o.selMat.length) {
                        chipHtml = '<div class="umat-ref-chips">' + o.selMat.map(function(m) {
                            return '<span class="umat-ref-chip"><span class="material-symbols-outlined">attach_file</span>' + esc(m.name) + '</span>';
                        }).join('') + '</div>';
                    }
                    d.innerHTML = '<div class="umat-msg-user"><div class="umat-bubble-user"><p>' + esc(q) + '</p></div>' + chipHtml +
                        '<button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div>';
                } else {
                    var srcs = '';
                    if (o.sources && o.sources.length && (!o.citations || !o.citations.length)) {
                        srcs = '<div class="umat-src-chips">' + o.sources.map(function(s) {
                            return '<span class="umat-src-chip">' + esc(s) + '</span>';
                        }).join('') + '</div>';
                    }
                    var citeZone = '';
                    if (o.citations && o.citations.length) {
                        citeZone = '<div class="umat-msg-citations-cards" data-cites=\'' + esc(JSON.stringify(o.citations)) + '\'></div>';
                    }
                    d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>' +
                        '<div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div>' +
                        '<div class="umat-bubble-ai"><div class="umat-ai-content">' + S._umatFormatAI(q) + '</div>' + srcs + citeZone + '</div>' +
                        '<button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div></div>';
                }
                var rb = d.querySelector('.umat-reply-btn');
                if (rb) rb.addEventListener('click', S._umatHandleReply);
                msgs.appendChild(d);
                msgs.scrollTop = msgs.scrollHeight;
                if (!isUser && o.citations && o.citations.length && S._umatRenderCitations) {
                    var zone = d.querySelector('.umat-msg-citations-cards');
                    if (zone) S._umatRenderCitations(zone, o.citations);
                }
                return d;
            }

            function welcomeMarkup() {
                var name = MODE === 'course' ? '<strong>' + esc(FIXED_CNAME) + '</strong>' : 'your courses';
                var chips = [
                    'Explain the key concepts from the most recent lecture.',
                    'Create a practice quiz on this topic.',
                    'Summarize our conversation so far.',
                    'What are common exam questions for this topic?'
                ].map(function(q) {
                    return '<button class="umat-chip" data-q="' + esc(q) + '" type="button">' + esc(q.split('.').slice(0, 2).join('.')) + '</button>';
                }).join('');
                return '<div class="ait-welcome">' +
                    '<div class="ait-welcome-ic"><span class="material-symbols-outlined">smart_toy</span></div>' +
                    '<h3>Welcome to your AI Tutor</h3>' +
                    '<p>I can help you study for ' + name + ' — ask questions, generate quizzes, flashcards, study guides and more using your course materials.</p>' +
                    '<div class="umat-chips-row">' + chips + '</div></div>';
            }

            function showWelcome() {
                msgs.innerHTML = welcomeMarkup();
            }

            /* ── Suggestions after each answer ─────────────────────────── */
            function appendSuggestions() {
                var row = document.createElement('div');
                row.className = 'ait-sugg-row';
                row.innerHTML = ['Create a practice quiz on this topic', 'Explain this in simpler terms', 'Generate study guide notes'].map(function(q) {
                    return '<button class="ait-sugg-chip" data-q="' + esc(q) + '" type="button">' + esc(q) + '</button>';
                }).join('');
                msgs.appendChild(row);
                msgs.scrollTop = msgs.scrollHeight;
            }

            /* ── Chat send ─────────────────────────────────────────────── */
            function sendQ(q, opts) {
                opts = opts || {};
                q = (q || '').trim();
                if (!q || busy) return;
                if (qRemaining() <= 0) {
                    toast('Rate limit reached. Please wait a moment before asking again.');
                    return;
                }
                var cid = currentCID();
                qTimes.push(Date.now());
                busy = true;
                sendBtn.setAttribute('aria-busy', 'true');
                sendBtn.disabled = true;

                var ctx = q;
                if (selMat.length) {
                    ctx = '[Referencing: ' + selMat.map(function(m) { return m.name; }).join(', ') + '] ' + q;
                }
                appendMsg(q, true, { selMat: selMat });
                input.value = '';
                input.style.height = 'auto';

                var tid = 'g_' + Date.now();
                S._umatShowTyping('ait-msgs', tid);
                S._umatStreamChat({
                    url: streamUrl,
                    sesskey: moodleSesskey,
                    courseid: cid,
                    question: ctx,
                    session_key: sessKey,
                    material_ids: selMat.map(function(m) { return m.id; }),
                    msgsId: 'ait-msgs',
                    sendBtnId: 'ait-send',
                    sendInputId: 'ait-input',
                    typingId: tid,
                    onMeta: function(meta) {
                        lastSrcCount = (meta && meta.sources && meta.sources.length) || 0;
                    },
                    onDone: function() {
                        S._umatHideTyping(tid);
                        busy = false;
                        sendBtn.removeAttribute('aria-busy');
                        if (chatControl) chatControl.sync();
                        lsSet(LS_SID, sessKey);
                        lsSet(LS_CID, String(cid));
                        // NOTE: tool generation (quiz/guide/summary/faq) no
                        // longer runs through the chat panel — see runTool().
                        // Plain Q&A only lands here.
                        appendSuggestions();
                        refreshSessions();
                    },
                    onError: function(err) {
                        S._umatHideTyping(tid);
                        busy = false;
                        sendBtn.removeAttribute('aria-busy');
                        if (chatControl) chatControl.sync();
                        if (err && err.error === 'rate_limit') qTimes.pop();
                    }
                });
            }

            /* ── Quiz JSON detection + inline renderer ─────────────────── */
            function extractQuiz(text) {
                if (!text) return null;
                var m = text.match(/\{[\s\S]*"questions"[\s\S]*\}/);
                if (!m) return null;
                try {
                    var o = JSON.parse(m[0]);
                    if (o.questions && o.questions.length) {
                        return { title: o.quiz_title || o.title || 'Practice Quiz', questions: o.questions };
                    }
                } catch (e) {}
                return null;
            }

            /* Normalize quiz payloads from any source (LLM JSON, quiz_data SSE
             * event, history) into the renderer's expected shape. Real LLM
             * output varies — questions may use `question`/`text`/`prompt`
             * instead of `q`, `choices` instead of `options`, etc. Without
             * this the question text would render empty (only the options
             * show), which users reported as "I only see the answers". */
            function normalizeQuiz(quiz) {
                if (!quiz || !quiz.questions || !quiz.questions.length) return quiz;
                quiz.questions = quiz.questions.map(function(qo) {
                    if (!qo || typeof qo !== 'object') {
                        return { q: String(qo || ''), options: [], answer: '', explanation: '' };
                    }
                    return {
                        q: qo.q || qo.question || qo.Question || qo.text || qo.prompt || qo.question_text || qo.query || '',
                        options: qo.options || qo.choices || qo.Options || qo.Choices || qo.options_list || qo.answers || [],
                        answer: qo.answer || qo.correct_answer || qo.correct || qo.Answer || qo.Correct || '',
                        explanation: qo.explanation || qo.explain || qo.reason || qo.hint || qo.Explanation || ''
                    };
                });
                if (!quiz.title) quiz.title = quiz.quiz_title || 'Practice Quiz';
                return quiz;
            }

            function renderQuizCard(quiz) {
                quiz = normalizeQuiz(quiz);
                if (!quiz) return;
                var card = document.createElement('div');
                card.className = 'ait-quiz-card';
                var idx = 0, correct = 0, answered = false, total = quiz.questions.length;
                var q = quiz.questions[0];

                function buildHeader() {
                    var dots = '';
                    for (var i = 0; i < total; i++) {
                        dots += '<span class="ait-quiz-dot' + (i < idx ? ' done' : '') + '"></span>';
                    }
                    return '<div class="ait-quiz-hdr"><span class="material-symbols-outlined">quiz</span>' +
                        '<strong>' + esc(quiz.title) + '</strong><span class="ait-quiz-score-tag" style="margin-left:6px;">' + idx + '/' + total + '</span>' +
                        '<div class="ait-quiz-progress">' + dots + '</div></div>';
                }

                function render() {
                    if (idx >= total) {
                        var pct = Math.round(correct / total * 100);
                        var msg = pct >= 80 ? 'Excellent work!' : (pct >= 50 ? 'Good effort — keep practising!' : 'Keep reviewing — you will get there!');
                        card.innerHTML = buildHeader() +
                            '<div class="ait-quiz-body" style="text-align:center;padding:22px;">' +
                            '<div class="ait-welcome-ic" style="width:46px;height:46px;border-radius:14px;margin:0 auto 10px;"><span class="material-symbols-outlined" style="font-size:24px;">' + (pct >= 50 ? 'emoji_events' : 'school') + '</span></div>' +
                            '<div style="font-size:26px;font-weight:800;color:var(--u-p);">' + correct + ' / ' + total + '</div>' +
                            '<div style="font-size:12px;color:var(--u-ol);margin-top:4px;">' + msg + '</div></div>' +
                            '<div class="ait-quiz-foot"><span class="ait-quiz-score-tag">' + pct + '%</span>' +
                            '<button class="ait-quiz-next" id="ait-quiz-restart" type="button">Try Again</button></div>';
                        var retry = card.querySelector('#ait-quiz-restart');
                        if (retry) retry.addEventListener('click', function() {
                            idx = 0; correct = 0;
                            q = quiz.questions[0];
                            render();
                        });
                        saveAttempt();
                        return;
                    }
                    q = quiz.questions[idx];
                    answered = false;
                    card.innerHTML = buildHeader() +
                        '<div class="ait-quiz-body"><div class="ait-quiz-q">' + esc(q.q) + '</div>' +
                        '<div class="ait-quiz-opts">' + (q.options || []).map(function(op, i) {
                            return '<button class="ait-quiz-opt" data-i="' + i + '" type="button">' + esc(op) + '</button>';
                        }).join('') + '</div>' +
                        '<div class="ait-quiz-exp" id="ait-quiz-exp"></div></div>' +
                        '<div class="ait-quiz-foot"><span class="ait-quiz-score-tag">Question ' + (idx + 1) + ' of ' + total + '</span>' +
                        '<button class="ait-quiz-next" id="ait-quiz-next" type="button" disabled>Next</button></div>';
                    card.querySelectorAll('.ait-quiz-opt').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            if (answered) return;
                            answered = true;
                            var chosen = q.options[parseInt(btn.dataset.i)];
                            var isCorrect = String(chosen).trim().toLowerCase() === String(q.answer).trim().toLowerCase() ||
                                String(chosen).trim().toLowerCase().indexOf(String(q.answer).trim().toLowerCase().charAt(0) + ')') === 0;
                            // Also accept "A) ..." vs "A" style comparisons.
                            if (!isCorrect && /^[A-Da-d]\)/.test(String(q.answer).trim()) && /^[A-Da-d]\)/.test(String(chosen).trim())) {
                                isCorrect = String(chosen).trim().charAt(0).toUpperCase() === String(q.answer).trim().charAt(0).toUpperCase();
                            }
                            if (isCorrect) correct++;
                            card.querySelectorAll('.ait-quiz-opt').forEach(function(b) {
                                b.disabled = true;
                                var txt = q.options[parseInt(b.dataset.i)];
                                var bIsAns = String(txt).trim().toLowerCase() === String(q.answer).trim().toLowerCase() ||
                                    (String(txt).trim().charAt(0).toUpperCase() === String(q.answer).trim().charAt(0).toUpperCase() && /^[A-Da-d]\)/.test(String(q.answer).trim()));
                                if (b === btn) b.classList.add(isCorrect ? 'correct' : 'wrong');
                                if (bIsAns && !isCorrect) b.classList.add('correct');
                            });
                            var exp = card.querySelector('#ait-quiz-exp');
                            if (exp) {
                                exp.textContent = (isCorrect ? '✓ Correct! ' : '✗ Not quite. ') + (q.explanation || '');
                            }
                            var next = card.querySelector('#ait-quiz-next');
                            if (next) next.disabled = false;
                        });
                    });
                    var next = card.querySelector('#ait-quiz-next');
                    if (next) next.addEventListener('click', function() {
                        idx++;
                        render();
                    });
                }

                function saveAttempt() {
                    var cid = currentCID();
                    if (!cid) return;
                    Ajax.call([{
                        methodname: 'local_umat_ai_save_quiz_attempt',
                        args: {
                            courseid: cid,
                            quiztitle: quiz.title,
                            total: total,
                            correct: correct,
                            score: Math.round(correct / total * 100),
                            details: JSON.stringify(quiz.questions.map(function(question, i) {
                                return { q: question.q, answer: question.answer, chosen: null };
                            }))
                        }
                    }])[0].fail(function() {});
                }

                render();
                msgs.appendChild(card);
                msgs.scrollTop = msgs.scrollHeight;
            }

            /* ── Studio output list ──────────────────────────────────────── */
            var outputItems = [];
            var outputListEl = document.getElementById('ait-studio-outputs');
            var pendingTool = null; // type of tool currently generating

            function addGeneratingCard(tool) {
                pendingTool = tool;
                var names = { quiz: 'Creating Quiz', guide: 'Creating Study Guide', summary: 'Creating Summary', faq: 'Creating FAQ', flashcards: 'Creating Flashcards' };
                outputItems.unshift({ id: 'gen_' + tool, type: tool, title: names[tool] || 'Generating…', meta: 'Streaming…', data: null, time: Date.now(), generating: true });
                renderOutputList();
            }

            function addOutputCard(type, title, meta, data) {
                pendingTool = null;
                // Replace the generating placeholder if present.
                for (var i = 0; i < outputItems.length; i++) {
                    if (outputItems[i].id === 'gen_' + type) { outputItems.splice(i, 1); break; }
                }
                var item = { id: Date.now(), type: type, title: title, meta: meta || '', data: data, time: Date.now() };
                outputItems.unshift(item);
                renderOutputList();
                return item;
            }

            function removeGeneratingCard() {
                // Called when the stream fails — drop the placeholder so the
                // Studio does not show a card that can never complete.
                pendingTool = null;
                for (var i = outputItems.length - 1; i >= 0; i--) {
                    if (outputItems[i].generating) { outputItems.splice(i, 1); }
                }
                renderOutputList();
            }

            function renderOutputList() {
                if (!outputListEl) return;
                // Generated items first, then DB notes (pinned first) — all in
                // one list, each card tagged with a category chip.
                var items = outputItems.slice();
                if (notes.length) {
                    notes.slice().sort(function(a, b) { return (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0); })
                        .forEach(function(n) {
                            items.push({
                                id: 'note_' + n.id, type: 'note', title: n.title || 'Untitled',
                                meta: 'Note' + (S._umatTimeAgo ? ' · ' + S._umatTimeAgo(n.timemodified) : ''),
                                data: n, time: n.timemodified || Date.now()
                            });
                        });
                }
                if (!items.length) {
                    outputListEl.innerHTML = '<div class="ait-empty" style="padding:16px 6px;"><span class="material-symbols-outlined">auto_awesome</span>Generated content will appear here.</div>';
                    return;
                }
                var chipMap = { quiz: 'Quiz', note: 'Note', guide: 'Study Guide', summary: 'Summary', faq: 'FAQ', flashcards: 'Flashcards' };
                outputListEl.innerHTML = items.map(function(item) {
                    if (item.generating) {
                        return '<div class="ait-output-generating" data-id="' + item.id + '">' +
                            '<span class="material-symbols-outlined">hourglass_top</span>' +
                            '<div class="ait-output-generating-info"><div class="ait-output-generating-title">' + esc(item.title) +
                            '<span class="ait-output-generating-dots"><span></span><span></span><span></span></span></div>' +
                            '<div class="ait-output-card-meta">Streaming…</div></div></div>';
                    }
                    var icon = item.type === 'quiz' ? 'quiz' : item.type === 'note' ? 'note' : item.type === 'flashcards' ? 'style' : item.type === 'guide' ? 'menu_book' : item.type === 'summary' ? 'summarize' : 'question_answer';
                    var timeAgo = S._umatTimeAgo ? S._umatTimeAgo(item.time) : '';
                    return '<div class="ait-output-card" data-id="' + item.id + '" data-type="' + item.type + '">' +
                        '<span class="material-symbols-outlined">' + icon + '</span>' +
                        '<span class="ait-out-chip">' + (chipMap[item.type] || 'Item') + '</span>' +
                        '<div class="ait-output-card-info"><div class="ait-output-card-title">' + esc(item.title) + '</div>' +
                        '<div class="ait-output-card-meta">' + esc(item.meta) + (timeAgo ? ' · ' + esc(timeAgo) : '') + '</div></div>' +
                        '<button class="ait-output-card-menu" type="button" title="More"><span class="material-symbols-outlined">more_vert</span></button></div>';
                }).join('');
                outputListEl.querySelectorAll('.ait-output-card').forEach(function(card) {
                    card.addEventListener('click', function(e) {
                        if (e.target.closest('.ait-output-card-menu')) return;
                        var id = card.dataset.id;
                        var item = items.find(function(x) { return String(x.id) === String(id); });
                        if (item) openCardItem(item);
                    });
                    card.querySelector('.ait-output-card-menu').addEventListener('click', function(e) {
                        e.stopPropagation();
                        var id = card.dataset.id;
                        var item = items.find(function(x) { return String(x.id) === String(id); });
                        if (item) showCardMenu(card, item);
                    });
                });
            }

            /* Open an output card — always INSIDE the Studio panel (chat and
             * sessions stay visible beside it). */
            function openCardItem(item) {
                if (!item || !item.data) return;
                if (item.type === 'quiz') {
                    openQuizSubView(item.data, item.title);
                } else if (item.type === 'note') {
                    openNoteSubView(item.data, false);
                } else if (item.type === 'flashcards') {
                    openFlashcardsSubView(item);
                } else if (item.type === 'guide' || item.type === 'summary' || item.type === 'faq') {
                    openContentSubView(item.title, item.data.text, item);
                }
            }

            /* ── Card overflow menu (⋮) ────────────────────────────────── */
            var cardMenuEl = null;
            var cardMenuCtx = null;
            function hideCardMenu() { if (cardMenuEl) cardMenuEl.remove(); cardMenuEl = null; cardMenuCtx = null; }
            function showCardMenu(card, item) {
                hideCardMenu();
                var host = studioBody || document.body;
                cardMenuEl = document.createElement('div');
                cardMenuEl.className = 'ait-card-menu';
                cardMenuCtx = item;
                var rows = [];
                rows.push(menuRow('open_in_new', 'Open', function() { hideCardMenu(); openCardItem(item); }));
                if (item.type === 'note') {
                    rows.push(menuRow('edit', 'Edit', function() { hideCardMenu(); openNoteSubView(item.data, true); }));
                    rows.push(menuRow(item.data.pinned ? 'push_pin' : 'push_pin', item.data.pinned ? 'Unpin' : 'Pin note', function() { hideCardMenu(); toggleNotePin(item); }));
                    rows.push(menuSep());
                    rows.push(menuRow('delete', 'Delete note', function() { hideCardMenu(); deleteNoteById(item.data); }, true));
                } else if (item.type === 'guide' || item.type === 'summary' || item.type === 'faq') {
                    rows.push(menuRow('note_add', 'Save to Notes', function() { hideCardMenu(); openNoteSubView({ id: 0, title: item.title, content: item.data.text, pinned: 0 }, true); }));
                    rows.push(menuSep());
                    rows.push(menuRow('delete', 'Remove', function() { hideCardMenu(); removeOutputItem(item); }, true));
                } else {
                    rows.push(menuSep());
                    rows.push(menuRow('delete', 'Remove', function() { hideCardMenu(); removeOutputItem(item); }, true));
                }
                cardMenuEl.innerHTML = rows.map(function(r) { return r.html; }).join('');
                // Attach each row's click handler — rows are plain HTML, so
                // they were previously unclickable (menu did nothing).
                var rowFns = rows.map(function(r) { return r.fn; }).filter(Boolean);
                cardMenuEl.querySelectorAll('button[data-act]').forEach(function(btn, i) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        rowFns[i]();
                    });
                });
                host.appendChild(cardMenuEl);
                var r = card.getBoundingClientRect(), h = host.getBoundingClientRect();
                var mw = 172, mh = cardMenuEl.offsetHeight || 120;
                cardMenuEl.style.left = Math.max(0, Math.min(r.right - mw - h.left, h.width - mw - 6)) + 'px';
                cardMenuEl.style.top = Math.max(0, Math.min(r.top - h.top, h.height - mh - 6)) + 'px';
                setTimeout(function() {
                    document.addEventListener('click', hideCardMenu, { once: true });
                }, 0);
            }
            function menuRow(icon, label, fn, danger) {
                return { html: '<button type="button" class="' + (danger ? 'danger' : '') + '" data-act="' + esc(label) + '"><span class="material-symbols-outlined">' + icon + '</span>' + esc(label) + '</button>', fn: fn };
            }
            function menuSep() { return { html: '<div class="ait-card-menu-sep"></div>', fn: null }; }
            function removeOutputItem(item) {
                // Persisted cards are deleted server-side — confirm once so a
                // misclick cannot wipe a saved quiz/guide/faq forever.
                if (item.persisted && !confirm('Remove this from Studio? It will be deleted permanently.')) return;
                outputItems = outputItems.filter(function(x) { return String(x.id) !== String(item.id); });
                deletePersistedOutput(item);
                renderOutputList();
                toast('Removed from Studio.');
            }
            function deleteNoteById(note) {
                if (!note || !note.id) return;
                if (!confirm('Delete this note?')) return;
                Ajax.call([{ methodname: 'local_umat_ai_delete_note', args: { noteid: note.id } }])[0]
                    .done(function() { toast('Note deleted.'); loadNotes(); })
                    .fail(function() { toast('Failed to delete note.'); });
            }
            function toggleNotePin(item) {
                var n = item.data;
                var payload = {
                    noteid: n.id, courseid: currentCID(),
                    title: n.title || 'Untitled note', content: n.content || '',
                    pinned: n.pinned ? 0 : 1, tags: []
                };
                Ajax.call([{ methodname: 'local_umat_ai_save_note', args: payload }])[0]
                    .done(function(r) { toast(r.saved ? (n.pinned ? 'Note unpinned.' : 'Note pinned.') : 'Could not update note.'); loadNotes(); })
                    .fail(function() { toast('Failed to update note.'); });
            }

            /* ── Studio output persistence ────────────────────────────────
             * Generated cards (quiz / guide / summary / faq / flashcards) are
             * saved to umat_ai_studio_outputs so they survive a refresh and a
             * re-login — they stay in the Studio until the student removes
             * them via the ⋮ menu. */
            function persistOutput(item, payloadObj) {
                var cid = currentCID();
                if (!cid || !item || !item.type) return;
                try {
                    Ajax.call([{
                        methodname: 'local_umat_ai_save_studio_output',
                        args: {
                            courseid: cid,
                            type: item.type,
                            title: String(item.title || '').substr(0, 250),
                            payload: JSON.stringify(payloadObj || {})
                        }
                    }])[0].done(function(r) {
                        if (r && r.outputid) { item.persisted = true; item.oid = r.outputid; }
                    }).fail(function() {});
                } catch (e) {}
            }
            function deletePersistedOutput(item) {
                if (!item || !item.oid) return;
                Ajax.call([{ methodname: 'local_umat_ai_delete_studio_output', args: { outputid: item.oid } }])[0]
                    .fail(function() { toast('Could not delete saved item.'); });
            }
            /* Restore persisted cards at boot. Quizzes and flashcard decks
             * dedupe by title (a re-generated card replaces the old one);
             * content cards (guide/summary/faq) always restore — each is a
             * distinct generation. */
            function loadPersistedOutputs() {
                var cid = currentCID();
                if (!cid) return;
                Ajax.call([{ methodname: 'local_umat_ai_get_studio_outputs', args: { courseid: cid } }])[0]
                    .done(function(r) {
                        var list = (r && r.outputs) || [];
                        if (!list.length) return;
                        var seen = {};
                        outputItems.forEach(function(x) { seen[x.type + '::' + x.title] = true; });
                        var changed = false;
                        list.slice().reverse().forEach(function(o) {
                            var type = o.type || '', title = o.title || '';
                            var data = null;
                            try { data = JSON.parse(o.payload || '{}'); } catch (e) { data = null; }
                            var item = null;
                            if (type === 'quiz') {
                                if (!data || !data.quiz) return;
                                if (seen['quiz::' + title]) return;
                                item = { id: 'st_' + o.id, type: 'quiz', title: title, meta: 'Saved', data: data.quiz, time: o.timecreated, persisted: true, oid: o.id };
                            } else if (type === 'guide' || type === 'summary' || type === 'faq') {
                                if (!data || typeof data.text !== 'string') return;
                                item = { id: 'st_' + o.id, type: type, title: title, meta: 'Saved', data: { text: data.text }, time: o.timecreated, persisted: true, oid: o.id };
                            } else if (type === 'flashcards') {
                                if (seen['flashcards::' + title]) return;
                                item = { id: 'st_' + o.id, type: 'flashcards', title: title, meta: (data && data.count ? data.count + ' cards' : ''), data: { topic: (data && data.topic) || '', count: (data && data.count) || 0 }, time: o.timecreated, persisted: true, oid: o.id };
                            }
                            if (item) {
                                seen[type + '::' + title] = true;
                                outputItems.unshift(item);
                                changed = true;
                            }
                        });
                        if (changed) renderOutputList();
                    })
                    .fail(function() {});
            }

            /* ── Studio sub-view (quiz inline) ────────────────────────────── */
            var subviewEl = document.getElementById('ait-studio-subview');
            var subviewContent = document.getElementById('ait-subview-content');
            var studioBody = document.querySelector('.ait-studio-body');
            var bcBack = document.getElementById('ait-bc-back');
            var bcTitle = document.getElementById('ait-bc-title');

            function openSubView(title) {
                if (subviewEl) subviewEl.style.display = 'flex';
                if (studioBody) studioBody.style.display = 'none';
                if (bcTitle) bcTitle.textContent = title;
            }
            function closeSubView() {
                if (subviewEl) subviewEl.style.display = 'none';
                if (studioBody) studioBody.style.display = '';
            }
            if (bcBack) bcBack.addEventListener('click', closeSubView);

            function openQuizSubView(quiz, title) {
                quiz = normalizeQuiz(quiz);
                if (!quiz) return;
                openSubView(title || quiz.title || 'Quiz');
                if (!subviewContent) return;

                var idx = 0, correct = 0, answered = false, total = quiz.questions.length;

                function buildDots() {
                    var dots = '';
                    for (var i = 0; i < total; i++) {
                        dots += '<span class="ait-subview-quiz-dot' + (i < idx ? ' done' : '') + (i === idx ? ' current' : '') + '"></span>';
                    }
                    return dots;
                }

                function renderQ() {
                    if (idx >= total) {
                        var pct = Math.round(correct / total * 100);
                        var msg = pct >= 80 ? 'Excellent work!' : (pct >= 50 ? 'Good effort — keep practising!' : 'Keep reviewing — you will get there!');
                        subviewContent.innerHTML =
                            '<div class="ait-subview-quiz-result">' +
                            '<div class="ait-subview-quiz-result-icon"><span class="material-symbols-outlined">' + (pct >= 50 ? 'emoji_events' : 'school') + '</span></div>' +
                            '<div class="ait-subview-quiz-result-score">' + correct + ' / ' + total + '</div>' +
                            '<div class="ait-subview-quiz-result-msg">' + msg + '</div>' +
                            '<div style="margin-top:16px;"><button class="ait-subview-quiz-next" id="ait-sv-quiz-retry" type="button">Try Again</button></div></div>' +
                            '<div class="ait-subview-feedback"><button class="ait-subview-fb-btn" type="button"><span class="material-symbols-outlined">thumb_up</span>Good content</button>' +
                            '<button class="ait-subview-fb-btn" type="button"><span class="material-symbols-outlined">thumb_down</span>Bad content</button></div>';
                        var retry = subviewContent.querySelector('#ait-sv-quiz-retry');
                        if (retry) retry.addEventListener('click', function() { idx = 0; correct = 0; renderQ(); });
                        saveSVQuizAttempt(quiz, total, correct);
                        return;
                    }
                    var q = quiz.questions[idx];
                    answered = false;
                    subviewContent.innerHTML =
                        '<div class="ait-subview-quiz-hdr"><span class="material-symbols-outlined">quiz</span><h3>' + esc(quiz.title) + '</h3></div>' +
                        '<div class="ait-subview-quiz-progress"><span class="ait-subview-quiz-counter">' + (idx + 1) + ' / ' + total + '</span><div class="ait-subview-quiz-dots">' + buildDots() + '</div></div>' +
                        '<div class="ait-subview-quiz-q">' + esc(q.q) + '</div>' +
                        '<div class="ait-subview-quiz-opts">' + (q.options || []).map(function(op, i) {
                            return '<button class="ait-subview-quiz-opt" data-i="' + i + '" type="button">' + esc(op) + '</button>';
                        }).join('') + '</div>' +
                        '<button class="ait-subview-quiz-hint" id="ait-sv-hint" type="button"><span class="material-symbols-outlined">lightbulb</span>Hint<span class="material-symbols-outlined">expand_more</span></button>' +
                        '<div class="ait-subview-quiz-hint-text" id="ait-sv-hint-text">' + esc(q.explanation || 'No hint available.') + '</div>' +
                        '<div class="ait-subview-quiz-foot"><span class="ait-subview-quiz-score">Question ' + (idx + 1) + ' of ' + total + '</span>' +
                        '<button class="ait-subview-quiz-next" id="ait-sv-next" type="button" disabled>Next</button></div>' +
                        '<div class="ait-subview-feedback"><button class="ait-subview-fb-btn" type="button"><span class="material-symbols-outlined">thumb_up</span>Good content</button>' +
                        '<button class="ait-subview-fb-btn" type="button"><span class="material-symbols-outlined">thumb_down</span>Bad content</button></div>';

                    // Hint toggle
                    var hintBtn = subviewContent.querySelector('#ait-sv-hint');
                    var hintText = subviewContent.querySelector('#ait-sv-hint-text');
                    if (hintBtn && hintText) hintBtn.addEventListener('click', function() { hintText.classList.toggle('show'); });

                    // Option selection
                    subviewContent.querySelectorAll('.ait-subview-quiz-opt').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            if (answered) return;
                            answered = true;
                            var chosen = q.options[parseInt(btn.dataset.i)];
                            var isCorrect = String(chosen).trim().toLowerCase() === String(q.answer).trim().toLowerCase() ||
                                String(chosen).trim().toLowerCase().indexOf(String(q.answer).trim().toLowerCase().charAt(0) + ')') === 0;
                            if (!isCorrect && /^[A-Da-d]\)/.test(String(q.answer).trim()) && /^[A-Da-d]\)/.test(String(chosen).trim())) {
                                isCorrect = String(chosen).trim().charAt(0).toUpperCase() === String(q.answer).trim().charAt(0).toUpperCase();
                            }
                            if (isCorrect) correct++;
                            subviewContent.querySelectorAll('.ait-subview-quiz-opt').forEach(function(b) {
                                b.disabled = true;
                                var txt = q.options[parseInt(b.dataset.i)];
                                var bIsAns = String(txt).trim().toLowerCase() === String(q.answer).trim().toLowerCase() ||
                                    (String(txt).trim().charAt(0).toUpperCase() === String(q.answer).trim().charAt(0).toUpperCase() && /^[A-Da-d]\)/.test(String(q.answer).trim()));
                                if (b === btn) b.classList.add(isCorrect ? 'correct' : 'wrong');
                                if (bIsAns && !isCorrect) b.classList.add('correct');
                            });
                            var exp = subviewContent.querySelector('#ait-sv-hint-text');
                            if (exp) { exp.textContent = (isCorrect ? '✓ Correct! ' : '✗ Not quite. ') + (q.explanation || ''); exp.classList.add('show'); }
                            var next = subviewContent.querySelector('#ait-sv-next');
                            if (next) next.disabled = false;
                        });
                    });
                    var next = subviewContent.querySelector('#ait-sv-next');
                    if (next) next.addEventListener('click', function() { idx++; renderQ(); });
                }

                function saveSVQuizAttempt(quiz, total, correct) {
                    var cid = currentCID();
                    if (!cid) return;
                    Ajax.call([{
                        methodname: 'local_umat_ai_save_quiz_attempt',
                        args: {
                            courseid: cid, quiztitle: quiz.title, total: total, correct: correct,
                            score: Math.round(correct / total * 100),
                            details: JSON.stringify(quiz.questions.map(function(q) { return { q: q.q, answer: q.answer, chosen: null }; }))
                        }
                    }])[0].fail(function() {});
                }

                renderQ();
            }

            /* ── Studio tools ──────────────────────────────────────────── */
            var TOOL_PROMPTS = {
                quiz: {
                    title: 'Practice Quiz',
                    prompt: 'Create a 5-question multiple-choice practice quiz based on our conversation and the course materials. ' +
                        'Return ONLY a valid JSON object with this exact structure: ' +
                        '{"quiz_title":"...","questions":[{"q":"question","options":["A) ...","B) ...","C) ...","D) ..."],"answer":"A) ...","explanation":"why"}]}. ' +
                        'Do not include any other text outside the JSON.'
                },
                guide: {
                    title: 'Study Guide',
                    prompt: 'Create a detailed study guide covering the key concepts from our conversation and the course materials. ' +
                        'Use clear sections with headings, bullet points, definitions, and 3 practice questions at the end.'
                },
                summary: {
                    title: 'Summary',
                    prompt: 'Summarize our conversation and the course materials in 5-7 concise bullet points, highlighting the most important takeaways.'
                },
                faq: {
                    title: 'FAQ',
                    prompt: 'Create an FAQ with 6 common questions students might ask about the topics in our conversation, with clear, concise answers.'
                }
            };

            function runTool(tool) {
                if (tool === 'flashcards') {
                    // Centred 970x485 config overlay (materials, count, topic)
                    // — generation results appear as a card in the Studio.
                    openFlashcardOverlay();
                    return;
                }
                if (tool === 'report') { openReportsSubView(); return; }
                var t = TOOL_PROMPTS[tool];
                if (!t) return;
                startStudioGen(tool, t.prompt);
            }

            /* ── Studio-only generation (never touches the chat panel) ─── */
            function startStudioGen(tool, prompt) {
                var cid = currentCID();
                if (!cid) {
                    toast(MODE === 'hub' ? 'Select a course first.' : 'No course selected.');
                    return;
                }
                // Realtime feedback: streaming card appears instantly.
                addGeneratingCard(tool);
                var acc = '';
                var quizPayload = null;
                var form = new FormData();
                form.append('sesskey', moodleSesskey);
                form.append('courseid', String(cid));
                form.append('question', prompt);
                form.append('session_key', sessKey);
                form.append('material_ids', JSON.stringify(selMat.map(function(m) { return m.id; })));
                fetch(streamUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                    .then(function(res) {
                        if (!res.ok || !res.body) throw new Error('Stream unavailable');
                        return consumeSse(res, function(ev, p) {
                            if (ev === 'meta') {
                                lastSrcCount = (p && p.sources && p.sources.length) || 0;
                            } else if (ev === 'token') {
                                acc += (p && p.text) || '';
                            } else if (ev === 'quiz_data') {
                                quizPayload = p && p.quiz;
                            } else if (ev === 'done') {
                                finishStudioGen(tool, acc, quizPayload);
                            } else if (ev === 'error') {
                                failStudioGen(tool);
                            }
                        });
                    })
                    .catch(function() { failStudioGen(tool); });
            }

            /* Minimal SSE consumer (mirrors umatshared's parser). */
            function consumeSse(response, onBlock) {
                var reader = response.body.getReader();
                var decoder = new TextDecoder();
                var buffer = '';
                function pump() {
                    return reader.read().then(function(result) {
                        if (result.done) return;
                        buffer += decoder.decode(result.value, { stream: true });
                        var parts = buffer.split('\n\n');
                        buffer = parts.pop() || '';
                        parts.forEach(function(block) {
                            var ev = 'message', data = '';
                            block.split('\n').forEach(function(line) {
                                if (line.indexOf('event:') === 0) ev = line.slice(6).trim();
                                else if (line.indexOf('data:') === 0) data = line.slice(5).trim();
                            });
                            if (!data) return;
                            try { onBlock(ev, JSON.parse(data)); } catch (e) {}
                        });
                        return pump();
                    });
                }
                return pump();
            }

            function finishStudioGen(tool, text, quizPayload) {
                if (tool === 'quiz') {
                    var quiz = quizPayload || extractQuiz(text);
                    if (quiz) {
                        var qMeta = (lastSrcCount ? lastSrcCount + ' sources' : '') + (activeCID ? ' · ' + (FIXED_CNAME || '') : '');
                        var card = addOutputCard('quiz', quiz.title || 'Practice Quiz', qMeta.trim(), quiz);
                        persistOutput(card, { quiz: quiz });
                        openQuizSubView(quiz, quiz.title || 'Practice Quiz');
                        toast('Quiz generated — opening in Studio.');
                    } else {
                        removeGeneratingCard();
                        toast('Quiz could not be generated. Please try again.');
                    }
                    return;
                }
                var tName = (tool === 'guide') ? 'Study Guide' : (tool === 'summary') ? 'Summary' : 'FAQ';
                var meta = (lastSrcCount ? lastSrcCount + ' sources' : '') + (activeCID ? ' · ' + (FIXED_CNAME || '') : '');
                var card = addOutputCard(tool, tName, meta.trim(), { text: text });
                persistOutput(card, { text: text });
                openContentSubView(tName, text, card);
                toast(tName + ' generated.');
            }

            function failStudioGen(tool) {
                removeGeneratingCard();
                toast('Generation failed. Please try again.');
            }

            /* Document sub-view for Study Guide / Summary / FAQ */
            function openContentSubView(title, text, card) {
                openSubView(title);
                if (!subviewContent) return;
                subviewContent.innerHTML =
                    '<div class="ait-sv-content">' + S._umatFormatAI(text) + '</div>' +
                    '<div class="ait-sv-actions">' +
                    '<button class="ait-tool-btn-sm" id="ait-sv-save-note" type="button"><span class="material-symbols-outlined">note_add</span>Save to Notes</button>' +
                    '</div>';
                var save = subviewContent.querySelector('#ait-sv-save-note');
                if (save) save.addEventListener('click', function() {
                    openNoteSubView({ id: 0, title: title, content: text, pinned: 0 }, true);
                });
            }

            /* ── Flashcards: 970x485 centred config overlay ────────────── */
            var fcModal = document.getElementById('ait-fc-modal');
            // The overlay lives inside a YUI panel whose transforms make
            // position:fixed behave like absolute — the dialog would sit
            // off-centre. Re-mount the modal on <body> so it truly centres.
            if (fcModal && fcModal.parentElement !== document.body) {
                document.body.appendChild(fcModal);
            }
            var fcMatsEl = document.getElementById('ait-fc-mats');
            var fcMsgEl = document.getElementById('ait-fc-msg');

            function openFlashcardOverlay() {
                var cid = currentCID();
                if (!cid) {
                    toast(MODE === 'hub' ? 'Select a course first.' : 'No course selected.');
                    return;
                }
                if (!fcModal) return;
                fcModal.style.display = 'flex';
                fcModal.setAttribute('aria-hidden', 'false');
                if (fcMsgEl) fcMsgEl.textContent = '';
                loadFlashcardMaterials(cid);
            }
            function closeFlashcardOverlay() {
                if (fcModal) { fcModal.style.display = 'none'; fcModal.setAttribute('aria-hidden', 'true'); }
            }
            function loadFlashcardMaterials(cid) {
                if (!fcMatsEl) return;
                fcMatsEl.innerHTML = '<div class="ait-fc-loading"><span class="material-symbols-outlined">hourglass_empty</span>Loading materials…</div>';
                Ajax.call([{ methodname: 'local_umat_ai_get_course_materials', args: { courseid: cid } }])[0]
                    .done(function(r) {
                        var mats = (r && r.materials) || [];
                        var indexed = mats.filter(function(m) { return m.is_indexed || m.indexed; });
                        var selectable = indexed.length ? indexed : mats;
                        if (!selectable.length) {
                            fcMatsEl.innerHTML = '<div class="ait-fc-empty"><span class="material-symbols-outlined">folder_off</span>No course materials found — upload materials to the course library first.</div>';
                            return;
                        }
                        fcMatsEl.innerHTML = selectable.map(function(m, i) {
                            var isVid = String(m.mimetype || '').indexOf('video') !== -1;
                            return '<label class="ait-fc-mat"><input type="checkbox" value="' + m.id + '"' + (i < 3 ? ' checked' : '') + '>' +
                                '<span class="material-symbols-outlined">' + (isVid ? 'play_circle' : 'description') + '</span>' +
                                '<span class="ait-fc-mat-name" title="' + esc(m.filename || m.name || '') + '">' + esc(m.filename || m.name || 'Material ' + m.id) + '</span>' +
                                (m.is_indexed ? '' : '<span class="ait-fc-mat-tag">not indexed</span>') +
                                '</label>';
                        }).join('');
                    })
                    .fail(function() {
                        fcMatsEl.innerHTML = '<div class="ait-fc-empty"><span class="material-symbols-outlined">cloud_off</span>Could not load materials. Try again.</div>';
                    });
            }
            function generateFlashcards() {
                var cid = currentCID();
                var ids = Array.prototype.slice.call(fcMatsEl.querySelectorAll('input:checked')).map(function(i) { return parseInt(i.value) || 0; });
                if (!ids.length) {
                    if (fcMsgEl) { fcMsgEl.textContent = 'Select at least one material.'; fcMsgEl.className = 'ait-fc-msg err'; }
                    return;
                }
                var count = parseInt((document.getElementById('ait-fc-count') || {}).value) || 10;
                var topic = ((document.getElementById('ait-fc-topic') || {}).value || '').trim();
                var genBtn = document.getElementById('ait-fc-gen');
                if (genBtn) genBtn.disabled = true;
                if (fcMsgEl) { fcMsgEl.textContent = 'Generating ' + count + ' flashcards from ' + ids.length + ' material(s)…'; fcMsgEl.className = 'ait-fc-msg'; }
                Ajax.call([{
                    methodname: 'local_umat_ai_generate_flashcards',
                    args: { courseid: cid, material_ids: JSON.stringify(ids), count: count, topic_label: topic }
                }])[0]
                    .done(function(r) {
                        if (genBtn) genBtn.disabled = false;
                        if (r && r.success) {
                            var made = r.generated || r.count || count;
                            var card = addOutputCard('flashcards', topic || 'Flashcards', made + ' cards', { topic: topic || '', count: made });
                            persistOutput(card, { topic: topic || '', count: made });
                            closeFlashcardOverlay();
                            toast(r.message || 'Flashcards generated.');
                        } else {
                            if (fcMsgEl) { fcMsgEl.textContent = (r && r.message) || 'Generation failed.'; fcMsgEl.className = 'ait-fc-msg err'; }
                        }
                    })
                    .fail(function() {
                        if (genBtn) genBtn.disabled = false;
                        if (fcMsgEl) { fcMsgEl.textContent = 'Could not reach the AI service.'; fcMsgEl.className = 'ait-fc-msg err'; }
                    });
            }

            /* ── Flashcards review inside the Studio sub-view ──────────── */
            function openFlashcardsSubView(item) {
                openSubView((item && item.title) || 'Flashcards');
                if (!subviewContent) return;
                var cid = currentCID();
                if (!cid) { subviewContent.innerHTML = '<div class="ait-empty">Select a course first.</div>'; return; }
                subviewContent.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">hourglass_empty</span>Loading deck…</div>';
                Ajax.call([{ methodname: 'local_umat_ai_get_flashcards', args: { courseid: cid } }])[0]
                    .done(function(r) {
                        var cards = (r && r.cards) || [];
                        if (!cards.length) {
                            subviewContent.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">style</span>No flashcards yet — use the Flashcards button to generate a deck.</div>';
                            return;
                        }
                        renderFlashcardsReview(cards);
                    })
                    .fail(function() {
                        subviewContent.innerHTML = '<div class="ait-empty">Could not load the deck. Try again.</div>';
                    });
            }
            function renderFlashcardsReview(cards) {
                var idx = 0, flipped = false;
                subviewContent.innerHTML =
                    '<div class="ait-sv-fc">' +
                    '<div class="ait-sv-fc-toolbar"><span class="ait-sv-fc-title">Deck review</span><span class="ait-sv-fc-progress" id="ait-sv-fc-progress">1 / ' + cards.length + '</span></div>' +
                    '<div class="umat-fc-poker" id="ait-sv-fc-card" role="button" tabindex="0" title="Click to flip">' +
                    '<div class="umat-fc-poker-inner" id="ait-sv-fc-inner">' +
                    '<div class="umat-fc-poker-face umat-fc-poker-front">' +
                    '<div class="umat-fc-poker-corner umat-fc-corner-tl"><span class="umat-fc-corner-rank" id="ait-sv-fc-rank">' + esc(cards[0].topic || '') + '</span><span class="umat-fc-corner-suit">?</span></div>' +
                    '<div class="umat-fc-poker-center"><div class="umat-fc-poker-chip">FLASHCARD</div><div class="umat-fc-text" id="ait-sv-fc-front"></div></div>' +
                    '<div class="umat-fc-poker-corner umat-fc-corner-br"><span class="umat-fc-corner-rank">' + esc(cards[0].topic || '') + '</span><span class="umat-fc-corner-suit">?</span></div>' +
                    '</div>' +
                    '<div class="umat-fc-poker-face umat-fc-poker-back">' +
                    '<div class="umat-fc-poker-back-pattern"></div>' +
                    '<div class="umat-fc-poker-center"><div class="umat-fc-poker-chip">ANSWER</div><div class="umat-fc-text" id="ait-sv-fc-back"></div></div>' +
                    '</div>' +
                    '</div></div>' +
                    '<div class="ait-sv-fc-hint" id="ait-sv-fc-hint">Click the card to reveal the answer</div>' +
                    '<div class="ait-sv-fc-actions" id="ait-sv-fc-actions" style="display:none;">' +
                    '<button class="umat-fc-btn fc-again" data-grade="again" type="button"><span class="umat-fc-btn-lbl">Again</span><span class="umat-fc-btn-sub">Forgot</span></button>' +
                    '<button class="umat-fc-btn fc-hard" data-grade="hard" type="button"><span class="umat-fc-btn-lbl">Hard</span><span class="umat-fc-btn-sub">Struggled</span></button>' +
                    '<button class="umat-fc-btn fc-good" data-grade="good" type="button"><span class="umat-fc-btn-lbl">Good</span><span class="umat-fc-btn-sub">Recalled</span></button>' +
                    '<button class="umat-fc-btn fc-easy" data-grade="easy" type="button"><span class="umat-fc-btn-lbl">Easy</span><span class="umat-fc-btn-sub">Instant</span></button>' +
                    '</div>' +
                    '<div class="ait-sv-fc-nav" style="display:flex;justify-content:center;gap:6px;margin-top:8px;">' +
                    '<button class="ait-nt-btn" id="ait-sv-fc-prev" type="button">Prev</button>' +
                    '<button class="ait-nt-btn" id="ait-sv-fc-next" type="button">Next</button>' +
                    '</div></div>';

                function renderCard(i) {
                    var c = cards[i];
                    if (!c) return;
                    idx = i; flipped = false;
                    var inner = subviewContent.querySelector('#ait-sv-fc-inner');
                    if (inner) inner.classList.remove('flipped');
                    var rank = subviewContent.querySelector('#ait-sv-fc-rank');
                    if (rank) rank.textContent = c.topic || '';
                    var f = subviewContent.querySelector('#ait-sv-fc-front');
                    if (f) f.textContent = c.front || '';
                    var b = subviewContent.querySelector('#ait-sv-fc-back');
                    if (b) b.textContent = c.back || '';
                    var hint = subviewContent.querySelector('#ait-sv-fc-hint');
                    if (hint) { hint.textContent = 'Click the card to reveal the answer'; hint.style.display = ''; }
                    var acts = subviewContent.querySelector('#ait-sv-fc-actions');
                    if (acts) acts.style.display = 'none';
                    var prog = subviewContent.querySelector('#ait-sv-fc-progress');
                    if (prog) prog.textContent = (i + 1) + ' / ' + cards.length;
                }
                var card = subviewContent.querySelector('#ait-sv-fc-card');
                if (card) card.addEventListener('click', function() {
                    if (!cards[idx]) return;
                    var inner = subviewContent.querySelector('#ait-sv-fc-inner');
                    flipped = inner.classList.toggle('flipped');
                    var hint = subviewContent.querySelector('#ait-sv-fc-hint');
                    if (hint) hint.style.display = flipped ? 'none' : '';
                    var acts = subviewContent.querySelector('#ait-sv-fc-actions');
                    if (acts) acts.style.display = flipped ? 'flex' : 'none';
                });
                subviewContent.querySelectorAll('#ait-sv-fc-actions .umat-fc-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var c = cards[idx];
                        if (!c) return;
                        var gradeBtn = btn;
                        gradeBtn.disabled = true;
                        Ajax.call([{
                            methodname: 'local_umat_ai_submit_flashcard_review',
                            args: { cardid: c.id, courseid: currentCID(), button: btn.dataset.grade }
                        }])[0]
                            .done(function() {
                                gradeBtn.disabled = false;
                                if (idx + 1 < cards.length) { renderCard(idx + 1); }
                                else {
                                    subviewContent.innerHTML =
                                        '<div class="ait-sv-fc-done"><span class="material-symbols-outlined">emoji_events</span>' +
                                        '<h3>Review complete</h3><p>' + cards.length + ' card(s) reviewed — nice work! Come back soon for more.</p></div>';
                                }
                            })
                            .fail(function() { gradeBtn.disabled = false; });
                    });
                });
                var prev = subviewContent.querySelector('#ait-sv-fc-prev');
                if (prev) prev.addEventListener('click', function() { if (idx > 0) renderCard(idx - 1); });
                var next = subviewContent.querySelector('#ait-sv-fc-next');
                if (next) next.addEventListener('click', function() { if (idx + 1 < cards.length) renderCard(idx + 1); });
                renderCard(0);
            }

            /* ── Sessions panel ────────────────────────────────────────── */
            var allSessions = [];
            function loadSessions() {
                var cid = MODE === 'course' ? FIXED_CID : 0;
                Ajax.call([{ methodname: 'local_umat_ai_get_ai_sessions', args: { courseid: cid, limit: 50 } }])[0]
                    .done(function(r) {
                        allSessions = r.sessions || [];
                        renderSessions();
                    })
                    .fail(function() {
                        sessList.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">error_outline</span>Could not load sessions.</div>';
                    });
            }
            function renderSessions() {
                var q = (document.getElementById('ait-sess-search').value || '').toLowerCase();
                var list = allSessions.filter(function(s) {
                    if (q && ((s.course_name || '') + ' ' + (s.preview || '')).toLowerCase().indexOf(q) === -1) return false;
                    if (MODE === 'hub' && q && s.courseid && parseInt(s.courseid) !== activeCID && activeCID) {
                        // In hub mode: when a course is selected, show only that course's sessions.
                        return false;
                    }
                    if (MODE === 'hub' && activeCID && parseInt(s.courseid) !== activeCID) return false;
                    return true;
                });
                if (!list.length) {
                    sessList.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">history</span>No sessions yet. Start a conversation below!</div>';
                    return;
                }
                sessList.innerHTML = list.map(function(s) {
                    var active = (s.session_key === sessKey) ? ' active' : '';
                    return '<div class="ait-sess-item' + active + '" data-sk="' + esc(s.session_key) + '" data-cid="' + (s.courseid || 0) + '" data-cn="' + esc(s.course_name || 'General') + '">' +
                        '<div class="ait-sess-item-row">' +
                        '<span class="ait-sess-item-badge">' + esc(s.course_short || 'GEN') + '</span>' +
                        '<span class="ait-sess-item-title">' + esc(s.course_name || 'General') + '</span>' +
                        '<button class="ait-sess-del" type="button" title="Delete session"><span class="material-symbols-outlined">delete</span></button>' +
                        '</div>' +
                        '<div class="ait-sess-item-preview">' + esc(s.preview || '') + '</div>' +
                        '<div class="ait-sess-item-meta"><span class="material-symbols-outlined">chat</span>' + (s.msg_count || 0) + ' messages · ' + esc(s.time_label || '') + '</div>' +
                        '</div>';
                }).join('');
                sessList.querySelectorAll('.ait-sess-item').forEach(function(item) {
                    item.addEventListener('click', function(e) {
                        if (e.target.closest('.ait-sess-del')) return;
                        resumeSession(item.dataset.sk, parseInt(item.dataset.cid) || 0, item.dataset.cn || '');
                    });
                    var del = item.querySelector('.ait-sess-del');
                    if (del) del.addEventListener('click', function(e) {
                        e.stopPropagation();
                        if (!confirm('Delete this conversation? This cannot be undone.')) return;
                        Ajax.call([{ methodname: 'local_umat_ai_delete_session', args: { session_key: item.dataset.sk } }])[0]
                            .done(function() { loadSessions(); })
                            .fail(function() { toast('Could not delete session.'); });
                    });
                });
            }
            function refreshSessions() {
                loadSessions();
            }
            function newSession() {
                sessKey = 'ait_' + Math.random().toString(36).substr(2, 18);
                selMat = [];
                if (drawerCtrl) drawerCtrl.clear();
                S._umatRenderMatsBar('ait-mat-bar', 'ait-attach-btn', [], function() { return []; });
                msgs.innerHTML = '';
                showWelcome();
                lsSet(LS_SID, sessKey);
                loadSessions();
                toast('New session started.');
            }
            function resumeSession(sk, cid, cname) {
                sessKey = sk;
                activeCID = cid || 0;
                if (MODE === 'hub' && courseSel && cid) courseSel.value = String(cid);
                lsSet(LS_SID, sk);
                lsSet(LS_CID, String(activeCID));
                msgs.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">hourglass_empty</span>Loading conversation…</div>';
                Ajax.call([{ methodname: 'local_umat_ai_get_chat_history', args: { courseid: cid || 1, session_key: sk, limit: 50 } }])[0]
                    .done(function(r) {
                        msgs.innerHTML = '';
                        var arr = r.messages || [];
                        if (!arr.length) { showWelcome(); return; }
                        arr.forEach(function(m) {
                            if (m.question) appendMsg(m.question, true);
                            if (m.answer) {
                                var stripped = stripQuiz(m.answer);
                                var d = appendMsg(stripped.text, false, {
                                    sources: m.sources || [],
                                    citations: m.citations || []
                                });
                                if (stripped.quiz && d) renderQuizCard(stripped.quiz);
                            }
                        });
                    })
                    .fail(function() { showWelcome(); });
            }
            function stripQuiz(text) {
                var m = text.match(/\{[\s\S]*"questions"[\s\S]*\}/);
                if (!m) return { text: text, quiz: null };
                try {
                    var o = JSON.parse(m[0]);
                    if (o.questions && o.questions.length) {
                        return { text: text.replace(m[0], '').trim(), quiz: { title: o.quiz_title || 'Practice Quiz', questions: o.questions } };
                    }
                } catch (e) {}
                return { text: text, quiz: null };
            }

            /* ── Notes (Studio output cards + sub-view editor) ─────────── */
            var notes = [];
            var currentNote = null;
            var editing = false;

            function loadNotes() {
                var cid = currentCID();
                Ajax.call([{ methodname: 'local_umat_ai_get_notes', args: { courseid: cid } }])[0]
                    .done(function(r) {
                        notes = r.notes || [];
                        renderOutputList();
                    })
                    .fail(function() {});
            }
            function noteSnippet(content) {
                var t = String(content || '');
                t = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                return t.length > 90 ? t.substr(0, 90) + '…' : t;
            }

            /* Notes open in the Studio sub-view (breadcrumb: Studio › title). */
            function openNoteSubView(note, startEdit) {
                currentNote = note || { id: 0, title: '', content: '', pinned: 0 };
                editing = !!startEdit;
                openSubView(currentNote.id ? (currentNote.title || 'Note') : 'New Note');
                if (!subviewContent) return;
                subviewContent.innerHTML =
                    '<div class="ait-note-editor">' +
                    '<div class="ait-note-editor-hdr">' +
                    '<input class="ait-note-title" id="ait-note-title" type="text" placeholder="Note title" value="' + esc(currentNote.title || '') + '">' +
                    '<button class="ait-ib" id="ait-note-pin" type="button" title="Pin note"><span class="material-symbols-outlined">push_pin</span></button>' +
                    '<button class="ait-ib ait-nt-btn danger" id="ait-note-delete" type="button" title="Delete note"><span class="material-symbols-outlined">delete</span></button>' +
                    '</div>' +
                    '<div class="ait-note-readonly" id="ait-note-readonly"></div>' +
                    '<div class="ait-note-tb" id="ait-note-tb">' +
                    '<button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>' +
                    '<button type="button" data-cmd="italic" title="Italic"><em>I</em></button>' +
                    '<button type="button" data-cmd="underline" title="Underline"><u>U</u></button>' +
                    '<span class="ait-note-tb-sep"></span>' +
                    '<button type="button" data-cmd="insertUnorderedList" title="Bullet list"><span class="material-symbols-outlined">format_list_bulleted</span></button>' +
                    '<button type="button" data-cmd="insertOrderedList" title="Numbered list"><span class="material-symbols-outlined">format_list_numbered</span></button>' +
                    '<span class="ait-note-tb-sep"></span>' +
                    '<button type="button" data-cmd="formatBlock" data-val="h3" title="Heading"><span class="material-symbols-outlined">title</span></button>' +
                    '<button type="button" data-cmd="formatBlock" data-val="blockquote" title="Quote"><span class="material-symbols-outlined">format_quote</span></button>' +
                    '</div>' +
                    '<div class="ait-note-edit" id="ait-note-edit" contenteditable="true" data-placeholder="Write your note…"></div>' +
                    '<div class="ait-note-editor-foot">' +
                    '<button class="ait-nt-btn" id="ait-note-edit-toggle" type="button">' + (editing ? 'Cancel Edit' : 'Edit') + '</button>' +
                    '<button class="ait-nt-btn-p" id="ait-note-save" type="button">Save</button>' +
                    '</div>' +
                    '</div>';
                var ro = subviewContent.querySelector('#ait-note-readonly');
                var ed = subviewContent.querySelector('#ait-note-edit');
                if (currentNote.content && currentNote.content.indexOf('<') !== -1) {
                    // Legacy/rich HTML content — render as-is (scripts stripped).
                    var tmp = document.createElement('div');
                    tmp.innerHTML = currentNote.content;
                    tmp.querySelectorAll('script,style,iframe').forEach(function(el) { el.remove(); });
                    ro.innerHTML = tmp.innerHTML;
                } else {
                    ro.innerHTML = S._umatFormatAI(currentNote.content || '<em>Empty note</em>');
                }
                ed.innerHTML = currentNote.content || '';
                var pin = subviewContent.querySelector('#ait-note-pin');
                pin.classList.toggle('ait-ib-on', !!currentNote.pinned);
                ro.style.display = editing ? 'none' : '';
                var tb = subviewContent.querySelector('#ait-note-tb');
                ed.style.display = editing ? '' : 'none';
                if (tb) tb.style.display = editing ? '' : 'none';
                if (editing) ed.focus();
                /* Formatting toolbar — execCommand keeps it simple and
                 * reliable across browsers; mousedown is prevented so the
                 * editor's selection is preserved when clicking a tool. */
                if (tb) tb.querySelectorAll('button[data-cmd]').forEach(function(btn) {
                    btn.addEventListener('mousedown', function(e) { e.preventDefault(); });
                    btn.addEventListener('click', function() {
                        try {
                            document.execCommand(btn.dataset.cmd, false, btn.dataset.val || null);
                        } catch (err) {}
                        ed.focus();
                    });
                });
                var tgl = subviewContent.querySelector('#ait-note-edit-toggle');
                tgl.addEventListener('click', function() {
                    editing = !editing;
                    ro.style.display = editing ? 'none' : '';
                    ed.style.display = editing ? '' : 'none';
                    if (tb) tb.style.display = editing ? '' : 'none';
                    this.textContent = editing ? 'Cancel Edit' : 'Edit';
                    if (editing) { ed.innerHTML = currentNote.content || ''; ed.focus(); }
                });
                subviewContent.querySelector('#ait-note-save').addEventListener('click', saveNote);
                pin.addEventListener('click', function() {
                    currentNote.pinned = currentNote.pinned ? 0 : 1;
                    this.classList.toggle('ait-ib-on', !!currentNote.pinned);
                    saveNote();
                });
                subviewContent.querySelector('#ait-note-delete').addEventListener('click', deleteNote);
            }
            function closeNoteEditor() {
                closeSubView();
                currentNote = null;
                editing = false;
                loadNotes();
            }
            function saveNote() {
                var titleEl = subviewContent.querySelector('#ait-note-title');
                var editEl = subviewContent.querySelector('#ait-note-edit');
                var title = (titleEl ? titleEl.value : '').trim() || 'Untitled note';
                var content = editing ? (editEl ? editEl.innerHTML : '') : currentNote.content;
                var cid = currentCID();
                var payload = {
                    noteid: currentNote.id || 0,
                    courseid: cid,
                    title: title,
                    content: content,
                    pinned: currentNote.pinned ? 1 : 0,
                    tags: []
                };
                Ajax.call([{ methodname: 'local_umat_ai_save_note', args: payload }])[0]
                    .done(function(r) {
                        toast(r.saved ? 'Note saved.' : 'Could not save note.');
                        closeNoteEditor();
                    })
                    .fail(function() { toast('Failed to save note.'); });
            }
            function deleteNote() {
                if (!currentNote || !currentNote.id) { closeNoteEditor(); return; }
                if (!confirm('Delete this note?')) return;
                Ajax.call([{ methodname: 'local_umat_ai_delete_note', args: { noteid: currentNote.id } }])[0]
                    .done(function() {
                        toast('Note deleted.');
                        closeNoteEditor();
                    })
                    .fail(function() { toast('Failed to delete note.'); });
            }

            /* ── Reports (Studio sub-view) ─────────────────────────────── */
            function openReportsSubView() {
                openSubView('Reports');
                if (!subviewContent) return;
                var cid = currentCID();
                subviewContent.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">hourglass_empty</span>Loading reports…</div>';
                var stats = { sessions: 0, cards: 0, due: 0, attempts: 0, best: null };

                function render() {
                    var attemptsHtml = '';
                    if (stats.best && stats.best.length) {
                        attemptsHtml = '<div class="ait-rep-sec-title">Recent Quiz Attempts</div>' + stats.best.slice(0, 8).map(function(a) {
                            return '<div class="ait-rep-attempt"><div class="ait-rep-attempt-hdr"><strong>' + esc(a.quiztitle || 'Quiz') + '</strong><span>' + (a.score || 0) + '%</span></div>' +
                                '<div class="ait-rep-bar"><i style="width:' + Math.min(100, a.score || 0) + '%"></i></div>' +
                                '<div class="ait-rep-meta">' + (a.correct || 0) + ' / ' + (a.total || 0) + ' correct · ' + esc(S._umatTimeAgo ? S._umatTimeAgo(a.timemodified) : '') + '</div></div>';
                        }).join('');
                    } else {
                        attemptsHtml = '<div class="ait-empty" style="padding:14px;"><span class="material-symbols-outlined">quiz</span>No quiz attempts yet — try the "Create Quiz" tool!</div>';
                    }
                    subviewContent.innerHTML =
                        '<div class="ait-rep-cards">' +
                        '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.sessions + '</div><div class="ait-rep-lbl">Sessions</div></div>' +
                        '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.cards + '</div><div class="ait-rep-lbl">Flashcards</div></div>' +
                        '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.due + '</div><div class="ait-rep-lbl">Due for review</div></div>' +
                        '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.attempts + '</div><div class="ait-rep-lbl">Quiz attempts</div></div>' +
                        '</div>' + attemptsHtml;
                }

                Ajax.call([{ methodname: 'local_umat_ai_get_ai_sessions', args: { courseid: cid, limit: 100 } }])[0]
                    .done(function(r) { stats.sessions = (r.sessions || []).length; render(); }).fail(function() { render(); });
                Ajax.call([{ methodname: 'local_umat_ai_get_flashcards', args: { courseid: cid } }])[0]
                    .done(function(r) {
                        var cards = r.cards || [];
                        stats.cards = cards.length;
                        stats.due = cards.filter(function(c) { return c.due_for_review || c.status === 0; }).length;
                        render();
                    }).fail(function() { render(); });
                Ajax.call([{ methodname: 'local_umat_ai_get_quiz_attempts', args: { courseid: cid, status: '' } }])[0]
                    .done(function(r) {
                        stats.attempts = (r.attempts || []).length;
                        stats.best = r.attempts || [];
                        render();
                    }).fail(function() { render(); });
            }

            /* ── Wiring ────────────────────────────────────────────────── */
            var chatControl = bindChat(input, sendBtn, msgs, function(q) { sendQ(q); });

            // Suggestion chips (welcome + follow-ups) are delegated on the msgs container.
            msgs.addEventListener('click', function(e) {
                var chip = e.target.closest('[data-q]');
                if (chip) sendQ(chip.dataset.q);
            });

            /* scroll-to-bottom */
            (function() {
                var sb = document.getElementById('ait-scroll-bottom');
                if (!sb) return;
                var timer = null;
                msgs.addEventListener('scroll', function() {
                    if (timer) clearTimeout(timer);
                    timer = setTimeout(function() {
                        var near = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 100;
                        sb.classList.toggle('visible', !near);
                    }, 80);
                });
                sb.addEventListener('click', function() { msgs.scrollTo({ top: msgs.scrollHeight, behavior: 'smooth' }); });
            })();

            /* attachment drawer */
            var drawerCtrl = S._umatInitAttachDrawer({
                getCourseId: function() { return currentCID(); },
                drawerId: 'ait-attach-drawer',
                attachBtnId: 'ait-attach-btn',
                closeBtnId: 'ait-drawer-close',
                clearId: 'ait-drawer-clear',
                searchId: 'ait-drawer-search',
                catsId: 'ait-drawer-cats',
                recentId: 'ait-drawer-recent',
                listId: 'ait-drawer-list',
                confirmId: 'ait-drawer-confirm',
                countId: 'ait-drawer-count',
                maxSelections: 20,
                onConfirm: function(mats) {
                    selMat = mats;
                    S._umatRenderMatsBar('ait-mat-bar', 'ait-attach-btn', selMat, function(id) {
                        selMat = selMat.filter(function(s) { return s.id != id; });
                        return selMat;
                    });
                }
            });

            /* voice input */
            (function() {
                var micBtn = document.getElementById('ait-mic-btn');
                if (micBtn && input) new S.ChatVoiceInput({ input: input, btn: micBtn, sesskey: moodleSesskey });
            })();

            /* ESC: close drawer first (the overlay's own ESC is registered elsewhere). */
            S._umatInitEsc([{
                id: 'ait-attach-drawer',
                isOpen: function(e) { return e.classList.contains('open'); },
                close: function(e) { e.classList.remove('open'); }
            }]);

            /* topbar toggles */
            var tglLeft = document.getElementById('ait-toggle-left');
            if (tglLeft) tglLeft.addEventListener('click', function() {
                root.classList.toggle('left-closed');
                lsSet(LS_LEFT, root.classList.contains('left-closed') ? '0' : '1');
            });
            var tglStudio = document.getElementById('ait-toggle-studio');
            var studioCollapse = document.getElementById('ait-studio-collapse');
            var reopenStudio = document.getElementById('ait-reopen-studio');
            function setStudio(open) {
                root.classList.toggle('studio-closed', !open);
                lsSet(LS_STUDIO, open ? '1' : '0');
                if (tglStudio) {
                    tglStudio.classList.toggle('ait-ib-on', open);
                    tglStudio.title = open ? 'Collapse Studio' : 'Open Studio';
                    tglStudio.setAttribute('aria-label', tglStudio.title);
                }
                // Floating expand button: only visible while the panel is collapsed.
                if (reopenStudio) reopenStudio.classList.toggle('show', !open);
            }
            if (tglStudio) tglStudio.addEventListener('click', function() {
                setStudio(root.classList.contains('studio-closed'));
            });
            if (studioCollapse) studioCollapse.addEventListener('click', function() { setStudio(false); });
            if (reopenStudio) reopenStudio.addEventListener('click', function() { setStudio(true); });

            /* restore persisted panel states */
            var leftPref = lsGet(LS_LEFT);
            if (leftPref === '0') root.classList.add('left-closed');
            var studioPref = lsGet(LS_STUDIO);
            if (studioPref === '0') setStudio(false);
            else setStudio(true);

            /* mobile 3-tab navigation */
            function setMobileTab(tab) {
                root.classList.remove('mt-chat', 'mt-sessions', 'mt-studio');
                root.classList.add('mt-' + tab);
                // Opening Studio from the mobile tab bar must expand a collapsed panel.
                if (tab === 'studio' && root.classList.contains('studio-closed')) {
                    setStudio(true);
                }
                document.querySelectorAll('#ait-mtabs .ait-mtab').forEach(function(b) {
                    b.classList.toggle('active', b.dataset.gmtab === tab);
                });
                lsSet(LS_TAB, tab);
            }
            document.querySelectorAll('#ait-mtabs .ait-mtab').forEach(function(b) {
                b.addEventListener('click', function() { setMobileTab(b.dataset.gmtab); });
            });
            if (window.innerWidth <= 760) {
                setMobileTab(lsGet(LS_TAB) || 'chat');
            }

            /* course selector (hub mode) */
            if (courseSel && MODE === 'hub') {
                courseSel.addEventListener('change', function() {
                    var v = parseInt(this.value) || 0;
                    if (v) {
                        activeCID = v;
                        lsSet(LS_CID, String(v));
                    }
                    // Studio cards are per-course: drop the previous course's
                    // items and restore the newly selected course's ones.
                    outputItems = [];
                    loadSessions();
                    loadNotes();
                    loadPersistedOutputs();
                });
            }

            /* studio tools */
            document.getElementById('ait-tool-quiz').addEventListener('click', function() { runTool('quiz'); });
            document.getElementById('ait-tool-fc').addEventListener('click', function() { runTool('flashcards'); });
            document.getElementById('ait-tool-guide').addEventListener('click', function() { runTool('guide'); });
            document.getElementById('ait-tool-summary').addEventListener('click', function() { runTool('summary'); });
            document.getElementById('ait-tool-faq').addEventListener('click', function() { runTool('faq'); });
            // Report tool button was removed in the "Your Studio" redesign —
            // guard it so a missing element can't kill the rest of init().
            var toolReport = document.getElementById('ait-tool-report');
            if (toolReport) toolReport.addEventListener('click', function() { runTool('report'); });

            /* new session */
            document.getElementById('ait-new-sess').addEventListener('click', newSession);

            /* session search */
            var sessSearch = document.getElementById('ait-sess-search');
            if (sessSearch) sessSearch.addEventListener('input', function() { renderSessions(); });

            /* ── Add note button (floating at bottom of Studio) ────────── */
            var addNoteBtn = document.getElementById('ait-add-note-btn');
            if (addNoteBtn) addNoteBtn.addEventListener('click', function() { openNoteSubView(null, true); });

            /* ── Flashcards overlay wiring ─────────────────────────────── */
            var fcCloseBtn = document.getElementById('ait-fc-close');
            var fcCancelBtn = document.getElementById('ait-fc-cancel');
            var fcGenBtn = document.getElementById('ait-fc-gen');
            if (fcCloseBtn) fcCloseBtn.addEventListener('click', closeFlashcardOverlay);
            if (fcCancelBtn) fcCancelBtn.addEventListener('click', closeFlashcardOverlay);
            if (fcModal) fcModal.querySelectorAll('[data-ait-fc-close]').forEach(function(el) {
                el.addEventListener('click', closeFlashcardOverlay);
            });
            if (fcGenBtn) fcGenBtn.addEventListener('click', generateFlashcards);
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && fcModal && fcModal.style.display === 'flex') closeFlashcardOverlay();
            });

            /* ── Boot ─────────────────────────────────────────────────── */
            loadSessions();
            loadNotes();
            loadPersistedOutputs();
            var savedSid = lsGet(LS_SID);
            if (savedSid) {
                resumeSession(savedSid, activeCID, FIXED_CNAME);
            } else {
                showWelcome();
            }
        }
    };
});
