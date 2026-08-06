define ("local_umat_ai/ai_tutor", ['core/ajax', 'local_umat_ai/umatshared', 'local_umat_ai/material_viewer'], function (Ajax, S, M) {
  'use strict';

  function esc(t) {
    return S._umatEsc(String(t == null ? '' : t));
  }

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

      if (!q) {
        sync();
        return;
      }

      onSend(q);
      sync();
    }

    sendButton.addEventListener('click', submit);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        submit();
      }
    });
    input.addEventListener('input', function () {
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 200) + 'px';
      sync();
    });
    sendButton._aitChatControl = {
      submit: submit,
      sync: sync
    };
    sync();
    return sendButton._aitChatControl;
  }

  return {
    init: function (data) {
      for (var k in S) window[k] = S[k];

      window.umatMaterialViewer = M;
      var UD = typeof data.userData === 'string' ? JSON.parse(data.userData || '{}') : data.userData || {};
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
      var LS_LEFT = 'ait-left-panel-open';
      var LS_STUDIO = 'ait-studio-panel-open';
      var LS_TAB = 'ait-active-tab';
      var LS_SID = 'ait-last-session-id';
      var LS_CID = 'ait-last-course-id';

      function lsGet(k) {
        try {
          return localStorage.getItem(k);
        } catch (e) {
          return null;
        }
      }

      function lsSet(k, v) {
        try {
          localStorage.setItem(k, v);
        } catch (e) {}
      }

      var sessKey = lsGet(LS_SID) || 'ait_' + Math.random().toString(36).substr(2, 18);
      var selMat = [];
      var activeCID = FIXED_CID || parseInt(lsGet(LS_CID) || '0') || 0;
      var lastTool = null;
      var busy = false;
      var qTimes = [];

      function qRemaining() {
        var now = Date.now();
        qTimes = qTimes.filter(function (t) {
          return now - t < 60000;
        });
        return Math.max(0, 10 - qTimes.length);
      }

      function currentCID() {
        if (MODE === 'course') return FIXED_CID;
        var v = parseInt(courseSel && courseSel.value) || 0;
        return v || activeCID || 0;
      }

      var toastEl = document.getElementById('ait-toast');
      var toastTimer = null;

      function toast(msg) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
          toastEl.classList.remove('show');
        }, 2600);
      }

      function appendMsg(q, isUser, o) {
        o = o || {};
        var d = document.createElement('div');

        if (isUser) {
          var chipHtml = '';

          if (o.selMat && o.selMat.length) {
            chipHtml = '<div class="umat-ref-chips">' + o.selMat.map(function (m) {
              return '<span class="umat-ref-chip"><span class="material-symbols-outlined">attach_file</span>' + esc(m.name) + '</span>';
            }).join('') + '</div>';
          }

          d.innerHTML = '<div class="umat-msg-user"><div class="umat-bubble-user"><p>' + esc(q) + '</p></div>' + chipHtml + '<button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div>';
        } else {
          var srcs = '';

          if (o.sources && o.sources.length && (!o.citations || !o.citations.length)) {
            srcs = '<div class="umat-src-chips">' + o.sources.map(function (s) {
              return '<span class="umat-src-chip">' + esc(s) + '</span>';
            }).join('') + '</div>';
          }

          var citeZone = '';

          if (o.citations && o.citations.length) {
            citeZone = '<div class="umat-msg-citations-cards" data-cites=\'' + esc(JSON.stringify(o.citations)) + '\'></div>';
          }

          d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>' + '<div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div>' + '<div class="umat-bubble-ai"><div class="umat-ai-content">' + S._umatFormatAI(q) + '</div>' + srcs + citeZone + '</div>' + '<button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div></div>';
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
        var chips = ['Explain the key concepts from the most recent lecture.', 'Create a practice quiz on this topic.', 'Summarize our conversation so far.', 'What are common exam questions for this topic?'].map(function (q) {
          return '<button class="umat-chip" data-q="' + esc(q) + '" type="button">' + esc(q.split('.').slice(0, 2).join('.')) + '</button>';
        }).join('');
        return '<div class="ait-welcome">' + '<div class="ait-welcome-ic"><span class="material-symbols-outlined">smart_toy</span></div>' + '<h3>Welcome to your AI Tutor</h3>' + '<p>I can help you study for ' + name + ' — ask questions, generate quizzes, flashcards, study guides and more using your course materials.</p>' + '<div class="umat-chips-row">' + chips + '</div></div>';
      }

      function showWelcome() {
        msgs.innerHTML = welcomeMarkup();
      }

      function appendSuggestions() {
        var row = document.createElement('div');
        row.className = 'ait-sugg-row';
        row.innerHTML = ['Create a practice quiz on this topic', 'Explain this in simpler terms', 'Generate study guide notes'].map(function (q) {
          return '<button class="ait-sugg-chip" data-q="' + esc(q) + '" type="button">' + esc(q) + '</button>';
        }).join('');
        msgs.appendChild(row);
        msgs.scrollTop = msgs.scrollHeight;
      }

      function appendToolActions(title, text) {
        var row = document.createElement('div');
        row.className = 'ait-tool-actions';
        row.innerHTML = '<button class="ait-tool-btn-sm" id="ait-save-note-btn" type="button"><span class="material-symbols-outlined">note_add</span>Save to Notes</button>';
        msgs.appendChild(row);
        msgs.scrollTop = msgs.scrollHeight;
        var btn = document.getElementById('ait-save-note-btn');
        if (btn) btn.addEventListener('click', function () {
          openNoteEditor({
            id: 0,
            title: title,
            content: text,
            pinned: 0
          }, true);
        });
      }

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
        if (opts.isTool) lastTool = opts.tool || null;
        var ctx = q;

        if (selMat.length) {
          ctx = '[Referencing: ' + selMat.map(function (m) {
            return m.name;
          }).join(', ') + '] ' + q;
        }

        appendMsg(q, true, {
          selMat: selMat
        });
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
          material_ids: selMat.map(function (m) {
            return m.id;
          }),
          msgsId: 'ait-msgs',
          sendBtnId: 'ait-send',
          sendInputId: 'ait-input',
          typingId: tid,
          onMeta: function () {},
          onDone: function () {
            S._umatHideTyping(tid);

            busy = false;
            sendBtn.removeAttribute('aria-busy');
            if (chatControl) chatControl.sync();
            lsSet(LS_SID, sessKey);
            lsSet(LS_CID, String(cid));
            var lastAi = msgs.querySelector('.umat-msg-ai:last-child .umat-ai-content');
            var text = lastAi ? lastAi.innerText : '';

            if (lastTool === 'quiz') {
              var quiz = extractQuiz(text);
              if (quiz) renderQuizCard(quiz);
              lastTool = null;
            } else if (lastTool) {
              var tName = lastTool === 'guide' ? 'Study Guide' : lastTool === 'summary' ? 'Summary' : 'FAQ';
              appendToolActions(tName, text);
              lastTool = null;
            } else {
              appendSuggestions();
            }

            refreshSessions();
          },
          onError: function (err) {
            S._umatHideTyping(tid);

            busy = false;
            sendBtn.removeAttribute('aria-busy');
            if (chatControl) chatControl.sync();
            if (err && err.error === 'rate_limit') qTimes.pop();
          }
        });
      }

      function extractQuiz(text) {
        if (!text) return null;
        var m = text.match(/\{[\s\S]*"questions"[\s\S]*\}/);
        if (!m) return null;

        try {
          var o = JSON.parse(m[0]);

          if (o.questions && o.questions.length) {
            return {
              title: o.quiz_title || 'Practice Quiz',
              questions: o.questions
            };
          }
        } catch (e) {}

        return null;
      }

      function renderQuizCard(quiz) {
        var card = document.createElement('div');
        card.className = 'ait-quiz-card';
        var idx = 0,
            correct = 0,
            answered = false,
            total = quiz.questions.length;
        var q = quiz.questions[0];

        function buildHeader() {
          var dots = '';

          for (var i = 0; i < total; i++) {
            dots += '<span class="ait-quiz-dot' + (i < idx ? ' done' : '') + '"></span>';
          }

          return '<div class="ait-quiz-hdr"><span class="material-symbols-outlined">quiz</span>' + '<strong>' + esc(quiz.title) + '</strong><span class="ait-quiz-score-tag" style="margin-left:6px;">' + idx + '/' + total + '</span>' + '<div class="ait-quiz-progress">' + dots + '</div></div>';
        }

        function render() {
          if (idx >= total) {
            var pct = Math.round(correct / total * 100);
            var msg = pct >= 80 ? 'Excellent work!' : pct >= 50 ? 'Good effort — keep practising!' : 'Keep reviewing — you will get there!';
            card.innerHTML = buildHeader() + '<div class="ait-quiz-body" style="text-align:center;padding:22px;">' + '<div class="ait-welcome-ic" style="width:46px;height:46px;border-radius:14px;margin:0 auto 10px;"><span class="material-symbols-outlined" style="font-size:24px;">' + (pct >= 50 ? 'emoji_events' : 'school') + '</span></div>' + '<div style="font-size:26px;font-weight:800;color:var(--u-p);">' + correct + ' / ' + total + '</div>' + '<div style="font-size:12px;color:var(--u-ol);margin-top:4px;">' + msg + '</div></div>' + '<div class="ait-quiz-foot"><span class="ait-quiz-score-tag">' + pct + '%</span>' + '<button class="ait-quiz-next" id="ait-quiz-restart" type="button">Try Again</button></div>';
            var retry = card.querySelector('#ait-quiz-restart');
            if (retry) retry.addEventListener('click', function () {
              idx = 0;
              correct = 0;
              q = quiz.questions[0];
              render();
            });
            saveAttempt();
            return;
          }

          q = quiz.questions[idx];
          answered = false;
          card.innerHTML = buildHeader() + '<div class="ait-quiz-body"><div class="ait-quiz-q">' + esc(q.q) + '</div>' + '<div class="ait-quiz-opts">' + (q.options || []).map(function (op, i) {
            return '<button class="ait-quiz-opt" data-i="' + i + '" type="button">' + esc(op) + '</button>';
          }).join('') + '</div>' + '<div class="ait-quiz-exp" id="ait-quiz-exp"></div></div>' + '<div class="ait-quiz-foot"><span class="ait-quiz-score-tag">Question ' + (idx + 1) + ' of ' + total + '</span>' + '<button class="ait-quiz-next" id="ait-quiz-next" type="button" disabled>Next</button></div>';
          card.querySelectorAll('.ait-quiz-opt').forEach(function (btn) {
            btn.addEventListener('click', function () {
              if (answered) return;
              answered = true;
              var chosen = q.options[parseInt(btn.dataset.i)];
              var isCorrect = String(chosen).trim().toLowerCase() === String(q.answer).trim().toLowerCase() || String(chosen).trim().toLowerCase().indexOf(String(q.answer).trim().toLowerCase().charAt(0) + ')') === 0;

              if (!isCorrect && /^[A-Da-d]\)/.test(String(q.answer).trim()) && /^[A-Da-d]\)/.test(String(chosen).trim())) {
                isCorrect = String(chosen).trim().charAt(0).toUpperCase() === String(q.answer).trim().charAt(0).toUpperCase();
              }

              if (isCorrect) correct++;
              card.querySelectorAll('.ait-quiz-opt').forEach(function (b) {
                b.disabled = true;
                var txt = q.options[parseInt(b.dataset.i)];
                var bIsAns = String(txt).trim().toLowerCase() === String(q.answer).trim().toLowerCase() || String(txt).trim().charAt(0).toUpperCase() === String(q.answer).trim().charAt(0).toUpperCase() && /^[A-Da-d]\)/.test(String(q.answer).trim());
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
          if (next) next.addEventListener('click', function () {
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
              details: JSON.stringify(quiz.questions.map(function (question, i) {
                return {
                  q: question.q,
                  answer: question.answer,
                  chosen: null
                };
              }))
            }
          }])[0].fail(function () {});
        }

        render();
        msgs.appendChild(card);
        msgs.scrollTop = msgs.scrollHeight;
      }

      var TOOL_PROMPTS = {
        quiz: {
          title: 'Practice Quiz',
          prompt: 'Create a 5-question multiple-choice practice quiz based on our conversation and the course materials. ' + 'Return ONLY a valid JSON object with this exact structure: ' + '{"quiz_title":"...","questions":[{"q":"question","options":["A) ...","B) ...","C) ...","D) ..."],"answer":"A) ...","explanation":"why"}]}. ' + 'Do not include any other text outside the JSON.'
        },
        guide: {
          title: 'Study Guide',
          prompt: 'Create a detailed study guide covering the key concepts from our conversation and the course materials. ' + 'Use clear sections with headings, bullet points, definitions, and 3 practice questions at the end.'
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
          if (MODE === 'course') {
            var tabBtn = document.querySelector('.umat-ov [data-sb-tab="flashcards"]') || document.querySelector('[data-sb-tab="flashcards"]');

            if (tabBtn) {
              tabBtn.click();
              toast('Opened Flashcards — generate cards from your materials.');
            }

            return;
          }

          var cid = currentCID();

          if (cid) {
            toast('Opening the course workspace to use Flashcards…');
            setTimeout(function () {
              window.location.href = WWW + '/course/view.php?id=' + cid;
            }, 900);
          } else {
            toast('Select a course first to use Flashcards.');
          }

          return;
        }

        var t = TOOL_PROMPTS[tool];
        if (!t) return;
        sendQ(t.prompt, {
          isTool: true,
          tool: tool
        });
      }

      var allSessions = [];

      function loadSessions() {
        var cid = MODE === 'course' ? FIXED_CID : 0;
        Ajax.call([{
          methodname: 'local_umat_ai_get_ai_sessions',
          args: {
            courseid: cid,
            limit: 50
          }
        }])[0].done(function (r) {
          allSessions = r.sessions || [];
          renderSessions();
        }).fail(function () {
          sessList.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">error_outline</span>Could not load sessions.</div>';
        });
      }

      function renderSessions() {
        var q = (document.getElementById('ait-sess-search').value || '').toLowerCase();
        var list = allSessions.filter(function (s) {
          if (q && ((s.course_name || '') + ' ' + (s.preview || '')).toLowerCase().indexOf(q) === -1) return false;

          if (MODE === 'hub' && q && s.courseid && parseInt(s.courseid) !== activeCID && activeCID) {
            return false;
          }

          if (MODE === 'hub' && activeCID && parseInt(s.courseid) !== activeCID) return false;
          return true;
        });

        if (!list.length) {
          sessList.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">history</span>No sessions yet. Start a conversation below!</div>';
          return;
        }

        sessList.innerHTML = list.map(function (s) {
          var active = s.session_key === sessKey ? ' active' : '';
          return '<div class="ait-sess-item' + active + '" data-sk="' + esc(s.session_key) + '" data-cid="' + (s.courseid || 0) + '" data-cn="' + esc(s.course_name || 'General') + '">' + '<div class="ait-sess-item-row">' + '<span class="ait-sess-item-badge">' + esc(s.course_short || 'GEN') + '</span>' + '<span class="ait-sess-item-title">' + esc(s.course_name || 'General') + '</span>' + '<button class="ait-sess-del" type="button" title="Delete session"><span class="material-symbols-outlined">delete</span></button>' + '</div>' + '<div class="ait-sess-item-preview">' + esc(s.preview || '') + '</div>' + '<div class="ait-sess-item-meta"><span class="material-symbols-outlined">chat</span>' + (s.msg_count || 0) + ' messages · ' + esc(s.time_label || '') + '</div>' + '</div>';
        }).join('');
        sessList.querySelectorAll('.ait-sess-item').forEach(function (item) {
          item.addEventListener('click', function (e) {
            if (e.target.closest('.ait-sess-del')) return;
            resumeSession(item.dataset.sk, parseInt(item.dataset.cid) || 0, item.dataset.cn || '');
          });
          var del = item.querySelector('.ait-sess-del');
          if (del) del.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!confirm('Delete this conversation? This cannot be undone.')) return;
            Ajax.call([{
              methodname: 'local_umat_ai_delete_session',
              args: {
                session_key: item.dataset.sk
              }
            }])[0].done(function () {
              loadSessions();
            }).fail(function () {
              toast('Could not delete session.');
            });
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

        S._umatRenderMatsBar('ait-mat-bar', 'ait-attach-btn', [], function () {
          return [];
        });

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
        Ajax.call([{
          methodname: 'local_umat_ai_get_chat_history',
          args: {
            courseid: cid || 1,
            session_key: sk,
            limit: 50
          }
        }])[0].done(function (r) {
          msgs.innerHTML = '';
          var arr = r.messages || [];

          if (!arr.length) {
            showWelcome();
            return;
          }

          arr.forEach(function (m) {
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
        }).fail(function () {
          showWelcome();
        });
      }

      function stripQuiz(text) {
        var m = text.match(/\{[\s\S]*"questions"[\s\S]*\}/);
        if (!m) return {
          text: text,
          quiz: null
        };

        try {
          var o = JSON.parse(m[0]);

          if (o.questions && o.questions.length) {
            return {
              text: text.replace(m[0], '').trim(),
              quiz: {
                title: o.quiz_title || 'Practice Quiz',
                questions: o.questions
              }
            };
          }
        } catch (e) {}

        return {
          text: text,
          quiz: null
        };
      }

      var notes = [];
      var currentNote = null;
      var editing = false;
      var notesList = document.getElementById('ait-notes-list');
      var noteEditor = document.getElementById('ait-note-editor');
      var noteReadonly = document.getElementById('ait-note-readonly');
      var noteEdit = document.getElementById('ait-note-edit');
      var noteTitle = document.getElementById('ait-note-title');

      function loadNotes() {
        var cid = currentCID();
        Ajax.call([{
          methodname: 'local_umat_ai_get_notes',
          args: {
            courseid: cid
          }
        }])[0].done(function (r) {
          notes = r.notes || [];
          renderNotes();
        }).fail(function () {
          if (notesList) notesList.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">error_outline</span>Could not load notes.</div>';
        });
      }

      function noteSnippet(content) {
        var t = String(content || '');
        t = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        return t.length > 90 ? t.substr(0, 90) + '…' : t;
      }

      function renderNotes() {
        var q = (document.getElementById('ait-note-search').value || '').toLowerCase();
        var list = notes.filter(function (n) {
          return !q || ((n.title || '') + ' ' + noteSnippet(n.content)).toLowerCase().indexOf(q) !== -1;
        });
        list.sort(function (a, b) {
          return (b.pinned ? 1 : 0) - (a.pinned ? 1 : 0);
        });

        if (!list.length) {
          notesList.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">note_add</span>No notes yet. Create one or save a study guide!</div>';
          return;
        }

        notesList.innerHTML = list.map(function (n) {
          return '<div class="ait-note-card' + (n.pinned ? ' pinned' : '') + '" data-id="' + n.id + '">' + '<div class="ait-note-card-hdr">' + (n.pinned ? '<span class="material-symbols-outlined">push_pin</span>' : '') + '<strong>' + esc(n.title || 'Untitled') + '</strong></div>' + '<div class="ait-note-card-prev">' + esc(noteSnippet(n.content)) + '</div>' + '<div class="ait-note-card-time"><span class="material-symbols-outlined">schedule</span>' + esc(S._umatTimeAgo ? S._umatTimeAgo(n.timemodified) : '') + '</div>' + '</div>';
        }).join('');
        notesList.querySelectorAll('.ait-note-card').forEach(function (c) {
          c.addEventListener('click', function () {
            var n = null;
            notes.forEach(function (x) {
              if (x.id === parseInt(c.dataset.id)) n = x;
            });
            if (n) openNoteEditor(n, false);
          });
        });
      }

      function openNoteEditor(note, startEditing) {
        currentNote = note || {
          id: 0,
          title: '',
          content: '',
          pinned: 0
        };
        editing = !!startEditing;

        if (currentNote.content && currentNote.content.indexOf('<') !== -1) {
          var tmp = document.createElement('div');
          tmp.innerHTML = currentNote.content;
          tmp.querySelectorAll('script,style,iframe').forEach(function (el) {
            el.remove();
          });
          noteReadonly.innerHTML = tmp.innerHTML;
        } else {
          noteReadonly.innerHTML = S._umatFormatAI(currentNote.content || '<em>Empty note</em>');
        }

        noteTitle.value = currentNote.title || '';
        noteEdit.value = currentNote.content || '';
        var pinBtn = document.getElementById('ait-note-pin');
        if (pinBtn) pinBtn.classList.toggle('ait-ib-on', !!currentNote.pinned);
        noteReadonly.style.display = editing ? 'none' : '';
        noteEdit.style.display = editing ? '' : 'none';
        document.getElementById('ait-note-edit-toggle').textContent = editing ? 'Cancel Edit' : 'Edit';
        notesList.style.display = 'none';
        noteEditor.style.display = 'flex';
      }

      function closeNoteEditor() {
        noteEditor.style.display = 'none';
        notesList.style.display = '';
        currentNote = null;
        editing = false;
      }

      function saveNote() {
        var title = noteTitle.value.trim() || 'Untitled note';
        var content = editing ? noteEdit.value : currentNote.content;
        var cid = currentCID();
        var payload = {
          noteid: currentNote.id || 0,
          courseid: cid,
          title: title,
          content: content,
          pinned: currentNote.pinned ? 1 : 0,
          tags: []
        };
        Ajax.call([{
          methodname: 'local_umat_ai_save_note',
          args: payload
        }])[0].done(function (r) {
          toast(r.saved ? 'Note saved.' : 'Could not save note.');
          closeNoteEditor();
          loadNotes();
        }).fail(function () {
          toast('Failed to save note.');
        });
      }

      function deleteNote() {
        if (!currentNote || !currentNote.id) {
          closeNoteEditor();
          return;
        }

        if (!confirm('Delete this note?')) return;
        Ajax.call([{
          methodname: 'local_umat_ai_delete_note',
          args: {
            noteid: currentNote.id
          }
        }])[0].done(function () {
          toast('Note deleted.');
          closeNoteEditor();
          loadNotes();
        }).fail(function () {
          toast('Failed to delete note.');
        });
      }

      function loadReports() {
        var pane = document.getElementById('ait-pane-reports');
        var cid = currentCID();
        pane.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">hourglass_empty</span>Loading reports…</div>';
        var stats = {
          sessions: 0,
          cards: 0,
          due: 0,
          attempts: 0,
          best: null
        };

        function render() {
          var attemptsHtml = '';

          if (stats.best && stats.best.length) {
            attemptsHtml = '<div class="ait-rep-sec-title">Recent Quiz Attempts</div>' + stats.best.slice(0, 8).map(function (a) {
              return '<div class="ait-rep-attempt"><div class="ait-rep-attempt-hdr"><strong>' + esc(a.quiztitle || 'Quiz') + '</strong><span>' + (a.score || 0) + '%</span></div>' + '<div class="ait-rep-bar"><i style="width:' + Math.min(100, a.score || 0) + '%"></i></div>' + '<div class="ait-rep-meta">' + (a.correct || 0) + ' / ' + (a.total || 0) + ' correct · ' + esc(S._umatTimeAgo ? S._umatTimeAgo(a.timemodified) : '') + '</div></div>';
            }).join('');
          } else {
            attemptsHtml = '<div class="ait-empty" style="padding:14px;"><span class="material-symbols-outlined">quiz</span>No quiz attempts yet — try the "Create Quiz" tool!</div>';
          }

          pane.innerHTML = '<div class="ait-rep-cards">' + '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.sessions + '</div><div class="ait-rep-lbl">Sessions</div></div>' + '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.cards + '</div><div class="ait-rep-lbl">Flashcards</div></div>' + '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.due + '</div><div class="ait-rep-lbl">Due for review</div></div>' + '<div class="ait-rep-card"><div class="ait-rep-num">' + stats.attempts + '</div><div class="ait-rep-lbl">Quiz attempts</div></div>' + '</div>' + attemptsHtml;
        }

        Ajax.call([{
          methodname: 'local_umat_ai_get_ai_sessions',
          args: {
            courseid: cid,
            limit: 100
          }
        }])[0].done(function (r) {
          stats.sessions = (r.sessions || []).length;
          render();
        }).fail(function () {
          render();
        });
        Ajax.call([{
          methodname: 'local_umat_ai_get_flashcards',
          args: {
            courseid: cid
          }
        }])[0].done(function (r) {
          var cards = r.cards || [];
          stats.cards = cards.length;
          stats.due = cards.filter(function (c) {
            return c.due_for_review || c.status === 0;
          }).length;
          render();
        }).fail(function () {
          render();
        });
        Ajax.call([{
          methodname: 'local_umat_ai_get_quiz_attempts',
          args: {
            courseid: cid,
            status: ''
          }
        }])[0].done(function (r) {
          stats.attempts = (r.attempts || []).length;
          stats.best = r.attempts || [];
          render();
        }).fail(function () {
          render();
        });
      }

      function loadFiles() {
        var pane = document.getElementById('ait-pane-files');
        var cid = currentCID();

        if (!cid) {
          pane.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">folder_open</span>' + (MODE === 'hub' ? 'Select a course above to browse its materials.' : 'No course selected.') + '</div>';
          return;
        }

        pane.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">hourglass_empty</span>Loading files…</div>';
        Ajax.call([{
          methodname: 'local_umat_ai_get_course_materials',
          args: {
            courseid: cid
          }
        }])[0].done(function (r) {
          var mats = r.materials || [];

          if (!mats.length) {
            pane.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">folder_open</span>No materials uploaded for this course yet.</div>';
            return;
          }

          pane.innerHTML = mats.map(function (m) {
            var mime = m.mimetype || '';
            var icon = S._umatFileTypeIcon ? S._umatFileTypeIcon(mime) : 'description';
            return '<div class="ait-file-item" data-url="' + esc(m.url || '') + '" data-name="' + esc(m.filename || m.name || 'Material') + '" data-mime="' + esc(mime) + '">' + '<span class="material-symbols-outlined">' + esc(icon) + '</span>' + '<div style="min-width:0;flex:1;"><div class="ait-file-item-name">' + esc(m.filename || m.name || 'Material') + '</div>' + '<div class="ait-file-item-meta">' + esc(m.type || 'Course material') + '</div></div>' + '<span class="material-symbols-outlined" style="font-size:16px;color:var(--u-ol);">open_in_new</span></div>';
          }).join('');
          pane.querySelectorAll('.ait-file-item').forEach(function (f) {
            f.addEventListener('click', function () {
              var url = f.dataset.url,
                  name = f.dataset.name,
                  mime = f.dataset.mime;
              if (!url) return;

              if (window.umatMaterialViewer) {
                var kind = mime.indexOf('video') !== -1 ? 'video' : mime.indexOf('image') !== -1 ? 'image' : 'pdf';
                window.umatMaterialViewer.open(kind, {
                  url: url,
                  name: name,
                  downloadUrl: url
                });
              } else {
                window.open(url, '_blank');
              }
            });
          });
        }).fail(function () {
          pane.innerHTML = '<div class="ait-empty"><span class="material-symbols-outlined">error_outline</span>Could not load materials.</div>';
        });
      }

      var chatControl = bindChat(input, sendBtn, msgs, function (q) {
        sendQ(q);
      });
      msgs.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-q]');
        if (chip) sendQ(chip.dataset.q);
      });

      (function () {
        var sb = document.getElementById('ait-scroll-bottom');
        if (!sb) return;
        var timer = null;
        msgs.addEventListener('scroll', function () {
          if (timer) clearTimeout(timer);
          timer = setTimeout(function () {
            var near = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 100;
            sb.classList.toggle('visible', !near);
          }, 80);
        });
        sb.addEventListener('click', function () {
          msgs.scrollTo({
            top: msgs.scrollHeight,
            behavior: 'smooth'
          });
        });
      })();

      var drawerCtrl = S._umatInitAttachDrawer({
        getCourseId: function () {
          return currentCID();
        },
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
        onConfirm: function (mats) {
          selMat = mats;

          S._umatRenderMatsBar('ait-mat-bar', 'ait-attach-btn', selMat, function (id) {
            selMat = selMat.filter(function (s) {
              return s.id != id;
            });
            return selMat;
          });
        }
      });

      (function () {
        var micBtn = document.getElementById('ait-mic-btn');
        if (micBtn && input) new S.ChatVoiceInput({
          input: input,
          btn: micBtn,
          sesskey: moodleSesskey
        });
      })();

      S._umatInitEsc([{
        id: 'ait-attach-drawer',
        isOpen: function (e) {
          return e.classList.contains('open');
        },
        close: function (e) {
          e.classList.remove('open');
        }
      }]);

      var tglLeft = document.getElementById('ait-toggle-left');
      if (tglLeft) tglLeft.addEventListener('click', function () {
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

        if (reopenStudio) reopenStudio.classList.toggle('show', !open);
      }

      if (tglStudio) tglStudio.addEventListener('click', function () {
        setStudio(root.classList.contains('studio-closed'));
      });
      if (studioCollapse) studioCollapse.addEventListener('click', function () {
        setStudio(false);
      });
      if (reopenStudio) reopenStudio.addEventListener('click', function () {
        setStudio(true);
      });
      var leftPref = lsGet(LS_LEFT);
      if (leftPref === '0') root.classList.add('left-closed');
      var studioPref = lsGet(LS_STUDIO);
      if (studioPref === '0') setStudio(false);else setStudio(true);

      function setMobileTab(tab) {
        root.classList.remove('mt-chat', 'mt-sessions', 'mt-studio');
        root.classList.add('mt-' + tab);

        if (tab === 'studio' && root.classList.contains('studio-closed')) {
          setStudio(true);
        }

        document.querySelectorAll('#ait-mtabs .ait-mtab').forEach(function (b) {
          b.classList.toggle('active', b.dataset.gmtab === tab);
        });
        lsSet(LS_TAB, tab);
      }

      document.querySelectorAll('#ait-mtabs .ait-mtab').forEach(function (b) {
        b.addEventListener('click', function () {
          setMobileTab(b.dataset.gmtab);
        });
      });

      if (window.innerWidth <= 760) {
        setMobileTab(lsGet(LS_TAB) || 'chat');
      }

      document.querySelectorAll('.ait-tabs .ait-tab').forEach(function (b) {
        b.addEventListener('click', function () {
          document.querySelectorAll('.ait-tabs .ait-tab').forEach(function (x) {
            x.classList.remove('active');
          });
          b.classList.add('active');
          document.querySelectorAll('.ait-pane').forEach(function (p) {
            p.classList.remove('active');
          });
          var pane = document.getElementById('ait-pane-' + b.dataset.gtab);
          if (pane) pane.classList.add('active');
          if (b.dataset.gtab === 'reports') loadReports();
          if (b.dataset.gtab === 'files') loadFiles();
          if (b.dataset.gtab === 'notes') loadNotes();
        });
      });

      if (courseSel && MODE === 'hub') {
        courseSel.addEventListener('change', function () {
          var v = parseInt(this.value) || 0;

          if (v) {
            activeCID = v;
            lsSet(LS_CID, String(v));
          }

          loadSessions();
        });
      }

      document.getElementById('ait-tool-quiz').addEventListener('click', function () {
        runTool('quiz');
      });
      document.getElementById('ait-tool-fc').addEventListener('click', function () {
        runTool('flashcards');
      });
      document.getElementById('ait-tool-guide').addEventListener('click', function () {
        runTool('guide');
      });
      document.getElementById('ait-tool-summary').addEventListener('click', function () {
        runTool('summary');
      });
      document.getElementById('ait-tool-faq').addEventListener('click', function () {
        runTool('faq');
      });
      document.getElementById('ait-new-sess').addEventListener('click', newSession);
      var sessSearch = document.getElementById('ait-sess-search');
      if (sessSearch) sessSearch.addEventListener('input', function () {
        renderSessions();
      });
      document.getElementById('ait-note-new').addEventListener('click', function () {
        openNoteEditor(null, true);
      });
      document.getElementById('ait-note-back').addEventListener('click', closeNoteEditor);
      document.getElementById('ait-note-edit-toggle').addEventListener('click', function () {
        editing = !editing;
        noteReadonly.style.display = editing ? 'none' : '';
        noteEdit.style.display = editing ? '' : 'none';
        this.textContent = editing ? 'Cancel Edit' : 'Edit';

        if (editing) {
          noteEdit.value = currentNote ? currentNote.content || '' : noteEdit.value;
          noteEdit.focus();
        }
      });
      document.getElementById('ait-note-save').addEventListener('click', saveNote);
      document.getElementById('ait-note-delete').addEventListener('click', deleteNote);
      document.getElementById('ait-note-pin').addEventListener('click', function () {
        if (!currentNote) return;
        currentNote.pinned = currentNote.pinned ? 0 : 1;
        this.classList.toggle('ait-ib-on', !!currentNote.pinned);
        saveNote();
      });
      var noteSearch = document.getElementById('ait-note-search');
      if (noteSearch) noteSearch.addEventListener('input', renderNotes);
      loadSessions();
      loadNotes();
      var savedSid = lsGet(LS_SID);

      if (savedSid) {
        resumeSession(savedSid, activeCID, FIXED_CNAME);
      } else {
        showWelcome();
      }
    }
  };
});
//# sourceMappingURL=ai_tutor.js.map
