// AMD module: local_umat_ai/umatshared
// Shared helper functions for UMaT AI overlays.
// Used by student, lecturer, and hub workspace overlays.
define([], function() {
    'use strict';

    var _msgIdCounter = 0;
    var _activeStream = null;  // AbortController of the currently running stream

    // ─── HTML Escaping ─────────────────────────────── //
    function _umatEsc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    // ─── Format AI response text (full Markdown → HTML) ─── //
    function _umatFormatAI(text) {
        if (!text) return '';

        /* Extract fenced code blocks before escaping */
        var codeBlocks = [];
        text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, function(m, lang, code) {
            var idx = codeBlocks.length;
            var escaped = _umatEsc(code.replace(/^(\s*\n)+|(\s*\n)+$/g, ''));
            codeBlocks.push('<div class="umat-code-wrap">'
                + '<button class="umat-code-copy" type="button" onclick="(function(b){var t=b.nextElementSibling.textContent;navigator.clipboard.writeText(t);b.textContent=\'Copied!\';setTimeout(function(){b.textContent=\'Copy\';},2000);})(this)">Copy</button>'
                + '<pre><code' + (lang ? ' class="lang-' + _umatEsc(lang) + '"' : '') + '>' + escaped + '</code></pre></div>');
            return '%%CB' + idx + '%%';
        });

        /* Convert HTML line breaks to newlines before escaping */
        text = text.replace(/<br\s*\/?>/gi, '\n');

        /* HTML-escape remaining text */
        text = _umatEsc(text);

        /* Inline formatting (tolerates unclosed markers for smooth streaming) */
        text = text.replace(/\*\*(.+?)(\*\*|$)/g, '<strong>$1</strong>');
        text = text.replace(/__(.+?)(__|$)/g, '<strong>$1</strong>');
        text = text.replace(/\*(.+?)(\*|$)/g, '<em>$1</em>');
        text = text.replace(/~~(.+?)(~~|$)/g, '<del>$1</del>');
        text = text.replace(/`([^`]+)(`|$)/g, '<code>$1</code>');
        text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

        /* Block-level: line-by-line */
        var lines = text.split('\n');
        var out = [];
        var inOl = false, inUl = false, inBq = false, inTbl = false;

        function closeBlock(keepBq) {
            if (inTbl) { out.push('</tbody></table>'); inTbl = false; }
            if (!keepBq && inBq) { out.push('</blockquote>'); inBq = false; }
            if (inUl) { out.push('</ul>'); inUl = false; }
            if (inOl) { out.push('</ol>'); inOl = false; }
        }

        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            var t = line.trim();

            /* Restore fenced code blocks */
            var cbMatch = t.match(/^%%CB(\d+)%%$/);
            if (cbMatch) { closeBlock(); out.push(codeBlocks[parseInt(cbMatch[1])]); continue; }

            if (!t) { closeBlock(); continue; }
            if (/^[-*_]{3,}$/.test(t)) { closeBlock(); out.push('<hr>'); continue; }

            /* Blockquotes */
            var bq = t.match(/^(>+)\s?(.*)/);
            if (bq) {
                if (!inBq) { closeBlock(true); out.push('<blockquote>'); inBq = true; }
                out.push('<p>' + bq[2] + '</p>');
                continue;
            }
            if (inBq) { out.push('</blockquote>'); inBq = false; }

            /* Headings */
            var h3 = t.match(/^###\s+(.*)/); if (h3) { closeBlock(); out.push('<h3>' + h3[1] + '</h3>'); continue; }
            var h2 = t.match(/^##\s+(.*)/);  if (h2) { closeBlock(); out.push('<h2>' + h2[1] + '</h2>'); continue; }
            var h1 = t.match(/^#\s+(.*)/);   if (h1) { closeBlock(); out.push('<h1>' + h1[1] + '</h1>'); continue; }

            /* Tables */
            if (/^\|.+\|$/.test(t)) {
                var cells = t.split('|').filter(function(c) { return c !== ''; });
                if (/^\|[\s:-]+\|/.test(t)) continue; /* separator row */
                if (!inTbl) {
                    out.push('<table><thead><tr>');
                    cells.forEach(function(c) { out.push('<th>' + c.trim() + '</th>'); });
                    out.push('</tr></thead><tbody>');
                    inTbl = true;
                } else {
                    out.push('<tr>');
                    cells.forEach(function(c) { out.push('<td>' + c.trim() + '</td>'); });
                    out.push('</tr>');
                }
                continue;
            }
            if (inTbl) { out.push('</tbody></table>'); inTbl = false; }

            /* Task lists */
            var task = t.match(/^[-*]\s+\[([ x])\]\s+(.*)/);
            if (task) {
                if (inOl) { out.push('</ol>'); inOl = false; }
                if (!inUl) { out.push('<ul class="umat-task-list">'); inUl = true; }
                out.push('<li class="' + (task[1] === 'x' ? 'task-checked' : 'task-unchecked') + '">'
                    + (task[1] === 'x' ? '&#x2611; ' : '&#x2610; ') + task[2] + '</li>');
                continue;
            }

            /* Ordered lists */
            var ol = t.match(/^\d+[.)]\s+(.*)/);
            if (ol) {
                if (inUl) { out.push('</ul>'); inUl = false; }
                if (!inOl) { out.push('<ol>'); inOl = true; }
                out.push('<li>' + ol[1] + '</li>');
                continue;
            }

            /* Unordered lists */
            var ul = t.match(/^[-*]\s+(.*)/);
            if (ul) {
                if (inOl) { out.push('</ol>'); inOl = false; }
                if (!inUl) { out.push('<ul>'); inUl = true; }
                out.push('<li>' + ul[1] + '</li>');
                continue;
            }

            closeBlock();
            out.push('<p>' + line + '</p>');
        }
        closeBlock();

        return out.join('\n');
    }

    // ─── Format duration (seconds → M:SS) ──────────── //
    function _umatFmtT(s) {
        var m = Math.floor(s / 60);
        var sc = Math.floor(s % 60);
        return m + ':' + (sc < 10 ? '0' : '') + sc;
    }

    // ─── Format file size (bytes → KB/MB) ──────────── //
    function _umatFmtSz(b) {
        if (!b) return '\u2014';
        if (b < 1048576) return (b / 1024).toFixed(0) + 'KB';
        return (b / 1048576).toFixed(1) + 'MB';
    }

    // ─── Time ago ──────────────────────────────────── //
    function _umatTimeAgo(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000);
        var n = new Date();
        var s = Math.floor((n - d) / 1000);
        if (s < 60) return 'just now';
        var m = Math.floor(s / 60);
        if (m < 60) return m + 'm ago';
        var h = Math.floor(m / 60);
        if (h < 24) return h + 'h ago';
        var D = Math.floor(h / 24);
        if (D < 30) return D + 'd ago';
        var M = Math.floor(D / 30);
        if (M < 12) return M + 'mo ago';
        var Y = Math.floor(M / 12);
        return Y + 'y ago';
    }

    // ─── Library tile class ← mime type ────────────── //
    function _umatLibTileClass(m) {
        if (!m) return 'lt-other';
        if (m.indexOf('pdf') !== -1) return 'lt-pdf';
        if (m.indexOf('video') !== -1) return 'lt-video';
        if (m.indexOf('image') !== -1) return 'lt-img';
        if (m.indexOf('word') !== -1 || m.indexOf('document') !== -1) return 'lt-doc';
        return 'lt-other';
    }

    // ─── File type icon name ← mime type ───────────── //
    function _umatFileTypeIcon(m) {
        if (!m) return 'description';
        if (m.indexOf('pdf') !== -1) return 'picture_as_pdf';
        if (m.indexOf('video') !== -1) return 'videocam';
        if (m.indexOf('image') !== -1) return 'image';
        return 'description';
    }

    // ─── Append user message bubble ────────────────── //
    var _replyContext = null;

    function _umatGetReplyText(el) {
        var txt = '';
        if (el.classList.contains('umat-bubble-user')) {
            txt = el.textContent || '';
        } else {
            var content = el.querySelector('.umat-ai-content');
            txt = content ? content.textContent : (el.textContent || '');
        }
        return txt.replace(/\s+/g, ' ').trim().substring(0, 200);
    }

    function _umatHandleReply(e) {
        var btn = e.currentTarget;
        /* The reply button is a SIBLING of the bubble, not a child.
           Search within the parent container (.umat-msg-ai-wrap or .umat-msg-user). */
        var wrap = btn.closest('.umat-msg-ai-wrap,.umat-msg-user');
        var bubble = wrap ? (wrap.querySelector('.umat-bubble-ai') || wrap.querySelector('.umat-bubble-user')) : null;
        if (!bubble) return;
        var txt = _umatGetReplyText(bubble);
        if (!txt) return;
        _replyContext = txt;
        var prev = document.getElementById('umat-reply-preview');
        if (prev) prev.remove();
        var preview = document.createElement('div');
        preview.id = 'umat-reply-preview';
        preview.className = 'umat-reply-preview';
        preview.innerHTML = '<span class="umat-reply-icon material-symbols-outlined">reply</span><span class="umat-reply-text">Replying to: ' + _umatEsc(txt) + '</span><button class="umat-reply-cancel" type="button">&times;</button>';
        preview.querySelector('.umat-reply-cancel').addEventListener('click', function() {
            _replyContext = null;
            preview.remove();
        });
        var chatbar = bubble.closest('.umat-tab-pane,.umat-cp-pane') || document;
        var cb = chatbar.querySelector ? chatbar.querySelector('.umat-chatbar') : null;
        if (cb && cb.parentNode) {
            cb.parentNode.insertBefore(preview, cb);
        } else {
            var msgs = document.getElementById(bubble.closest('[id$="msgs"]') ? bubble.closest('[id$="msgs"]').id : '');
            if (msgs && msgs.parentNode) {
                msgs.parentNode.insertBefore(preview, msgs.nextSibling);
            }
        }
    }

    function _umatAppendUser(cid, q, mats) {
        var c = document.getElementById(cid);
        if (!c) return;
        /* Strip [Referencing: ...] prefix if present — display as chips instead */
        var refNames = [];
        var cleanQ = q;
        var refMatch = cleanQ.match(/^\[Referencing:\s*([^\]]+)\]\s*/i);
        if (refMatch) {
            refNames = refMatch[1].split(',').map(function(s){ return s.trim(); }).filter(Boolean);
            cleanQ = cleanQ.substring(refMatch[0].length);
        }
        /* Also accept mats param (array of {name,id}) */
        if (mats && mats.length && !refNames.length) {
            refNames = mats.map(function(m){ return m.name || m; });
        }
        var chipHtml = '';
        if (refNames.length) {
            chipHtml = '<div class="umat-ref-chips">' + refNames.map(function(n) {
                return '<span class="umat-ref-chip"><span class="material-symbols-outlined">attach_file</span>' + _umatEsc(n) + '</span>';
            }).join('') + '</div>';
        }
        var d = document.createElement('div');
        var mid = 'msg_' + (++_msgIdCounter);
        d.setAttribute('data-msg-id', mid);
        d.setAttribute('data-msg-role', 'user');
        d.innerHTML = '<div class="umat-msg-user"><div class="umat-bubble-user"><p>' + _umatEsc(cleanQ) + '</p></div>' + chipHtml + '<button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div>';
        d.querySelector('.umat-reply-btn').addEventListener('click', _umatHandleReply);
        c.appendChild(d);
        c.scrollTop = c.scrollHeight;
    }

    // ─── Append AI message bubble ──────────────────── //
    function _umatAppendAi(cid, t, s) {
        var c = document.getElementById(cid);
        if (!c) return;
        t = (t || '').replace(/```(?:json)?\s*\{[\s\S]*?"quiz"\s*:[\s\S]*?\}\s*```\s*/g, '');
        var src = '';
        if (s && s.length) {
            src = '<div class="umat-src-chips">' + s.map(function(x) {
                return '<span class="umat-src-chip">' + _umatEsc(x) + '</span>';
            }).join('') + '</div>';
        }
        var d = document.createElement('div');
        var mid = 'msg_' + (++_msgIdCounter);
        d.setAttribute('data-msg-id', mid);
        d.setAttribute('data-msg-role', 'ai');
        d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-ai-content">' + _umatFormatAI(t) + '</div>' + src + '</div><button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div></div>';
        d.querySelector('.umat-reply-btn').addEventListener('click', _umatHandleReply);
        c.appendChild(d);
        c.scrollTop = c.scrollHeight;
    }

    // ─── Show typing indicator ─────────────────────── //
    function _umatShowTyping(cid, tid) {
        var c = document.getElementById(cid);
        if (!c) return;
        // Prevent duplicate typing bubbles for the same request
        if (document.getElementById(tid)) return;
        var d = document.createElement('div');
        d.id = tid;
        d.className = 'umat-typing-wrap';
        d.setAttribute('role', 'status');
        d.setAttribute('aria-live', 'polite');
        d.setAttribute('aria-label', 'AI is preparing a response');
        d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai umat-typing-bubble"><div class="umat-typing" aria-hidden="true"><span></span><span></span><span></span></div><span class="umat-typing-label">AI is responding&hellip;</span></div></div></div>';
        c.appendChild(d);
        c.scrollTop = c.scrollHeight;
    }

    // ─── Hide typing indicator ─────────────────────── //
    function _umatHideTyping(tid) {
        if (!tid) return;
        var e = document.getElementById(tid);
        if (e && e.parentNode) e.parentNode.removeChild(e);
    }

    // ─── Append source chips to a streaming bubble ─── //
    function _umatAppendSources(bubble, sources) {
        if (!bubble || !sources || !sources.length) return;
        if (bubble.querySelector('.umat-src-chips')) return;
        var src = document.createElement('div');
        src.className = 'umat-src-chips';
        src.innerHTML = sources.map(function(x) {
            return '<span class="umat-src-chip">' + _umatEsc(x) + '</span>';
        }).join('');
        bubble.appendChild(src);
    }

    // ─── Shared SSE block parser (chat + inline panels) ── //
    function _umatParseSseBlock(block) {
        var event = 'message';
        var data = '';
        block.split('\n').forEach(function(line) {
            if (line.indexOf('event:') === 0) {
                event = line.slice(6).trim();
            } else if (line.indexOf('data:') === 0) {
                data = line.slice(5).trim();
            }
        });
        if (!data) {
            return null;
        }
        return { event: event, payload: JSON.parse(data) };
    }

    function _umatConsumeSseStream(response, onBlock) {
        if (!response.ok || !response.body) {
            throw new Error('Stream unavailable');
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function pump() {
            return reader.read().then(function(result) {
                if (result.done) {
                    return;
                }
                buffer += decoder.decode(result.value, { stream: true });
                var parts = buffer.split('\n\n');
                buffer = parts.pop() || '';
                parts.forEach(function(block) {
                    var parsed = _umatParseSseBlock(block);
                    if (parsed) {
                        onBlock(parsed.event, parsed.payload);
                    }
                });
                return pump();
            });
        }

        return pump();
    }

    // ─── Stream AI tutor response via SSE proxy ────── //
    var _chatState = 'idle';  // idle | submitting | waiting | streaming | completed | failed

    function _umatStreamChat(opts) {
        var accumulated = '';
        var streamRow = null;
        var contentEl = null;
        var bubbleEl = null;
        var formatTimer = null;
        var typingHidden = false;
        var label = opts.label || 'AI ASSISTANT';
        var controller = new AbortController();
        var doneReceived = false;
        var maxRetries = 3;
        var statusText = opts.label || null;
        var statusPending = !!statusText && !opts._noStatus;
        var statusEl = null;
        var quizDataHandled = false;
        var firstTokenReceived = false;
        opts._retries = opts._retries || 0;

        // --- State management ---
        _chatState = 'submitting';
        if (typeof opts.onStateChange === 'function') opts.onStateChange(_chatState);

        // --- Prevent duplicate submissions ---
        if (_activeStream && _activeStream !== controller) {
            try { _activeStream.abort(); } catch(e) {}
        }
        _activeStream = controller;

        // --- Send-button management ---
        var sendBtnId = opts.sendBtnId || null;
        var sendInputId = opts.sendInputId || null;
        function _disableSend() {
            if (sendBtnId) {
                var btn = document.getElementById(sendBtnId);
                if (btn) {
                    btn.disabled = false; /* keep clickable for stop */
                    btn.setAttribute('aria-busy', 'true');
                    btn.classList.add('is-stop');
                    var icon = btn.querySelector('.material-symbols-outlined');
                    if (icon) icon.textContent = 'stop_circle';
                    /* Remove any previous stop handler to prevent listener leaks on retries */
                    if (btn._stopStreamHandler) {
                        btn.removeEventListener('click', btn._stopStreamHandler, true);
                    }
                    /* capture-phase handler aborts stream before bubble handlers fire */
                    btn._stopStreamHandler = function(e) {
                        e.stopImmediatePropagation();
                        try { controller.abort(); } catch(ex) {}
                    };
                    btn.addEventListener('click', btn._stopStreamHandler, true);
                }
            }
        }
        function _enableSend() {
            if (sendBtnId) {
                var btn = document.getElementById(sendBtnId);
                if (btn) {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btn.classList.remove('is-stop');
                    var icon = btn.querySelector('.material-symbols-outlined');
                    if (icon) icon.textContent = 'arrow_upward';
                    if (btn._stopStreamHandler) {
                        btn.removeEventListener('click', btn._stopStreamHandler, true);
                        btn._stopStreamHandler = null;
                    }
                }
            }
        }
        _disableSend();

        function hideTypingOnce() {
            if (typingHidden) return;
            typingHidden = true;
            if (opts.typingId) _umatHideTyping(opts.typingId);
            if (typeof opts.onTypingHidden === 'function') opts.onTypingHidden();
        }

        function ensureBubble() {
            if (streamRow) {
                return;
            }
            hideTypingOnce();
            _chatState = 'streaming';
            if (typeof opts.onStateChange === 'function') opts.onStateChange(_chatState);
            var c = document.getElementById(opts.msgsId);
            if (!c) {
                return;
            }
            streamRow = document.createElement('div');
            streamRow.className = 'umat-msg-ai umat-msg-streaming';
            streamRow.setAttribute('data-msg-id', 'msg_' + (++_msgIdCounter));
            streamRow.setAttribute('data-msg-role', 'ai');
            var innerHtml;
            if (statusPending) {
                innerHtml = '<div class="umat-ai-stream-content" style="display:none"></div>'
                    + '<div class="umat-status-text" role="status" aria-live="polite"><span class="umat-status-spinner"></span>' + _umatEsc(statusText) + '</div>';
            } else {
                innerHtml = '<div class="umat-ai-stream-content"></div>';
            }
            streamRow.innerHTML = '<div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>'
                + '<div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">' + _umatEsc(label) + '</div>'
                + '<div class="umat-bubble-ai is-streaming">' + innerHtml + '</div></div></div>';
            c.appendChild(streamRow);
            bubbleEl = streamRow.querySelector('.umat-bubble-ai');
            contentEl = streamRow.querySelector('.umat-ai-stream-content');
            if (statusPending) {
                statusEl = streamRow.querySelector('.umat-status-text');
            }
            c.scrollTop = c.scrollHeight;
        }

        function renderContent() {
            if (!contentEl) {
                return;
            }
            contentEl.innerHTML = _umatFormatAI(accumulated);
            var c = document.getElementById(opts.msgsId);
            if (c) {
                c.scrollTop = c.scrollHeight;
            }
        }

        function scheduleRender() {
            if (formatTimer) {
                return;
            }
            formatTimer = setTimeout(function() {
                formatTimer = null;
                renderContent();
            }, 45);
        }

        function _finishAll() {
            _enableSend();
            if (_activeStream === controller) _activeStream = null;
            if (_chatState !== 'failed') {
                _chatState = 'completed';
                if (typeof opts.onStateChange === 'function') opts.onStateChange(_chatState);
            }
        }

        function finishStream(payload) {
            if (payload && payload.answer) {
                accumulated = payload.answer;
            }
            console.log('[UMAT-SSE] finishStream called', {accumulatedLength:accumulated.length, hasQuizJson:/```(?:json)?\s*\{[\s\S]*?"quiz"/.test(accumulated)});
            accumulated = accumulated.replace(/```(?:json)?\s*\{[\s\S]*?"quiz"\s*:[\s\S]*?\}\s*```\s*/g, '');
            console.log('[UMAT-SSE] after quiz strip', {newLength:accumulated.length});
            if (statusPending && !quizDataHandled) {
                if (statusEl) statusEl.style.display = 'none';
                if (contentEl) contentEl.style.display = '';
                statusPending = false;
            }
            ensureBubble();
            renderContent();
            if (bubbleEl) {
                bubbleEl.classList.remove('is-streaming');
            }
            if (streamRow) {
                streamRow.classList.remove('umat-msg-streaming');
            }
            _umatAppendSources(bubbleEl, (payload && payload.sources) || []);
            if (streamRow) {
                var wrap = streamRow.querySelector('.umat-msg-ai-wrap');
                if (wrap && !wrap.querySelector('.umat-reply-btn')) {
                    var rp = document.createElement('button');
                    rp.className = 'umat-reply-btn';
                    rp.type = 'button';
                    rp.title = 'Reply';
                    rp.innerHTML = '<span class="material-symbols-outlined">reply</span>';
                    rp.addEventListener('click', _umatHandleReply);
                    wrap.appendChild(rp);
                }
            }
        }

        function showFailureUI(message) {
            hideTypingOnce();
            _chatState = 'failed';
            if (typeof opts.onStateChange === 'function') opts.onStateChange(_chatState);
            // Ensure we have a bubble to show the error in
            ensureBubble();
            if (streamRow) {
                streamRow.classList.remove('umat-msg-streaming');
            }
            if (bubbleEl) {
                bubbleEl.classList.remove('is-streaming');
            }
            if (contentEl) {
                contentEl.innerHTML = '<p class="umat-ai-error-text">The AI could not respond. Please try again.</p>';
                contentEl.style.display = '';
            }
            if (statusEl) {
                statusEl.style.display = 'none';
            }
            // Add retry button
            if (bubbleEl) {
                var existingRetry = bubbleEl.querySelector('.umat-retry-btn');
                if (!existingRetry) {
                    var retryBtn = document.createElement('button');
                    retryBtn.className = 'umat-retry-btn';
                    retryBtn.type = 'button';
                    retryBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;">refresh</span>Retry';
                    retryBtn.setAttribute('aria-label', 'Retry sending message');
                    retryBtn.addEventListener('click', function() {
                        // Remove the failed bubble and retry
                        if (streamRow && streamRow.parentNode) {
                            streamRow.parentNode.removeChild(streamRow);
                        }
                        streamRow = null;
                        contentEl = null;
                        bubbleEl = null;
                        typingHidden = false;
                        firstTokenReceived = false;
                        opts._retries = 0;
                        _chatState = 'idle';
                        _umatStreamChat(opts);
                    });
                    bubbleEl.parentNode.appendChild(retryBtn);
                }
            }
            _enableSend();
            if (_activeStream === controller) _activeStream = null;
        }

        var body = new FormData();
        body.append('sesskey', opts.sesskey);
        body.append('courseid', String(opts.courseid));
        body.append('question', opts.question);
        body.append('session_key', opts.session_key || '');
        body.append('material_ids', JSON.stringify(opts.material_ids || []));

        var promise = fetch(opts.url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            signal: controller.signal
        }).then(function(response) {
            return _umatConsumeSseStream(response, function(event, payload) {
                if (event === 'meta') {
                    _chatState = 'waiting';
                    if (typeof opts.onStateChange === 'function') opts.onStateChange(_chatState);
                    if (opts.onMeta) opts.onMeta(payload);
                } else if (event === 'token') {
                    ensureBubble();
                    accumulated += payload.text || '';
                    // On first token, transition from status text to content
                    if (!firstTokenReceived) {
                        firstTokenReceived = true;
                        if (statusPending && statusEl) {
                            statusEl.style.display = 'none';
                            if (contentEl) contentEl.style.display = '';
                            statusPending = false;
                        }
                    }
                    if (!statusPending) {
                        scheduleRender();
                    }
                } else if (event === 'quiz_data') {
                    quizDataHandled = true;
                    console.log('[UMAT-SSE] quiz_data event received', {hasQuiz:!!(payload&&payload.quiz), questionsCount:(payload&&payload.quiz&&payload.quiz.questions)?payload.quiz.questions.length:0, onQuizDataExists:typeof opts.onQuizData==='function'});
                    if (statusPending && statusEl) {
                        statusEl.style.display = 'none';
                        if (contentEl) contentEl.style.display = '';
                        statusPending = false;
                    }
                    if (typeof opts.onQuizData === 'function') opts.onQuizData(payload);
                    else if (typeof window._umatOnQuizData === 'function') window._umatOnQuizData(payload);
            accumulated = accumulated.replace(/```(?:json)?\s*\{[\s\S]*?"quiz"\s*:[\s\S]*?\}\s*```\s*/g, '');
                    scheduleRender();
                } else if (event === 'done') {
                    doneReceived = true;
                    finishStream(payload);
                    _finishAll();
                    if (opts.onDone) opts.onDone(payload, accumulated);
                } else if (event === 'error') {
                    showFailureUI(payload.message || 'An error occurred.');
                    if (opts.onError) opts.onError(payload);
                }
            });
        }).then(function() {
            if (!doneReceived && _chatState !== 'failed') {
                throw new Error('The response stream ended before completion.');
            }
        }).catch(function(err) {
            if (err && err.name === 'AbortError') {
                finishStream({ answer: accumulated });
                _finishAll();
                if (opts.onDone) opts.onDone({ stopped: true }, accumulated);
                return;
            }
            // Check if we should retry
            if (opts._retries < maxRetries) {
                opts._retries++;
                hideTypingOnce();
                _chatState = 'waiting';
                if (typeof opts.onStateChange === 'function') opts.onStateChange(_chatState);
                setTimeout(function() { _umatStreamChat(opts); }, opts._retries * 2000);
                return;
            }
            showFailureUI(err.message || 'Connection error.');
            if (opts.onError) opts.onError({ message: err.message || 'Connection error.' });
        });

        promise.abort = function() { controller.abort(); };
        return promise;
    }

    // ─── Stream into a single panel (NLQ, health report) ── //
    function _umatStreamInline(opts) {
        var accumulated = '';
        var formatTimer = null;
        var controller = new AbortController();
        var target = document.getElementById(opts.targetId);
        if (!target) {
            return Promise.reject(new Error('Target not found'));
        }

        target.classList.add('umat-ai-stream-panel', 'is-streaming');
        target.innerHTML = '<div class="umat-ai-stream-content umat-ai-content"></div>';
        var contentEl = target.querySelector('.umat-ai-stream-content');

        function renderContent() {
            if (!contentEl) {
                return;
            }
            contentEl.innerHTML = _umatFormatAI(accumulated);
            if (typeof opts.onRender === 'function') {
                opts.onRender(contentEl);
            }
        }

        function scheduleRender() {
            if (formatTimer) {
                return;
            }
            formatTimer = setTimeout(function() {
                formatTimer = null;
                renderContent();
            }, 45);
        }

        function finishStream(payload) {
            if (payload && payload.answer) {
                accumulated = payload.answer;
            }
            renderContent();
            target.classList.remove('is-streaming');
        }

        var body = new FormData();
        body.append('sesskey', opts.sesskey);
        body.append('courseid', String(opts.courseid));
        body.append('question', opts.question);
        body.append('session_key', opts.session_key || 'inline_' + Date.now());
        body.append('material_ids', JSON.stringify(opts.material_ids || []));

        var promise = fetch(opts.url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            signal: controller.signal
        }).then(function(response) {
            return _umatConsumeSseStream(response, function(event, payload) {
                if (event === 'meta') {
                    if (opts.onMeta) {
                        opts.onMeta(payload);
                    }
                } else if (event === 'token') {
                    accumulated += payload.text || '';
                    scheduleRender();
                } else if (event === 'done') {
                    finishStream(payload);
                    if (opts.onDone) {
                        opts.onDone(payload, accumulated);
                    }
                } else if (event === 'error') {
                    target.classList.remove('is-streaming');
                    if (opts.onError) {
                        opts.onError(payload);
                    }
                }
            });
        }).catch(function(err) {
            target.classList.remove('is-streaming');
            if (opts.onError) {
                opts.onError({ message: err.message || 'Connection error.' });
            }
        });

        promise.abort = function() {
            controller.abort();
        };
        return promise;
    }

    // ─── Voice input (MediaRecorder + server transcription) ── //
    /**
     * ChatVoiceInput — in-place voice recording for chatbars.
     *
     * Transforms the chatbar into a recording panel with waveform,
     * timer, and Done/Cancel controls. Sends audio to the server
     * for Whisper transcription and inserts the result into the
     * associated text input.
     *
     * @param {Object} opts
     *   input   — the text input element (or its ID)
     *   btn     — the mic button element (or its ID)
     *   formUrl — URL of the PHP transcription proxy (transcribe_chat.php)
     *   sesskey — Moodle sesskey for CSRF protection
     */
    function ChatVoiceInput(opts) {
        this.inp = typeof opts.input === 'string' ? document.getElementById(opts.input) : opts.input;
        this.btn = typeof opts.btn === 'string' ? document.getElementById(opts.btn) : opts.btn;
        this.formUrl = opts.formUrl || M.cfg.wwwroot + '/local/umat_ai/transcribe_chat.php';
        this.sesskey = opts.sesskey || (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) || '';
        this._recording = false;
        this._mediaRecorder = null;
        this._chunks = [];
        this._timerInterval = null;
        this._seconds = 0;
        this._panel = null;
        this._origParent = null;
        this._origNext = null;

        var self = this;
        if (this.btn) {
            this.btn.addEventListener('click', function() {
                if (self._recording) { self._cancel(); } else { self._start(); }
            });
        }
    }

    ChatVoiceInput.prototype._start = function() {
        var self = this;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this._showError('Voice recording is not supported in this browser.');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function(stream) {
            self._beginRecording(stream);
        })['catch'](function(err) {
            console.error('[UMAT-Voice] getUserMedia failed:', err);
            self._showError('Microphone access denied. Please allow microphone access and try again.');
        });
    };

    ChatVoiceInput.prototype._beginRecording = function(stream) {
        this._recording = true;
        this._chunks = [];
        this._seconds = 0;

        // Pick best supported MIME type
        var mime = 'audio/webm';
        if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
            mime = 'audio/webm;codecs=opus';
        } else if (MediaRecorder.isTypeSupported('audio/webm')) {
            mime = 'audio/webm';
        } else if (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
            mime = 'audio/ogg;codecs=opus';
        }

        try {
            this._mediaRecorder = new MediaRecorder(stream, { mimeType: mime });
        } catch(e) {
            this._mediaRecorder = new MediaRecorder(stream);
        }

        var self = this;
        this._mediaRecorder.ondataavailable = function(e) {
            if (e.data && e.data.size > 0) self._chunks.push(e.data);
        };
        this._mediaRecorder.onstop = function() {
            self._stop(stream);
        };

        // Build the in-place recording panel
        this._buildPanel();
        this._mediaRecorder.start(250); // collect in 250ms chunks

        this._timerInterval = setInterval(function() {
            self._seconds++;
            var el = self._panel && self._panel.querySelector('.umat-voice-timer');
            if (el) el.textContent = self._fmtTime(self._seconds);
        }, 1000);
    };

    ChatVoiceInput.prototype._buildPanel = function() {
        if (!this.inp) return;
        var chatbar = this.inp.closest('.umat-chatbar');
        if (!chatbar) return;

        // Remember original position for restoration
        this._origParent = this.inp.parentNode;
        this._origNext = this.inp.nextSibling;

        // Build voice panel
        var panel = document.createElement('div');
        panel.className = 'umat-voice-panel';
        panel.innerHTML =
            '<div class="umat-voice-wave">' +
                '<span></span><span></span><span></span><span></span><span></span>' +
            '</div>' +
            '<div class="umat-voice-timer">0:00</div>' +
            '<div class="umat-voice-controls">' +
                '<button type="button" class="umat-voice-btn umat-voice-cancel" title="Cancel">' +
                    '<span class="material-symbols-outlined">close</span> Cancel' +
                '</button>' +
                '<button type="button" class="umat-voice-btn umat-voice-done" title="Done">' +
                    '<span class="material-symbols-outlined">check</span> Done' +
                '</button>' +
            '</div>';

        this._panel = panel;

        // Hide input, insert panel in its place
        this.inp.style.display = 'none';
        if (this._origNext && this._origNext.parentNode === this._origParent) {
            this._origParent.insertBefore(panel, this._origNext);
        } else {
            this._origParent.appendChild(panel);
        }

        // Wire up buttons
        var self = this;
        panel.querySelector('.umat-voice-cancel').addEventListener('click', function() { self._cancel(); });
        panel.querySelector('.umat-voice-done').addEventListener('click', function() { self._finish(); });
    };

    ChatVoiceInput.prototype._fmtTime = function(s) {
        var m = Math.floor(s / 60);
        var sc = Math.floor(s % 60);
        return m + ':' + (sc < 10 ? '0' : '') + sc;
    };

    ChatVoiceInput.prototype._cancel = function() {
        if (this._mediaRecorder && this._mediaRecorder.state !== 'inactive') {
            this._mediaRecorder.stop();
        }
        this._teardown();
    };

    ChatVoiceInput.prototype._finish = function() {
        if (this._mediaRecorder && this._mediaRecorder.state !== 'inactive') {
            this._mediaRecorder.stop();
        }
        // _stop will send the audio
    };

    ChatVoiceInput.prototype._stop = function(stream) {
        // Stop timer
        if (this._timerInterval) {
            clearInterval(this._timerInterval);
            this._timerInterval = null;
        }

        // Stop all tracks
        if (stream) {
            stream.getTracks().forEach(function(t) { t.stop(); });
        }

        this._recording = false;

        // If we have audio data, send it
        if (this._chunks.length > 0) {
            this._sendForTranscription();
        } else {
            this._teardown();
        }
    };

    ChatVoiceInput.prototype._sendForTranscription = function() {
        var self = this;
        var blob = new Blob(this._chunks, { type: this._mediaRecorder.mimeType || 'audio/webm' });
        this._chunks = [];

        // Show loading state in panel
        var loadingEl = this._panel && this._panel.querySelector('.umat-voice-controls');
        if (loadingEl) {
            loadingEl.innerHTML =
                '<div class="umat-voice-loading">' +
                    '<div class="umat-vw-spinner"></div>' +
                    '<span>Transcribing\u2026</span>' +
                '</div>';
        }

        var t0 = Date.now();
        var formData = new FormData();
        formData.append('audio', blob, 'voice_' + Date.now() + '.webm');
        formData.append('sesskey', this.sesskey);

        fetch(this.formUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        }).then(function(resp) {
            return resp.json();
        }).then(function(data) {
            var elapsed = Date.now() - t0;
            console.log('[UMAT-Voice] Transcription took ' + elapsed + 'ms', data);
            if (data.success && data.transcript) {
                // Insert transcript into input
                if (self.inp) {
                    self.inp.value = data.transcript;
                    self.inp.style.display = '';
                    self.inp.focus();
                    // Trigger input event so send button enables
                    self.inp.dispatchEvent(new Event('input', { bubbles: true }));
                }
                self._teardown();
            } else {
                var msg = (data && data.message) || 'Transcription failed';
                console.error('[UMAT-Voice] Server error:', msg);
                self._showError(msg);
                self._teardown();
            }
        })['catch'](function(err) {
            console.error('[UMAT-Voice] Fetch error:', err);
            self._showError('Could not reach transcription service.');
            self._teardown();
        });
    };

    ChatVoiceInput.prototype._showError = function(msg) {
        // Briefly show error in the input area, then restore
        if (this.inp) {
            this.inp.style.display = '';
            this.inp.value = '';
            this.inp.placeholder = msg;
            var inp = this.inp;
            setTimeout(function() { inp.placeholder = 'Ask the AI tutor\u2026'; }, 4000);
        }
        this._teardown();
    };

    ChatVoiceInput.prototype._teardown = function() {
        if (this._timerInterval) {
            clearInterval(this._timerInterval);
            this._timerInterval = null;
        }
        this._recording = false;
        this._mediaRecorder = null;
        this._chunks = [];

        // Remove voice panel, restore input
        if (this._panel && this._panel.parentNode) {
            this._panel.parentNode.removeChild(this._panel);
        }
        this._panel = null;
        if (this.inp) {
            this.inp.style.display = '';
        }
    };

    // ─── Render material chips bar ─────────────────── //
    function _umatRenderMatsBar(barId, btnId, mats, onRemove) {
        var bar = document.getElementById(barId);
        var btn = document.getElementById(btnId);
        if (!bar) return;
        bar.innerHTML = mats.length ? mats.map(function(m) {
            return '<span class="umat-mat-chip"><span class="umat-mat-chip-name">' + _umatEsc(m.name) + '</span><button class="umat-mat-chip-remove" data-id="' + m.id + '" type="button">&times;</button></span>';
        }).join('') : '';
        bar.querySelectorAll('.umat-mat-chip-remove').forEach(function(x) {
            x.addEventListener('click', function() {
                var remaining = onRemove ? onRemove(this.dataset.id) : [];
                _umatRenderMatsBar(barId, btnId, remaining, onRemove);
                if (btn) {
                    btn.style.color = remaining.length ? 'var(--u-p)' : '';
                    btn.innerHTML = '<span class="material-symbols-outlined">add</span>';
                }
            });
        });
        if (!btn) return;
        btn.style.color = mats.length ? 'var(--u-p)' : '';
        btn.innerHTML = '<span class="material-symbols-outlined">add</span>';
    }

    // ─── File type icon name ← mime type (drawer) ──── //
    function _umatDrawerIcon(m) {
        if (!m) return { icon: 'description', cls: 'di-other' };
        m = m.toLowerCase();
        if (m.indexOf('pdf') !== -1) return { icon: 'picture_as_pdf', cls: 'di-pdf' };
        if (m.indexOf('video') !== -1) return { icon: 'videocam', cls: 'di-video' };
        if (m.indexOf('presentation') !== -1 || m.indexOf('powerpoint') !== -1) return { icon: 'co_present', cls: 'di-pptx' };
        if (m.indexOf('sheet') !== -1 || m.indexOf('excel') !== -1) return { icon: 'table_chart', cls: 'di-xlsx' };
        if (m.indexOf('word') !== -1 || m.indexOf('document') !== -1) return { icon: 'description', cls: 'di-doc' };
        if (m.indexOf('image') !== -1) return { icon: 'image', cls: 'di-img' };
        if (m.indexOf('audio') !== -1) return { icon: 'music_note', cls: 'di-audio' };
        if (m.indexOf('zip') !== -1 || m.indexOf('rar') !== -1 || m.indexOf('tar') !== -1 || m.indexOf('gz') !== -1 || m.indexOf('7z') !== -1) return { icon: 'folder_zip', cls: 'di-archive' };
        if (m.indexOf('text/') === 0 || m.indexOf('application/json') === 0 || m.indexOf('application/xml') === 0 || m.indexOf('application/javascript') !== -1 || m.indexOf('application/x-httpd-php') !== -1) return { icon: 'code', cls: 'di-code' };
        return { icon: 'description', cls: 'di-other' };
    }

    // ─── Category ← mime type ──────────────────────── //
    function _umatDrawerCat(m) {
        if (!m) return 'other';
        m = m.toLowerCase();
        if (m.indexOf('pdf') !== -1) return 'pdf';
        if (m.indexOf('video') !== -1) return 'video';
        if (m.indexOf('presentation') !== -1 || m.indexOf('powerpoint') !== -1) return 'slides';
        if (m.indexOf('sheet') !== -1 || m.indexOf('excel') !== -1) return 'sheets';
        if (m.indexOf('word') !== -1 || m.indexOf('document') !== -1) return 'docs';
        if (m.indexOf('image') !== -1) return 'image';
        if (m.indexOf('audio') !== -1) return 'audio';
        return 'other';
    }

    // ─── Recently used (localStorage) ──────────────── //
    function _umatDrawerRecentGet() {
        try {
            return JSON.parse(localStorage.getItem('umat_drawer_recent') || '[]');
        } catch (e) { return []; }
    }

    function _umatDrawerRecentAdd(id, name) {
        var r = _umatDrawerRecentGet();
        r = r.filter(function(x) { return x.id !== id; });
        r.unshift({ id: id, name: name });
        if (r.length > 10) r = r.slice(0, 10);
        try { localStorage.setItem('umat_drawer_recent', JSON.stringify(r)); } catch (e) {}
    }

    // ─── Init attachment drawer (enhanced) ─────────── //
    function _umatInitAttachDrawer(cfg) {
        var d = document.getElementById(cfg.drawerId);
        var ab = document.getElementById(cfg.attachBtnId);
        if (!ab || !d) return { clear: function() {} };

        var m = [];
        var allMats = [];
        var activeCat = 'all';
        var searchQ = '';
        var maxSel = cfg.maxSelections || 20;
        var focusIdx = -1;

        var searchInput = document.getElementById(cfg.searchId);
        var listEl = document.getElementById(cfg.listId);
        var countEl = document.getElementById(cfg.countId);
        var confirmBtn = document.getElementById(cfg.confirmId);
        var closeBtn = document.getElementById(cfg.closeBtnId);
        var catsEl = document.getElementById(cfg.catsId);

        function closeDrawer() { d.classList.remove('open'); }

        function updateCount() {
            if (countEl) {
                var txt = m.length + ' selected';
                if (maxSel < 999) txt = txt + ' (max ' + maxSel + ')';
                countEl.textContent = txt;
            }
            if (confirmBtn) {
                confirmBtn.disabled = m.length === 0;
            }
        }

        function toggleItem(id, checked) {
            if (checked) {
                if (m.length >= maxSel) {
                    showMaxWarn();
                    return false;
                }
                var mat = allMats.find(function(x) { return String(x.id) === String(id); });
                if (mat && !m.find(function(x) { return String(x.id) === String(id); })) {
                    m.push({ id: mat.id, name: mat.filename });
                    _umatDrawerRecentAdd(mat.id, mat.filename);
                }
            } else {
                m = m.filter(function(x) { return String(x.id) !== String(id); });
            }
            updateCount();
            renderRecentChips();
            return true;
        }

        function showMaxWarn() {
            var warn = d.querySelector('.umat-drawer-max-warn');
            if (!warn) {
                warn = document.createElement('div');
                warn.className = 'umat-drawer-max-warn';
                warn.innerHTML = '<span class="material-symbols-outlined">warning</span> Maximum ' + maxSel + ' materials allowed. Deselect one to add another.';
                if (searchInput && searchInput.parentNode) {
                    searchInput.parentNode.parentNode.insertBefore(warn, searchInput.parentNode.nextSibling);
                }
            }
            warn.style.display = 'flex';
            setTimeout(function() { warn.style.display = 'none'; }, 3000);
        }

        function clearAll() {
            m = [];
            if (listEl) {
                listEl.querySelectorAll('input[type=checkbox]:checked').forEach(function(cb) {
                    cb.checked = false;
                });
                listEl.querySelectorAll('.umat-drawer-item-selected').forEach(function(item) {
                    item.classList.remove('umat-drawer-item-selected');
                });
            }
            updateCount();
            renderRecentChips();
        }

        function filterList() {
            if (!listEl) return;
            var items = listEl.querySelectorAll('.umat-drawer-item');
            var q = searchQ.toLowerCase().trim();
            var visibleCount = 0;
            items.forEach(function(item) {
                var name = (item.dataset.name || '').toLowerCase();
                var cat = item.dataset.cat || 'other';
                var matchCat = activeCat === 'all' || cat === activeCat;
                var matchQ = !q || name.indexOf(q) !== -1;
                var visible = matchCat && matchQ;
                item.classList.toggle('umat-drawer-item-hidden', !visible);
                if (visible) visibleCount++;
            });
            var noResults = listEl.querySelector('.umat-drawer-noresults');
            if (visibleCount === 0 && items.length > 0) {
                if (!noResults) {
                    noResults = document.createElement('div');
                    noResults.className = 'umat-drawer-noresults';
                    listEl.appendChild(noResults);
                }
                noResults.innerHTML = 'No ' + (activeCat !== 'all' ? activeCat + ' ' : '') + 'materials match "<strong>' + _umatEsc(searchQ) + '</strong>"';
                noResults.style.display = '';
            } else if (noResults) {
                noResults.style.display = 'none';
            }
            focusIdx = -1;
        }

        function renderRecentChips() {
            var recentContainer = document.getElementById(cfg.recentId);
            if (!recentContainer) return;
            var recent = _umatDrawerRecentGet();
            if (!recent.length) {
                recentContainer.style.display = 'none';
                return;
            }
            recentContainer.style.display = '';
            recentContainer.innerHTML = '<div class="umat-drawer-recent-lbl">Recently Used</div><div class="umat-drawer-recent-chips">' +
                recent.map(function(r) {
                    return '<button class="umat-drawer-recent-chip" data-id="' + r.id + '" data-name="' + _umatEsc(r.name) + '" type="button"><span class="material-symbols-outlined">history</span>' + _umatEsc(r.name) + '</button>';
                }).join('') + '</div>';
            recentContainer.querySelectorAll('.umat-drawer-recent-chip').forEach(function(chip) {
                chip.addEventListener('click', function() {
                    var id = this.dataset.id;
                    var cb = listEl ? listEl.querySelector('input[value="' + id + '"]') : null;
                    if (cb) {
                        if (!cb.checked) {
                            if (toggleItem(id, true)) {
                                cb.checked = true;
                                var item = cb.closest('.umat-drawer-item');
                                if (item) item.classList.add('umat-drawer-item-selected');
                            }
                        }
                    }
                });
            });
        }

        function renderMats(ms) {
            allMats = ms || [];
            if (!listEl) return;
            if (!ms || !ms.length) {
                listEl.innerHTML = '<div class="umat-drawer-empty"><span class="material-symbols-outlined">folder_open</span><p>No materials for this course.</p><p class="sub">Add materials in your course settings.</p></div>';
                if (confirmBtn) confirmBtn.disabled = true;
                return;
            }

            var cats = {};
            var catLabels = { pdf: 'PDF', video: 'Video', slides: 'Slides', sheets: 'Sheets', docs: 'Documents', image: 'Images', audio: 'Audio', other: 'Other' };
            var catOrder = ['pdf', 'video', 'slides', 'docs', 'sheets', 'image', 'audio', 'other'];

            listEl.innerHTML = ms.map(function(x) {
                var mime = x.mimetype || '';
                var di = _umatDrawerIcon(mime);
                var cat = _umatDrawerCat(mime);
                if (!cats[cat]) cats[cat] = 0;
                cats[cat]++;
                var sz = _umatFmtSz(x.filesize);
                var checked = m.some(function(s) { return String(s.id) === String(x.id); }) ? ' checked' : '';
                return '<label class="umat-drawer-item' + (checked ? ' umat-drawer-item-selected' : '') + '" data-name="' + _umatEsc(x.filename) + '" data-cat="' + cat + '" data-id="' + x.id + '">' +
                    '<input type="checkbox" value="' + x.id + '" data-name="' + _umatEsc(x.filename) + '"' + checked + '>' +
                    '<div class="umat-drawer-item-icon ' + di.cls + '"><span class="material-symbols-outlined">' + di.icon + '</span></div>' +
                    '<div class="umat-drawer-item-info"><strong>' + _umatEsc(x.filename) + '</strong><span>' + sz + '</span></div>' +
                    '</label>';
            }).join('');

            // Category tabs
            if (catsEl) {
                var catHtml = '<button class="umat-drawer-cat-btn active" data-cat="all">All <span class="umat-drawer-count">' + ms.length + '</span></button>';
                catOrder.forEach(function(c) {
                    if (cats[c]) {
                        catHtml += '<button class="umat-drawer-cat-btn" data-cat="' + c + '">' + (catLabels[c] || c) + ' <span class="umat-drawer-count">' + cats[c] + '</span></button>';
                    }
                });
                catsEl.innerHTML = catHtml;
                catsEl.querySelectorAll('.umat-drawer-cat-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        catsEl.querySelectorAll('.umat-drawer-cat-btn').forEach(function(b) { b.classList.remove('active'); });
                        this.classList.add('active');
                        activeCat = this.dataset.cat;
                        filterList();
                    });
                });
            }

            // Checkbox events
            listEl.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var item = this.closest('.umat-drawer-item');
                    if (this.checked) {
                        if (toggleItem(this.value, true)) {
                            if (item) item.classList.add('umat-drawer-item-selected');
                        } else {
                            this.checked = false;
                        }
                    } else {
                        toggleItem(this.value, false);
                        if (item) item.classList.remove('umat-drawer-item-selected');
                    }
                });
            });

            // Click on item label toggles
            listEl.querySelectorAll('.umat-drawer-item').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    if (e.target.tagName === 'INPUT') return;
                    var cb = this.querySelector('input[type=checkbox]');
                    if (cb) cb.click();
                });
            });

            // Keyboard navigation
            listEl.addEventListener('keydown', function(e) {
                var items = listEl.querySelectorAll('.umat-drawer-item:not(.umat-drawer-item-hidden)');
                if (!items.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    focusIdx = Math.min(focusIdx + 1, items.length - 1);
                    updateFocus(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    focusIdx = Math.max(focusIdx - 1, 0);
                    updateFocus(items);
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (focusIdx >= 0 && focusIdx < items.length) {
                        var focused = items[focusIdx];
                        var cb = focused.querySelector('input[type=checkbox]');
                        if (cb) cb.click();
                    }
                }
            });

            function updateFocus(items) {
                items.forEach(function(item, i) {
                    item.classList.toggle('umat-drawer-focused', i === focusIdx);
                    if (i === focusIdx) {
                        item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    }
                });
            }

            renderRecentChips();
            updateCount();
            filterList();
        }

        function loadMats() {
            var cid = typeof cfg.getCourseId === 'function' ? cfg.getCourseId() : cfg.courseid;
            if (!cid) return;
            if (!listEl) return;
            listEl.innerHTML = '<div class="umat-drawer-loading"><div class="umat-vw-spinner"></div><span>Loading materials\u2026</span></div>';
            require(['core/ajax'], function(A) {
                A.call([{ methodname: 'local_umat_ai_get_course_materials', args: { courseid: cid } }])[0]
                    .done(function(r) {
                        renderMats(r.materials || []);
                    }).fail(function() {
                        listEl.innerHTML = '<div class="umat-drawer-empty"><span class="material-symbols-outlined">error</span><p>Failed to load materials.</p><p class="sub">Check your connection and try again.</p></div>';
                    });
            });
        }

        // ─── Event binding ──────────────────────────── //
        ab.addEventListener('click', function() {
            d.classList.toggle('open');
            console.log('[umat] drawer open:', d.classList.contains('open'));
            if (d.classList.contains('open')) {
                loadMats();
                setTimeout(function() {
                    if (searchInput) searchInput.focus();
                }, 400);
            }
        });

        if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                closeDrawer();
                if (cfg.onConfirm) cfg.onConfirm(m);
            });
        }

        // Clear all
        var clearBtn = document.getElementById(cfg.clearId);
        if (clearBtn) clearBtn.addEventListener('click', clearAll);

        // Search input
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                searchQ = this.value;
                filterList();
            });
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    this.value = '';
                    searchQ = '';
                    filterList();
                    this.blur();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    var items = listEl ? listEl.querySelectorAll('.umat-drawer-item:not(.umat-drawer-item-hidden)') : [];
                    if (items.length > 0 && focusIdx < 0) focusIdx = 0;
                    if (focusIdx >= 0 && focusIdx < items.length) {
                        var cb = items[focusIdx].querySelector('input[type=checkbox]');
                        if (cb) cb.click();
                    }
                } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.stopPropagation();
                }
            });
        }

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (d.classList.contains('open') && !d.contains(e.target) && !ab.contains(e.target)) {
                closeDrawer();
            }
        });

        return {
            clear: function() {
                m = [];
                if (listEl) {
                    listEl.querySelectorAll('input[type=checkbox]:checked').forEach(function(cb) {
                        cb.checked = false;
                    });
                    listEl.querySelectorAll('.umat-drawer-item-selected').forEach(function(item) {
                        item.classList.remove('umat-drawer-item-selected');
                    });
                }
                updateCount();
                renderRecentChips();
            }
        };
    }

    // ═══════════════════════════════════════════════════ //
    //   YOUTUBE-STYLE TILE RENDER HELPERS                 //
    // ═══════════════════════════════════════════════════ //

    function _ytThumbBg(mime) {
        if (!mime) return 'yt-bg-other';
        mime = mime.toLowerCase();
        if (mime.indexOf('video') !== -1) return 'yt-bg-video';
        if (mime.indexOf('pdf') !== -1) return 'yt-bg-pdf';
        if (mime.indexOf('word') !== -1 || mime.indexOf('document') !== -1) return 'yt-bg-word';
        if (mime.indexOf('presentation') !== -1 || mime.indexOf('powerpoint') !== -1) return 'yt-bg-pptx';
        if (mime.indexOf('sheet') !== -1 || mime.indexOf('excel') !== -1) return 'yt-bg-excel';
        if (mime.indexOf('image') !== -1) return 'yt-bg-image';
        if (mime.indexOf('audio') !== -1) return 'yt-bg-audio';
        return 'yt-bg-other';
    }

    function _ytAvCls(mime) {
        if (!mime) return 'yt-av-other';
        mime = mime.toLowerCase();
        if (mime.indexOf('video') !== -1) return 'yt-av-video';
        if (mime.indexOf('pdf') !== -1) return 'yt-av-pdf';
        if (mime.indexOf('word') !== -1 || mime.indexOf('document') !== -1) return 'yt-av-word';
        if (mime.indexOf('presentation') !== -1 || mime.indexOf('powerpoint') !== -1) return 'yt-av-pptx';
        if (mime.indexOf('sheet') !== -1 || mime.indexOf('excel') !== -1) return 'yt-av-excel';
        if (mime.indexOf('image') !== -1) return 'yt-av-image';
        if (mime.indexOf('audio') !== -1) return 'yt-av-audio';
        return 'yt-av-other';
    }

    function _ytIcon(mime) {
        if (!mime) return 'description';
        mime = mime.toLowerCase();
        if (mime.indexOf('video') !== -1) return 'videocam';
        if (mime.indexOf('pdf') !== -1) return 'picture_as_pdf';
        if (mime.indexOf('word') !== -1 || mime.indexOf('document') !== -1) return 'description';
        if (mime.indexOf('presentation') !== -1 || mime.indexOf('powerpoint') !== -1) return 'co_present';
        if (mime.indexOf('sheet') !== -1 || mime.indexOf('excel') !== -1) return 'table_chart';
        if (mime.indexOf('image') !== -1) return 'image';
        if (mime.indexOf('audio') !== -1) return 'music_note';
        return 'description';
    }

    function _ytExtLabel(mime) {
        if (!mime) return 'FILE';
        var m = mime.toLowerCase();
        if (m.indexOf('pdf') !== -1) return 'PDF';
        if (m.indexOf('wordprocessingml') !== -1 || m.indexOf('msword') !== -1) return 'DOCX';
        if (m.indexOf('presentationml') !== -1 || m.indexOf('powerpoint') !== -1) return 'PPTX';
        if (m.indexOf('spreadsheetml') !== -1 || m.indexOf('excel') !== -1) return 'XLSX';
        if (m.indexOf('video/mp4') !== -1) return 'MP4';
        if (m.indexOf('video') !== -1) return 'VIDEO';
        if (m.indexOf('image/png') !== -1) return 'PNG';
        if (m.indexOf('image/jpeg') !== -1) return 'JPG';
        if (m.indexOf('image') !== -1) return 'IMG';
        if (m.indexOf('audio') !== -1) return 'AUDIO';
        var parts = mime.split('/');
        return (parts[1] || parts[0] || 'FILE').toUpperCase().replace('VND.', '').split('.').pop();
    }

    // ─── Shared material tile renderer ─────────────── //
    function _renderYtMaterials(mats, g, pfx, courseId) {
        if (!mats || !mats.length) {
            g.innerHTML = '<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">folder_open</span><p>No materials found for this course.</p></div>';
            return;
        }
        g.className = 'yt-grid';
        g.innerHTML = mats.map(function(m) {
            var mime = m.mimetype || '';
            var bg = _ytThumbBg(mime);
            var av = _ytAvCls(mime);
            var ic = _ytIcon(mime);
            var ext = _ytExtLabel(mime);
            var isVideo = mime.toLowerCase().indexOf('video') !== -1;
            var playIcon = isVideo ? 'play_arrow' : 'open_in_new';
            var badge = '';
            if (m.duration) badge = '<span class="yt-badge">' + _umatEsc(m.duration) + '</span>';
            else if (m.page_count && m.page_count > 0) badge = '<span class="yt-badge">' + m.page_count + ' pp</span>';
            else badge = '<span class="yt-badge">' + ext + '</span>';
            var sz = (Math.round((m.filesize || 0) / 1024)) + 'KB';
            return                 '<div class="yt-tile" data-url="' + _umatEsc(m.url || '') + '" data-name="' + _umatEsc(m.filename || '') + '" data-mime="' + _umatEsc(mime) + '" data-fileid="' + (m.id || 0) + '" data-courseid="' + (courseId || '') + '">' +
                '<div class="yt-thumb ' + bg + '">' +
                '<span class="yt-thumb-icon material-symbols-outlined">' + ic + '</span>' +
                '<div class="yt-play-ov"><span class="material-symbols-outlined">' + playIcon + '</span></div>' +
                badge +
                '</div>' +
                '<div class="yt-meta">' +
                '<div class="yt-av ' + av + '"><span class="material-symbols-outlined">' + ic + '</span></div>' +
                '<div class="yt-text">' +
                '<h4 class="yt-title" title="' + _umatEsc(m.filename || '') + '">' + _umatEsc(m.filename || '') + '</h4>' +
                '<p class="yt-channel">' + ext + ' · ' + sz + '</p>' +
                '<p class="yt-stats">' + _umatEsc(m.time_ago || '') + '</p>' +
                '</div>' +
                '</div>' +
                '<div class="yt-actions">' +
                '<button class="yt-btn yt-view-btn"><span class="material-symbols-outlined">visibility</span>View</button>' +
                '<a class="yt-btn" href="' + _umatEsc(m.url || '#') + '" download="' + _umatEsc(m.filename || '') + '" onclick="event.stopPropagation()"><span class="material-symbols-outlined">download</span>Download</a>' +

                '</div>' +
                '</div>';
        }).join('');

        g.querySelectorAll('.yt-tile').forEach(function(tile) {
            tile.addEventListener('click', function(e) {
                if (e.target.closest('a.yt-btn')) return;
                e.preventDefault();
                var mime = tile.dataset.mime || '';
                var url = tile.dataset.url;
                var name = tile.dataset.name;
                if (window.umatMaterialViewer) {
                    var type =
                        mime.indexOf('video') !== -1 ? 'video' :
                        mime.indexOf('pdf') !== -1 ? 'pdf' :
                        mime.indexOf('image') !== -1 ? 'image' :
                        mime.indexOf('audio') !== -1 ? 'audio' :
                        mime.indexOf('wordprocessingml.document') !== -1 ? 'docx' :
                        mime.indexOf('msword') !== -1 ? 'doc' :
                        mime.indexOf('spreadsheetml.sheet') !== -1 ? 'xlsx' :
                        mime.indexOf('presentationml.presentation') !== -1 ? 'pptx' :
                        mime.indexOf('ms-powerpoint') !== -1 ? 'ppt' :
                        (mime.indexOf('text/') === 0 || mime.indexOf('application/json') === 0 || mime.indexOf('application/xml') === 0 || mime.indexOf('application/x-httpd-php') !== -1) ? 'code' :
                        'other';
                    window.umatMaterialViewer.open(type, { url: url, name: name, downloadUrl: url, mime: mime, materialId: parseInt(tile.dataset.fileid) || 0, courseId: parseInt(tile.dataset.courseid) || 0 });
                } else {
                    window.open(url, '_blank');
                }
            });
            var vb = tile.querySelector('.yt-view-btn');
            if (vb) vb.addEventListener('click', function(e) { e.stopPropagation(); tile.click(); });
        });
    }

    // ─── Video tiles (student Lectures tab) ────────── //
    function showTranscriptModal(jobId, title) {
        var ov = document.createElement('div');
        ov.className = 'umat-cs-overlay';
        ov.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.4);';
        ov.innerHTML = '<div class="umat-cs-modal" style="max-width:700px;max-height:80vh;display:flex;flex-direction:column;">' +
            '<div class="umat-cs-modal-hdr"><h3>' + _umatEsc(title || 'Transcript') + '</h3>' +
            '<button class="umat-cs-close" type="button"><span class="material-symbols-outlined">close</span></button></div>' +
            '<div id="umatshared-transcript-body" style="flex:1;overflow-y:auto;padding:16px;font-size:13px;line-height:1.6;white-space:pre-wrap;color:var(--u-ons);">' +
            '<div style="text-align:center;padding:20px;"><span class="material-symbols-outlined" style="font-size:24px;color:var(--u-ol);">hourglass_empty</span>' +
            '<p style="font-size:12px;color:var(--u-ol);">Loading transcript\u2026</p></div></div></div>';
        document.body.appendChild(ov);
        ov.querySelector('.umat-cs-close').addEventListener('click', function() { document.body.removeChild(ov); });
        ov.addEventListener('click', function(e) { if (e.target === this) document.body.removeChild(ov); });

        require(['core/ajax'], function(Ajax) {
            Ajax.call([{ methodname: 'local_umat_ai_get_transcription', args: { job_id: jobId } }])[0]
                .done(function(r) {
                    var body = document.getElementById('umatshared-transcript-body');
                    if (!body) return;
                    if (r.success && r.transcript) {
                        body.textContent = r.transcript;
                    } else if (r.status === 'processing') {
                        body.innerHTML = '<div style="text-align:center;padding:20px;">' +
                            '<span class="material-symbols-outlined" style="font-size:24px;color:#d97706;">hourglass_empty</span>' +
                            '<p style="font-size:12px;color:#d97706;">Transcription is still processing\u2026</p></div>';
                    } else {
                        body.innerHTML = '<div style="text-align:center;padding:20px;">' +
                            '<span class="material-symbols-outlined" style="font-size:24px;color:var(--u-ter);">error</span>' +
                            '<p style="font-size:12px;color:var(--u-ter);">' + _umatEsc(r.error || 'No transcript available') + '</p></div>';
                    }
                })
                .fail(function() {
                    var body = document.getElementById('umatshared-transcript-body');
                    if (body) body.innerHTML = '<div style="text-align:center;padding:20px;"><p style="font-size:12px;color:var(--u-ter);">Could not load transcript.</p></div>';
                });
        });
    }

    function renderVideoTiles(recs) {
        var grid = document.getElementById('stu-lec-grid') || document.getElementById('ws-lib-lectures') || document.getElementById('ws-video-grid') || document.getElementById('lec-lib-lectures');
        console.log('[UMAT-REC] renderVideoTiles', {gridId:grid?grid.id:'null', recsCount:recs?recs.length:0});
        if (!grid) return;
        if (recs && !Array.isArray(recs)) {
            recs = recs.recordings || recs.data || recs.tiles || [];
        }
        recs = recs || [];
        if (!recs.length) {
            grid.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">video_library</span><p>No lecture recordings yet. They appear once a BBB session is processed by your lecturer.</p></div>';
            return;
        }
        grid.className = 'yt-grid';
        grid.innerHTML = recs.map(function(r, i) {
            var badge = r.duration ? '<span class="yt-badge">' + _umatEsc(r.duration) + '</span>' : '';
            var segsData = JSON.stringify(r.segments || []).replace(/'/g, '&#39;');
            var hasTranscript = r.has_transcript || (r.segments && r.segments.length > 0);
            var recordingStatus = r.status || 'pending';
            var transBtnHtml = '';
            if (hasTranscript) {
                transBtnHtml = '<button class="yt-btn yt-transcript-btn" data-session="' + _umatEsc(r.session_key || r.id || '') + '" onclick="event.stopPropagation()"><span class="material-symbols-outlined">subtitles</span>View Transcript</button>';
            } else if (recordingStatus === 'transcribing' || recordingStatus === 'indexing') {
                transBtnHtml = '<button class="yt-btn" disabled style="opacity:.6;"><span class="material-symbols-outlined">hourglass_top</span>Processing\u2026</button>';
            } else {
                transBtnHtml = '<button class="yt-btn yt-transcribe-btn" data-session="' + _umatEsc(r.session_key || r.id || '') + '" data-status="' + _umatEsc(recordingStatus) + '" onclick="event.stopPropagation()"><span class="material-symbols-outlined">mic</span>Transcribe</button>';
            }
            return '<div class="yt-tile" data-idx="' + i + '" data-url="' + _umatEsc(r.url || '') + '" data-title="' + _umatEsc(r.title || 'Lecture Recording') + '" data-segs=\'' + segsData + '\' data-has-transcript="' + (hasTranscript ? '1' : '0') + '" data-session-key="' + _umatEsc(r.session_key || r.id || '') + '">' +
                '<div class="yt-thumb yt-bg-video">' +
                '<span class="yt-thumb-icon material-symbols-outlined">play_circle</span>' +
                '<div class="yt-play-ov"><span class="material-symbols-outlined">play_arrow</span></div>' +
                badge +
                '</div>' +
                '<div class="yt-meta">' +
                '<div class="yt-av yt-av-video"><span class="material-symbols-outlined">smart_toy</span></div>' +
                '<div class="yt-text">' +
                '<h4 class="yt-title" title="' + _umatEsc(r.title || 'Lecture Recording') + '">' + _umatEsc(r.title || 'Lecture Recording') + '</h4>' +
                '<p class="yt-channel">' + _umatEsc(r.description || 'UMaT Lecture') + '</p>' +
                '<p class="yt-stats">' + _umatEsc(r.time_ago || r.date || '') + '</p>' +
                '</div>' +
                '</div>' +
                '<div class="yt-actions">' +
                '<button class="yt-btn" data-play="1" onclick="event.stopPropagation()"><span class="material-symbols-outlined">play_arrow</span>Play</button>' +
                transBtnHtml +
                '</div>' +
                '</div>';
        }).join('');

        grid.querySelectorAll('.yt-tile').forEach(function(tile) {
            tile.addEventListener('click', function(e) {
                if (e.target.closest('a.yt-btn') || e.target.closest('.yt-transcribe-btn') || e.target.closest('.yt-transcript-btn')) return;
                e.preventDefault();
                var segs = [];
                try { segs = JSON.parse(tile.dataset.segs || '[]'); } catch (ex) {}
                if (window.umatMaterialViewer) {
                    window.umatMaterialViewer.open('video', {
                        url: tile.dataset.url,
                        name: tile.dataset.title || 'Lecture Recording',
                        segments: segs,
                        downloadUrl: tile.dataset.url
                    });
                } else if (tile.dataset.url) window.open(tile.dataset.url, '_blank');
            });
            var playBtn = tile.querySelector('[data-play]');
            if (playBtn) playBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                tile.click();
            });
            var transcribeBtn = tile.querySelector('.yt-transcribe-btn');
            if (transcribeBtn) {
                transcribeBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var btn = this;
                    var sessionKey = btn.dataset.session;
                    if (!sessionKey) return;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="material-symbols-outlined">hourglass_top</span>Starting\u2026';
                    require(['core/ajax'], function(Ajax) {
                        Ajax.call([{ methodname: 'local_umat_ai_transcribe_recording', args: { session_id: sessionKey } }])[0]
                            .done(function(r) {
                                if (r.success) {
                                    btn.innerHTML = '<span class="material-symbols-outlined">hourglass_top</span>Processing\u2026';
                                    tile.dataset.hasTranscript = '0';
                                    var pollInterval = setInterval(function() {
                                        Ajax.call([{ methodname: 'local_umat_ai_get_transcription', args: { job_id: sessionKey } }])[0]
                                            .done(function(pr) {
                                                if (pr.success && pr.transcript) {
                                                    clearInterval(pollInterval);
                                                    btn.disabled = false;
                                                    btn.className = 'yt-btn yt-transcript-btn';
                                                    btn.innerHTML = '<span class="material-symbols-outlined">subtitles</span>View Transcript';
                                                    tile.dataset.hasTranscript = '1';
                                                } else if (pr.status === 'failed' || (pr.error && !pr.transcript)) {
                                                    clearInterval(pollInterval);
                                                    btn.disabled = false;
                                                    btn.innerHTML = '<span class="material-symbols-outlined">mic</span>Transcribe';
                                                }
                                            })
                                            .fail(function() { clearInterval(pollInterval); btn.disabled = false; btn.innerHTML = '<span class="material-symbols-outlined">mic</span>Transcribe'; });
                                    }, 5000);
                                } else {
                                    btn.disabled = false;
                                    btn.innerHTML = '<span class="material-symbols-outlined">mic</span>Transcribe';
                                    require(['core/notification'], function(N) { N.error({ message: r.message || 'Transcription failed.' }); });
                                }
                            })
                            .fail(function() {
                                btn.disabled = false;
                                btn.innerHTML = '<span class="material-symbols-outlined">mic</span>Transcribe';
                                require(['core/notification'], function(N) { N.error({ message: 'Could not start transcription.' }); });
                            });
                    });
                });
            }
            var transcriptBtn = tile.querySelector('.yt-transcript-btn');
            if (transcriptBtn) {
                transcriptBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var sessionKey = this.dataset.session;
                    if (!sessionKey) return;
                    showTranscriptModal(sessionKey, tile.dataset.title || 'Transcript');
                });
            }
        });
    }

    // ─── Course tiles (student My Courses) ─────────── //
    function renderCourses(courses, gridOverride) {
        var grid = gridOverride || document.getElementById('stu-courses-grid') || document.getElementById('ws-courses-grid');
        if (!grid) return;
        if (courses && !Array.isArray(courses)) courses = courses.courses || [];
        courses = courses || [];
        if (!courses.length) {
            grid.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No enrolled courses found.</p></div>';
            return;
        }
        grid.className = 'yt-grid';
        grid.innerHTML = courses.map(function(c) {
            return '<div class="yt-tile" data-cid="' + c.id + '" data-cname="' + _umatEsc(c.fullname || '') + '">' +
                '<div class="yt-thumb yt-bg-course">' +
                '<div class="yt-course-ov">' +
                '<div class="yt-course-code">' + _umatEsc(c.shortname || '') + '</div>' +
                '<div class="yt-course-name">' + _umatEsc(c.fullname || '') + '</div>' +
                '</div>' +
                '</div>' +
                '<div class="yt-meta">' +
                '<div class="yt-av yt-av-course"><span class="material-symbols-outlined">menu_book</span></div>' +
                '<div class="yt-text">' +
                '<h4 class="yt-title">' + _umatEsc(c.fullname || '') + '</h4>' +
                '<p class="yt-channel">' + _umatEsc(c.shortname || '') + '</p>' +
                '<p class="yt-stats">Click to chat about this course</p>' +
                '</div>' +
                '</div>' +
                '<div class="yt-actions">' +
                '<button class="yt-btn"><span class="material-symbols-outlined">smart_toy</span>AI Tutor</button>' +
                '</div>' +
                '</div>';
        }).join('');

        grid.querySelectorAll('.yt-tile').forEach(function(tile) {
            tile.addEventListener('click', function() {
                if (typeof selectCourse === 'function') selectCourse(parseInt(tile.dataset.cid), tile.dataset.cname);
            });
        });
        var srch = document.getElementById('stu-courses-srch') || document.getElementById('stu-courses-search');
        if (srch) srch.addEventListener('input', function() {
            var q = this.value.toLowerCase();
            grid.querySelectorAll('.yt-tile').forEach(function(t) {
                t.style.display = (!q || t.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    // ─── Library tiles ─────────────────────────────── //
    function renderLibrary(mats, courseId) {
        var grid = document.getElementById('stu-lib-grid') || document.getElementById('ws-lib-grid');
        if (!grid) return;
        if (mats && !Array.isArray(mats)) mats = mats.materials || [];
        _renderYtMaterials(mats || [], grid, 'stu', courseId);
    }

    // ─── Lecturer library tiles ────────────────────── //
    function renderLibTiles(materials, g, courseId) {
        if (!g) { g = document.getElementById('lec-lib-grid'); }
        _renderYtMaterials(materials, g, 'lec', courseId);
    }

    // ─── ESC key handler (nested-first) ────────────── //
    function _umatInitEsc(layers) {
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            for (var i = 0; i < layers.length; i++) {
                var el = document.getElementById(layers[i].id);
                if (el && layers[i].isOpen(el)) {
                    layers[i].close(el);
                    e.preventDefault();
                    return;
                }
            }
        });
    }

    // ─── Scroll-to-Bottom FAB ──────────────────────── //
    function _umatInitScrollToBottom(containerId) {
        var c = document.getElementById(containerId);
        if (!c) return;

        // Clean up any previous state for this container.
        if (c._umatToggle) {
            c.removeEventListener('scroll', c._umatToggle);
            delete c._umatToggle;
        }
        var oldFab = c.querySelector('.umat-scroll-bottom-fab');
        if (oldFab) oldFab.remove();

        c.style.position = 'relative';

        var fab = document.createElement('button');
        fab.className = 'umat-scroll-bottom-fab';
        fab.innerHTML = '<span class="material-symbols-outlined">expand_more</span>';
        fab.setAttribute('aria-label', 'Scroll to bottom');
        c.appendChild(fab);

        c._umatToggle = function() {
            var atBottom = c.scrollHeight - c.scrollTop - c.clientHeight < 5;
            fab.classList.toggle('visible', !atBottom);
        };

        c.addEventListener('scroll', c._umatToggle);
        c._umatToggle();

        fab.addEventListener('click', function() {
            c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' });
        });
    }

    // ─── Short-name aliases ────────────────────────── //
    var esc = _umatEsc;
    var fmtDuration = _umatFmtT;
    var fmtSz = _umatFmtSz;
    var fmtFileSize = _umatFmtSz;
    var timeAgo = _umatTimeAgo;
    var libTileClass = _umatLibTileClass;
    var fileTypeIcon = _umatFileTypeIcon;

    // ─── AJAX helper ───────────────────────────────── //
    function ajax(method, args, done, fail) {
        require(['core/ajax'], function(A) {
            A.call([{ methodname: method, args: args }])[0].done(done).fail(fail || function() {});
        });
    }

    // ─── Exports ───────────────────────────────────── //
    return {
        // HTML escaping
        _umatEsc: _umatEsc,
        esc: esc,
        htmlEsc: _umatEsc,

        // Formatting
        _umatFmtT: _umatFmtT,
        fmtDuration: fmtDuration,
        fmtT: _umatFmtT,
        _umatFmtSz: _umatFmtSz,
        fmtSz: fmtSz,
        fmtFileSize: fmtFileSize,

        // Time
        _umatTimeAgo: _umatTimeAgo,
        timeAgo: timeAgo,

        // Material helpers
        _umatLibTileClass: _umatLibTileClass,
        libTileClass: libTileClass,
        _umatFileTypeIcon: _umatFileTypeIcon,
        fileTypeIcon: fileTypeIcon,

        // Chat UI
        _umatAppendUser: _umatAppendUser,
        _umatAppendAi: _umatAppendAi,
        _umatShowTyping: _umatShowTyping,
        _umatHideTyping: _umatHideTyping,
        _umatStreamChat: _umatStreamChat,
        _umatStreamInline: _umatStreamInline,
        _umatFormatAI: _umatFormatAI,
        _umatHandleReply: _umatHandleReply,
        _getReplyContext: function() { return _replyContext; },
        _clearReplyContext: function() { _replyContext = null; },
        _cancelActiveStream: function() {
            if (_activeStream) {
                try { _activeStream.abort(); } catch(e) {}
                _activeStream = null;
            }
        },
        _isStreamActive: function() { return !!_activeStream; },
        getChatState: function() { return _chatState; },

        // Voice
        ChatVoiceInput: ChatVoiceInput,

        // Materials bar
        _umatRenderMatsBar: _umatRenderMatsBar,

        // Drawer helpers
        _umatDrawerIcon: _umatDrawerIcon,
        _umatDrawerCat: _umatDrawerCat,
        _umatDrawerRecentGet: _umatDrawerRecentGet,
        _umatDrawerRecentAdd: _umatDrawerRecentAdd,

        // Attachment drawer
        _umatInitAttachDrawer: _umatInitAttachDrawer,

        // YT tile helpers
        _ytThumbBg: _ytThumbBg,
        _ytAvCls: _ytAvCls,
        _ytIcon: _ytIcon,
        _ytExtLabel: _ytExtLabel,
        ytThumbBg: _ytThumbBg,
        ytAvCls: _ytAvCls,
        ytIcon: _ytIcon,
        ytExtLabel: _ytExtLabel,

        // Renderers
        renderVideoTiles: renderVideoTiles,
        showTranscriptModal: showTranscriptModal,
        renderCourses: renderCourses,
        renderLibrary: renderLibrary,
        renderLibTiles: renderLibTiles,
        _renderYtMaterials: _renderYtMaterials,

        // ESC handler
        _umatInitEsc: _umatInitEsc,

        // Scroll-to-bottom FAB
        _umatInitScrollToBottom: _umatInitScrollToBottom,

        // AJAX
        ajax: ajax,


    };
});
