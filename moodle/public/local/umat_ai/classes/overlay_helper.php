<?php

namespace local_umat_ai;

class overlay_helper {

    public static function sidebar_html(array $tabs, string $newBtnLabel, string $closeId): string {
        global $CFG;
        $wwwroot = rtrim($CFG->wwwroot, '/');
        $logUrl  = $wwwroot . '/login/logout.php';
        $tabHtml = '';
        foreach ($tabs as $t) {
            $active = !empty($t['active']) ? ' active' : '';
            $badge = !empty($t['badge']) ? ' <span class="umat-sb-badge" id="sb-badge-' . htmlspecialchars($t['badge'], ENT_QUOTES) . '" style="display:none;margin-left:auto;background:var(--u-ter);color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:999px;line-height:14px;min-width:16px;text-align:center;"></span>' : '';
            $tabHtml .= '<button class="umat-sb-item' . $active . '" data-sb-tab="'
                . htmlspecialchars($t['id'], ENT_QUOTES) . '" type="button">'
                . '<span class="material-symbols-outlined">' . htmlspecialchars($t['icon'], ENT_QUOTES) . '</span>'
                . '<span class="umat-sb-item-lbl">' . htmlspecialchars($t['label'], ENT_QUOTES) . '</span>'
                . $badge . '</button>';
        }
        $safeLabel = htmlspecialchars($newBtnLabel, ENT_QUOTES);
        return <<<HTML
<div class="umat-sb">
    <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">school</span></div>
        <div class="umat-sb-brand"><strong>UMaT Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="{$closeId}" type="button" title="Close">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>
    <nav class="umat-sb-nav">{$tabHtml}</nav>
    <div class="umat-sb-divider"></div>
    <button class="umat-sb-new" id="sb-new-btn" type="button">
        <span class="material-symbols-outlined">add</span>
        <span class="umat-sb-new-lbl">{$safeLabel}</span>
    </button>
    <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'">
            <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
    </div>
</div>
HTML;
    }

    public static function shared_js(string $overlayId, string $closeId): string {
        return <<<JS
<script>
/* AMD modules handle umatshared loading directly */
(function(){var cb=document.getElementById('{$closeId}'),ov=document.getElementById('{$overlayId}');if(cb&&ov)cb.addEventListener('click',function(){ov.classList.remove('open');});})();
/* Mobile nav: slide-to-hide + indicator pill */
document.querySelectorAll('.umat-glass-tabs').forEach(function(nav){var pill=document.createElement('div');pill.className='umat-glass-pill';nav.appendChild(pill);function mv(){var a=nav.querySelector('.umat-glass-tab.active');if(!a)return;var nr=nav.getBoundingClientRect(),tr=a.getBoundingClientRect();pill.style.left=(tr.left-nr.left)+'px';pill.style.width=tr.width+'px';}mv();nav.addEventListener('click',function(e){if(e.target.closest('.umat-glass-tab'))setTimeout(mv,30);});var ly=0,ti=false;function os(){if(ti)return;ti=true;requestAnimationFrame(function(){var y=window.scrollY;if(y-ly>40)nav.classList.add('umat-navbar-hidden');else if(ly-y>10)nav.classList.remove('umat-navbar-hidden');ly=y;ti=false;});}window.addEventListener('scroll',os,{passive:true});window.addEventListener('resize',mv);});
/* Thumbnail loader */
window.loadYtThumbnails=window.loadYtThumbnails||function(g){if(!g)return;g.querySelectorAll('.yt-tile[data-url]').forEach(function(tile){var th=tile.querySelector('.yt-thumb');if(!th||th._td)return;th._td=1;var url=tile.dataset.url||'',mime=(tile.dataset.mime||'').toLowerCase();if(!url)return;if(mime.includes('image')){var img=document.createElement('img');img.className='yt-thumb-img';img.loading='lazy';img.src=url;th.appendChild(img);}else if(mime.includes('video')){var v=document.createElement('video');v.src=url;v.preload='metadata';v.muted=true;v.style.cssText='position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:12px;';v.addEventListener('loadedmetadata',function(){v.currentTime=Math.min(2,v.duration*0.1);});v.addEventListener('seeked',function(){th.appendChild(v);});v.load();}else if(mime.includes('pdf')){var lo=document.createElement('div');lo.className='yt-thumb-loading';th.appendChild(lo);(function(){var s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';s.onload=function(){window.pdfjsLib&&(window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',pdfjsLib.getDocument(url).promise.then(function(p){return p.getPage(1);}).then(function(pg){var vp=pg.getViewport({scale:1}),sc=Math.min(th.offsetWidth/vp.width,th.offsetHeight/vp.height)||1,vp2=pg.getViewport({scale:sc}),c=document.createElement('canvas');c.className='yt-thumb-canvas';c.width=vp2.width;c.height=vp2.height;lo.remove();th.appendChild(c);pg.render({canvasContext:c.getContext('2d'),viewport:vp2});}).catch(function(){lo.remove();}));};document.head.appendChild(s);})();}else if(mime.includes('word')||mime.includes('document')||mime.includes('presentation')||mime.includes('powerpoint')||mime.includes('spreadsheet')||mime.includes('excel')){var dv=document.createElement('div');dv.className='yt-thumb-doc-preview';for(var i=0;i<6;i++){var dl=document.createElement('div');dl.className='yt-thumb-doc-line';dv.appendChild(dl);}th.appendChild(dv);}});};
new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes.forEach(function(n){if(n.nodeType!==1)return;if(n.classList&&n.classList.contains('yt-grid'))window.loadYtThumbnails(n);var gs=n.querySelectorAll&&n.querySelectorAll('.yt-grid');if(gs&&gs.length)gs.forEach(function(g){window.loadYtThumbnails(g);});});});}).observe(document.body,{childList:true,subtree:true});
/* AJAX cache (5-min TTL for analytics/struggle) */
if(typeof ajax==='function'&&!window._ajaxCached){window._ajaxCached=1;var _ac={},_at={};window._origAjax=ajax;window.ajax=function(m,a,d,f){if(m.includes('analytics')||m.includes('struggle')){var k=m+':'+JSON.stringify(a),n=Date.now();if(_ac[k]&&n-_at[k]<300000){setTimeout(function(){d(_ac[k]);},0);return;}_origAjax(m,a,function(r){_ac[k]=r;_at[k]=Date.now();d(r);},f);}else _origAjax(m,a,d,f);};}
/* Draggable FABs — separate touch/mouse handlers; drag starts after 6px threshold */
(function(){var D=null,T=6;function C(){if(!D)return;D.el.style.transition='';D.el.classList.remove('umat-fab-dragging');if(D.a){var k='umat_fab_pos_'+D.el.id;if(D.el.style.left)localStorage.setItem(k,D.el.style.left+';'+D.el.style.top);D.el.dataset.umatFabDrag='1';}D=null;}
document.querySelectorAll('.umat-fab').forEach(function(f){var k='umat_fab_pos_'+f.id,s=localStorage.getItem(k);if(s){var p=s.split(';');if(p.length===2){f.style.left=p[0];f.style.top=p[1];f.style.bottom='auto';f.style.right='auto';}}});
document.addEventListener('mousedown',function(e){if(e.button!==0)return;var f=e.target.closest('.umat-fab');if(!f)return;var r=f.getBoundingClientRect();D={el:f,ox:e.clientX-r.left,oy:e.clientY-r.top,sx:e.clientX,sy:e.clientY,a:false};delete f.dataset.umatFabDrag;},true);
document.addEventListener('mousemove',function(e){if(!D)return;var dx=Math.abs(e.clientX-D.sx),dy=Math.abs(e.clientY-D.sy);if(!D.a&&(dx>T||dy>T)){D.a=true;D.el.style.transition='none';D.el.classList.add('umat-fab-dragging');}if(!D.a)return;D.el.style.left=Math.max(0,Math.min(window.innerWidth-D.el.offsetWidth,e.clientX-D.ox))+'px';D.el.style.top=Math.max(0,Math.min(window.innerHeight-D.el.offsetHeight,e.clientY-D.oy))+'px';D.el.style.bottom='auto';D.el.style.right='auto';});
document.addEventListener('mouseup',C);
document.addEventListener('touchstart',function(e){var f=e.target.closest('.umat-fab');if(!f)return;var t=e.changedTouches[0],r=f.getBoundingClientRect();D={el:f,ox:t.clientX-r.left,oy:t.clientY-r.top,sx:t.clientX,sy:t.clientY,a:false};delete f.dataset.umatFabDrag;},{passive:true});
document.addEventListener('touchmove',function(e){if(!D)return;var t=e.changedTouches[0],dx=Math.abs(t.clientX-D.sx),dy=Math.abs(t.clientY-D.sy);if(!D.a&&(dx>T||dy>T)){D.a=true;D.el.style.transition='none';D.el.classList.add('umat-fab-dragging');}if(!D.a)return;e.preventDefault();D.el.style.left=Math.max(0,Math.min(window.innerWidth-D.el.offsetWidth,t.clientX-D.ox))+'px';D.el.style.top=Math.max(0,Math.min(window.innerHeight-D.el.offsetHeight,t.clientY-D.oy))+'px';D.el.style.bottom='auto';D.el.style.right='auto';},{passive:false});
document.addEventListener('touchend',C);document.addEventListener('touchcancel',C);
document.addEventListener('click',function(e){var f=e.target.closest('.umat-fab');if(!f||f.dataset.umatFabDrag!=='1')return;delete f.dataset.umatFabDrag;e.stopPropagation();},true);})();
</script>
JS;
    }


    public static function student_overlay(int $courseid, string $courseName, string $wwwroot, object $user, string $userData): string {
        $safeName  = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCid     = (int)$courseid;
        $jsName    = json_encode($courseName);
        $userName  = fullname($user);
        $safeUser  = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $initials  = strtoupper(mb_substr($user->firstname, 0, 1) . mb_substr($user->lastname, 0, 1));
        $approveUrl = $wwwroot . '/local/umat_ai/approve.php?courseid=' . $courseid;
        $streamUrl = json_encode($wwwroot . '/local/umat_ai/chat_stream.php');
        $moodleSesskey = json_encode(sesskey());

        $tabs = [
            ['id' => 'home',      'icon' => 'home',          'label' => 'Home',      'active' => false],
            ['id' => 'ai-tutor',  'icon' => 'smart_toy',     'label' => 'AI Tutor',  'active' => true],
            ['id' => 'lectures',  'icon' => 'play_circle',   'label' => 'Lectures',  'active' => false],
            ['id' => 'courses',   'icon' => 'menu_book',     'label' => 'My Courses','active' => false],
            ['id' => 'library',   'icon' => 'local_library', 'label' => 'Library',   'active' => false],
            ['id' => 'my-notes',  'icon' => 'note_add',      'label' => 'My Notes',  'active' => false],
            ['id' => 'my-progress','icon' => 'trending_up',  'label' => 'My Progress','active' => false],
            ['id' => 'sessions',   'icon' => 'chat_bubble',   'label' => 'Sessions',   'active' => false],
            ['id' => 'group-study', 'icon' => 'group',       'label' => 'Study Group', 'active' => false],
            ['id' => 'report-issue', 'icon' => 'flag',       'label' => 'Report Issue','active' => false, 'badge' => 'responses'],
        ];
        $sidebar = self::sidebar_html($tabs, 'New Session', 'stu-ws-close');
        $sharedJs = self::shared_js('umat-student-ov', 'stu-ws-close');

        // Glassmorphism mobile tab bar (in-overlay)
        $stuGlassTabs = [
            ['id' => 'home',     'icon' => 'home',        'label' => 'Home',     'active' => true],
            ['id' => 'ai-tutor', 'icon' => 'smart_toy',   'label' => 'Tutor',    'active' => false],
            ['id' => 'lectures', 'icon' => 'play_circle', 'label' => 'Lectures', 'active' => false],
            ['id' => 'courses',  'icon' => 'menu_book',   'label' => 'Courses',  'active' => false],
            ['id' => 'library',  'icon' => 'local_library','label' => 'Library',  'active' => false],
            ['id' => 'my-notes', 'icon' => 'note_add',    'label' => 'Notes',    'active' => false],
            ['id' => 'my-progress','icon' => 'trending_up','label' => 'Progress','active' => false],
            ['id' => 'sessions',   'icon' => 'chat_bubble', 'label' => 'Sessions',  'active' => false],
            ['id' => 'group-study','icon' => 'group',       'label' => 'Group',     'active' => false],
            ['id' => 'report-issue', 'icon' => 'flag',     'label' => 'Report',    'active' => false, 'badge' => 'responses'],
        ];
        $stuMobTabs = self::glassmorph_tab_bar($stuGlassTabs, 'sb-tab', 'stu-glass-tabs');

        return <<<HTML

<!-- STUDENT FAB -->
<button class="umat-fab umat-fab-pulse" id="umat-stu-fab" type="button" aria-label="Open AI Assistant">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tip">UMaT AI Assistant</span>
</button>

<!-- COMPACT PANEL -->
<div class="umat-cp-ov" id="stu-cp-ov">
  <div class="umat-cp" id="stu-cp">
    <div class="umat-cp-hdr">
      <div class="umat-cp-hdr-row">
        <div class="umat-cp-av"><span class="material-symbols-outlined">smart_toy</span><span class="umat-cp-dot"></span></div>
        <div class="umat-cp-info">
          <h2>AI Tutor</h2>
          <div class="sub" id="stu-conn-status">● Checking…</div>
          <div class="ctx" title="{$safeName}">{$safeName}</div>
        </div>
        <button class="umat-cp-hbtn umat-cp-exp" id="stu-expand-btn" type="button">
          <span class="material-symbols-outlined">open_in_full</span><span>Expand</span>
        </button>
        <button class="umat-cp-hbtn" id="stu-cp-close" type="button"><span class="material-symbols-outlined">close</span></button>
      </div>
    </div>
    <div class="umat-cp-tabs umat-cp-tabs-legacy">
      <button class="umat-cp-tab active" data-cp-tab="cp-chat" type="button">Chat</button>
      <button class="umat-cp-tab" data-cp-tab="cp-notes" type="button">Notes</button>
      <button class="umat-cp-tab" data-cp-tab="cp-resources" type="button">Resources</button>
    </div>
    <div class="umat-cp-feature-tabs" aria-label="Quick student tools" role="tablist">
      <button class="umat-cp-feature-tab active" data-cp-pane="cp-chat" type="button"><span class="material-symbols-outlined">smart_toy</span><span>Tutor</span></button>
      <button class="umat-cp-feature-tab" data-cp-pane="cp-notes" type="button"><span class="material-symbols-outlined">note_add</span><span>Notes</span></button>
      <button class="umat-cp-feature-tab" data-cp-pane="cp-resources" type="button"><span class="material-symbols-outlined">folder_open</span><span>Files</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="home" type="button"><span class="material-symbols-outlined">home</span><span>Home</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="lectures" type="button"><span class="material-symbols-outlined">play_circle</span><span>Lectures</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="courses" type="button"><span class="material-symbols-outlined">menu_book</span><span>Courses</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="library" type="button"><span class="material-symbols-outlined">local_library</span><span>Library</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="my-progress" type="button"><span class="material-symbols-outlined">trending_up</span><span>Progress</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="sessions" type="button"><span class="material-symbols-outlined">chat_bubble</span><span>Sessions</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="group-study" type="button"><span class="material-symbols-outlined">group</span><span>Group</span></button>
      <button class="umat-cp-feature-tab" data-cp-open="report-issue" type="button"><span class="material-symbols-outlined">flag</span><span>Report</span></button>
    </div>
    <div class="umat-cp-pane active" id="cp-chat">
      <div class="umat-msgs" id="cp-msgs">
        <div class="umat-msg-ai">
          <div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
          <div class="umat-msg-ai-wrap">
            <div class="umat-msg-lbl">AI TUTOR</div>
            <div class="umat-bubble-ai"><p>Hello <strong>{$safeUser}</strong>! I'm your AI tutor for <strong>{$safeName}</strong>. Expand for the full workspace, or ask me anything here. ✨</p></div>
            <div class="umat-chips-row">
              <button class="umat-chip" data-q="Summarize today's lecture key points." type="button">Summarize lecture</button>
              <button class="umat-chip" data-q="What are the current assignment requirements?" type="button">Assignment help</button>
              <button class="umat-chip" data-q="What are my upcoming deadlines?" type="button">Deadlines</button>
            </div>
          </div>
        </div>
      </div>
      <!-- QUIZ PANE (compact panel) -->
      <div class="umat-quiz-pane" id="cp-quiz-pane" style="display:none;">
        <div class="umat-quiz-topbar">
          <button class="umat-quiz-back" id="cp-quiz-back" type="button" title="Back to chat"><span class="material-symbols-outlined">arrow_back</span></button>
          <div class="umat-quiz-title" id="cp-quiz-title">Quiz</div>
          <span id="cp-quiz-total" style="display:none;"></span>
          <div class="umat-quiz-circle" id="cp-quiz-circle"></div>
        </div>
        <div class="umat-quiz-body" id="cp-quiz-body"></div>
        <div class="umat-quiz-score" id="cp-quiz-score" style="display:none;">
          <div class="umat-quiz-score-ic"><span class="material-symbols-outlined" id="cp-quiz-score-icon">emoji_events</span></div>
          <div class="umat-quiz-score-num" id="cp-quiz-score-num">0</div>
          <div class="umat-quiz-score-lbl" id="cp-quiz-score-lbl">correct</div>
          <div class="umat-quiz-score-sub" id="cp-quiz-score-sub"></div>
          <div class="umat-quiz-score-bar"><div class="umat-quiz-score-fill" id="cp-quiz-score-fill"></div></div>
          <button class="umat-quiz-retry" id="cp-quiz-retry" type="button"><span class="material-symbols-outlined">refresh</span>Try Again</button>
          <button class="umat-quiz-close" id="cp-quiz-close-pane" type="button"><span class="material-symbols-outlined">chat</span>Back to Chat</button>
        </div>
      </div>
      <div class="umat-input-area">
        <div class="umat-chatbar">
          <textarea id="cp-input" class="umat-chatbar-input" placeholder="Ask anything…" rows="1" maxlength="900"></textarea>
          <button class="umat-chatbar-btn" id="cp-mic" type="button" title="Voice input"><span class="material-symbols-outlined">mic</span></button>
          <button class="umat-chatbar-send" id="cp-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
        </div>
      </div>
    </div>
    <div class="umat-cp-pane" id="cp-notes">
      <div class="umat-cp-notes-header">
        <div class="umat-cp-notes-tabs">
          <button class="umat-cp-notes-tab active" data-cp-nt="cp-nt-mine" type="button">My Notes</button>
          <button class="umat-cp-notes-tab" data-cp-nt="cp-nt-ai" type="button">AI Notes</button>
        </div>
        <button class="umat-cp-notes-add" id="cp-notes-add-btn" type="button" title="New note">
          <span class="material-symbols-outlined">add</span>
        </button>
      </div>
      <div class="umat-cp-notes-tab-pane active" id="cp-nt-mine">
        <div class="umat-empty"><span class="material-symbols-outlined">note_add</span><p>No notes yet. Tap + to create your first note!</p></div>
      </div>
      <div class="umat-cp-notes-tab-pane" id="cp-nt-ai">
        <div class="umat-empty"><span class="material-symbols-outlined">description</span><p>AI-generated notes appear here once your lecturer approves them.</p></div>
      </div>
    </div>
    <div class="umat-cp-pane" id="cp-resources">
      <div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>Indexed course materials will appear here.</p></div>
    </div>
    <div class="umat-cp-pane" id="cp-feature">
      <div class="umat-cp-feature-head"><span class="material-symbols-outlined" id="cp-feature-icon">home</span><div><strong id="cp-feature-title">Home</strong><small id="cp-feature-sub">Quick view</small></div></div>
      <div class="umat-cp-feature-body" id="cp-feature-body"></div>
    </div>
  </div>
</div>

<!-- STUDENT FULL WORKSPACE OVERLAY -->
<div class="umat-ov" id="umat-student-ov" role="dialog" aria-modal="true">
  {$sidebar}

  <div class="umat-ov-content">
    <button class="umat-ov-close-btn" type="button" onclick="document.getElementById('umat-student-ov').classList.remove('open')" title="Close">
      <span class="material-symbols-outlined">close</span>
    </button>

    <!-- HOME TAB -->
    <div class="umat-tab-pane active" data-tab="home">
      <div class="umat-content-hdr">
        <h2>Welcome back, {$safeUser}!</h2>
        <span class="pill" id="ws-goal-pill">Goal: 0%</span>
      </div>
      <div class="umat-home-wrap">
        <div class="umat-home-hero">
          <h1>Good to see you, {$safeUser}! 👋</h1>
          <p>Continue your AI-assisted learning journey for <strong>{$safeName}</strong>.</p>
          <div class="hero-sub">Your AI tutor is online and ready to help you master any concept.</div>
        </div>
        <div class="umat-metrics-row">
          <div class="umat-metric-card">
            <div class="umat-metric-icon mi-g"><span class="material-symbols-outlined">forum</span></div>
            <div><div class="umat-metric-val" id="ws-m-sessions">—</div><div class="umat-metric-lbl">Sessions this week</div></div>
          </div>
          <div class="umat-metric-card">
            <div class="umat-metric-icon mi-s"><span class="material-symbols-outlined">help</span></div>
            <div><div class="umat-metric-val" id="ws-m-questions">—</div><div class="umat-metric-lbl">Questions asked</div></div>
          </div>
          <div class="umat-metric-card">
            <div class="umat-metric-icon mi-w"><span class="material-symbols-outlined">bolt</span></div>
            <div><div class="umat-metric-val" id="ws-m-goal">—</div><div class="umat-metric-lbl">Weekly goal</div></div>
          </div>
        </div>
        <div class="umat-home-section">
          <div class="umat-goal-bar-wrap">
            <div class="umat-goal-bar-row"><span>Weekly Study Goal</span><strong id="ws-goal-pct">0%</strong></div>
            <div class="umat-goal-bar"><div class="umat-goal-fill" id="ws-goal-bar" style="width:0%"></div></div>
          </div>
        </div>
        <div class="umat-home-section">
          <h3>Quick Actions</h3>
          <div class="umat-quick-actions-grid">
            <button class="umat-qa-btn" data-sb-tab="ai-tutor" type="button">
              <span class="material-symbols-outlined">smart_toy</span>
              <div class="umat-qa-btn-text"><strong>Ask AI Tutor</strong><span>Start a new question</span></div>
            </button>
            <button class="umat-qa-btn" data-sb-tab="lectures" type="button">
              <span class="material-symbols-outlined">play_circle</span>
              <div class="umat-qa-btn-text"><strong>Watch Lectures</strong><span>Browse recordings</span></div>
            </button>
            <button class="umat-qa-btn" data-sb-tab="library" type="button">
              <span class="material-symbols-outlined">local_library</span>
              <div class="umat-qa-btn-text"><strong>Course Library</strong><span>Notes, PDFs &amp; more</span></div>
            </button>
            <button class="umat-qa-btn" data-sb-tab="sessions" type="button">
              <span class="material-symbols-outlined">chat_bubble</span>
              <div class="umat-qa-btn-text"><strong>Past Sessions</strong><span>Resume previous chats</span></div>
            </button>
          </div>
        </div>
        <div class="umat-home-section" id="ws-recent-session-wrap" style="display:none;">
          <h3>Continue where you left off</h3>
          <div id="ws-recent-session"></div>
        </div>
      </div>
    </div>

    <!-- AI TUTOR TAB -->
    <div class="umat-tab-pane" data-tab="ai-tutor" style="position:relative;">
      <div class="umat-content-hdr">
        <h2>AI Tutor</h2>
      </div>
      <div style="display:flex;flex:1;overflow:hidden;">
        <!-- Left: full-width chat -->
        <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;">
          <div style="display:flex;flex-wrap:wrap;gap:6px;padding:10px 14px;border-bottom:1px solid var(--u-olv);flex-shrink:0;" id="ws-chips">
            <button class="umat-chip" data-q="Explain the key concept discussed in the most recent lecture." type="button">Explain key concept</button>
            <button class="umat-chip" data-q="Can you compare this topic with what was covered earlier in the course?" type="button">Compare topics</button>
            <button class="umat-chip" data-q="Create a practice quiz on this week's material." type="button">Practice quiz</button>
            <button class="umat-chip" data-q="What are the most common exam questions for this topic?" type="button">Exam prep</button>
          </div>
          <div class="umat-msgs" id="ws-msgs">
            <div class="umat-msg-ai">
              <div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
              <div class="umat-msg-ai-wrap">
                <div class="umat-msg-lbl">AI TUTOR</div>
                <div class="umat-bubble-ai"><p>Welcome to your AI Tutor for <strong>{$safeName}</strong>! I can reference your selected course materials for precise answers. Use the attachment button to select specific materials, or ask me anything!</p></div>
              </div>
            </div>
          </div>
          <div class="umat-input-area" style="position:relative;">
            <div class="umat-attach-drawer umat-drawer-enhanced" id="ws-attach-drawer">
              <div class="umat-drawer-hdr">
                <div class="umat-drawer-hdr-left">
                  <span class="material-symbols-outlined" style="font-size:17px;color:var(--u-p);">attach_file</span>
                  <h4>Select Materials</h4>
                  <span class="umat-drawer-count" id="ws-drawer-count">0 selected</span>
                </div>
                <div class="umat-drawer-hdr-actions">
                  <button class="umat-drawer-clear-btn" id="ws-drawer-clear" type="button">Clear</button>
                  <button class="umat-drawer-close-btn" id="ws-drawer-close" type="button"><span class="material-symbols-outlined">close</span></button>
                </div>
              </div>
              <div class="umat-drawer-search-wrap">
                <span class="material-symbols-outlined umat-drawer-search-icon">search</span>
                <input type="text" id="ws-drawer-search" placeholder="Search materials…">
              </div>
              <div class="umat-drawer-cats" id="ws-drawer-cats"></div>
              <div class="umat-drawer-recent" id="ws-drawer-recent"></div>
              <div class="umat-drawer-list" id="ws-drawer-list"><div class="umat-drawer-loading"><div class="umat-vw-spinner"></div><span>Loading materials&hellip;</span></div></div>
              <div class="umat-drawer-foot">
                <span class="umat-drawer-foot-info">Select materials for AI</span>
                <button class="umat-drawer-confirm" id="ws-drawer-confirm" type="button"><span class="material-symbols-outlined">check</span> Use Selected</button>
              </div>
            </div>
            <div class="umat-chatbar">
              <button class="umat-chatbar-btn" id="ws-attach-btn" type="button"><span class="material-symbols-outlined">add</span></button>
              <textarea id="ws-input" class="umat-chatbar-input" placeholder="Ask AI about this course…" rows="1" maxlength="900"></textarea>
              <button class="umat-chatbar-btn" id="ws-mic-btn" type="button" title="Voice input"><span class="material-symbols-outlined">mic</span></button>
              <button class="umat-chatbar-send" id="ws-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
            </div>
            <div class="umat-mat-bar" id="ws-mat-bar"></div>
          </div>
        </div>
      </div>
      <!-- QUIZ PANE (overlays chat when active) -->
      <div class="umat-quiz-pane" id="ws-quiz-pane" style="display:none;">
        <div class="umat-quiz-topbar">
          <button class="umat-quiz-back" id="ws-quiz-back" type="button" title="Back to chat"><span class="material-symbols-outlined">arrow_back</span></button>
          <div class="umat-quiz-title" id="ws-quiz-title">Quiz</div>
          <span id="ws-quiz-total" style="display:none;"></span>
          <div class="umat-quiz-circle" id="ws-quiz-circle"></div>
        </div>
        <div class="umat-quiz-body" id="ws-quiz-body"></div>
        <div class="umat-quiz-score" id="ws-quiz-score" style="display:none;">
          <div class="umat-quiz-score-ic"><span class="material-symbols-outlined" id="ws-quiz-score-icon">emoji_events</span></div>
          <div class="umat-quiz-score-num" id="ws-quiz-score-num">0</div>
          <div class="umat-quiz-score-lbl" id="ws-quiz-score-lbl">correct</div>
          <div class="umat-quiz-score-sub" id="ws-quiz-score-sub"></div>
          <div class="umat-quiz-score-bar"><div class="umat-quiz-score-fill" id="ws-quiz-score-fill"></div></div>
          <button class="umat-quiz-retry" id="ws-quiz-retry" type="button"><span class="material-symbols-outlined">refresh</span>Try Again</button>
          <button class="umat-quiz-close" id="ws-quiz-close-pane" type="button"><span class="material-symbols-outlined">chat</span>Back to Chat</button>
        </div>
      </div>
    </div>

    <!-- LECTURES TAB -->
    <div class="umat-tab-pane" data-tab="lectures" style="position:relative;overflow:hidden;">
      <div class="umat-content-hdr">
        <h2>Lecture Recordings</h2>
        <button class="umat-content-hdr-btn" id="ws-lec-refresh" type="button">
          <span class="material-symbols-outlined">refresh</span>Refresh
        </button>
      </div>
      <div class="umat-video-grid" id="ws-video-grid">
        <div class="umat-empty"><span class="material-symbols-outlined">play_circle</span><p>Loading lecture recordings…</p></div>
      </div>
      <!-- Video player (slides over the grid — using shared material_viewer) -->
    </div>

    <!-- MY COURSES TAB -->
    <div class="umat-tab-pane" data-tab="courses">
      <div class="umat-content-hdr">
        <h2>My Courses</h2>
        <span class="pill" id="ws-courses-count">—</span>
      </div>
      <div class="umat-courses-grid" id="ws-courses-grid">
        <div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>Loading your courses…</p></div>
      </div>
    </div>

    <!-- LIBRARY TAB -->
    <div class="umat-tab-pane" data-tab="library" style="position:relative;overflow:hidden;">
      <div class="umat-content-hdr">
        <h2>Course Library</h2>
        <button class="umat-content-hdr-btn" id="ws-lib-refresh" type="button">
          <span class="material-symbols-outlined">refresh</span>Refresh
        </button>
      </div>
      <div class="umat-lib-grid" id="ws-lib-grid">
        <div class="umat-empty"><span class="material-symbols-outlined">local_library</span><p>Loading course materials…</p></div>
      </div>
      <!-- PDF Viewer (using shared material_viewer) -->
    </div>

    <!-- MY NOTES TAB -->
    <div class="umat-tab-pane" data-tab="my-notes">
    </div>

    <!-- SESSIONS TAB -->
    <div class="umat-tab-pane" data-tab="sessions">
      <div class="umat-content-hdr">
        <h2>AI Chat Sessions</h2>
        <button class="umat-sb-new" style="position:relative;margin:0;" id="ws-new-session-btn2" type="button">
          <span class="material-symbols-outlined">add</span>
          <span class="umat-sb-new-lbl">New Session</span>
        </button>
      </div>
      <div class="umat-sessions-list" id="ws-sessions-list">
        <div class="umat-empty"><span class="material-symbols-outlined">chat_bubble</span><p>Loading your AI chat sessions…</p></div>
      </div>
    </div>

    <!-- REPORT ISSUE TAB -->
    <div class="umat-tab-pane" data-tab="report-issue">
      <div class="umat-content-hdr">
        <h2><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:6px;">flag</span>Report Issue</h2>
        <button class="umat-sb-new" id="ws-issue-toggle" type="button">
          <span class="material-symbols-outlined">add</span>
          <span>New Report</span>
        </button>
      </div>
      <div class="umat-home-wrap">
        <!-- New Issue Form (hidden by default) -->
        <div class="umat-home-section" id="ws-issue-form-wrap" style="display:none;">
          <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;">
            <div style="margin-bottom:12px;">
              <label style="font-size:12px;font-weight:700;color:var(--u-ol);display:block;margin-bottom:4px;">Category</label>
              <select id="ws-issue-cat" style="width:100%;padding:8px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r8);background:var(--u-bg);font-size:13px;">
                <option value="concept_confusion">I don't understand a concept</option>
                <option value="material_error">Error in course material</option>
                <option value="technical_issue">Technical issue with the platform</option>
                <option value="suggestion">Suggestion for improvement</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div style="margin-bottom:12px;">
              <label style="font-size:12px;font-weight:700;color:var(--u-ol);display:block;margin-bottom:4px;">Topic (optional)</label>
              <input type="text" id="ws-issue-topic" placeholder="e.g. Transistors, Ohm's Law, Week 3 lecture" style="width:100%;padding:8px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r8);background:var(--u-bg);font-size:13px;">
            </div>
            <div style="margin-bottom:12px;">
              <label style="font-size:12px;font-weight:700;color:var(--u-ol);display:block;margin-bottom:4px;">Describe the issue</label>
              <textarea id="ws-issue-desc" placeholder="Explain what you don't understand or what the problem is…" rows="4" style="width:100%;padding:8px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r8);background:var(--u-bg);font-size:13px;resize:vertical;"></textarea>
            </div>
            <button class="umat-btn-p" id="ws-issue-submit" type="button" style="width:100%;justify-content:center;">
              <span class="material-symbols-outlined">send</span>Submit Report
            </button>
            <div id="ws-issue-msg" style="margin-top:8px;font-size:12px;display:none;"></div>
          </div>
        </div>

        <!-- My Reports list -->
        <div class="umat-home-section">
          <h3 style="display:flex;align-items:center;gap:6px;"><span class="material-symbols-outlined" style="font-size:18px;">history</span>My Reports</h3>
          <div id="ws-issue-list">
            <div class="umat-empty"><span class="material-symbols-outlined">flag</span><p>No issues reported yet.</p></div>
          </div>
        </div>
      </div>
    </div>

    <!-- STUDY GROUP TAB -->
    <div class="umat-tab-pane" data-tab="group-study">
      <div class="umat-content-hdr">
        <h2><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:6px;">group</span>Study Groups</h2>
      </div>
      <div class="umat-home-wrap" id="ws-group-wrap">
        <div class="umat-home-section">
          <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;margin-bottom:16px;">
            <div style="margin-bottom:10px;">
              <input type="text" id="ws-group-name" class="form-control form-control-sm" placeholder="Group name\u2026" maxlength="255" style="width:100%;padding:8px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r8);background:var(--u-bg);font-size:13px;margin-bottom:6px;box-sizing:border-box;">
              <textarea id="ws-group-desc" class="form-control form-control-sm" rows="2" placeholder="Description\u2026" maxlength="500" style="width:100%;padding:8px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r8);background:var(--u-bg);font-size:13px;margin-bottom:6px;resize:none;box-sizing:border-box;"></textarea>
              <div style="display:flex;gap:8px;">
                <input type="number" id="ws-group-max" class="form-control form-control-sm" value="5" min="2" max="20" style="width:70px;padding:6px 8px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:13px;">
                <button class="umat-btn-p" id="ws-group-create" type="button" style="flex:1;justify-content:center;"><span class="material-symbols-outlined" style="font-size:16px;">add</span>Create Group</button>
              </div>
            </div>
          </div>
          <h3 style="display:flex;align-items:center;gap:6px;margin-bottom:12px;"><span class="material-symbols-outlined" style="font-size:18px;">group</span>Available Groups</h3>
          <div id="ws-group-list"></div>
        </div>
        <div id="ws-group-chat" style="display:none;"></div>
      </div>
    </div>

    <!-- My Progress pane -->
    <div class="umat-tab-pane" data-tab="my-progress">
      <div class="umat-content-hdr">
        <h2><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:6px;">trending_up</span>My Progress</h2>
      </div>
      <div class="umat-home-wrap" id="prog-body">
        <div class="umat-empty" id="prog-loading"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading your progress&hellip;</p></div>
        <div id="prog-content" style="display:none;">
          <div class="prog-kpi-row" id="prog-kpis"></div>
          <div id="prog-ai-recs" style="display:none;"></div>
          <div class="umat-home-section">
            <h3><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">bar_chart</span>Weekly Activity</h3>
            <div id="prog-week-chart" class="prog-chart" style="margin-top:8px;"></div>
          </div>
          <div class="umat-home-section">
            <h3><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">psychology</span>Struggle Topics</h3>
            <div id="prog-struggle-list" class="prog-struggle-list" style="margin-top:8px;"></div>
          </div>
          <div class="umat-home-section">
            <h3><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">flag</span>Issue Reports</h3>
            <div id="prog-issues" style="margin-top:8px;"></div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /ov-content -->

  {$stuMobTabs}
</div><!-- /student workspace overlay -->

{$sharedJs}

<script>/* Student overlay JS moved to amd/src/umat_student.js */</script>
HTML;
    }

    public static function lecturer_overlay(int $courseid, string $courseName, int $pending, string $wwwroot, object $user, string $userData): string {
        $safe        = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCid       = (int)$courseid;
        $jsName      = json_encode($courseName);
        $jsUD        = $userData;
        $jsPending   = (int)$pending;
        $uid         = (int)$user->id;
        $uName       = json_encode(fullname($user));
        $uInit       = htmlspecialchars(strtoupper(mb_substr($user->firstname,0,1).mb_substr($user->lastname,0,1)), ENT_QUOTES);
        $logUrl      = $wwwroot . '/login/logout.php';
        $badgeHtml   = $pending > 0
            ? '<span class="umat-fab-badge">' . ($pending > 9 ? '9+' : $pending) . '</span>'
            : '';
        $pendingBannerHtml = $pending > 0
            ? '<div class="umat-pending-banner" id="lec-pending-banner"><span class="material-symbols-outlined">pending_actions</span><p>' . (int)$pending . ' AI output' . ($pending > 1 ? 's' : '') . ' awaiting your review. <button class="umat-chip" data-lp="lec-review" type="button" style="font-size:11px;padding:2px 9px;">Review now →</button></p></div>'
            : '';

        $sharedJs = self::shared_js('lec-ov', 'lec-ov-close');
        $streamUrl = json_encode($wwwroot . '/local/umat_ai/chat_stream.php');
        $moodleSesskey = json_encode(sesskey());

        // Glassmorphism mobile tab bar (in-overlay)
        $lecGlassTabs = [
            ['id' => 'lec-home',      'icon' => 'home',         'label' => 'Home',     'active' => true],
            ['id' => 'lec-analytics', 'icon' => 'bar_chart',     'label' => 'Analytics','active' => false],
            ['id' => 'lec-struggle',  'icon' => 'psychology',    'label' => 'Struggle', 'active' => false],
            ['id' => 'lec-courses',   'icon' => 'menu_book',     'label' => 'Courses',  'active' => false],
            ['id' => 'lec-library',   'icon' => 'local_library', 'label' => 'Library',  'active' => false],
            ['id' => 'lec-sessions',  'icon' => 'history',       'label' => 'Sessions', 'active' => false],
            ['id' => 'lec-review',    'icon' => 'fact_check',    'label' => 'Review',   'active' => false],
            ['id' => 'lec-issues',   'icon' => 'flag',          'label' => 'Issues',   'active' => false],
        ];
        $lecMobTabs = self::glassmorph_tab_bar($lecGlassTabs, 'lp', 'lec-glass-tabs');

        return <<<HTML
<!-- ============================================================
     LECTURER FAB + COMPACT PANEL + ANALYTICS OVERLAY
     ============================================================ -->

<!-- FAB -->
<button class="umat-fab umat-fab-pulse" id="lec-fab" type="button" aria-label="Open Analytics" style="position:relative;">
  <span class="material-symbols-outlined">leaderboard</span>
  <span class="umat-fab-tip">Lecturer Analytics</span>
  {$badgeHtml}
</button>

<!-- COMPACT INSIGHTS PANEL -->
<div class="umat-cp-ov" id="lec-cp-ov" role="dialog" aria-modal="true">
  <div class="umat-cp umat-cp-lec" id="lec-cp">
    <div class="umat-cp-hdr">
      <div class="umat-cp-hdr-row">
        <div class="umat-cp-av"><span class="material-symbols-outlined">analytics</span></div>
        <div class="umat-cp-info"><h2>Lecturer Analytics</h2><div class="ctx" title="{$safe}">{$safe}</div></div>
        <button class="umat-cp-hbtn umat-cp-exp" id="lec-expand" type="button">
          <span class="material-symbols-outlined">open_in_full</span><span>Dashboard</span>
        </button>
        <button class="umat-cp-hbtn" id="lec-cp-close" type="button" aria-label="Close">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>
    <div class="umat-cp-tabs umat-cp-tabs-legacy">
      <button class="umat-cp-tab active" data-lcp-tab="lcp-insights" type="button">Home</button>
      <button class="umat-cp-tab" data-lcp-tab="lcp-questions" type="button">Questions</button>
      <button class="umat-cp-tab" data-lcp-tab="lcp-ai" type="button">Ask AI</button>
    </div>
    <div class="umat-cp-feature-tabs" aria-label="Quick lecturer tools" role="tablist">
      <button class="umat-cp-feature-tab active" data-lcp-pane="lcp-insights" type="button"><span class="material-symbols-outlined">home</span><span>Home</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-questions" type="button"><span class="material-symbols-outlined">forum</span><span>Questions</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-ai" type="button"><span class="material-symbols-outlined">smart_toy</span><span>Ask AI</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-analytics" type="button"><span class="material-symbols-outlined">bar_chart</span><span>Analytics</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-struggle" type="button"><span class="material-symbols-outlined">psychology</span><span>Struggle</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span>Courses</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-library" type="button"><span class="material-symbols-outlined">local_library</span><span>Library</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-sessions" type="button"><span class="material-symbols-outlined">history</span><span>Sessions</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span><span>Review</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-issues" type="button"><span class="material-symbols-outlined">flag</span><span>Issues</span></button>
    </div>
    <div class="umat-cp-pane active" id="lcp-insights" style="overflow-y:auto;">
      <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:9px;" id="lcp-kpi-grid">
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">group</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Active Students</div>
          <div style="font-size:18px;font-weight:800;" id="lcp-k-active">—</div>
          <span style="font-size:9px;background:#dcfce7;color:#065f46;padding:2px 6px;border-radius:999px;font-weight:700;" id="lcp-k-active-b">Loading</span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(245,158,11,.1);color:#d97706;display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">forum</span></div>
          <div style="font-size:10px;color:var(--u-ol);">AI Interactions</div>
          <div style="font-size:18px;font-weight:800;" id="lcp-k-int">—</div>
          <span style="font-size:9px;background:var(--u-secc);color:var(--u-sec);padding:2px 6px;border-radius:999px;font-weight:700;">30 days</span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(165,48,77,.1);color:var(--u-ter);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">psychology_alt</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Struggle Index</div>
          <div style="font-size:14px;font-weight:800;" id="lcp-k-str">—</div>
          <span style="font-size:9px;background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:999px;font-weight:700;">High</span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(61,104,68,.1);color:var(--u-sec);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">pending_actions</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Pending Review</div>
          <div style="font-size:18px;font-weight:800;">{$pending}</div>
          <button type="button" data-lp="lec-review" style="font-size:9px;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:999px;font-weight:700;border:none;cursor:pointer;">Review →</button>
        </div>
      </div>
      <div style="padding:0 14px 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">AI Insights</div>
      <div style="padding:0 14px 14px;display:flex;flex-direction:column;gap:8px;">
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-left:3px solid var(--u-ter);border-radius:var(--u-r12);padding:12px;">
          <div style="font-size:12px;font-weight:700;display:flex;align-items:center;gap:6px;margin-bottom:4px;"><span class="material-symbols-outlined" style="font-size:16px;color:var(--u-ter);">warning</span><span id="lcp-gap-title">Analysing learning gaps…</span></div>
          <div style="font-size:11px;color:var(--u-onsv);margin-bottom:8px;" id="lcp-gap-desc">Scanning question patterns…</div>
          <button class="umat-chip" id="lcp-open-dash" type="button">Open Full Dashboard</button>
        </div>
      </div>
      <div style="padding:10px 14px;border-top:1px solid var(--u-olv);display:flex;flex-direction:column;gap:7px;">
        <button class="umat-btn-p" id="lcp-dash-btn" type="button" style="justify-content:center;"><span class="material-symbols-outlined">dashboard</span>Open Analytics Dashboard</button>
        <button class="umat-btn-o" style="justify-content:center;width:100%;" data-lp="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span>Review Outputs ({$pending})</button>
      </div>
    </div>
    <div class="umat-cp-pane" id="lcp-questions" style="overflow-y:auto;">
      <div style="padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">Top Student Questions</div>
      <div id="lcp-q-list" style="padding:0 14px 14px;display:flex;flex-direction:column;gap:6px;">
        <div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">Loading…</div>
      </div>
    </div>
    <div class="umat-cp-pane" id="lcp-ai" style="flex-direction:column;">
      <div class="umat-msgs" id="lcp-msgs">
        <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
          <div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div>
            <div class="umat-bubble-ai"><p>Ask me about your course analytics — e.g. <em>"Which topics are students struggling with?"</em></p></div>
            <div class="umat-chips-row">
              <button class="umat-chip" data-lp="Which topics are students struggling with the most?" type="button">Struggle areas</button>
              <button class="umat-chip" data-lp="Summarise student AI questions from this week." type="button">Weekly summary</button>
              <button class="umat-chip" data-lp="Which students appear at risk based on AI usage?" type="button">At-risk students</button>
            </div>
          </div>
        </div>
      </div>
      <div class="umat-input-area">
        <div class="umat-chatbar">
          <textarea id="lcp-input" class="umat-chatbar-input" placeholder="Ask about your course…" rows="1" maxlength="700"></textarea>
          <button class="umat-chatbar-send" id="lcp-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
        </div>
      </div>
    </div>
    <div class="umat-cp-pane" id="lcp-feature">
      <div class="umat-cp-feature-head"><span class="material-symbols-outlined" id="lcp-feature-icon">bar_chart</span><div><strong id="lcp-feature-title">Analytics</strong><small id="lcp-feature-sub">Quick view</small></div></div>
      <div class="umat-cp-feature-body" id="lcp-feature-body"></div>
    </div>
  </div>
</div>

<!-- FULL ANALYTICS OVERLAY -->
<div class="umat-ov" id="lec-ov" role="dialog" aria-modal="true" aria-label="Lecturer Analytics Dashboard">
  <div class="umat-ov-body" style="flex:1;overflow:hidden;display:flex;">

    <!-- SIDEBAR -->
    <div class="umat-sb" id="lec-sb">
      <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">school</span></div>
        <div class="umat-sb-brand"><strong>UMaT Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="lec-ov-close" type="button" title="Close Dashboard">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <nav class="umat-sb-nav">
        <button class="umat-sb-item active" data-lp="lec-home" type="button"><span class="material-symbols-outlined">home</span><span class="umat-sb-item-lbl">Home</span></button>
        <button class="umat-sb-item" data-lp="lec-analytics" type="button"><span class="material-symbols-outlined">bar_chart</span><span class="umat-sb-item-lbl">Analytics</span></button>
        <button class="umat-sb-item" data-lp="lec-struggle" type="button"><span class="material-symbols-outlined">psychology</span><span class="umat-sb-item-lbl">Struggle</span></button>
        <button class="umat-sb-item" data-lp="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span class="umat-sb-item-lbl">My Courses</span></button>
        <button class="umat-sb-item" data-lp="lec-library" type="button"><span class="material-symbols-outlined">local_library</span><span class="umat-sb-item-lbl">Library</span></button>
        <button class="umat-sb-item" data-lp="lec-sessions" type="button"><span class="material-symbols-outlined">history</span><span class="umat-sb-item-lbl">Sessions</span></button>
        <button class="umat-sb-item" data-lp="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span><span class="umat-sb-item-lbl">Review Outputs</span></button>
        <button class="umat-sb-item" data-lp="lec-issues" type="button"><span class="material-symbols-outlined">flag</span><span class="umat-sb-item-lbl">Student Issues</span><span class="umat-sb-badge" id="sb-badge-new-issues" style="display:none;margin-left:auto;background:var(--u-ter);color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:999px;line-height:14px;min-width:16px;text-align:center;"></span></button>
      </nav>
      <div class="umat-sb-divider"></div>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'">
          <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
      </div>
    </div>

    <!-- CONTENT -->
    <div class="umat-ov-content">
      <button class="umat-ov-close-btn" type="button" onclick="document.getElementById('lec-ov').classList.remove('open')" title="Close">
        <span class="material-symbols-outlined">close</span>
      </button>

      <!-- HOME -->
      <div class="umat-tab-pane active" id="lec-home">
        <div class="umat-home-wrap">
          <div class="umat-home-hero">
            <h1>Welcome, {$uInit}! 📊</h1>
             <p>Lecturer Analytics Hub — <strong>{$safe}</strong></p>
            <div class="hero-sub" id="lec-home-date"></div>
          </div>
          {$pendingBannerHtml}
          <div class="umat-metrics-row">
            <div class="umat-metric-card"><div class="umat-metric-icon mi-g"><span class="material-symbols-outlined">group</span></div><div><div class="umat-metric-val" id="lec-met-active">—</div><div class="umat-metric-lbl">Active students</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-w"><span class="material-symbols-outlined">forum</span></div><div><div class="umat-metric-val" id="lec-met-int">—</div><div class="umat-metric-lbl">AI interactions</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-r"><span class="material-symbols-outlined">pending_actions</span></div><div><div class="umat-metric-val">{$pending}</div><div class="umat-metric-lbl">Pending review</div></div></div>
          </div>
          <div class="umat-home-section" style="margin-top:20px;">
            <h3>Quick Actions</h3>
            <div class="umat-quick-actions-grid">
              <button class="umat-qa-btn" data-lp="lec-analytics" type="button"><span class="material-symbols-outlined">bar_chart</span><div class="umat-qa-btn-text"><strong>View Analytics</strong><span>Course performance data</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-struggle" type="button"><span class="material-symbols-outlined">psychology</span><div class="umat-qa-btn-text"><strong>Struggle Insights</strong><span>Topic &amp; student struggle data</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span><div class="umat-qa-btn-text"><strong>My Courses</strong><span>Switch course analytics</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-library" type="button"><span class="material-symbols-outlined">local_library</span><div class="umat-qa-btn-text"><strong>Library</strong><span>Materials &amp; recordings</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span><div class="umat-qa-btn-text"><strong>Review AI Outputs</strong><span>{$pending} pending</span></div></button>
            </div>
          </div>
        </div>
      </div>

      <!-- ANALYTICS -->
      <div class="umat-tab-pane" id="lec-analytics" style="overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">bar_chart</span> Analytics <span id="lec-an-course-label"></span></h2>
          <div style="display:flex;gap:6px;align-items:center;">
            <button class="umat-content-hdr-btn" id="lec-an-cs-btn" type="button" title="Select course"><span class="material-symbols-outlined">menu_book</span><span id="lec-an-cs-label">All Courses</span></button>
            <button class="umat-content-hdr-btn" id="lec-an-export" type="button" onclick="window.print()"><span class="material-symbols-outlined">download</span>Export</button>
          </div>
        </div>
        <!-- Course picker overlay -->
        <div class="umat-cs-overlay" id="lec-an-cs-ov">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">menu_book</span>Select a Course</h3>
              <button class="umat-cs-close" id="lec-an-cs-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="lec-an-cs-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="lec-an-cs-list"></div>
          </div>
        </div>
        <div class="umat-an-scroll" id="lec-an-body">
          <div id="lec-an-overview" style="display:none;">
            <!-- KPI Summary row -->
            <div class="ov-kpi-row" id="ov-an-kpis"></div>
            <!-- Two-column layout: Course comparison + Engagement donut -->
            <div class="ov-2col">
              <div class="ov-card">
                <div class="ov-card-hdr"><span class="material-symbols-outlined">bar_chart</span>Active Students by Course</div>
                <div class="ov-chart-bars" id="ov-an-bars"><div class="ov-loading">Loading…</div></div>
              </div>
              <div class="ov-card">
                <div class="ov-card-hdr"><span class="material-symbols-outlined">donut_small</span>Engagement Distribution</div>
                <div class="ov-donut-wrap" id="ov-an-donut"><div class="ov-loading">Loading…</div></div>
              </div>
            </div>
            <!-- Daily Activity Trend -->
            <div class="ov-card ov-full">
              <div class="ov-card-hdr"><span class="material-symbols-outlined">trending_up</span>Daily Activity Trend (All Courses)</div>
              <div class="ov-chart-canvas"><canvas id="ov-an-chart"></canvas><div class="ov-chart-labels" id="ov-an-chart-labels"></div></div>
            </div>
            <!-- Top Questions -->
            <div class="ov-card ov-full">
              <div class="ov-card-hdr"><span class="material-symbols-outlined">forum</span>Top Questions Across All Courses</div>
              <div id="ov-an-questions" class="ov-q-list"><div class="ov-loading">Loading…</div></div>
            </div>
          </div>
          <div id="lec-an-detail">
            <div class="umat-an-kpi-row">
              <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-g"><span class="material-symbols-outlined">group</span></div><span class="umat-an-kpi-pill pill-g" id="an-pill-active">active</span></div><div class="umat-an-kpi-lbl">Active Students</div><div class="umat-an-kpi-val" id="an-v-active">—</div><div class="umat-an-kpi-sub" id="an-s-active">of — enrolled</div></div>
              <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-s"><span class="material-symbols-outlined">timer</span></div><span class="umat-an-kpi-pill pill-b">avg Q/session</span></div><div class="umat-an-kpi-lbl">Avg Session Depth</div><div class="umat-an-kpi-val" id="an-v-time">—</div><div class="umat-an-kpi-sub">questions per session</div></div>
              <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-r"><span class="material-symbols-outlined">psychology_alt</span></div><span class="umat-an-kpi-pill pill-r">High</span></div><div class="umat-an-kpi-lbl">Struggle Index</div><div class="umat-an-kpi-val" style="font-size:18px;" id="an-v-str">—</div><div class="umat-an-kpi-sub">Most-questioned session</div></div>
              <div class="umat-an-kpi"><div class="umat-an-kpi-head"><div class="umat-an-kpi-ico ak-w"><span class="material-symbols-outlined">forum</span></div><span class="umat-an-kpi-pill pill-b" id="an-pill-int">new</span></div><div class="umat-an-kpi-lbl">AI Interactions</div><div class="umat-an-kpi-val" id="an-v-int">—</div><div class="umat-an-kpi-sub">last 30 days</div></div>
            </div>
            <div class="umat-an-2col">
              <div class="umat-an-card">
                <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">bar_chart</span>Student Engagement Trends</h3>
                  <div style="display:flex;align-items:center;gap:10px;font-size:11px;color:var(--u-ol);">
                    <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:var(--u-p);display:inline-block;"></span>Lectures</span>
                    <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:var(--u-secc);display:inline-block;"></span>Quizzes</span>
                  </div>
                </div>
                <div class="umat-an-card-body">
                  <canvas id="an-chart" class="umat-chart-canvas"></canvas>
                  <div id="an-chart-labels" style="display:flex;justify-content:space-around;margin-top:5px;font-size:10px;color:var(--u-ol);overflow:hidden;"></div>
                </div>
              </div>
              <div class="umat-an-card">
                <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">stacked_bar_chart</span>Student Performance</h3></div>
                <div class="umat-an-card-body">
                  <div class="umat-perf-item"><div class="umat-perf-row"><span class="umat-perf-lbl">🟢 High Engagement</span><span class="umat-perf-num" id="an-p-high">—</span></div><div class="umat-perf-bar"><div class="umat-perf-fill pf-high" id="an-pb-high" style="width:0%"></div></div></div>
                  <div class="umat-perf-item"><div class="umat-perf-row"><span class="umat-perf-lbl">🟡 On Track</span><span class="umat-perf-num" id="an-p-track">—</span></div><div class="umat-perf-bar"><div class="umat-perf-fill pf-track" id="an-pb-track" style="width:0%"></div></div></div>
                  <div class="umat-perf-item"><div class="umat-perf-row"><span class="umat-perf-lbl">🔴 At Risk</span><span class="umat-perf-num" id="an-p-risk">—</span></div><div class="umat-perf-bar"><div class="umat-perf-fill pf-risk" id="an-pb-risk" style="width:0%"></div></div></div>
                  <div style="font-size:11px;color:var(--u-ol);margin-top:10px;font-style:italic;">Estimated from AI interaction frequency over 30 days.</div>
                </div>
              </div>
            </div>
            <div class="umat-an-card" style="margin-bottom:18px;">
              <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">grid_view</span>Lecture Rewatch Heatmap</h3>
                <div class="umat-hm-legend">
                  <span>Less</span>
                  <span class="umat-hm-legend-sw" style="background:#dbeafe;"></span>
                  <span class="umat-hm-legend-sw" style="background:#93c5fd;"></span>
                  <span class="umat-hm-legend-sw" style="background:#4ade80;"></span>
                  <span class="umat-hm-legend-sw" style="background:var(--u-p);"></span>
                  <span>Struggle Zone</span>
                </div>
              </div>
              <div class="umat-an-card-body">
                <div class="umat-hm-grid" id="an-hm-grid" style="grid-template-columns:40px repeat(10,1fr);"></div>
                <div class="umat-an-ai-insight" id="an-insight" style="display:none;">
                  <span class="material-symbols-outlined">lightbulb</span>
                  <div class="umat-an-insight-text"><strong id="an-insight-title">AI Insight</strong><span id="an-insight-desc"></span></div>
                </div>
              </div>
            </div>
            <div class="umat-an-card">
              <div class="umat-an-card-hdr"><h3 class="umat-an-card-title"><span class="material-symbols-outlined">help</span>Common Student Questions</h3><span style="padding:3px 9px;border-radius:999px;background:var(--u-secc);color:var(--u-sec);font-size:10px;font-weight:700;" id="an-q-badge">0+ chats</span></div>
              <div class="umat-q-list" id="an-q-list"><div style="text-align:center;padding:24px;color:var(--u-ol);font-size:13px;">Loading questions…</div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- STRUGGLE INSIGHTS -->
      <div class="umat-tab-pane" id="lec-struggle" style="overflow-y:auto;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">psychology</span> Struggle Insights <span id="stru-course-label"></span></h2>
          <div style="display:flex;gap:8px;align-items:center;">
            <button class="umat-content-hdr-btn" id="stru-cs-btn" type="button" title="Select course"><span class="material-symbols-outlined">menu_book</span><span id="stru-cs-label">All Courses</span></button>
            <span class="umat-pill pill-info" id="stru-mode-badge">PHP Engine</span>
          </div>
        </div>
        <!-- Course picker overlay -->
        <div class="umat-cs-overlay" id="stru-cs-ov">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">menu_book</span>Select a Course</h3>
              <button class="umat-cs-close" id="stru-cs-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="stru-cs-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="stru-cs-list"></div>
          </div>
        </div>
        <div id="stru-overview" style="display:none;">
          <!-- Summary bar -->
          <div class="ov-kpi-row" id="ov-stru-kpis"></div>
          <!-- Two-column: Course comparison + Topic top -->
          <div class="ov-2col">
            <div class="ov-card">
              <div class="ov-card-hdr"><span class="material-symbols-outlined">leaderboard</span>Struggle Score by Course</div>
              <div class="ov-chart-bars" id="ov-stru-bars"><div class="ov-loading">Loading…</div></div>
            </div>
            <div class="ov-card">
              <div class="ov-card-hdr"><span class="material-symbols-outlined">psychology</span>Most Struggled Topics</div>
              <div id="ov-stru-topics" class="ov-topic-mini"><div class="ov-loading">Loading…</div></div>
            </div>
          </div>
          <!-- At-Risk Students -->
          <div class="ov-card ov-full">
            <div class="ov-card-hdr"><span class="material-symbols-outlined">warning</span>At-Risk Students (All Courses)</div>
            <div id="ov-stru-students" class="ov-student-list"><div class="ov-loading">Loading…</div></div>
          </div>
        </div>
        <div id="stru-loading" class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading struggle insights…</p></div>
        <div id="stru-error" class="umat-empty" style="display:none;"><span class="material-symbols-outlined">error_outline</span><p id="stru-error-text">Could not load struggle insights.</p></div>
        <div id="stru-content" style="display:none;padding:0 20px 20px;">

          <!-- Summary bar -->
          <div class="struggle-summary-bar" id="stru-summary-bar">
            <span><strong id="stru-total-q">0</strong> questions from <strong id="stru-total-s">0</strong> students</span>
            <span class="struggle-worst-topic">Worst topic: <strong id="stru-worst">—</strong></span>
          </div>
          <div id="stru-issues-summary" style="display:none;align-items:center;gap:10px;padding:8px 12px;margin-top:8px;border-radius:10px;background:var(--u-warnbg, rgba(239,68,68,.08));font-size:13px;cursor:pointer;" onclick="switchPane('lec-issues')" title="View student issues"></div>

          <!-- AI Overall Summary -->
          <div id="stru-ai-summary" style="display:none;align-items:center;gap:10px;padding:10px 14px;margin-top:10px;border-radius:10px;background:linear-gradient(135deg, rgba(99,102,241,.08), rgba(168,85,247,.08));font-size:13px;border:1px solid rgba(99,102,241,.15);"></div>

          <!-- AI Course Health Report -->
          <div id="stru-course-health" style="display:none;margin-top:12px;border-radius:10px;border:1px solid var(--u-border);overflow:hidden;"></div>

          <!-- Topic Struggle Matrix -->
          <div class="struggle-section">
            <div class="struggle-section-header">
              <h3><span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">leaderboard</span> Topic Struggle Matrix</h3>
              <span class="umat-pill pill-info">Score 0-100</span>
            </div>
            <div id="stru-topic-grid" class="struggle-topic-grid">
              <div class="umat-empty"><span class="material-symbols-outlined">search</span><p>No topic data yet.</p></div>
            </div>
          </div>

          <!-- Material Breakdown (grouped by course sections) -->
          <div class="struggle-section">
            <div class="struggle-section-header">
              <h3><span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">folder</span> Material Breakdown</h3>
            </div>
            <div id="stru-material-list" class="struggle-material-list">
              <div class="umat-empty"><span class="material-symbols-outlined">inventory_2</span><p>No materials with struggle data.</p></div>
            </div>
          </div>

          <!-- At-Risk Students -->
          <div class="struggle-section">
            <div class="struggle-section-header">
              <h3><span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">warning</span> At-Risk Students</h3>
              <span class="umat-pill pill-info">Risk score 0-100</span>
            </div>
            <div id="stru-student-list" class="struggle-student-list">
              <div class="umat-empty"><span class="material-symbols-outlined">person_search</span><p>No at-risk student data yet.</p></div>
            </div>
          </div>

        </div>
      </div>

      <!-- MY COURSES (LECTURER) -->
      <div class="umat-tab-pane" id="lec-courses">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">menu_book</span> My Courses</h2>
          <input type="text" id="lec-courses-search" placeholder="Filter courses…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(160px,40vw);">
        </div>
        <div class="umat-courses-grid" id="lec-courses-grid">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading your courses…</p></div>
        </div>
      </div>

      <!-- LIBRARY (LECTURER) -->
      <div class="umat-tab-pane" id="lec-library" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">local_library</span> Library</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" id="lec-lib-hdr-actions">
            <input type="text" id="lec-lib-search" placeholder="Search materials…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">
          </div>
        </div>
        <!-- Course picker overlay -->
        <div class="umat-cs-overlay" id="lec-lib-cs-ov">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">menu_book</span>Select a Course</h3>
              <button class="umat-cs-close" id="lec-lib-cs-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="lec-lib-cs-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="lec-lib-cs-list"></div>
          </div>
        </div>
        <!-- Materials grid -->
        <div class="umat-lib-grid" id="lec-lib-grid">
          <div class="umat-lib-picker">
            <span class="material-symbols-outlined">folder_open</span>
            <p>Select a course to browse its library materials.</p>
            <button type="button" id="lec-lib-pick-btn"><span class="material-symbols-outlined">menu_book</span>Select Course</button>
          </div>
        </div>
        <!-- Viewers (using shared material_viewer) -->
      </div>

      <!-- SESSIONS (LECTURER) -->
      <div class="umat-tab-pane" id="lec-sessions" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">history</span> AI Chat Sessions</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" id="lec-sess-hdr-actions"></div>
        </div>
        <!-- Course picker overlay -->
        <div class="umat-cs-overlay" id="lec-sess-cs-ov">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">menu_book</span>Select a Course</h3>
              <button class="umat-cs-close" id="lec-sess-cs-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="lec-sess-cs-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="lec-sess-cs-list"></div>
          </div>
        </div>
        <!-- Sessions list -->
        <div class="umat-sessions-list" id="lec-sess-list"></div>
      </div>

      <!-- REVIEW OUTPUTS (LECTURER) -->
      <div class="umat-tab-pane" id="lec-review" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">fact_check</span> Review AI Outputs <span class="umat-badge-num" id="lec-review-badge"></span></h2>
          <button class="umat-content-hdr-btn" id="lec-review-refresh" type="button"><span class="material-symbols-outlined">refresh</span>Refresh</button>
        </div>
        <div id="lec-review-body" style="flex:1;overflow-y:auto;padding:16px 20px;">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading pending outputs…</p></div>
        </div>
      </div>

      <!-- STUDENT ISSUES (LECTURER) -->
      <div class="umat-tab-pane" id="lec-issues" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">flag</span> Student Issues <span class="umat-badge-num" id="lec-issues-count"></span></h2>
          <div style="display:flex;gap:6px;">
            <select id="lec-issues-filter" style="padding:6px 8px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;background:var(--u-bg);color:var(--u-ons);">
              <option value="">All</option>
              <option value="open">Open</option>
              <option value="in_review">In Review</option>
              <option value="resolved">Resolved</option>
              <option value="closed">Closed</option>
            </select>
            <button class="umat-content-hdr-btn" id="lec-issues-refresh" type="button"><span class="material-symbols-outlined">refresh</span></button>
          </div>
        </div>
        <div id="lec-issues-body" style="flex:1;overflow-y:auto;padding:16px 20px;">
          <div class="umat-empty"><span class="material-symbols-outlined">flag</span><p>No student issues for this course.</p></div>
        </div>
      </div>

    </div><!-- /content -->

    <!-- AI FAB + Mini Panel (inside overlay, only visible when dashboard is open) -->
<button class="umat-fab umat-fab-pulse" id="lec-ai-fab" type="button" style="position:fixed;bottom:100px!important;right:28px!important;z-index:100001!important;" aria-label="Ask AI Assistant">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tip">Ask AI Assistant</span>
</button>
    <div id="lec-ai-mini" style="position:fixed;bottom:170px;right:28px;z-index:100002;width:min(340px,92vw);background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r16);box-shadow:var(--u-shadow);display:none;flex-direction:column;overflow:hidden;max-height:440px;">
      <div style="background:linear-gradient(135deg,var(--u-p),var(--u-pb));padding:11px 14px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <span style="font-size:13px;font-weight:700;">Ask AI About Analytics</span>
        <button id="lec-ai-mini-close" style="background:rgba(255,255,255,.2);border:none;color:#fff;width:26px;height:26px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;" type="button"><span class="material-symbols-outlined" style="font-size:15px;">close</span></button>
      </div>
      <div class="umat-msgs" id="lec-mini-msgs" style="max-height:260px;">
        <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Ask me about your course analytics, student patterns, or teaching recommendations.</p></div></div></div>
      </div>
      <div class="umat-input-area">
        <div class="umat-chatbar">
          <textarea id="lec-mini-input" class="umat-chatbar-input" placeholder="Ask about analytics…" rows="1"></textarea>
          <button class="umat-chatbar-send" id="lec-mini-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
        </div>
      </div>
    </div>
  </div><!-- /ov-body -->

  {$lecMobTabs}
</div>

{$sharedJs}

<script>/* Lecturer JS moved to amd/src/umat_lecturer.js */</script>
HTML;
    }

    public static function hub_overlay(string $wwwroot, object $user, string $userData): string {
        $uid     = (int)$user->id;
        $uName   = json_encode(fullname($user));
        $uInit   = htmlspecialchars(strtoupper(mb_substr($user->firstname,0,1).mb_substr($user->lastname,0,1)), ENT_QUOTES);
        $jsUD    = $userData; // raw JSON string from preload_user_data()
        $logUrl  = $wwwroot . '/login/logout.php';
        $streamUrl = json_encode($wwwroot . '/local/umat_ai/chat_stream.php');
        $moodleSesskey = json_encode(sesskey());
        $sharedJs = self::shared_js('hub-ov', 'hub-ov-close');

        // Glassmorphism mobile tab bar (in-overlay)
        $hubGlassTabs = [
            ['id' => 'hub-home',     'icon' => 'home',          'label' => 'Home',     'active' => true],
            ['id' => 'hub-tutor',    'icon' => 'smart_toy',     'label' => 'Tutor',    'active' => false],
            ['id' => 'hub-lectures', 'icon' => 'video_library', 'label' => 'Lectures', 'active' => false],
            ['id' => 'hub-courses',  'icon' => 'menu_book',     'label' => 'Courses',  'active' => false],
            ['id' => 'hub-library',  'icon' => 'local_library', 'label' => 'Library',  'active' => false],
            ['id' => 'hub-sessions', 'icon' => 'history',       'label' => 'Sessions', 'active' => false],
        ];
        $hubMobTabs = self::glassmorph_tab_bar($hubGlassTabs, 'hp', 'hub-glass-tabs');

        return <<<HTML
<!-- ============================================================
     HUB FAB + OVERLAY (non-course pages — students only)
     ============================================================ -->

<button class="umat-fab umat-fab-pulse" id="hub-fab" type="button" aria-label="Open AI Hub">
  <span class="material-symbols-outlined">forum</span>
  <span class="umat-fab-tip">AI Learning Hub</span>
</button>

<div class="umat-ov" id="hub-ov" role="dialog" aria-modal="true" aria-label="AI Learning Hub">
  <div class="umat-ov-body" style="flex:1;overflow:hidden;display:flex;">

    <!-- SIDEBAR -->
    <div class="umat-sb" id="hub-sb">
      <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">school</span></div>
        <div class="umat-sb-brand"><strong>UMaT Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="hub-ov-close" type="button" title="Close Hub">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
      <nav class="umat-sb-nav">
        <button class="umat-sb-item active" data-hp="hub-home" type="button"><span class="material-symbols-outlined">home</span><span class="umat-sb-item-lbl">Home</span></button>
        <button class="umat-sb-item" data-hp="hub-tutor" type="button"><span class="material-symbols-outlined">smart_toy</span><span class="umat-sb-item-lbl">AI Tutor</span></button>
        <button class="umat-sb-item" data-hp="hub-lectures" type="button"><span class="material-symbols-outlined">video_library</span><span class="umat-sb-item-lbl">Lecture Recordings</span></button>
        <button class="umat-sb-item" data-hp="hub-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span class="umat-sb-item-lbl">My Courses</span></button>
        <button class="umat-sb-item" data-hp="hub-library" type="button"><span class="material-symbols-outlined">local_library</span><span class="umat-sb-item-lbl">Library</span></button>
        <button class="umat-sb-item" data-hp="hub-sessions" type="button"><span class="material-symbols-outlined">history</span><span class="umat-sb-item-lbl">Sessions</span></button>
      </nav>
      <div class="umat-sb-divider"></div>
      <button class="umat-sb-new" id="hub-new-sess" type="button">
        <span class="material-symbols-outlined">add</span>
        <span class="umat-sb-new-lbl">New Session</span>
      </button>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'">
          <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
      </div>
    </div>

    <!-- CONTENT -->
    <div class="umat-ov-content">
      <button class="umat-ov-close-btn" type="button" onclick="document.getElementById('hub-ov').classList.remove('open')" title="Close">
        <span class="material-symbols-outlined">close</span>
      </button>

      <!-- HOME -->
      <div class="umat-tab-pane active" id="hub-home">
        <div class="umat-home-wrap">
          <div class="umat-home-hero">
            <h1>Welcome back, {$uInit}! 👋</h1>
            <p>Your cross-course AI learning companion — ask anything, anytime.</p>
            <div class="hero-sub" id="hub-home-date"></div>
          </div>
          <div class="umat-metrics-row">
            <div class="umat-metric-card"><div class="umat-metric-icon mi-g"><span class="material-symbols-outlined">forum</span></div><div><div class="umat-metric-val" id="hub-met-sess">—</div><div class="umat-metric-lbl">Sessions this week</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-s"><span class="material-symbols-outlined">help</span></div><div><div class="umat-metric-val" id="hub-met-q">—</div><div class="umat-metric-lbl">Questions asked</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-w"><span class="material-symbols-outlined">bolt</span></div><div><div class="umat-metric-val" id="hub-met-goal">—%</div><div class="umat-metric-lbl">Weekly goal</div></div></div>
          </div>
          <div class="umat-goal-bar-wrap">
            <div class="umat-goal-bar-row"><span>Weekly Study Goal</span><strong id="hub-goal-pct">0%</strong></div>
            <div class="umat-goal-bar"><div class="umat-goal-fill" id="hub-goal-fill" style="width:0%"></div></div>
          </div>
          <div class="umat-home-section" id="hub-pulse-section" style="margin-top:20px;">
            <h3>Learning Pulse — Most Active Topics</h3>
            <div id="hub-pulse-tags" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
          </div>
          <div class="umat-home-section" style="margin-top:20px;">
            <h3>Quick Actions</h3>
            <div class="umat-quick-actions-grid">
              <button class="umat-qa-btn" data-hp="hub-tutor" type="button"><span class="material-symbols-outlined">smart_toy</span><div class="umat-qa-btn-text"><strong>Ask AI Tutor</strong><span>Get instant help across all courses</span></div></button>
              <button class="umat-qa-btn" data-hp="hub-lectures" type="button"><span class="material-symbols-outlined">video_library</span><div class="umat-qa-btn-text"><strong>Watch Lectures</strong><span>Recordings with AI search</span></div></button>
              <button class="umat-qa-btn" data-hp="hub-courses" type="button"><span class="material-symbols-outlined">menu_book</span><div class="umat-qa-btn-text"><strong>My Courses</strong><span>Jump into a specific course</span></div></button>
              <button class="umat-qa-btn" data-hp="hub-sessions" type="button"><span class="material-symbols-outlined">history</span><div class="umat-qa-btn-text"><strong>Past Sessions</strong><span>Resume previous conversations</span></div></button>
            </div>
          </div>
          <div class="umat-home-section" id="hub-recent-section" style="margin-top:20px;display:none;">
            <h3>Recent Session Logs</h3>
            <div id="hub-recent-tiles" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;"></div>
          </div>
        </div>
      </div>

      <!-- AI TUTOR -->
      <div class="umat-tab-pane" id="hub-tutor" style="position:relative;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">smart_toy</span> General AI Tutor</h2>
          <select id="hub-course-sel" style="padding:6px 11px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);max-width:min(200px,45vw);">
            <option value="0">All Courses</option>
          </select>
        </div>
        <div class="umat-msgs" id="hub-msgs">
          <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
            <div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div>
              <div class="umat-bubble-ai"><p>Hello! I'm your cross-course AI tutor. Ask me anything about your engineering studies or campus inquiries. Select a course above to get course-specific answers! 🎓</p></div>
              <div class="umat-chips-row">
                <button class="umat-chip" data-q="What are the main differences between open-pit and underground mining?" type="button">Mining methods</button>
                <button class="umat-chip" data-q="Explain the Mohr-Coulomb failure criterion." type="button">Rock mechanics</button>
                <button class="umat-chip" data-q="How does electrical impedance affect circuit design?" type="button">Circuit theory</button>
              </div>
            </div>
          </div>
        </div>
        <div class="umat-input-area" style="position:relative;">
          <div class="umat-attach-drawer umat-drawer-enhanced" id="hub-attach-drawer">
            <div class="umat-drawer-hdr">
              <div class="umat-drawer-hdr-left">
                <span class="material-symbols-outlined" style="font-size:17px;color:var(--u-p);">attach_file</span>
                <h4>Select Materials</h4>
                <span class="umat-drawer-count" id="hub-drawer-count">0 selected</span>
              </div>
              <div class="umat-drawer-hdr-actions">
                <button class="umat-drawer-clear-btn" id="hub-drawer-clear" type="button">Clear</button>
                <button class="umat-drawer-close-btn" id="hub-drawer-close" type="button"><span class="material-symbols-outlined">close</span></button>
              </div>
            </div>
            <div class="umat-drawer-search-wrap">
              <span class="material-symbols-outlined umat-drawer-search-icon">search</span>
              <input type="text" id="hub-drawer-search" placeholder="Search materials…">
            </div>
            <div class="umat-drawer-cats" id="hub-drawer-cats"></div>
            <div class="umat-drawer-recent" id="hub-drawer-recent"></div>
            <div class="umat-drawer-list" id="hub-drawer-list"><div class="umat-drawer-loading"><div class="umat-vw-spinner"></div><span>Select a course first.</span></div></div>
            <div class="umat-drawer-foot">
<span class="umat-drawer-foot-info">Select materials for AI</span>
                <button class="umat-drawer-confirm" id="hub-drawer-confirm" type="button"><span class="material-symbols-outlined">check</span> Use Selected</button>
            </div>
          </div>
          <div class="umat-chatbar">
            <button class="umat-chatbar-btn" id="hub-attach-btn" type="button"><span class="material-symbols-outlined">add</span></button>
            <textarea id="hub-input" class="umat-chatbar-input" placeholder="Ask anything about your courses…" rows="1" maxlength="900"></textarea>
            <button class="umat-chatbar-btn" id="hub-mic-btn" type="button" title="Voice input"><span class="material-symbols-outlined">mic</span></button>
            <button class="umat-chatbar-send" id="hub-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
          </div>
          <div class="umat-mat-bar" id="hub-mat-bar"></div>
        </div>
      </div>

      <!-- LECTURES -->
      <div class="umat-tab-pane" id="hub-lectures" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">video_library</span> Lecture Recordings</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="hub-lec-course-sel" style="padding:5px 10px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);max-width:min(170px,40vw);">
              <option value="0">All Courses</option>
            </select>
            <input type="text" id="hub-lec-search" placeholder="Search…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">
          </div>
        </div>
        <div class="umat-video-grid" id="hub-lec-grid">
          <div class="umat-empty"><span class="material-symbols-outlined">video_library</span><p>Select a course and load recordings.</p></div>
        </div>
        <!-- Player (using shared material_viewer) -->
      </div>

      <!-- MY COURSES -->
      <div class="umat-tab-pane" id="hub-courses">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">menu_book</span> My Courses</h2>
          <input type="text" id="hub-courses-search" placeholder="Filter courses…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(160px,40vw);">
        </div>
        <div class="umat-courses-grid" id="hub-courses-grid">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading enrolled courses…</p></div>
        </div>
      </div>

      <!-- LIBRARY -->
      <div class="umat-tab-pane" id="hub-library" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">local_library</span> Library</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" id="hub-lib-hdr-actions">
            <input type="text" id="hub-lib-search" placeholder="Search materials…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">
          </div>
        </div>
        <!-- Course picker overlay -->
        <div class="umat-cs-overlay" id="hub-lib-cs-ov">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">menu_book</span>Select a Course</h3>
              <button class="umat-cs-close" id="hub-lib-cs-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="hub-lib-cs-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="hub-lib-cs-list"></div>
          </div>
        </div>
        <!-- Materials grid -->
        <div class="umat-lib-grid" id="hub-lib-grid">
          <div class="umat-lib-picker">
            <span class="material-symbols-outlined">folder_open</span>
            <p>Select a course to browse its library materials.</p>
            <button type="button" id="hub-lib-pick-btn"><span class="material-symbols-outlined">menu_book</span>Select Course</button>
          </div>
        </div>
        <!-- PDF Viewer (using shared material_viewer) -->
      </div>

      <!-- SESSIONS -->
      <div class="umat-tab-pane" id="hub-sessions">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">history</span> AI Chat Sessions</h2>
          <button class="umat-content-hdr-btn" id="hub-new-sess2" type="button"><span class="material-symbols-outlined">add</span>New Session</button>
        </div>
        <div class="umat-sessions-list" id="hub-sess-list">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading your sessions…</p></div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /ov-body -->

  {$hubMobTabs}
</div><!-- /hub-ov -->

{$sharedJs}

<script>/* Hub JS moved to amd/src/umat_hub.js */</script>
HTML;
    }

    public static function glassmorph_tab_bar(array $tabs, string $attrName, string $containerId): string {
        $realTabs = '';
        foreach ($tabs as $tab) {
            $active = !empty($tab['active']);
            $attr = htmlspecialchars($attrName, ENT_QUOTES);
            $val  = htmlspecialchars($tab['id'], ENT_QUOTES);
            $icon = htmlspecialchars($tab['icon'], ENT_QUOTES);
            $label = htmlspecialchars($tab['label'], ENT_QUOTES);
            $realTabs .= '<button class="umat-glass-tab' . ($active ? ' active' : '') . '" data-'
                . $attr . '="' . $val . '" type="button">'
                . '<span class="material-symbols-outlined">' . $icon . '</span>'
                . '<span>' . $label . '</span></button>';
        }
        return '<div class="umat-glass-tabs" id="' . htmlspecialchars($containerId, ENT_QUOTES) . '">'
            . '<div class="umat-glass-tabs-row">' . $realTabs . '</div>'
            . '</div>';
    }

    public static function glassmorph_init_js(): string {
        return '<script>'
            . 'M.util.js_pending("local_umat_ai/glassmorph_nav");'
            . 'M.util.js_pending("local_umat_ai/mobile_navbar");'
            . '!function c(){typeof require===\'function\'?require(["local_umat_ai/glassmorph_nav","local_umat_ai/mobile_navbar"],function(gm,mn){gm.init();mn.init();M.util.js_complete("local_umat_ai/glassmorph_nav");M.util.js_complete("local_umat_ai/mobile_navbar");}):setTimeout(c,20);}();'
            . '</script>';
    }
}



