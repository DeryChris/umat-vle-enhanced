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
            $tabHtml .= '<button class="umat-sb-item' . $active . '" data-sb-tab="'
                . htmlspecialchars($t['id'], ENT_QUOTES) . '" type="button">'
                . '<span class="material-symbols-outlined">' . htmlspecialchars($t['icon'], ENT_QUOTES) . '</span>'
                . '<span class="umat-sb-item-lbl">' . htmlspecialchars($t['label'], ENT_QUOTES) . '</span></button>';
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
window._umatSharedReady=new Promise(function(r){!function c(){typeof require==='function'?require(['local_umat_ai/umatshared','local_umat_ai/material_viewer'],function(s){for(var k in s)window[k]=s[k];var cb=document.getElementById('{$closeId}'),ov=document.getElementById('{$overlayId}');if(cb&&ov)cb.addEventListener('click',function(){ov.classList.remove('open');});r();}):setTimeout(c,20);}();});
</script>

/* Session tile on home tab */
(function(){var t=document.getElementById('ws-recent-sess-tile')||document.querySelector('[id$="recent-sess-tile"]');if(t&&!t._cw){t._cw=1;t.style.cursor='pointer';t.addEventListener('click',function(){var sk=t.dataset.sessKey||'',cid=parseInt(t.dataset.sessCid||0),cn=t.dataset.sessCn||'';if(typeof resumeSess==='function'&&sk)resumeSess(sk,cid,cn);else{var b=document.querySelector('[data-pane="sessions"],[data-sb-tab="sessions"]');if(b)b.click();}});}})();
/* Mobile nav: slide-to-hide + indicator pill */
(function(){document.querySelectorAll('.umat-mob-tabs').forEach(function(nav){var pill=document.createElement('div');pill.className='umat-mob-pill';nav.appendChild(pill);function mv(){var a=nav.querySelector('.umat-mob-tab.active');if(!a)return;var nr=nav.getBoundingClientRect(),tr=a.getBoundingClientRect();pill.style.left=(tr.left-nr.left)+'px';pill.style.width=tr.width+'px';}mv();nav.addEventListener('click',function(e){if(e.target.closest('.umat-mob-tab'))setTimeout(mv,30);});var sc=nav.closest&&nav.closest('.umat-ov')&&nav.closest('.umat-ov').querySelector('.umat-ov-content'),ly=0,ti=false;function os(){if(ti)return;ti=true;requestAnimationFrame(function(){var y=sc?sc.scrollTop:window.scrollY;if(y-ly>40)nav.classList.add('nav-hidden');else if(ly-y>10)nav.classList.remove('nav-hidden');ly=y;ti=false;});}(sc||window).addEventListener('scroll',os,{passive:true});window.addEventListener('resize',mv);});})();
/* Thumbnail loader */
window.loadYtThumbnails=window.loadYtThumbnails||function(g){if(!g)return;g.querySelectorAll('.yt-tile[data-url]').forEach(function(tile){var th=tile.querySelector('.yt-thumb');if(!th||th._td)return;th._td=1;var url=tile.dataset.url||'',mime=(tile.dataset.mime||'').toLowerCase();if(!url)return;if(mime.includes('image')){var img=document.createElement('img');img.className='yt-thumb-img';img.loading='lazy';img.src=url;th.appendChild(img);}else if(mime.includes('video')){var v=document.createElement('video');v.src=url;v.preload='metadata';v.muted=true;v.style.cssText='position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:12px;';v.addEventListener('loadedmetadata',function(){v.currentTime=Math.min(2,v.duration*0.1);});v.addEventListener('seeked',function(){th.appendChild(v);});v.load();}else if(mime.includes('pdf')){var lo=document.createElement('div');lo.className='yt-thumb-loading';th.appendChild(lo);(function(){var s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';s.onload=function(){window.pdfjsLib&&(window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',pdfjsLib.getDocument(url).promise.then(function(p){return p.getPage(1);}).then(function(pg){var vp=pg.getViewport({scale:1}),sc=Math.min(th.offsetWidth/vp.width,th.offsetHeight/vp.height)||1,vp2=pg.getViewport({scale:sc}),c=document.createElement('canvas');c.className='yt-thumb-canvas';c.width=vp2.width;c.height=vp2.height;lo.remove();th.appendChild(c);pg.render({canvasContext:c.getContext('2d'),viewport:vp2});}).catch(function(){lo.remove();}));};document.head.appendChild(s);})();}else if(mime.includes('word')||mime.includes('document')||mime.includes('presentation')||mime.includes('powerpoint')||mime.includes('spreadsheet')||mime.includes('excel')){var dv=document.createElement('div');dv.className='yt-thumb-doc-preview';for(var i=0;i<6;i++){var dl=document.createElement('div');dl.className='yt-thumb-doc-line';dv.appendChild(dl);}th.appendChild(dv);}});};
new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes.forEach(function(n){if(n.nodeType!==1)return;if(n.classList&&n.classList.contains('yt-grid'))window.loadYtThumbnails(n);var gs=n.querySelectorAll&&n.querySelectorAll('.yt-grid');if(gs&&gs.length)gs.forEach(function(g){window.loadYtThumbnails(g);});});});}).observe(document.body,{childList:true,subtree:true});
/* AJAX cache (5-min TTL for analytics/struggle) */
if(typeof ajax==='function'&&!window._ajaxCached){window._ajaxCached=1;var _ac={},_at={};window._origAjax=ajax;window.ajax=function(m,a,d,f){if(m.includes('analytics')||m.includes('struggle')){var k=m+':'+JSON.stringify(a),n=Date.now();if(_ac[k]&&n-_at[k]<300000){setTimeout(function(){d(_ac[k]);},0);return;}_origAjax(m,a,function(r){_ac[k]=r;_at[k]=Date.now();d(r);},f);}else _origAjax(m,a,d,f);};}
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
            ['id' => 'sessions',  'icon' => 'chat_bubble',   'label' => 'Sessions',  'active' => false],
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
            ['id' => 'sessions', 'icon' => 'chat_bubble', 'label' => 'Sessions', 'active' => false],
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
      <button class="umat-cp-feature-tab" data-cp-open="sessions" type="button"><span class="material-symbols-outlined">chat_bubble</span><span>Sessions</span></button>
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
      <div class="umat-input-area">
        <div class="umat-input-row">
          <textarea id="cp-input" class="umat-textarea" placeholder="Ask anything…" rows="2" maxlength="900"></textarea>
          <button class="umat-send-btn" id="cp-send" type="button"><span class="material-symbols-outlined">send</span></button>
        </div>
        <div class="umat-input-actions">
          <span class="umat-rate-txt" id="cp-rate" style="font-size:10px;color:var(--u-ol);">10 questions remaining</span>
          <button class="umat-ia-btn" id="cp-mic" type="button"><span class="material-symbols-outlined">mic</span>Voice</button>
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

  {$stuMobTabs}

  <div class="umat-ov-content">

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
    <div class="umat-tab-pane" data-tab="ai-tutor">
      <div class="umat-content-hdr">
        <h2>AI Tutor</h2>
        <span class="pill" id="ws-rate-pill">10 Q remaining</span>
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
                  <h4>Select Reference Materials</h4>
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
                <span class="umat-drawer-foot-info">Select materials for AI to reference</span>
                <button class="umat-drawer-confirm" id="ws-drawer-confirm" type="button"><span class="material-symbols-outlined">check</span> Reference Selected</button>
              </div>
            </div>
            <div class="umat-input-row">
              <textarea id="ws-input" class="umat-textarea" placeholder="Ask AI about this course…" rows="2" maxlength="900"></textarea>
              <button class="umat-send-btn" id="ws-send" type="button"><span class="material-symbols-outlined">send</span></button>
            </div>
            <div class="umat-mat-bar" id="ws-mat-bar"></div>
            <div class="umat-input-actions">
              <button class="umat-ia-btn" id="ws-attach-btn" type="button"><span class="material-symbols-outlined">attach_file</span>Reference Material</button>
              <button class="umat-ia-btn" id="ws-mic-btn" type="button"><span class="material-symbols-outlined">mic</span>Voice</button>
            </div>
          </div>
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

  </div><!-- /ov-content -->
</div><!-- /student workspace overlay -->

{$sharedJs}

<script>
window._umatSharedReady.then(function() {
(function(){
'use strict';
var courseId   = {$jsCid};
var courseName = {$jsName};
var userData   = {$userData};
var streamUrl  = {$streamUrl};
var moodleSesskey = {$moodleSesskey};
var sessionKey = 'stu_'+Math.random().toString(36).substr(2,18);
/* Rolling 60s rate-limit window — mirrors the server check, refills as entries expire */
var RATE_MAX = 10;
var qTimes   = [];
var selectedMats = [];
var lecturesLoaded = false;
var libraryLoaded  = false;
var coursesLoaded  = false;
var notesLoaded    = false;
var sessionsLoaded = false;
var ov = document.getElementById('umat-student-ov');

/* ---- FAB & compact panel ---- */
var fab     = document.getElementById('umat-stu-fab');
var cpOv    = document.getElementById('stu-cp-ov');
var cpClose = document.getElementById('stu-cp-close');
var expBtn  = document.getElementById('stu-expand-btn');

fab.addEventListener('click', function(){ cpOv.classList.add('open'); updateRate(); checkConn(); initCpNotes(); });
cpClose.addEventListener('click', function(){ cpOv.classList.remove('open'); });
cpOv.addEventListener('click', function(e){ if(e.target===cpOv) cpOv.classList.remove('open'); });
expBtn.addEventListener('click', function(){ cpOv.classList.remove('open'); openOverlay(); });

document.getElementById('sb-new-btn').addEventListener('click', newSession);
var nb2=document.getElementById('ws-new-session-btn2'); if(nb2)nb2.addEventListener('click',newSession);

function newSession(){
  _clearNoteCtx();
  sessionKey='stu_'+Math.random().toString(36).substr(2,18);
  selectedMats=[];
  if(wsDrawerCtrl)wsDrawerCtrl.clear();
  _umatRenderMatsBar('ws-mat-bar','ws-attach-btn',[],function(){return[];});
  var m=document.getElementById('ws-msgs');
  if(m){
    m.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Starting a fresh session! Ask me anything about your course materials.</p></div></div></div>';
  }
  var cm=document.getElementById('cp-msgs');
  if(cm){
    cm.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>New session ready! How can I help you?</p></div></div></div>';
  }
  switchToTab('ai-tutor');
}

function openOverlay(){ ov.classList.add('open'); populateHomeTab(); }
function closeOverlay(){ ov.classList.remove('open'); cpOv.classList.add('open'); }
if(ov)ov.addEventListener('click',function(e){if(e.target===ov)closeOverlay();});

/* Wire up the workspace close button */
var wsClose=document.getElementById('stu-ws-close');
if(wsClose)wsClose.addEventListener('click',closeOverlay);

/* ---- compact panel tabs ---- */
function showCpPane(id){
  document.querySelectorAll('#stu-cp [data-cp-tab]').forEach(function(b){b.classList.toggle('active',b.dataset.cpTab===id);});
  document.querySelectorAll('#stu-cp [data-cp-pane]').forEach(function(b){b.classList.toggle('active',b.dataset.cpPane===id);});
  document.querySelectorAll('#stu-cp .umat-cp-pane').forEach(function(p){p.classList.toggle('active',p.id===id);});
}
document.querySelectorAll('#stu-cp [data-cp-tab]').forEach(function(btn){
  btn.addEventListener('click',function(){showCpPane(btn.dataset.cpTab);});
});
document.querySelectorAll('#stu-cp [data-cp-pane]').forEach(function(btn){
  btn.addEventListener('click',function(){showCpPane(btn.dataset.cpPane);});
});
document.querySelectorAll('#stu-cp [data-cp-open]').forEach(function(btn){
  btn.addEventListener('click',function(){renderCpFeature(btn.dataset.cpOpen);});
});

function setCpFeatureActive(name){
  document.querySelectorAll('#stu-cp [data-cp-pane]').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('#stu-cp [data-cp-open]').forEach(function(b){b.classList.toggle('active',b.dataset.cpOpen===name);});
}
function renderCpFeature(name){
  var meta={
    home:['home','Home','Course snapshot'],lectures:['play_circle','Lectures','Recent recordings'],courses:['menu_book','Courses','Your enrolled courses'],library:['local_library','Library','Course materials'],sessions:['chat_bubble','Sessions','Recent AI chats']
  }[name]||['widgets','Feature','Quick view'];
  showCpPane('cp-feature');setCpFeatureActive(name);
  document.getElementById('cp-feature-icon').textContent=meta[0];document.getElementById('cp-feature-title').textContent=meta[1];document.getElementById('cp-feature-sub').textContent=meta[2];
  var body=document.getElementById('cp-feature-body');body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading '+meta[1].toLowerCase()+'…</p></div>';
  if(name==='home')return renderCpHome(body);
  if(name==='courses')return renderCpCourses(body);
  if(name==='sessions')return renderCpSessions(body);
  if(name==='lectures')return renderCpLectures(body);
  if(name==='library')return renderCpLibrary(body);
}
function renderCpHome(body){
  var d=userData||{};var courses=d.courses||[],sessions=d.sessions||[];
  body.innerHTML='<div class="umat-cp-mini-grid">'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">chat_bubble</span><strong>'+(d.week_questions||0)+'</strong><small>questions this week</small></div>'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">history</span><strong>'+(d.week_sessions||0)+'</strong><small>sessions this week</small></div>'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">menu_book</span><strong>'+courses.length+'</strong><small>courses</small></div>'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">task_alt</span><strong>'+(d.goal_progress||0)+'%</strong><small>goal progress</small></div></div>'+
    (sessions[0]?'<div class="umat-cp-list-card"><strong>Recent session</strong><p>'+_umatEsc(sessions[0].preview||'Continue your last chat')+'</p></div>':'');
}
function renderCpCourses(body){
  var courses=(userData&&userData.courses)||[];
  if(!courses.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses found.</p></div>';return;}
  body.innerHTML=courses.map(function(c){return '<button class="umat-cp-list-card as-btn" data-cid="'+c.id+'" type="button"><strong>'+_umatEsc(c.shortname||c.fullname)+'</strong><p>'+_umatEsc(c.fullname||'')+'</p></button>';}).join('');
  body.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){courseId=parseInt(b.dataset.cid)||courseId;showCpPane('cp-chat');});});
}
function renderCpSessions(body){
  var sessions=(userData&&userData.sessions)||[];
  if(!sessions.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">chat_bubble</span><p>No AI sessions yet.</p></div>';return;}
  body.innerHTML=sessions.slice(0,10).map(function(s){return '<button class="umat-cp-list-card as-btn" data-sk="'+_umatEsc(s.session_key)+'" data-cid="'+(s.courseid||courseId)+'" type="button"><strong>'+_umatEsc(s.course_name||'AI Session')+'</strong><p>'+_umatEsc(s.preview||'Resume chat')+'</p><small>'+_umatEsc(s.time_label||'')+'</small></button>';}).join('');
  body.querySelectorAll('[data-sk]').forEach(function(b){b.addEventListener('click',function(){sessionKey=b.dataset.sk;courseId=parseInt(b.dataset.cid)||courseId;showCpPane('cp-chat');_umatAppendAi('cp-msgs','Session resumed. Ask a follow-up question to continue.',[]);});});
}
function renderCpLectures(body){
  require(['core/ajax'],function(Ajax){Ajax.call([{methodname:'local_umat_ai_get_course_recordings',args:{courseid:courseId}}])[0].done(function(r){var recs=r.recordings||r||[];if(!recs.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">videocam_off</span><p>No recordings yet.</p></div>';return;}body.innerHTML=recs.slice(0,10).map(function(v){return '<button class="umat-cp-list-card as-btn" type="button"><strong>'+_umatEsc(v.title||'Lecture Recording')+'</strong><p>'+_umatEsc(v.duration||v.time_label||'Recording available')+'</p></button>';}).join('');}).fail(function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load recordings.</p></div>';});});
}
function renderCpLibrary(body){
  require(['core/ajax'],function(Ajax){Ajax.call([{methodname:'local_umat_ai_get_course_materials',args:{courseid:courseId}}])[0].done(function(r){var mats=r.materials||[];if(!mats.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>No materials indexed yet.</p></div>';return;}body.innerHTML=mats.slice(0,12).map(function(m){return '<button class="umat-cp-list-card as-btn" data-mid="'+m.id+'" type="button"><strong>'+_umatEsc(m.filename||m.name||'Material')+'</strong><p>'+_umatEsc(m.mimetype||m.type||'Course material')+'</p></button>';}).join('');}).fail(function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load materials.</p></div>';});});
}

/* ---- workspace tab switching ---- */
function switchToTab(name){
  ov.querySelectorAll('[data-sb-tab]').forEach(function(b){b.classList.toggle('active',b.dataset.sbTab===name);});
  ov.querySelectorAll('.umat-tab-pane').forEach(function(p){p.classList.toggle('active',p.dataset.tab===name);});
  if(name==='lectures'   && !lecturesLoaded){ loadLectures(); lecturesLoaded=true; }
  if(name==='library'    && !libraryLoaded){  loadLibrary();  libraryLoaded=true;  }
  if(name==='courses'    && !coursesLoaded){  renderCourses(userData.courses||[]); coursesLoaded=true; }
  if(name==='my-notes'   && !notesLoaded){   initNotesTab();  notesLoaded=true;    }
  if(name==='sessions'   && !sessionsLoaded){ loadSessions();  sessionsLoaded=true; }
}
/* Select course: set context and switch to AI Tutor */
function selectCourse(cid,cname){
  courseId=cid;
  switchToTab('ai-tutor');
}
ov.querySelectorAll('[data-sb-tab]').forEach(function(btn){
  btn.addEventListener('click',function(){ switchToTab(btn.dataset.sbTab); });
});
/* Quick action buttons on Home tab */
ov.querySelectorAll('[data-sb-tab]').forEach(function(btn){
  btn.addEventListener('click',function(){ switchToTab(btn.dataset.sbTab); });
});

/* ---- rate counter (rolling 60s window) ---- */
function qRemaining(){
  var now=Date.now();
  qTimes=qTimes.filter(function(t){return now-t<60000;});
  return Math.max(0,RATE_MAX-qTimes.length);
}
/* Reconcile with the server's count (covers other tabs/devices) */
function syncRemaining(rem){
  if(typeof rem!=='number'||rem<0)return;
  var now=Date.now();
  while(qRemaining()>rem)qTimes.push(now);
}
function updateRate(){
  var left=qRemaining();
  var el=document.getElementById('cp-rate'),el2=document.getElementById('ws-rate-pill');
  var t=left+' question'+(left!==1?'s':'')+' remaining';
  if(el)el.textContent=t;
  if(el2)el2.textContent=t;
}
setInterval(updateRate,5000); /* refill display as window entries expire */

/* ---- connection status ---- */
var connOnline=null,connTimer=null;
function setConn(state){
  var el=document.getElementById('stu-conn-status');
  if(!el)return;
  if(state==='online'){el.innerHTML='&#9679; Online &amp; Ready';el.style.color='';}
  else if(state==='checking'){el.innerHTML='&#9679; Checking…';el.style.color='#d97706';}
  else{el.innerHTML='<span class="material-symbols-outlined" style="font-size:12px;vertical-align:-2px;">wifi_off</span> Offline — retrying…';el.style.color='#fca5a5';}
}
function markConn(online){
  connOnline=online;
  setConn(online?'online':'offline');
  clearTimeout(connTimer);
  if(!online)connTimer=setTimeout(checkConn,15000); /* keep retrying while panel is open */
}
function checkConn(){
  if(connOnline===null)setConn('checking');
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_service_status',args:{}}])[0]
      .done(function(r){markConn(!!r.online);})
      .fail(function(){markConn(false);});
  });
}

/* ---- HOME TAB data ---- */
function populateHomeTab(){
  var d=userData||{};
  var set=function(id,v){var el=document.getElementById(id);if(el)el.textContent=v;};
  set('ws-m-sessions',  d.week_sessions||0);
  set('ws-m-questions', d.week_questions||0);
  var gp=d.goal_progress||0;
  set('ws-m-goal', gp+'%');
  set('ws-goal-pct', gp+'%');
  set('ws-goal-pill','Goal: '+gp+'%');
  var bar=document.getElementById('ws-goal-bar');
  if(bar) setTimeout(function(){bar.style.width=gp+'%';},200);

  /* Recent session card */
  var sessions=d.sessions||[];
  if(sessions.length>0){
    var s=sessions[0];
    var wrap=document.getElementById('ws-recent-session-wrap');
    var cont=document.getElementById('ws-recent-session');
    if(wrap)wrap.style.display='';
    if(cont){
      cont.innerHTML='<div class="umat-session-tile" style="max-width:480px;">'
        +'<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+_umatEsc(s.course_short||'')+'</span><span class="umat-session-time">'+_umatEsc(s.time_label)+'</span></div>'
        +'<h4>'+_umatEsc(s.course_name)+' AI Session</h4>'
        +'<p>'+_umatEsc(s.preview)+'</p>'
        +'<div class="umat-session-tile-foot"><span class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</span>'
        +'<button class="umat-resume-btn" data-sk="'+_umatEsc(s.session_key)+'" type="button">Resume →</button></div></div>';
      cont.querySelector('.umat-resume-btn').addEventListener('click',function(){
        resumeSession(this.dataset.sk, d.courses&&d.courses[0]?d.courses[0].id:courseId);
      });
    }
  }
}

/* ---- AI TUTOR chat (streaming) ---- */
function sendQuestion(q, msgsId){
  q=(q||'').trim();if(!q)return;
  if(qRemaining()<=0){_umatAppendAi(msgsId,'Rate limit reached. Please wait a moment before asking again.',[]); return;}
  qTimes.push(Date.now());updateRate();
  _umatAppendUser(msgsId,q);
  var tid='typ_'+Date.now();_umatShowTyping(msgsId,tid);

  var contextQ=selectedMats.length>0?'[Referencing: '+selectedMats.map(function(m){return m.name;}).join(', ')+'] '+q:q;
  var matIds=selectedMats.map(function(m){return m.id;});

  _umatStreamChat({
    url: streamUrl,
    sesskey: moodleSesskey,
    courseid: courseId,
    question: contextQ,
    session_key: sessionKey,
    material_ids: matIds,
    msgsId: msgsId,
    onMeta: function(meta){ syncRemaining(meta.remaining); updateRate(); markConn(true); },
    onDone: function(meta){ _umatHideTyping(tid); syncRemaining(meta.remaining); updateRate(); markConn(true); },
    onError: function(err){
      _umatHideTyping(tid);
      if(err.error==='rate_limit'){ qTimes.pop(); updateRate(); }
      else { markConn(false); }
      _umatAppendAi(msgsId, err.message||'Sorry, an error occurred. Please try again.', []);
    }
  });
}

/* workspace AI tutor */
var wsInput=document.getElementById('ws-input'),wsSend=document.getElementById('ws-send');
if(wsSend)wsSend.addEventListener('click',function(){sendQuestion(wsInput.value,'ws-msgs');wsInput.value='';});
if(wsInput)wsInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();wsSend.click();}});
/* suggestion chips */
ov.addEventListener('click',function(e){
  var chip=e.target.closest('[data-q]');
  if(chip){sendQuestion(chip.dataset.q,'ws-msgs');}
});

/* compact panel send */
var cpInput=document.getElementById('cp-input'),cpSend=document.getElementById('cp-send');
if(cpSend)cpSend.addEventListener('click',function(){sendQuestion(cpInput.value,'cp-msgs');cpInput.value='';});
if(cpInput)cpInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();cpSend.click();}});

/* voice */
var wsMic=document.getElementById('ws-mic-btn');
if(wsMic&&wsInput)_umatInitVoice(wsInput,wsMic);
var cpMic=document.getElementById('cp-mic');
if(cpMic&&cpInput)_umatInitVoice(cpInput,cpMic);

/* attachment drawer */
var wsDrawerCtrl = _umatInitAttachDrawer({
  getCourseId:function(){return courseId;},
  drawerId:'ws-attach-drawer',
  attachBtnId:'ws-attach-btn',
  closeBtnId:'ws-drawer-close',
  clearId:'ws-drawer-clear',
  searchId:'ws-drawer-search',
  catsId:'ws-drawer-cats',
  recentId:'ws-drawer-recent',
  listId:'ws-drawer-list',
  confirmId:'ws-drawer-confirm',
  countId:'ws-drawer-count',
  maxSelections:20,
  onConfirm:function(mats){selectedMats=mats;_umatRenderMatsBar('ws-mat-bar','ws-attach-btn',selectedMats,function(id){selectedMats=selectedMats.filter(function(s){return s.id!=id;});return selectedMats;});}
});

/* lecture player send */
var plInput=document.getElementById('ws-player-input'),plSend=document.getElementById('ws-player-send');
if(plSend)plSend.addEventListener('click',function(){sendQuestion(plInput.value,'ws-player-msgs');plInput.value='';});
if(plInput)plInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();plSend.click();}});

/* ---- LECTURES: load & display ---- */
function loadLectures(){
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_course_recordings',args:{courseid:courseId}}])[0]
      .done(function(r){renderVideoTiles(r.recordings||r||[]);}).fail(function(){
        document.getElementById('ws-video-grid').innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load recordings. Make sure the AI service is running.</p></div>';
      });
  });
}
document.getElementById('ws-lec-refresh').addEventListener('click',function(){lecturesLoaded=false;loadLectures();lecturesLoaded=true;});


function openVideoPlayer(rec){
  if(window.umatMaterialViewer)window.umatMaterialViewer.open('video',{
    url:rec.url, name:rec.title||'Lecture Recording',
    segments:rec.transcript||rec.segments||[],
    downloadUrl:rec.url
  });
}

/* ---- MY COURSES: render from preloaded data ---- */

/* ---- LIBRARY ---- */
function loadLibrary(){
  var grid=document.getElementById('ws-lib-grid');
  grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>';
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_course_materials',args:{courseid:courseId}}])[0]
      .done(function(r){renderLibrary(r.materials||[]);if(typeof updateMaterialAnalysis==='function')updateMaterialAnalysis(courseId);})
      .fail(function(){grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load materials.</p></div>';});
  });
}
document.getElementById('ws-lib-refresh').addEventListener('click',function(){libraryLoaded=false;loadLibrary();libraryLoaded=true;});


function openPdfViewer(url,name){
  if(window.umatMaterialViewer)window.umatMaterialViewer.open('pdf',{
    url:url, name:name||'Document', downloadUrl:url
  });
}

/* ---- SESSIONS ---- */
function loadSessions(){
  var list=document.getElementById('ws-sessions-list');
  var sessions=(userData&&userData.sessions)||[];
  if(!sessions.length){
    list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">chat_bubble</span><p>No AI chat sessions yet. Start one in the AI Tutor tab!</p></div>';
    return;
  }
  list.innerHTML=sessions.map(function(s){
    return'<div class="umat-session-tile" data-sk="'+_umatEsc(s.session_key)+'" data-cid="'+s.courseid+'">'
      +'<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+_umatEsc(s.course_short||'')+'</span><span class="umat-session-time">'+_umatEsc(s.time_label)+'</span></div>'
      +'<h4>'+_umatEsc(s.course_name||'General Session')+'</h4>'
      +'<p>'+_umatEsc(s.preview)+'</p>'
      +'<div class="umat-session-tile-foot"><span class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</span>'
      +'<button class="umat-resume-btn" type="button">Resume →</button></div></div>';
  }).join('');
  function resumeSession(sk,cid){
    sessionKey=sk;
    courseId=parseInt(cid)||courseId;
    switchToTab('ai-tutor');
    var msgs=document.getElementById('ws-msgs');
    if(!msgs)return;
    msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p><em>Loading conversation history\u2026</em></p></div></div></div>';
    require(['core/ajax'],function(A){
      A.call([{methodname:'local_umat_ai_get_chat_history',args:{courseid:courseId,session_key:sk,limit:50}}])[0]
        .done(function(r){
          msgs.innerHTML='';
          (r.messages||[]).forEach(function(msg){
            if(msg.question)_umatAppendUser('ws-msgs',msg.question);
            if(msg.answer)_umatAppendAi('ws-msgs',msg.answer,msg.sources||[]);
          });
          if(!(r.messages||[]).length){
            msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Welcome back! This session had no previous messages. Ask me anything about your course!</p></div></div></div>';
          }
        }).fail(function(){
          msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Welcome back! I\'m ready to continue where we left off.</p></div></div></div>';
        });
    });
  }
  list.querySelectorAll('.umat-session-tile').forEach(function(tile){
    tile.addEventListener('click',function(){
      resumeSession(tile.dataset.sk,tile.dataset.cid);
    });
    tile.querySelector('.umat-resume-btn').addEventListener('click',function(e){
      e.stopPropagation();
      resumeSession(tile.dataset.sk,tile.dataset.cid);
    });
  });
}

/* ---- NOTES ────────────────────────────────────────────── */
function initNotesTab(){
  var pane=document.querySelector('[data-tab="my-notes"]');
  if(!pane)return;
  pane.innerHTML=
    '<div class="umat-notes-wrap">'+
      '<div class="umat-notes-toolbar">'+
        '<div class="umat-notes-search-wrap">'+
          '<span class="material-symbols-outlined umat-notes-search-icon">search</span>'+
          '<input type="text" class="umat-notes-search" id="ws-notes-search" placeholder="Search notes\u2026">'+
        '</div>'+
        '<button class="umat-notes-add-btn" id="ws-notes-add" type="button"><span class="material-symbols-outlined">add</span>New Note</button>'+
      '</div>'+
      '<div class="umat-notes-list" id="ws-notes-list"></div>'+
    '</div>';
  /* Wire ws-notes-add button */
  var wsAdd=document.getElementById('ws-notes-add');
  if(wsAdd)wsAdd.addEventListener('click',function(){openNoteEditor(null);});
  /* Also init compact panel notes */
  initCpNotes();
  loadNotesAndRender();
}
function initCpNotes(){
  if(window._cpNotesInited)return;
  window._cpNotesInited=true;
  /* Tab switching */
  document.querySelectorAll('#cp-notes [data-cp-nt]').forEach(function(btn){
    btn.addEventListener('click',function(){
      document.querySelectorAll('#cp-notes [data-cp-nt]').forEach(function(b){b.classList.toggle('active',b===btn);});
      document.querySelectorAll('#cp-notes .umat-cp-notes-tab-pane').forEach(function(p){p.classList.toggle('active',p.id===btn.dataset.cpNt);});
    });
  });
  /* Add button */
  var addBtn=document.getElementById('cp-notes-add-btn');
  if(addBtn)addBtn.addEventListener('click',function(){openNoteEditor(null);});
}
var _notesCache=null;
var _noteMats=[]; /* Material IDs added by chat-with-note, for cleanup */
function _clearNoteCtx(){
  window._noteContext=null;
  document.querySelectorAll('.umat-chip-note').forEach(function(e){e.remove();});
  if(_noteMats.length){
    selectedMats=selectedMats.filter(function(s){return _noteMats.indexOf(s.id)<0;});
    _noteMats=[];
    _umatRenderMatsBar('ws-mat-bar','ws-attach-btn',selectedMats,function(id){selectedMats=selectedMats.filter(function(s){return s.id!=id;});return selectedMats;});
  }
}
function loadNotesAndRender(){
  var list=document.getElementById('ws-notes-list');
  if(!list)return;
  ajax('local_umat_ai_get_notes',{courseid:courseId},
    function(r){
      _notesCache=r.notes||[];
      renderNotesList(_notesCache);
      renderCpNotes(_notesCache);
    },
    function(){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load notes. Check your connection.</p></div>';}
  );
}
function renderNotesList(notes){
  var list=document.getElementById('ws-notes-list');
  if(!list)return;
  if(!notes||!notes.length){
    list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">note_add</span><p>No notes yet. Tap + New Note to create your first note!</p></div>';
    return;
  }
  list.innerHTML=notes.map(function(n){
    var plainPreview=(n.content||'').replace(/<[^>]*>/g,'').substring(0,180);
    var hasMedia=n.content&&/<(img|video|audio)/.test(n.content);
    var tagChips=(n.tags||[]).map(function(t){
      var icon='label';
      if(t.tag_type==='course')icon='menu_book';
      else if(t.tag_type==='material')icon='description';
      else if(t.tag_type==='session')icon='chat';
      var attr='';
      if(t.tag_type==='session'&&t.tag_value)attr=' data-tag-value="'+_umatEsc(t.tag_value)+'"';
      if(t.tag_type==='material'&&t.tag_id)attr+=' data-tag-id="'+t.tag_id+'"';
      return '<span class="umat-note-tag" data-tag-type="'+_umatEsc(t.tag_type)+'"'+attr+'>'+
        '<span class="material-symbols-outlined" style="font-size:13px;">'+icon+'</span>'+
        _umatEsc(t.tag_label)+'</span>';
    }).join('');
    var pinBtn=n.pinned
      ?'<button class="umat-note-pin-btn active" title="Unpin" data-pin="'+n.id+'" type="button"><span class="material-symbols-outlined">push_pin</span></button>'
      :'<button class="umat-note-pin-btn" title="Pin to top" data-pin="'+n.id+'" type="button"><span class="material-symbols-outlined">push_pin</span></button>';
    var date=new Date(n.timemodified*1000);
    var dateStr=date.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
    return '<div class="umat-note-card'+(n.pinned?' pinned':'')+'" data-note-id="'+n.id+'">'+
      '<div class="umat-note-card-hdr">'+
        '<h4 class="umat-note-title">'+_umatEsc(n.title||'Untitled Note')+'</h4>'+
        '<div class="umat-note-card-actions">'+
          pinBtn+
          '<button class="umat-note-del-btn" data-del="'+n.id+'" title="Delete" type="button"><span class="material-symbols-outlined">delete</span></button>'+
        '</div>'+
      '</div>'+
      (tagChips?'<div class="umat-note-tags-row">'+tagChips+'</div>':'')+
      '<div class="umat-note-preview'+(hasMedia?' umat-note-preview-rich':'')+'">'+
        (n.content&&/<[^>]+>/.test(n.content) ? n.content : _umatEsc(plainPreview||'No content'))+
      '</div>'+
      '<div class="umat-note-card-foot">'+
        '<span class="umat-note-date">'+dateStr+'</span>'+
        '<div class="umat-note-actions">'+
          (n.tags&&n.tags.some(function(t){return t.tag_type==='session'&&t.tag_value;})
            ?'<button class="umat-note-action-btn resume-session-btn" data-sk="'+
              _umatEsc((n.tags.find(function(t){return t.tag_type==='session';})||{}).tag_value||'')+
              '" data-cid="'+n.courseid+'" type="button"><span class="material-symbols-outlined">history</span>Resume Session</button>'
            :'')+
          '<button class="umat-note-action-btn chat-note-btn" data-note-id="'+n.id+'" type="button"><span class="material-symbols-outlined">smart_toy</span>Chat with Note</button>'+
        '</div>'+
      '</div>'+
    '</div>';
  }).join('');
  /* Wire events */
  list.querySelectorAll('.umat-note-card').forEach(function(card){
    var id=parseInt(card.dataset.noteId);
    card.addEventListener('click',function(){openNoteEditor(id);});
  });
  list.querySelectorAll('.umat-note-del-btn').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var id=parseInt(btn.dataset.del);
      if(confirm('Delete this note?'))deleteNote(id);
    });
  });
  list.querySelectorAll('.umat-note-pin-btn').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var id=parseInt(btn.dataset.pin);
      togglePin(id);
    });
  });
  list.querySelectorAll('.resume-session-btn').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var sk=btn.dataset.sk,cid=btn.dataset.cid;
      doResumeSession(sk,cid);
    });
  });
  list.querySelectorAll('.chat-note-btn').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var id=parseInt(btn.dataset.noteId);
      chatWithNote(id);
    });
  });
  /* Search */
  var searchInput=document.getElementById('ws-notes-search');
  if(searchInput){
    searchInput.addEventListener('input',function(){
      var q=this.value.toLowerCase().trim();
      list.querySelectorAll('.umat-note-card').forEach(function(c){
        var match=c.textContent.toLowerCase().indexOf(q)!==-1;
        c.style.display=match?'':'none';
      });
    });
  }
}
function renderCpNotes(notes){
  var pane=document.getElementById('cp-nt-mine');
  if(!pane)return;
  var mine=notes||[];
  if(!mine.length){
    pane.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">note_add</span><p>No notes yet. Tap + to create your first note!</p></div>';
    return;
  }
  pane.innerHTML=mine.slice(0,5).map(function(n){
    var preview=(n.content||'').replace(/<[^>]*>/g,'').substring(0,80);
    return '<div class="umat-cp-note-item" data-note-id="'+n.id+'">'+
      '<div class="umat-cp-note-title">'+_umatEsc(n.title||'Untitled Note')+'</div>'+
      '<div class="umat-cp-note-preview">'+_umatEsc(preview||'No content')+'</div>'+
    '</div>';
  }).join('');
  if(mine.length>5){
    pane.innerHTML+='<div class="umat-cp-note-more"><button class="umat-chip" onclick="switchToTab(\'my-notes\')" type="button">View all '+mine.length+' notes →</button></div>';
  }
  pane.querySelectorAll('.umat-cp-note-item').forEach(function(item){
    item.addEventListener('click',function(){openNoteEditor(parseInt(item.dataset.noteId));});
  });
}
function openNoteEditor(noteId){
  var existing=null;
  if(noteId){
    var notes=document.querySelectorAll('.umat-note-card');
    // Find note data from the rendered cards — we need it from the ajax call
    // Instead, fetch single note data on demand
  }
  renderNoteEditor(noteId);
}
function renderNoteEditor(noteId){
  var overlay=document.getElementById('umat-note-editor');
  if(overlay)overlay.remove();
  var div=document.createElement('div');
  div.id='umat-note-editor';
  div.className='umat-note-editor-overlay';
  div.innerHTML=
    '<div class="umat-note-editor">'+
      '<div class="umat-ne-hdr">'+
        '<h3>'+(noteId?'Edit Note':'New Note')+'</h3>'+
        '<button class="umat-ne-close" id="ne-close" type="button"><span class="material-symbols-outlined">close</span></button>'+
      '</div>'+
      '<div class="umat-ne-body">'+
        '<input type="text" class="umat-ne-input umat-ne-title-input" id="ne-title" placeholder="Note title\u2026">'+
        '<div class="umat-ne-toolbar" id="ne-toolbar">'+
          '<button class="umat-ne-tb-btn" data-cmd="bold" title="Bold (Ctrl+B)" type="button"><strong>B</strong></button>'+
          '<button class="umat-ne-tb-btn" data-cmd="italic" title="Italic (Ctrl+I)" type="button"><em>I</em></button>'+
          '<button class="umat-ne-tb-btn" data-cmd="underline" title="Underline (Ctrl+U)" type="button"><u>U</u></button>'+
          '<span class="umat-ne-tb-sep"></span>'+
          '<button class="umat-ne-tb-btn" data-cmd="formatBlock" data-val="h2" title="Heading" type="button">H<small>2</small></button>'+
          '<button class="umat-ne-tb-btn" data-cmd="formatBlock" data-val="h3" title="Subheading" type="button">H<small>3</small></button>'+
          '<span class="umat-ne-tb-sep"></span>'+
          '<button class="umat-ne-tb-btn" data-cmd="insertUnorderedList" title="Bullet list" type="button">\u2022</button>'+
          '<button class="umat-ne-tb-btn" data-cmd="insertOrderedList" title="Numbered list" type="button">1.</button>'+
          '<span class="umat-ne-tb-sep"></span>'+
          '<button class="umat-ne-tb-btn" data-cmd="createLink" title="Insert link" type="button">\u00b7\u00b7\u00b7</button>'+
        '</div>'+
        '<div class="umat-ne-content" id="ne-content" contenteditable="true" role="textbox" aria-multiline="true" placeholder="Write your note here\u2026"></div>'+
        '<div class="umat-ne-section">'+
          '<label class="umat-ne-label">Tags <span class="umat-ne-hint">— tag courses, materials, or chat sessions</span></label>'+
          '<div class="umat-ne-tags" id="ne-tags"></div>'+
          '<div class="umat-ne-tag-adder">'+
            '<select id="ne-tag-type" class="umat-ne-select">'+
              '<option value="course">Course</option>'+
              '<option value="material">Material</option>'+
              '<option value="session">Chat Session</option>'+
              '<option value="custom">Custom</option>'+
            '</select>'+
            '<select id="ne-tag-ref" class="umat-ne-select" style="flex:1;"></select>'+
            '<button class="umat-ne-add-tag-btn" id="ne-add-tag" type="button">Add</button>'+
          '</div>'+
        '</div>'+
        '<label class="umat-ne-pin-label">'+
          '<input type="checkbox" id="ne-pinned"> Pin to top'+
        '</label>'+
      '</div>'+
      '<div class="umat-ne-foot">'+
        '<button class="umat-ne-delete-btn" id="ne-delete-btn" type="button" style="display:none;">Delete Note</button>'+
        '<button class="umat-ne-save-btn" id="ne-save-btn" type="button"><span class="material-symbols-outlined">save</span>Save Note</button>'+
      '</div>'+
    '</div>';
  document.body.appendChild(div);
  setTimeout(function(){div.classList.add('open');},10);
  var closeBtn=document.getElementById('ne-close');
  if(closeBtn)closeBtn.addEventListener('click',function(){closeNoteEditor();});
  div.addEventListener('click',function(e){if(e.target===div)closeNoteEditor();});
  var tagType=document.getElementById('ne-tag-type');
  var tagRef=document.getElementById('ne-tag-ref');
  if(tagType&&tagRef){
    tagType.addEventListener('change',function(){populateTagRef(this.value);});
    populateTagRef(tagType.value);
  }
  var addTagBtn=document.getElementById('ne-add-tag');
  if(addTagBtn)addTagBtn.addEventListener('click',function(){addNoteTag();});
  var saveBtn=document.getElementById('ne-save-btn');
  if(saveBtn)saveBtn.addEventListener('click',function(){saveNoteFromEditor(noteId);});
  /* Formatting toolbar */
  document.querySelectorAll('#ne-toolbar .umat-ne-tb-btn').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.preventDefault();
      var cmd=btn.dataset.cmd,val=btn.dataset.val||null;
      if(cmd==='createLink'){
        var url=prompt('Enter link URL:','https://');
        if(url&&url.trim())document.execCommand('createLink',false,url.trim());
      }else{
        document.execCommand(cmd,false,val);
      }
      document.getElementById('ne-content').focus();
    });
  });
  /* Keyboard shortcuts for toolbar */
  document.getElementById('ne-content').addEventListener('keydown',function(e){
    if(e.ctrlKey||e.metaKey){
      var cmd=null;
      if(e.key==='b')cmd='bold';
      else if(e.key==='i')cmd='italic';
      else if(e.key==='u')cmd='underline';
      if(cmd){e.preventDefault();document.execCommand(cmd,false,null);}
    }
  });
  /* ESC close */
  document.addEventListener('keydown',function neEsc(e){
    if(e.key==='Escape'){closeNoteEditor();document.removeEventListener('keydown',neEsc);}
  });
  /* If editing, load existing note data */
  if(noteId){
    var _loadNoteData=function(note){
      if(!note)return;
      document.getElementById('ne-title').value=note.title;
      document.getElementById('ne-content').innerHTML=note.content;
      document.getElementById('ne-pinned').checked=!!note.pinned;
      var delBtn=document.getElementById('ne-delete-btn');
      if(delBtn){delBtn.style.display='';delBtn.addEventListener('click',function(){
        if(confirm('Delete this note?')){
          deleteNote(noteId);
          closeNoteEditor();
        }
      });}
      renderNoteTags(note.tags||[]);
    };
    var note=null;
    if(_notesCache){
      _notesCache.forEach(function(n){if(n.id===noteId)note=n;});
    }
    if(note){
      _loadNoteData(note);
    }else{
      ajax('local_umat_ai_get_notes',{courseid:0},
        function(r){
          var n=null;
          (r.notes||[]).forEach(function(x){if(x.id===noteId)n=x;});
          _loadNoteData(n);
        },
        function(){}
      );
    }
  } else {
    /* Pre-populate with current session context if applicable */
    var existingTags=[];
    if(selectedMats.length){
      selectedMats.forEach(function(m){
        existingTags.push({tag_type:'material',tag_id:m.id,tag_label:m.name,tag_value:''});
      });
    }
    if(sessionKey){
      var d=new Date();
      existingTags.push({tag_type:'session',tag_id:0,tag_label:'Current Session — '+d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}),tag_value:sessionKey});
    }
    if(existingTags.length)renderNoteTags(existingTags);
  }
}
function closeNoteEditor(){
  var overlay=document.getElementById('umat-note-editor');
  if(overlay){overlay.classList.remove('open');setTimeout(function(){overlay.remove();},300);}
}
var _editorTags=[]; /* Holds full tag objects for the editor */

function renderNoteTags(tags){
  _editorTags=(tags||[]).slice();
  renderEditorTagUI();
}
function renderEditorTagUI(){
  var cont=document.getElementById('ne-tags');
  if(!cont)return;
  if(!_editorTags.length){cont.innerHTML='';return;}
  cont.innerHTML=_editorTags.map(function(t,i){
    var icon='label';
    if(t.tag_type==='course')icon='menu_book';
    else if(t.tag_type==='material')icon='description';
    else if(t.tag_type==='session')icon='chat';
    return '<span class="umat-ne-tag" data-idx="'+i+'">'+
      '<span class="material-symbols-outlined" style="font-size:14px;">'+icon+'</span>'+
      _umatEsc(t.tag_label)+
      '<button class="umat-ne-tag-remove" data-idx="'+i+'" type="button">&times;</button></span>';
  }).join('');
  cont.querySelectorAll('.umat-ne-tag-remove').forEach(function(btn){
    btn.addEventListener('click',function(){
      var idx=parseInt(btn.dataset.idx);
      _editorTags.splice(idx,1);
      renderEditorTagUI();
    });
  });
}
function populateTagRef(type){
  var ref=document.getElementById('ne-tag-ref');
  if(!ref)return;
  if(type==='custom'){
    ref.innerHTML='<option value="">Type custom tag in Add</option>';
    return;
  }
  if(type==='course'){
    var courses=(userData&&userData.courses)||[];
    ref.innerHTML='<option value="">Select course\u2026</option>'+
      courses.map(function(c){return '<option value="'+c.id+'" data-label="'+_umatEsc(c.shortname||c.fullname)+'">'+_umatEsc(c.shortname||c.fullname)+'</option>';}).join('');
    return;
  }
  ajax('local_umat_ai_get_note_tag_sources',{courseid:courseId},
    function(r){
      var items=type==='material'?(r.materials||[]):(r.sessions||[]);
      ref.innerHTML='<option value="">Select '+type+'\u2026</option>'+
        items.map(function(item){
          var label=item.label||item.filename||'Item';
          var val=type==='session'?item.value:(item.id||0);
          return '<option value="'+_umatEsc(''+val)+'" data-label="'+_umatEsc(label)+'">'+_umatEsc(label)+'</option>';
        }).join('');
    },
    function(){ref.innerHTML='<option value="">Error loading options</option>';}
  );
}
function addNoteTag(){
  var typeEl=document.getElementById('ne-tag-type');
  var refEl=document.getElementById('ne-tag-ref');
  var type=typeEl?typeEl.value:'custom';
  var refVal=refEl?refEl.value:'';
  if(type!=='custom'&&!refVal){alert('Please select a '+type+' to tag.');return;}
  var tagsCont=document.getElementById('ne-tags');
  if(!tagsCont)return;
  var label='';
  var tagId=0;
  var tagValue='';
  if(type==='custom'){
    label=prompt('Enter custom tag label:');
    if(!label||!label.trim())return;
    label=label.trim();
  } else {
    if(refEl){
      var opt=refEl.options[refEl.selectedIndex];
      if(opt){
        label=opt.getAttribute('data-label')||opt.textContent;
        tagId=type==='session'?0:parseInt(refVal)||0;
        tagValue=type==='session'?refVal:'';
      }
    }
  }
  if(!label)return;
  /* Check duplicate */
  var dup=false;
  _editorTags.forEach(function(t){if(t.tag_label===label&&t.tag_type===type)dup=true;});
  if(dup)return;
  _editorTags.push({tag_type:type,tag_id:tagId,tag_label:label,tag_value:tagValue});
  renderEditorTagUI();
}
function iconForTagType(type){
  if(type==='course')return 'menu_book';
  if(type==='material')return 'description';
  if(type==='session')return 'chat';
  return 'label';
}
function saveNoteFromEditor(noteId){
  var title=document.getElementById('ne-title');
  var content=document.getElementById('ne-content');
  var pinned=document.getElementById('ne-pinned');
  if(!title||!content)return;
  var contentHtml=content.innerHTML;
  /* Ensure current session_key is set on session-type tags */
  var curSessionKey=sessionKey||'';
  _editorTags.forEach(function(t){
    if(t.tag_type==='session'&&curSessionKey&&!t.tag_value)t.tag_value=curSessionKey;
  });
  ajax('local_umat_ai_save_note',{
    noteid:noteId||0,
    courseid:courseId,
    title:title.value,
    content:contentHtml,
    pinned:!!pinned.checked,
    tags:_editorTags
  },
  function(r){
    if(r.saved){
      closeNoteEditor();
      loadNotesAndRender();
    }
  },
  function(){alert('Failed to save note. Try again.');}
  );
}
function deleteNote(id){
  ajax('local_umat_ai_delete_note',{noteid:id},
    function(r){
      if(r.deleted)loadNotesAndRender();
    },
    function(){alert('Failed to delete note.');}
  );
}
function togglePin(id){
  /* Fetch current note data, toggle pin, save */
  ajax('local_umat_ai_get_notes',{courseid:0},
    function(r){
      var note=null;
      (r.notes||[]).forEach(function(n){if(n.id===id)note=n;});
      if(!note)return;
      ajax('local_umat_ai_save_note',{
        noteid:note.id,
        courseid:note.courseid,
        title:note.title,
        content:note.content,
        pinned:!note.pinned,
        tags:(note.tags||[]).map(function(t){return {tag_type:t.tag_type,tag_id:t.tag_id,tag_label:t.tag_label,tag_value:t.tag_value};})
      },
      function(r2){if(r2.saved)loadNotesAndRender();},
      function(){}
      );
    },
    function(){}
  );
}
function doResumeSession(sk,cid){
  _clearNoteCtx();
  sessionKey=sk;
  courseId=parseInt(cid)||courseId;
  switchToTab('ai-tutor');
  var msgs=document.getElementById('ws-msgs');
  if(!msgs)return;
  msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p><em>Loading conversation history\u2026</em></p></div></div></div>';
  require(['core/ajax'],function(A){
    A.call([{methodname:'local_umat_ai_get_chat_history',args:{courseid:courseId,session_key:sk,limit:50}}])[0]
      .done(function(r){
        msgs.innerHTML='';
        (r.messages||[]).forEach(function(msg){
          if(msg.question)_umatAppendUser('ws-msgs',msg.question);
          if(msg.answer)_umatAppendAi('ws-msgs',msg.answer,msg.sources||[]);
        });
        if(!(r.messages||[]).length){
          msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Welcome back! This session had no previous messages. Ask me anything!</p></div></div></div>';
        }
      }).fail(function(){
        msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Welcome back! I\'m ready to continue where we left off.</p></div></div></div>';
      });
  });
}
function chatWithNote(noteId){
  ajax('local_umat_ai_get_notes',{courseid:0},
    function(r){
      var note=null;
      (r.notes||[]).forEach(function(n){if(n.id===noteId)note=n;});
      if(!note){alert('Note not found.');return;}
      newSession();
      _clearNoteCtx();
      var plainContent=(note.content||'').replace(/<[^>]*>/g,'').replace(/&nbsp;/g,' ').replace(/&amp;/g,'&').trim();
      var contextMsg='[Referencing my note: "'+(note.title||'Untitled')+'"]\\n\\n'+(plainContent||'(empty)');
      /* Add visible note chip to ws-chips */
      var chipsBar=document.getElementById('ws-chips');
      if(chipsBar){
        var chip=document.createElement('button');
        chip.className='umat-chip umat-chip-note';
        chip.type='button';
        chip.innerHTML='<span class="material-symbols-outlined" style="font-size:14px;margin-right:4px;">description</span>Note: '+_umatEsc(note.title||'Untitled')+'<span class="umat-chip-remove" style="margin-left:6px;cursor:pointer;font-weight:bold;font-size:16px;">&times;</span>';
        chip.querySelector('.umat-chip-remove').addEventListener('click',function(e){
          e.stopPropagation();
          _clearNoteCtx();
          /* Rebuild first AI message without note context */
          var m=document.getElementById('ws-msgs');
          if(m)m.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Note reference removed. Starting a fresh session! Ask me anything about your course materials.</p></div></div></div>';
        });
        chipsBar.insertBefore(chip,chipsBar.firstChild);
      }
      /* Attach note materials to reference selection */
      _noteMats=[];
      if(note.tags){
        note.tags.forEach(function(t){
          if(t.tag_type==='material'&&t.tag_id){
            var mid=t.tag_id;
            if(!selectedMats.some(function(sm){return sm.id===mid;})){
              selectedMats.push({id:mid,name:t.tag_label});
              _noteMats.push(mid);
            }
          }
        });
      }
      if(_noteMats.length){
        _umatRenderMatsBar('ws-mat-bar','ws-attach-btn',selectedMats,function(id){selectedMats=selectedMats.filter(function(s){return s.id!=id;});return selectedMats;});
      }
      /* Set chat welcome message */
      var msgs=document.getElementById('ws-msgs');
      if(msgs){
        msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>I\'ve loaded your note <strong>"'+_umatEsc(note.title||'Untitled')+'"</strong>. Ask me anything about it or expand on its content!</p></div></div></div>';
      }
      var cpMsgs=document.getElementById('cp-msgs');
      if(cpMsgs){
        cpMsgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Note loaded! Ask me about <strong>"'+_umatEsc(note.title||'Untitled')+'"</strong>.</p></div></div></div>';
      }
      window._noteContext=contextMsg;
      switchToTab('ai-tutor');
    },
    function(){alert('Could not load note.');}
  );
}

/* ---- Init on page load ---- */
populateHomeTab();
/* Expose player & course functions globally so shared yt-grid renderers can call them */
window.openVideoPlayer=openVideoPlayer;
window.openPdfViewer=openPdfViewer;
window.selectCourse=selectCourse;

/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'ws-attach-drawer',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}},
  {id:'umat-note-editor',isOpen:function(e){return e.classList.contains('open');},close:function(e){closeNoteEditor();}},
  {id:'umat-student-ov',isOpen:function(e){return e.classList.contains('open');},close:closeOverlay},
  {id:'stu-cp-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);
/* Override sendQuestion to include note context if _noteContext is set */
var _origSendQ=sendQuestion;
sendQuestion=function(q,msgsId){
  if(window._noteContext){
    q='[Note Context]\\n'+window._noteContext+'\\n\\n---\\n\\n'+q;
    window._noteContext=null;
  }
  return _origSendQ(q,msgsId);
};

})();
});
</script>
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
      <button class="umat-cp-tab active" data-lcp-tab="lcp-insights" type="button">Insights</button>
      <button class="umat-cp-tab" data-lcp-tab="lcp-questions" type="button">Questions</button>
      <button class="umat-cp-tab" data-lcp-tab="lcp-ai" type="button">Ask AI</button>
    </div>
    <div class="umat-cp-feature-tabs" aria-label="Quick lecturer tools" role="tablist">
      <button class="umat-cp-feature-tab active" data-lcp-pane="lcp-insights" type="button"><span class="material-symbols-outlined">insights</span><span>Insights</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-questions" type="button"><span class="material-symbols-outlined">forum</span><span>Questions</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-ai" type="button"><span class="material-symbols-outlined">smart_toy</span><span>Ask AI</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-analytics" type="button"><span class="material-symbols-outlined">bar_chart</span><span>Analytics</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-struggle" type="button"><span class="material-symbols-outlined">psychology</span><span>Struggle</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span>Courses</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-library" type="button"><span class="material-symbols-outlined">local_library</span><span>Library</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-sessions" type="button"><span class="material-symbols-outlined">history</span><span>Sessions</span></button>
      <button class="umat-cp-feature-tab" data-lcp-open="lec-review" type="button"><span class="material-symbols-outlined">fact_check</span><span>Review</span></button>
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
        <div class="umat-input-row">
          <textarea id="lcp-input" class="umat-textarea" placeholder="Ask about your course…" rows="2" maxlength="700"></textarea>
          <button class="umat-send-btn" id="lcp-send" type="button"><span class="material-symbols-outlined">send</span></button>
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
      </nav>
      <div class="umat-sb-divider"></div>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'">
          <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
      </div>
    </div>

    {$lecMobTabs}

    <!-- CONTENT -->
    <div class="umat-ov-content">

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
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">bar_chart</span> Analytics — <span id="lec-an-course-label">{$safe}</span></h2>
          <button class="umat-content-hdr-btn" id="lec-an-export" type="button" onclick="window.print()"><span class="material-symbols-outlined">download</span>Export</button>
        </div>
        <div class="umat-an-scroll" id="lec-an-body">
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

      <!-- STRUGGLE INSIGHTS -->
      <div class="umat-tab-pane" id="lec-struggle" style="overflow-y:auto;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">psychology</span> Struggle Insights — <span id="stru-course-label">{$safe}</span></h2>
          <div style="display:flex;gap:8px;align-items:center;">
            <span class="umat-pill pill-info" id="stru-mode-badge">PHP Engine</span>
          </div>
        </div>
        <div id="stru-loading" class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading struggle insights…</p></div>
        <div id="stru-error" class="umat-empty" style="display:none;"><span class="material-symbols-outlined">error_outline</span><p id="stru-error-text">Could not load struggle insights.</p></div>
        <div id="stru-content" style="display:none;padding:0 20px 20px;">

          <!-- Summary bar -->
          <div class="struggle-summary-bar">
            <span><strong id="stru-total-q">0</strong> questions from <strong id="stru-total-s">0</strong> students</span>
            <span class="struggle-worst-topic">Worst topic: <strong id="stru-worst">—</strong></span>
          </div>

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
      <div class="umat-input-area" style="padding:8px 12px;border-top:1px solid var(--u-olv);">
        <div class="umat-input-row">
          <input type="text" id="lec-mini-input" placeholder="Ask about analytics…" style="flex:1;padding:8px 11px;border:1.5px solid var(--u-olv);border-radius:var(--u-r8);font-size:13px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sf);">
          <button class="umat-send-btn" id="lec-mini-send" type="button" style="width:36px;height:36px;"><span class="material-symbols-outlined">send</span></button>
        </div>
      </div>
    </div>
  </div><!-- /ov-body -->
</div>

{$sharedJs}

<script>
window._umatSharedReady.then(function() {
/* ============================================================
   LECTURER OVERLAY — self-contained IIFE
   ============================================================ */
(function(){
'use strict';
var CID   = {$jsCid};
var CN    = {$jsName};
var UID   = {$uid};
var UD    = {$jsUD};
var streamUrl = {$streamUrl};
var moodleSesskey = {$moodleSesskey};
var anLoaded = {};
var lecLoaded= {};
var struggleCache = {};


/* ─── LECTURER COURSE TILES ────────────────── */
function renderLecCourses(courses,g){
  if(!g){g=document.getElementById('lec-courses-grid');}
  if(!g)return;
  courses=courses||[];
  if(!courses.length){
    g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>No courses assigned.</p></div>';
    return;
  }
  g.className='yt-grid';
  g.innerHTML=courses.map(function(c){
    var pending=c.pending_count||0;
    var enrolled=c.enrolled_count||0;
    var sessions=c.session_count||0;
    var badge=pending>0?'<span class="yt-badge" style="background:var(--u-ter);">'+pending+' pending</span>':'';
    return'<div class="yt-tile" data-cid="'+c.id+'" data-cname="'+esc(c.fullname||'')+'">'+
      '<div class="yt-thumb yt-bg-course">'+
        '<div class="yt-course-ov">'+
          '<div class="yt-course-code">'+esc(c.shortname||'')+'</div>'+
          '<div class="yt-course-name">'+esc(c.fullname||'')+'</div>'+
        '</div>'+
        badge+
      '</div>'+
      '<div class="yt-meta">'+
        '<div class="yt-av yt-av-course"><span class="material-symbols-outlined">bar_chart</span></div>'+
        '<div class="yt-text">'+
          '<h4 class="yt-title">'+esc(c.fullname||'')+'</h4>'+
          '<p class="yt-channel">'+esc(c.shortname||'')+(enrolled?' · '+enrolled+' students':'')+'</p>'+
          '<p class="yt-stats">'+sessions+' sessions'+(pending>0?' · '+pending+' outputs pending':'')+'</p>'+
        '</div>'+
      '</div>'+
      '<div class="yt-actions">'+
        '<button class="yt-btn" data-act="analytics" onclick="event.stopPropagation()"><span class="material-symbols-outlined">bar_chart</span>Analytics</button>'+
        '<button class="yt-btn" data-act="library" onclick="event.stopPropagation()"><span class="material-symbols-outlined">local_library</span>Library</button>'+
        (pending>0?'<button class="yt-btn" data-act="review" onclick="event.stopPropagation()" style="border-color:var(--u-ter);color:var(--u-ter);"><span class="material-symbols-outlined">fact_check</span>Review</button>':'')+
      '</div>'+
    '</div>';
  }).join('');

  /* Tile body click → analytics */
  g.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('[data-act]'))return;
      CID=parseInt(tile.dataset.cid)||CID;CN=tile.dataset.cname||CN;
      var lbl=document.getElementById('lec-an-course-label');if(lbl)lbl.textContent=CN;
      var ctx=document.getElementById('lec-ctx-label');if(ctx)ctx.textContent=CN;
      anLoaded[CID]=false;
      switchPane('lec-analytics');loadAnalytics(CID);
    });
    /* Action buttons */
    tile.querySelectorAll('[data-act]').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.stopPropagation();
        CID=parseInt(tile.dataset.cid)||CID;CN=tile.dataset.cname||CN;
        var lbl=document.getElementById('lec-an-course-label');if(lbl)lbl.textContent=CN;
        var ctx=document.getElementById('lec-ctx-label');if(ctx)ctx.textContent=CN;
        var act=btn.dataset.act;
        if(act==='analytics'){anLoaded[CID]=false;switchPane('lec-analytics');loadAnalytics(CID);}
        else if(act==='library'){lecLoaded['lec-library']=false;switchPane('lec-library');loadLibrary();}
        else if(act==='review'){lecLoaded['lec-review']=false;switchPane('lec-review');if(typeof loadReviewPane==='function')loadReviewPane();}
      });
    });
  });
  var srch=document.getElementById('lec-courses-search')||document.getElementById('lec-courses-srch');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    g.querySelectorAll('.yt-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});
  });
}

/* FAB / panel / overlay */
var fab=document.getElementById('lec-fab');
var cpOv=document.getElementById('lec-cp-ov');
var lecOv=document.getElementById('lec-ov');
var cpClose=document.getElementById('lec-cp-close');
var ovClose=document.getElementById('lec-ov-close');
var expand=document.getElementById('lec-expand');
var panelDataLoaded=false;

function openPanel(){cpOv.classList.add('open');fab.setAttribute('aria-expanded','true');if(!panelDataLoaded){loadPanelData();panelDataLoaded=true;}}
function closePanel(){cpOv.classList.remove('open');fab.setAttribute('aria-expanded','false');}
function openDash(){closePanel();lecOv.classList.add('open');if(!anLoaded[CID]){loadAnalytics(CID);}}
function closeDash(){lecOv.classList.remove('open');openPanel();}

if(fab)fab.addEventListener('click',openPanel);
if(cpClose)cpClose.addEventListener('click',closePanel);
if(cpOv)cpOv.addEventListener('click',function(e){if(e.target===cpOv)closePanel();});
if(expand)expand.addEventListener('click',openDash);
if(ovClose)ovClose.addEventListener('click',closeDash);
if(lecOv)lecOv.addEventListener('click',function(e){if(e.target===lecOv)closeDash();});
var dashBtn=document.getElementById('lcp-dash-btn');if(dashBtn)dashBtn.addEventListener('click',openDash);
var openDashBtn=document.getElementById('lcp-open-dash');if(openDashBtn)openDashBtn.addEventListener('click',openDash);

/* Compact panel tabs */
function showLcpPane(t){
  document.querySelectorAll('[data-lcp-tab]').forEach(function(x){x.classList.toggle('active',x.dataset.lcpTab===t);});
  document.querySelectorAll('#lec-cp [data-lcp-pane]').forEach(function(x){x.classList.toggle('active',x.dataset.lcpPane===t);});
  document.querySelectorAll('#lec-cp .umat-cp-pane').forEach(function(x){x.classList.toggle('active',x.id===t);});
}
document.querySelectorAll('[data-lcp-tab]').forEach(function(b){
  b.addEventListener('click',function(){showLcpPane(b.dataset.lcpTab);});
});
document.querySelectorAll('#lec-cp [data-lcp-pane]').forEach(function(b){
  b.addEventListener('click',function(){showLcpPane(b.dataset.lcpPane);});
});
document.querySelectorAll('#lec-cp [data-lcp-open]').forEach(function(b){
  b.addEventListener('click',function(){renderLcpFeature(b.dataset.lcpOpen);});
});
function setLcpFeatureActive(name){
  document.querySelectorAll('#lec-cp [data-lcp-pane]').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('#lec-cp [data-lcp-open]').forEach(function(b){b.classList.toggle('active',b.dataset.lcpOpen===name);});
}
function renderLcpFeature(name){
  var meta={
    'lec-analytics':['bar_chart','Analytics','Course performance'],'lec-struggle':['psychology','Struggle','Learning gaps'],'lec-courses':['menu_book','Courses','Your teaching courses'],'lec-library':['local_library','Library','Course materials'],'lec-sessions':['history','Sessions','AI interaction history'],'lec-review':['fact_check','Review','Pending AI outputs']
  }[name]||['widgets','Feature','Quick view'];
  showLcpPane('lcp-feature');setLcpFeatureActive(name);
  document.getElementById('lcp-feature-icon').textContent=meta[0];document.getElementById('lcp-feature-title').textContent=meta[1];document.getElementById('lcp-feature-sub').textContent=meta[2];
  var body=document.getElementById('lcp-feature-body');body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading '+meta[1].toLowerCase()+'…</p></div>';
  if(name==='lec-courses')return renderLcpCourses(body);
  if(name==='lec-review')return renderLcpReview(body);
  if(name==='lec-analytics'||name==='lec-struggle')return renderLcpAnalytics(body,name);
  if(name==='lec-library')return renderLcpLibrary(body);
  if(name==='lec-sessions')return renderLcpSessions(body);
}
function renderLcpCourses(body){var courses=(UD&&UD.courses)||[];if(!courses.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses found.</p></div>';return;}body.innerHTML=courses.map(function(c){return '<button class="umat-cp-list-card as-btn" data-cid="'+c.id+'" data-name="'+esc(c.fullname||'')+'" type="button"><strong>'+esc(c.shortname||c.fullname)+'</strong><p>'+esc(c.fullname||'')+'</p></button>';}).join('');body.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){CID=parseInt(b.dataset.cid)||CID;CN=b.dataset.name||CN;renderLcpFeature('lec-analytics');});});}
function renderLcpAnalytics(body,name){if(!CID){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>Open a course page to view analytics.</p></div>';return;}ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(d){if(name==='lec-struggle'){body.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">psychology</span><strong>'+esc(d.struggle_index||'N/A')+'</strong><small>struggle index</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">forum</span><strong>'+((d.top_questions||[]).length)+'</strong><small>top questions</small></div></div>'+((d.top_questions||[]).slice(0,6).map(function(q){return '<div class="umat-cp-list-card"><strong>'+esc(q.text)+'</strong><p>'+q.ask_count+' students asked</p></div>';}).join('')||'<div class="umat-empty"><span class="material-symbols-outlined">check_circle</span><p>No struggle questions yet.</p></div>');return;}body.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">group</span><strong>'+d.active_students+'/'+d.enrolled_students+'</strong><small>active students</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">forum</span><strong>'+d.total_interactions+'</strong><small>AI interactions</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">psychology</span><strong>'+esc(d.struggle_index||'N/A')+'</strong><small>struggle index</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">timer</span><strong>'+esc(d.avg_questions_per_session||'0')+'</strong><small>avg Q/session</small></div></div>';},function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load analytics.</p></div>';});}
function renderLcpLibrary(body){var courses=(UD&&UD.courses)||[];body.innerHTML=(courses.length?'<p class="umat-cp-help">Choose a course to view materials in this panel.</p>'+courses.slice(0,10).map(function(c){return '<button class="umat-cp-list-card as-btn" data-cid="'+c.id+'" type="button"><strong>'+esc(c.shortname||c.fullname)+'</strong><p>'+esc(c.fullname||'')+'</p></button>';}).join(''):'<div class="umat-empty"><span class="material-symbols-outlined">local_library</span><p>No courses available.</p></div>');body.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){var cid=parseInt(b.dataset.cid);body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>';ajax('local_umat_ai_get_course_materials',{courseid:cid},function(r){var mats=r.materials||[];body.innerHTML=mats.length?mats.slice(0,12).map(function(m){return '<div class="umat-cp-list-card"><strong>'+esc(m.filename||m.name||'Material')+'</strong><p>'+esc(m.mimetype||m.type||'Course material')+'</p></div>';}).join(''):'<div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>No materials for this course.</p></div>';},function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load materials.</p></div>';});});});}
function renderLcpSessions(body){var courses=(UD&&UD.courses)||[];body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>Select a course from Courses, then ask AI about session history in this panel.</p></div>'+(courses.length?courses.slice(0,6).map(function(c){return '<button class="umat-cp-list-card as-btn" data-cid="'+c.id+'" type="button"><strong>'+esc(c.shortname||c.fullname)+'</strong><p>Use this course context</p></button>';}).join(''):'');body.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){CID=parseInt(b.dataset.cid)||CID;showLcpPane('lcp-ai');});});}
function renderLcpReview(body){body.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">pending_actions</span><strong>{$pending}</strong><small>pending outputs</small></div></div><div class="umat-cp-list-card"><strong>Review workflow</strong><p>Use the full review table for approvals. You can ask AI for a summary here first.</p><button class="umat-btn-p" type="button" id="lcp-review-ask"><span class="material-symbols-outlined">smart_toy</span>Summarise pending work</button></div>';var ask=document.getElementById('lcp-review-ask');if(ask)ask.addEventListener('click',function(){showLcpPane('lcp-ai');document.getElementById('lcp-input').value='Summarise pending AI outputs for this course.';document.getElementById('lcp-send').click();});}
var lcpMsgs=document.getElementById('lcp-msgs');
if(lcpMsgs)lcpMsgs.addEventListener('click',function(e){
  var chip=e.target.closest('.umat-chip[data-lp]');
  if(chip){switchToAI(chip.dataset.lp);}
});
function switchToAI(q){
  showLcpPane('lcp-ai');
  if(q){document.getElementById('lcp-input').value=q;document.getElementById('lcp-send').click();}
}

/* Sidebar & mobile tab pane switching */
function switchPane(name){
  document.querySelectorAll('#lec-ov .umat-tab-pane').forEach(function(p){p.classList.remove('active');});
  document.querySelectorAll('#lec-sb [data-lp], #lec-glass-tabs [data-lp]').forEach(function(b){b.classList.toggle('active',b.dataset.lp===name);});
  var pane=document.getElementById(name);if(pane)pane.classList.add('active');
  if(!lecLoaded[name]){lecLoaded[name]=true;loadPaneData(name);}
}
/* Handle data-lp clicks from compact panel → open full overlay */
document.querySelectorAll('#lec-cp [data-lp^="lec-"]').forEach(function(b){
  b.addEventListener('click',function(){closePanel();openDash();switchPane(b.dataset.lp);});
});
document.querySelectorAll('#lec-sb [data-lp], #lec-glass-tabs [data-lp]').forEach(function(b){
  b.addEventListener('click',function(){switchPane(b.dataset.lp);});
});
document.addEventListener('click',function(e){
  var btn=e.target.closest('[data-lp]');
  if(btn && btn.closest('#lec-home')){switchPane(btn.dataset.lp);}
});

/* Home init */
function initHome(){
  if(!CID)return;
  var d=new Date(),dEl=document.getElementById('lec-home-date');
  if(dEl)dEl.textContent=d.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  /* Use panel data if already loaded */
  if(panelDataLoaded)return;
  ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(data){
    var ms=document.getElementById('lec-met-active');var mi=document.getElementById('lec-met-int');
    if(ms)ms.textContent=data.active_students+'/'+data.enrolled_students;
    if(mi)mi.textContent=data.total_interactions.toLocaleString();
  },function(){});
}

function loadPaneData(name){
  if(name==='lec-analytics')loadAnalytics(CID);
  if(name==='lec-struggle')loadStruggleInsights(CID);
  if(name==='lec-courses')loadLecturerCourses();
  if(name==='lec-library'){populateLibCourseSel();loadLibrary();}
  if(name==='lec-sessions'){populateSessCourseSel();loadSessions();}
  if(name==='lec-review')loadReviewPane();
  if(name==='lec-home')initHome();
}

/* Refresh review pane */
var reviewRefresh=document.getElementById('lec-review-refresh');
if(reviewRefresh)reviewRefresh.addEventListener('click',loadReviewPane);

/* Load panel (compact) data */
function loadPanelData(){
  if(!CID){return;}
  ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(d){
    var s=function(id,v){var e=document.getElementById(id);if(e)e.textContent=v;};
    s('lcp-k-active',d.active_students+'/'+d.enrolled_students);
    s('lcp-k-active-b',Math.round(d.active_students/Math.max(d.enrolled_students,1)*100)+'% active');
    s('lcp-k-int',d.total_interactions.toLocaleString());
    s('lcp-k-str',d.struggle_index);
    if(d.struggle_index!=='N/A'){
      s('lcp-gap-title','Learning Gap: '+d.struggle_index);
      s('lcp-gap-desc','Students ask the most questions in '+d.struggle_index+'. Consider a targeted review session.');
    }
    var ms=document.getElementById('lec-met-active');var mi=document.getElementById('lec-met-int');
    if(ms)ms.textContent=d.active_students+'/'+d.enrolled_students;
    if(mi)mi.textContent=d.total_interactions.toLocaleString();
    /* Top questions */
    var ql=document.getElementById('lcp-q-list');
    if(ql&&d.top_questions&&d.top_questions.length){
      ql.innerHTML=d.top_questions.slice(0,5).map(function(q){
        return '<div style="padding:8px;background:var(--u-sf);border:1px solid var(--u-olv);border-radius:var(--u-r8);">'+
          '<div style="font-size:12px;color:var(--u-ons);margin-bottom:3px;">'+esc(q.text)+'</div>'+
          '<div style="font-size:10px;color:var(--u-ol);"><b style="color:var(--u-p);">'+q.ask_count+'</b> students asked</div></div>';
      }).join('');
    }
  },function(){});
}

/* Analytics load & render */
function loadAnalytics(cid){
  anLoaded[cid]=true;
  var label=document.getElementById('lec-an-course-label');
  if(!cid){if(label)label.textContent='Go to a course page to view analytics';return;}
  document.getElementById('lec-an-course-label').textContent=cid===CID?CN:'Loading…';
  ajax('local_umat_ai_get_analytics',{courseid:cid,days:30},function(d){
    /* KPI cards */
    var s=function(id,v){var e=document.getElementById(id);if(e)e.textContent=v;};
    s('an-v-active',d.active_students+' / '+d.enrolled_students);
    s('an-s-active','of '+d.enrolled_students+' enrolled');
    s('an-pill-active',Math.round(d.active_students/Math.max(d.enrolled_students,1)*100)+'% active');
    s('an-v-time',d.avg_questions_per_session+' Q');
    s('an-v-str',d.struggle_index);
    s('an-v-int',d.total_interactions.toLocaleString());
    s('an-pill-int','+'+d.total_interactions);
    /* Chart */
    drawChart(d.daily_counts,d.max_daily||1);
    /* Performance */
    var tot=Math.max(d.enrolled_students,1);
    var h=d.high_performers||0,risk=Math.max(0,d.enrolled_students-d.active_students),track=Math.max(0,d.active_students-h);
    s('an-p-high',h+' students');s('an-p-track',track+' students');s('an-p-risk',risk+' students');
    setTimeout(function(){
      var pb=function(id,n,tot){var e=document.getElementById(id);if(e)e.style.width=Math.min(100,Math.round(n/tot*100))+'%';};
      pb('an-pb-high',h,tot);pb('an-pb-track',track,tot);pb('an-pb-risk',risk,tot);
    },300);
    /* Heatmap */
    buildHeatmap(d.daily_counts,d.max_daily||1,d.struggle_index);
    /* Questions */
    var badge=document.getElementById('an-q-badge');if(badge)badge.textContent='Aggregation of '+d.total_interactions+'+ chats';
    var qList=document.getElementById('an-q-list');
    if(qList){
      if(!d.top_questions||!d.top_questions.length){qList.innerHTML='<div style="text-align:center;padding:32px;color:var(--u-ol);font-size:13px;">No questions logged yet.</div>';return;}
      var acts=['Prepare Response','Generate AI Summary','Add to FAQ','Create Quiz','Schedule Review'];
      qList.innerHTML=d.top_questions.map(function(q,i){
        return '<div class="umat-q-row">'+
          '<div class="umat-q-votes"><div class="v-n">'+q.ask_count+'</div><div class="v-l">votes</div></div>'+
          '<div class="umat-q-content"><div class="umat-q-text">&ldquo;'+esc(q.text)+'&rdquo;</div><div class="umat-q-related">Related to: <span>Course Materials</span></div></div>'+
          '<div class="umat-q-action"><button class="umat-q-action-btn" type="button">'+esc(acts[i%acts.length])+'</button></div></div>';
      }).join('');
    }
  },function(){var s=document.getElementById('an-v-active');if(s)s.textContent='Error';});
}

/* Bar chart */
function drawChart(daily,maxV){
  var canvas=document.getElementById('an-chart');if(!canvas||!daily||!daily.length)return;
  var ctx=canvas.getContext('2d');
  var W=canvas.offsetWidth||600,H=180;canvas.width=W;canvas.height=H;
  var n=daily.length,pad={l:28,r:8,t:16,b:24};
  var cW=W-pad.l-pad.r,cH=H-pad.t-pad.b;
  var bW=Math.max(6,(cW/n)*0.5),bW2=bW*0.55;
  var labDiv=document.getElementById('an-chart-labels');if(labDiv)labDiv.innerHTML='';
  ctx.clearRect(0,0,W,H);
  [.25,.5,.75,1].forEach(function(f){
    var y=pad.t+cH*(1-f);ctx.strokeStyle='#e5e7eb';ctx.lineWidth=1;
    ctx.beginPath();ctx.moveTo(pad.l,y);ctx.lineTo(pad.l+cW,y);ctx.stroke();
    ctx.fillStyle='#9ca3af';ctx.font='10px Inter,sans-serif';ctx.textAlign='right';
    ctx.fillText(Math.round(maxV*f),pad.l-3,y+3);
  });
  ctx.strokeStyle='#d1d5db';ctx.lineWidth=1;
  ctx.beginPath();ctx.moveTo(pad.l,pad.t+cH);ctx.lineTo(pad.l+cW,pad.t+cH);ctx.stroke();
  daily.forEach(function(d,i){
    var x=pad.l+(i/n)*cW+((cW/n)-bW-bW2-2)/2;
    var bH=Math.max(2,(d.count/maxV)*cH),y=pad.t+cH-bH;
    var g=ctx.createLinearGradient(0,y,0,pad.t+cH);g.addColorStop(0,'#00873d');g.addColorStop(1,'#006b2f');
    ctx.fillStyle=g;ctx.beginPath();
    if(ctx.roundRect){ctx.roundRect(x,y,bW,bH,[3,3,0,0]);}else{ctx.rect(x,y,bW,bH);}ctx.fill();
    var qH=Math.max(2,bH*0.38),qY=pad.t+cH-qH;
    ctx.fillStyle='rgba(190,239,193,.85)';ctx.beginPath();
    if(ctx.roundRect){ctx.roundRect(x+bW+2,qY,bW2,qH,[2,2,0,0]);}else{ctx.rect(x+bW+2,qY,bW2,qH);}ctx.fill();
    ctx.fillStyle='#6b7280';ctx.font='10px Inter,sans-serif';ctx.textAlign='center';
    ctx.fillText(d.label||'',x+bW/2,pad.t+cH+16);
  });
}

/* Heatmap */
function buildHeatmap(daily,maxV,struggleIdx){
  var grid=document.getElementById('an-hm-grid');if(!grid)return;
  var days=['Mon','Tue','Wed','Thu','Fri'];
  var n=Math.min(10,daily.length);
  if(!n){grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">No heatmap data yet.</div>';return;}
  grid.style.gridTemplateColumns='40px repeat('+n+',1fr)';
  var html='<div></div>';
  for(var c=0;c<n;c++)html+='<div style="font-size:9px;color:var(--u-ol);text-align:center;padding-bottom:4px;">L'+(c+1)+'</div>';
  days.forEach(function(day,row){
    html+='<div class="umat-hm-row-lbl">'+day+'</div>';
    for(var col=0;col<n;col++){
      var base=daily[col]?daily[col].count:0;
      var va=[1,.8,1.2,.6,.9][row]*[1,.7,1.1,.85,.95,.6,1.3,.8,.75,1][col%10];
      var val=Math.round(base*va*.5);var pct=val/(maxV||1);
      var bg=pct<.15?'#dbeafe':pct<.4?'#93c5fd':pct<.7?'#4ade80':'var(--u-p)';
      var color=pct>=.7?'#fff':'rgba(0,0,0,.5)';
      html+='<div class="umat-hm-cell" style="background:'+bg+';color:'+color+';" title="'+day+' · L'+(col+1)+': '+val+'">'+(val>0?val:'')+'</div>';
    }
  });
  grid.innerHTML=html;
  if(struggleIdx&&struggleIdx!=='N/A'){
    var ins=document.getElementById('an-insight');var t=document.getElementById('an-insight-title');var desc=document.getElementById('an-insight-desc');
    if(ins&&t&&desc){ins.style.display='flex';t.textContent='AI Insight: Complex Concept Detected';desc.textContent='Students are spending significantly more time on '+struggleIdx+'. Consider scheduling a recap session.';}
  }
}

/* Lecturer courses (from preloaded UD.courses, fallback AJAX) */
function loadLecturerCourses(){
  var g=document.getElementById('lec-courses-grid');
  if(UD&&UD.courses&&UD.courses.length){renderLecCourses(UD.courses,g);return;}
  ajax('local_umat_ai_get_my_courses',{role:'lecturer'},function(r){renderLecCourses(r.courses||[],g);},function(){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load courses.</p></div>';});
}

/* Library — with course overlay selector */
var lecLibCourseId = 0;
function populateLibCourseSel(){
  var list=document.getElementById('lec-lib-cs-list');
  if(!list||!UD||!UD.courses)return;
  list.innerHTML=UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">menu_book</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  list.querySelectorAll('.umat-cs-item').forEach(function(btn){
    btn.addEventListener('click',function(){
      var cid=parseInt(this.dataset.cid);
      lecLibCourseId=cid;
      document.getElementById('lec-lib-cs-ov').classList.remove('open');
      loadLibrary(cid);
    });
  });
  var srch=document.getElementById('lec-lib-cs-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    list.querySelectorAll('.umat-cs-item').forEach(function(it){
      it.style.display=(!q||it.textContent.toLowerCase().includes(q))?'':'none';
    });
  });
  var closeBtn=document.getElementById('lec-lib-cs-close');
  if(closeBtn)closeBtn.addEventListener('click',function(){document.getElementById('lec-lib-cs-ov').classList.remove('open');});
  var ov=document.getElementById('lec-lib-cs-ov');
  if(ov)ov.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
  var g=document.getElementById('lec-lib-grid');
  if(g&&!g._lecLibPickerInited){g._lecLibPickerInited=true;g.addEventListener('click',function(e){if(e.target.closest('#lec-lib-pick-btn'))openLecLibPicker();});}
}
function loadLibrary(cid){
  var g=document.getElementById('lec-lib-grid');
  var courseId=cid||lecLibCourseId||CID||0;
  if(!courseId){
    g.innerHTML='<div class="umat-lib-picker"><span class="material-symbols-outlined">folder_open</span><p>Select a course to browse its library materials.</p><button type="button" id="lec-lib-pick-btn"><span class="material-symbols-outlined">menu_book</span>Select Course</button></div>';
    return;
  }
  var hdr=document.getElementById('lec-lib-hdr-actions');
  if(hdr){
    var course=(UD.courses||[]).find(function(c){return c.id===courseId;});
    hdr.innerHTML=(course?'<button class="umat-lib-sel-label" id="lec-lib-sel-label" type="button"><span class="material-symbols-outlined">menu_book</span>'+esc(course.shortname)+'</button>':'')+
      '<input type="text" id="lec-lib-search" placeholder="Search materials…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">';
    var lbl=document.getElementById('lec-lib-sel-label');
    if(lbl)lbl.addEventListener('click',openLecLibPicker);
  }
  g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>';
  ajax('local_umat_ai_get_course_materials',{courseid:courseId},function(r){renderLibTiles(r.materials||[],g);if(typeof updateMaterialAnalysis==='function')updateMaterialAnalysis(courseId);},function(){g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
}
function openLecLibPicker(){
  var ov=document.getElementById('lec-lib-cs-ov');
  if(ov)ov.classList.add('open');
}
function openLecPdf(url,name){
  if(window.umatMaterialViewer)window.umatMaterialViewer.open('pdf',{
    url:url, name:name||'Document', downloadUrl:url
  });
}
function openLecPlayer(url,name,segments){
  if(window.umatMaterialViewer)window.umatMaterialViewer.open('video',{
    url:url, name:name||'Video',
    segments:segments||[],
    downloadUrl:url
  });
}

/* Sessions — with course overlay selector */
var lecSessCourseId = 0;
function populateSessCourseSel(){
  var list=document.getElementById('lec-sess-cs-list');
  if(!list||!UD||!UD.courses)return;
  list.innerHTML=UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">menu_book</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  list.querySelectorAll('.umat-cs-item').forEach(function(btn){
    btn.addEventListener('click',function(){
      var cid=parseInt(this.dataset.cid);
      lecSessCourseId=cid;
      document.getElementById('lec-sess-cs-ov').classList.remove('open');
      loadSessions(cid);
    });
  });
  var srch=document.getElementById('lec-sess-cs-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    list.querySelectorAll('.umat-cs-item').forEach(function(it){
      it.style.display=(!q||it.textContent.toLowerCase().includes(q))?'':'none';
    });
  });
  var closeBtn=document.getElementById('lec-sess-cs-close');
  if(closeBtn)closeBtn.addEventListener('click',function(){document.getElementById('lec-sess-cs-ov').classList.remove('open');});
  var ov=document.getElementById('lec-sess-cs-ov');
  if(ov)ov.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
  var g=document.getElementById('lec-sess-list');
  if(g&&!g._sessPickerInited){g._sessPickerInited=true;g.addEventListener('click',function(e){if(e.target.closest('#lec-sess-pick-btn'))openLecSessPicker();});}
}
function openLecSessPicker(){
  var ov=document.getElementById('lec-sess-cs-ov');
  if(ov)ov.classList.add('open');
}
function loadSessions(cid){
  var list=document.getElementById('lec-sess-list');
  var courseId=cid||lecSessCourseId||0;
  if(!courseId){
    list.innerHTML='<div class="umat-lib-picker"><span class="material-symbols-outlined">school</span><p>Select a course to view its chat sessions.</p><button type="button" id="lec-sess-pick-btn"><span class="material-symbols-outlined">menu_book</span>Select Course</button></div>';
    return;
  }
  var hdr=document.getElementById('lec-sess-hdr-actions');
  if(hdr){
    var course=(UD.courses||[]).find(function(c){return c.id===courseId;});
    hdr.innerHTML=course?'<button class="umat-lib-sel-label" id="lec-sess-sel-label" type="button"><span class="material-symbols-outlined">menu_book</span>'+esc(course.shortname)+'</button>':'';
    var lbl=document.getElementById('lec-sess-sel-label');
    if(lbl)lbl.addEventListener('click',openLecSessPicker);
  }
  list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading sessions…</p></div>';
  ajax('local_umat_ai_get_ai_sessions',{courseid:courseId,limit:20},function(r){
    if(!r.sessions||!r.sessions.length){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No AI chat sessions yet.</p></div>';return;}
    list.innerHTML=r.sessions.map(function(s){
      return '<div class="umat-session-tile">'+
        '<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+esc(s.course_short||'GEN')+'</span><span class="umat-session-time">'+esc(s.time_label)+'</span></div>'+
        '<h4>'+esc(s.course_name)+' AI Session</h4><p>'+esc(s.preview)+'</p>'+
        '<div class="umat-session-tile-foot"><div class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</div></div></div>';
    }).join('');
  },function(){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load sessions.</p></div>';});
}

/* ---- Review Outputs pane ---- */
function fmtDate(ts){var d=new Date(ts*1000);return d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});}
function outTypeIcon(t){if(t==='summary')return 'summarize';if(t==='notes')return 'notes';if(t==='quiz')return 'quiz';return 'description';}
function outTypeLbl(t){if(t==='summary')return 'Summary';if(t==='notes')return 'Notes';if(t==='quiz')return 'Quiz';return t;}

function loadReviewPane(){
  var body=document.getElementById('lec-review-body');
  if(!body)return;
  body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading pending outputs…</p></div>';
  ajax('local_umat_ai_get_pending_outputs',{courseid:CID},function(r){
    renderReviewOutputs(r);
  },function(){
    body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load pending outputs.</p></div>';
  });
}

function renderReviewOutputs(data){
  var body=document.getElementById('lec-review-body');
  var badge=document.getElementById('lec-review-badge');
  if(!body)return;
  var total=data.total_pending||0;
  if(badge)badge.textContent=total?'('+total+')':'';
  if(!data.sessions||!data.sessions.length){
    body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">fact_check</span><p>No AI outputs awaiting review.</p></div>';
    return;
  }
  body.innerHTML=data.sessions.map(function(s){
    return '<div class="umat-rev-sess" data-sid="'+s.session_id+'" data-cid="'+s.courseid+'">'+
      '<div class="umat-rev-shdr">'+
        '<span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">mic</span>'+
        '<div><strong>'+esc(s.course_name)+'</strong><span>'+fmtDate(s.timecreated)+'</span></div>'+
        '<span class="umat-rev-badge">'+s.pending_count+' pending</span>'+
      '</div>'+
      s.outputs.map(function(o){
        return '<div class="umat-rev-out" data-oid="'+o.id+'">'+
          '<div class="umat-rev-ohdr">'+
            '<span class="umat-rev-type type-'+o.type+'"><span class="material-symbols-outlined">'+outTypeIcon(o.type)+'</span>'+outTypeLbl(o.type)+'</span>'+
            '<span class="umat-rev-date">'+fmtDate(o.timecreated)+'</span>'+
          '</div>'+
          '<div class="umat-rev-cont">'+esc(o.content)+'</div>'+
          '<div class="umat-rev-acts">'+
            '<button class="umat-rev-btn rev-ap" type="button"><span class="material-symbols-outlined">check_circle</span>Approve</button>'+
            '<button class="umat-rev-btn rev-rj" type="button"><span class="material-symbols-outlined">cancel</span>Reject</button>'+
          '</div>'+
        '</div>';
      }).join('')+
    '</div>';
  }).join('');

  body.querySelectorAll('.umat-rev-btn').forEach(function(btn){
    btn.addEventListener('click',function(){
      var outEl=btn.closest('.umat-rev-out');
      var sessEl=btn.closest('.umat-rev-sess');
      if(!outEl||!sessEl)return;
      var oid=parseInt(outEl.dataset.oid);
      var cid=parseInt(sessEl.dataset.cid);
      var action=btn.classList.contains('rev-ap')?'approve':'reject';
      if(!oid||!cid)return;
      btn.disabled=true;var orig=btn.innerHTML;
      btn.innerHTML='<span class="material-symbols-outlined" style="font-size:14px;">hourglass_top</span>';
      ajax('local_umat_ai_approve_output',{outputid:oid,courseid:cid,action:action,comment:''},function(r){
        if(r.success){
          outEl.style.opacity='.35';outEl.style.pointerEvents='none';
          outEl.querySelector('.umat-rev-acts').innerHTML='<span class="umat-rev-done"><span class="material-symbols-outlined">check</span>'+action.charAt(0).toUpperCase()+action.slice(1)+'d</span>';
          updateReviewCounts();
        }else{
          btn.disabled=false;btn.innerHTML=orig+' (Failed)';
        }
      },function(){
        btn.disabled=false;btn.innerHTML=orig+' (Error)';
      });
    });
  });
}

function updateReviewCounts(){
  var badge=document.getElementById('lec-review-badge');
  var remaining=document.querySelectorAll('.umat-rev-out[style*="opacity"]').length;
  var total=document.querySelectorAll('.umat-rev-out').length;
  var pending=total-remaining;
  if(badge)badge.textContent=pending?'('+pending+')':'';
  if(pending===0){
    var body=document.getElementById('lec-review-body');
    if(body)setTimeout(function(){
      if(body.querySelectorAll('.umat-rev-out:not([style*="opacity"])').length===0)
        body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">fact_check</span><p>All outputs reviewed! 🎉</p></div>';
    },600);
  }
}

/* ──────────────────────────────────────────────
   STRUGGLE INSIGHTS
   ────────────────────────────────────────────── */
function loadStruggleInsights(cid){
  if(!cid){document.getElementById('stru-course-label').textContent='Open a course page.';return;}
  document.getElementById('stru-course-label').textContent=cid===CID?CN:'Loading\u2026';
  var loading=document.getElementById('stru-loading');
  var error=document.getElementById('stru-error');
  var content=document.getElementById('stru-content');
  if(loading)loading.style.display='';
  if(error)error.style.display='none';
  if(content)content.style.display='none';
  ajax('local_umat_ai_get_struggle_insights',{courseid:cid,days:60},
    function(d){
      if(loading)loading.style.display='none';
      if(content)content.style.display='block';
      renderStruggleSummary(d.summary);
      var _tm=d.topic_matrix&&d.topic_matrix.length>0?d.topic_matrix:(d.summary&&d.summary.total_questions>0?[{topic:"General Course Questions",question_count:d.summary.total_questions,student_count:d.summary.total_students,struggle_score:Math.min(80,20+d.summary.total_questions*5),trend:"stable",trend_pct:0,difficulty:"intermediate",materials:[]}]:[]);renderTopicMatrix(_tm);
      renderMaterialBreakdown(d.material_breakdown);
      renderAtRiskStudents(d.at_risk_students);
      /* Cached */
      struggleCache[cid]=true;
    },
    function(){
      if(loading)loading.style.display='none';
      if(error){error.style.display='';document.getElementById('stru-error-text').textContent='No data yet. Struggle insights appear once students start chatting with the AI Tutor.';}
    }
  );
}

function renderStruggleSummary(summary){
  if(!summary)return;
  s('stru-total-q',summary.total_questions);
  s('stru-total-s',summary.total_students);
  s('stru-worst',summary.worst_topic||'\u2014');
  var badge=document.getElementById('stru-mode-badge');
  if(badge)badge.textContent=summary.ai_service_used?'AI Engine':'PHP Engine';
}

function renderTopicMatrix(topics){
  var grid=document.getElementById('stru-topic-grid');
  if(!grid)return;
  if(!topics||!topics.length){
    grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">search</span><p>No topic data yet. Questions will appear once students start asking.</p></div>';
    return;
  }
  grid.innerHTML=topics.map(function(t){
    var pct=Math.min(100,t.struggle_score);
    var sev=pct>=60?'high':(pct>=30?'medium':'low');
    var trendHtml='';
    if(t.trend==='up')trendHtml='<span class="struggle-trend trend-up"><span class="material-symbols-outlined">trending_up</span> +'+t.trend_pct+'%</span>';
    else if(t.trend==='down')trendHtml='<span class="struggle-trend trend-down"><span class="material-symbols-outlined">trending_down</span> '+t.trend_pct+'%</span>';
    else trendHtml='<span class="struggle-trend trend-stable"><span class="material-symbols-outlined">trending_flat</span></span>';
    var matChips=(t.materials||[]).slice(0,4).map(function(m){
      return '<span class="struggle-mat-chip" title="'+esc(m.name)+': '+m.question_count+' questions">'+
        '<span class="material-symbols-outlined" style="font-size:11px;">description</span>'+
        esc(m.name)+(m.question_count?' ('+m.question_count+')':'')+'</span>';
    }).join('');
    if((t.materials||[]).length>4)matChips+='<span class="struggle-mat-chip" style="opacity:.6;">+'+(t.materials.length-4)+' more</span>';
    var diffPill=t.difficulty==='advanced'?'<span class="umat-pill pill-high">Advanced</span>':
      (t.difficulty==='beginner'?'<span class="umat-pill pill-ok">Beginner</span>':
      '<span class="umat-pill pill-warn">Intermediate</span>');
    return '<div class="struggle-topic-card struggle-'+sev+'">'+
      '<div class="struggle-topic-header">'+
        '<div class="struggle-topic-name"><strong>'+esc(t.topic)+'</strong> '+diffPill+'</div>'+
        '<div class="struggle-score-wrap"><span class="struggle-score-pill struggle-'+sev+'">'+t.struggle_score+'</span>'+trendHtml+'</div>'+
      '</div>'+
      '<div class="struggle-topic-body">'+
        '<span><strong>'+t.question_count+'</strong> questions</span>'+
        '<span><strong>'+t.student_count+'</strong> students</span>'+
      '</div>'+
      (matChips?'<div class="struggle-topic-materials">'+matChips+'</div>':'')+
    '</div>';
  }).join('');
}

function renderMaterialBreakdown(sections){
  var list=document.getElementById('stru-material-list');
  if(!list)return;
  if(!sections||!sections.length){
    list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">inventory_2</span><p>No materials with struggle data yet.</p></div>';
    return;
  }
  list.innerHTML=sections.map(function(sec){
    var matHtml=(sec.materials||[]).map(function(m){
      var diffPill=m.difficulty==='advanced'?'<span class="umat-pill pill-high">'+(m.difficulty||'intermediate').substring(0,4)+'</span>':
        (m.difficulty==='beginner'?'<span class="umat-pill pill-ok">Beg</span>':
        '<span class="umat-pill pill-warn">Int</span>');
      var concepts=(m.key_concepts||[]).map(function(c){
        return '<span class="struggle-concept-chip" title="'+esc(c.concept)+': '+c.question_count+' questions">'+
          esc(c.concept)+(c.question_count?' <span class="chip-count">'+c.question_count+'</span>':'')+'</span>';
      }).join('');
      var sev=m.question_count>10?'high':(m.question_count>3?'medium':'low');
      return '<div class="struggle-material-row struggle-'+sev+'">'+
        '<div class="struggle-mat-info">'+
          '<span class="material-symbols-outlined" style="font-size:16px;color:var(--u-ol);">'+
            (m.filename.match(/\.pdf$/i)?'picture_as_pdf':
             m.filename.match(/\.(pptx?|ppt)$/i)?'slideshow':
             m.filename.match(/\.(docx?|doc)$/i)?'description':
             m.filename.match(/\.(xlsx?|xls|csv)$/i)?'table_chart':
             'insert_drive_file')+'</span>'+
          '<span class="struggle-mat-name" title="'+esc(m.filename)+'">'+esc(m.filename)+'</span>'+
          diffPill+
        '</div>'+
        '<div class="struggle-mat-stats"><strong>'+m.question_count+'</strong> Q</div>'+
        (concepts?'<div class="struggle-mat-concepts">'+concepts+'</div>':'')+
      '</div>';
    }).join('');
    return '<div class="struggle-material-group">'+
      '<div class="struggle-group-header" onclick="var b=this.nextElementSibling;b.style.display=b.style.display===\'none\'?\'\':\'none\';this.querySelector(\'.struggle-toggle\').textContent=b.style.display===\'none\'?\'keyboard_arrow_right\':\'keyboard_arrow_down\';">'+
        '<span class="material-symbols-outlined struggle-toggle">keyboard_arrow_down</span>'+
        '<strong>'+esc(sec.section_name)+'</strong>'+
        '<span class="umat-pill pill-info">'+(sec.materials||[]).length+' items</span>'+
      '</div>'+
      '<div class="struggle-group-body">'+matHtml+'</div>'+
    '</div>';
  }).join('');
}

function renderAtRiskStudents(students){
  var list=document.getElementById('stru-student-list');
  if(!list)return;
  if(!students||!students.length){
    list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">person_search</span><p>No at-risk student data yet.</p></div>';
    return;
  }
  list.innerHTML='<div class="struggle-student-header">'+
    '<span>Student</span><span>Questions</span><span>Struggle Topics</span><span>Risk</span><span>Activity</span>'+
  '</div>'+
  students.slice(0,20).map(function(s){
    var riskPill=s.risk_level==='high'?'<span class="umat-pill pill-high">High</span>':
      (s.risk_level==='medium'?'<span class="umat-pill pill-warn">Med</span>':
      '<span class="umat-pill pill-ok">Low</span>');
    var topicTags=(s.struggle_topics||[]).slice(0,3).map(function(t){
      return '<span class="struggle-topic-tag">'+esc(t)+'</span>';
    }).join('');
    var trendIcon=s.trend==='up'?'<span class="material-symbols-outlined" style="font-size:14px;color:var(--u-ter);">trending_up</span>':
      (s.trend==='down'?'<span class="material-symbols-outlined" style="font-size:14px;color:var(--u-p);">trending_down</span>':
      '<span class="material-symbols-outlined" style="font-size:14px;color:var(--u-ol);">trending_flat</span>');
    return '<div class="struggle-student-row">'+
      '<div class="struggle-student-info">'+
        '<img src="'+esc(s.profileimageurl)+'" alt="" class="struggle-student-avatar" onerror="this.style.display=\'none\'">'+
        '<span class="struggle-student-name">'+esc(s.fullname)+'</span>'+
      '</div>'+
      '<span class="struggle-student-qcount"><strong>'+s.question_count+'</strong> '+trendIcon+'</span>'+
      '<span class="struggle-student-topics">'+topicTags+'</span>'+
      '<span class="struggle-student-risk">'+riskPill+' ('+s.risk_score+')</span>'+
      '<span class="struggle-student-active">'+esc(s.last_active)+'</span>'+
    '</div>';
  }).join('');
}

/* Compact panel lecturer AI send (streaming) */
function sendLecQ(q){
  q=(q||'').trim();if(!q)return;
  if(!CID){_umatAppendAi('lcp-msgs','Please open a course page first to ask about its analytics.',[]);return;}
  _umatAppendUser('lcp-msgs',q);
  var inp=document.getElementById('lcp-input');if(inp)inp.value='';
  var tid='lt_'+Date.now();_umatShowTyping('lcp-msgs',tid);
  
  _umatStreamChat({
    url: streamUrl,
    sesskey: moodleSesskey,
    courseid: CID,
    question: q,
    session_key: 'lec_cp_' + CID,
    material_ids: [],
    msgsId: 'lcp-msgs',
    onMeta: function(meta){},
    onDone: function(meta){ _umatHideTyping(tid); },
    onError: function(err){
      _umatHideTyping(tid);
      _umatAppendAi('lcp-msgs', err.message||'Sorry, an error occurred. Please try again.', []);
    }
  });
}
var lcpIn=document.getElementById('lcp-input');var lcpSend=document.getElementById('lcp-send');
if(lcpSend)lcpSend.addEventListener('click',function(){sendLecQ(lcpIn.value);});
if(lcpIn)lcpIn.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();lcpSend.click();}});

/* Compact panel suggestion chips click */
var cp=document.getElementById('lec-cp');
if(cp){
  cp.addEventListener('click',function(e){
    var chip=e.target.closest('[data-lp]');
    if(chip){sendLecQ(chip.dataset.lp);}
  });
}

/* Mini AI panel (always accessible, outside overlay - streaming) */
var aiFab=document.getElementById('lec-ai-fab');var aiMini=document.getElementById('lec-ai-mini');
if(aiFab&&aiMini)aiFab.addEventListener('click',function(){aiMini.style.display=aiMini.style.display==='flex'?'none':'flex';});
var aiclose=document.getElementById('lec-ai-mini-close');
if(aiclose&&aiMini)aiclose.addEventListener('click',function(){aiMini.style.display='none';});
if(aiMini&&aiFab)document.addEventListener('click',function(e){if(aiMini.style.display==='flex'&&!aiMini.contains(e.target)&&!aiFab.contains(e.target))aiMini.style.display='none';});

var miniIn=document.getElementById('lec-mini-input');var miniSend=document.getElementById('lec-mini-send');
if(miniSend)miniSend.addEventListener('click',function(){
  var q=(miniIn.value||'').trim();if(!q)return;
  _umatAppendUser('lec-mini-msgs',q);
  miniIn.value='';
  var tid='lt_mini_'+Date.now();_umatShowTyping('lec-mini-msgs',tid);
  
  _umatStreamChat({
    url: streamUrl,
    sesskey: moodleSesskey,
    courseid: CID,
    question: q,
    session_key: 'lec_mini_' + CID,
    material_ids: [],
    msgsId: 'lec-mini-msgs',
    onMeta: function(meta){},
    onDone: function(meta){ _umatHideTyping(tid); },
    onError: function(err){
      _umatHideTyping(tid);
      _umatAppendAi('lec-mini-msgs', err.message||'Sorry, an error occurred. Please try again.', []);
    }
  });
});
if(miniIn)miniIn.addEventListener('keypress',function(e){if(e.key==='Enter'){e.preventDefault();if(miniSend)miniSend.click();}});

/* Init home on overlay open */
initHome();
document.getElementById('lec-home-date').textContent=(function(){var d=new Date();return d.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});})();
/* Populate library course selector */
populateLibCourseSel();
/* Auto-load analytics + struggle when overlay opens */
if(expand)expand.addEventListener('click',function(){setTimeout(function(){if(!lecLoaded['lec-analytics']){lecLoaded['lec-analytics']=true;loadAnalytics(CID);}if(!struggleCache[CID]){struggleCache[CID]=true;loadStruggleInsights(CID);}},100);});
/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'lec-ai-mini',isOpen:function(e){return e.style.display==='flex';},close:function(e){e.style.display='none';}},
  {id:'lec-ov',isOpen:function(e){return e.classList.contains('open');},close:closeDash},
  {id:'lec-cp-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
});
</script>
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

    {$hubMobTabs}

    <!-- CONTENT -->
    <div class="umat-ov-content">

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
                <h4>Reference Materials</h4>
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
              <span class="umat-drawer-foot-info">Select materials for AI to reference</span>
              <button class="umat-drawer-confirm" id="hub-drawer-confirm" type="button"><span class="material-symbols-outlined">check</span> Reference Selected</button>
            </div>
          </div>
          <div class="umat-input-row">
            <textarea id="hub-input" class="umat-textarea" placeholder="Ask anything about your courses…" rows="2" maxlength="900"></textarea>
            <button class="umat-send-btn" id="hub-send" type="button"><span class="material-symbols-outlined">send</span></button>
          </div>
          <div class="umat-mat-bar" id="hub-mat-bar"></div>
          <div class="umat-input-actions">
            <button class="umat-ia-btn" id="hub-attach-btn" type="button"><span class="material-symbols-outlined">attach_file</span>Reference Material</button>
            <button class="umat-ia-btn" id="hub-mic-btn" type="button"><span class="material-symbols-outlined">mic</span>Voice</button>
            <span class="umat-ia-btn" id="hub-rate" style="cursor:default;">10 Q/min</span>
          </div>
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
</div><!-- /hub-ov -->

{$sharedJs}

<script>
window._umatSharedReady.then(function() {
/* ============================================================
   HUB OVERLAY IIFE
   ============================================================ */
(function(){
'use strict';

var UD      = {$jsUD} || {};
var UID     = {$uid};
var streamUrl = {$streamUrl};
var moodleSesskey = {$moodleSesskey};
var sessKey = 'hub_'+Math.random().toString(36).substr(2,18);
/* Rolling 60s rate-limit window — mirrors the server check, refills as entries expire */
var RATE_MAX = 10;
var qTimes   = [];
var selMat  = [];
var loaded  = {};
var activeCID = 0;

/* FAB / overlay toggle */
var fab=document.getElementById('hub-fab');
var ov=document.getElementById('hub-ov');
var ovClose=document.getElementById('hub-ov-close');
var newBtn=document.getElementById('hub-new-sess');
var newBtn2=document.getElementById('hub-new-sess2');

fab.addEventListener('click',function(){ov.classList.add('open');initHome();});
ovClose.addEventListener('click',function(){ov.classList.remove('open');});
ov.addEventListener('click',function(e){if(e.target===ov)ov.classList.remove('open');});

/* Pane switching */
function switchPane(name){
  document.querySelectorAll('#hub-ov .umat-tab-pane').forEach(function(p){p.classList.remove('active');});
  document.querySelectorAll('#hub-sb [data-hp], #hub-glass-tabs [data-hp]').forEach(function(b){b.classList.toggle('active',b.dataset.hp===name);});
  var pane=document.getElementById(name);if(pane)pane.classList.add('active');
  if(!loaded[name]){loaded[name]=true;loadPane(name);}
}
document.querySelectorAll('#hub-sb [data-hp], #hub-glass-tabs [data-hp]').forEach(function(b){
  b.addEventListener('click',function(){switchPane(b.dataset.hp);});
});
document.addEventListener('click',function(e){
  var btn=e.target.closest('[data-hp]');
  if(btn&&btn.closest('#hub-home')){switchPane(btn.dataset.hp);}
});

function loadPane(name){
  if(name==='hub-courses')loadCourses();
  if(name==='hub-sessions')loadSessions();
  if(name==='hub-lectures')populateLecCourseSel();
  if(name==='hub-library')populateLibCourseSel();
}

/* Home */
function initHome(){
  var dEl=document.getElementById('hub-home-date');
  if(dEl)dEl.textContent=(new Date()).toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  if(UD.week_sessions!==undefined){
    var ms=document.getElementById('hub-met-sess');var mq=document.getElementById('hub-met-q');
    var mg=document.getElementById('hub-met-goal');var gp=document.getElementById('hub-goal-pct');
    var gf=document.getElementById('hub-goal-fill');
    if(ms)ms.textContent=UD.week_sessions;if(mq)mq.textContent=UD.week_questions;
    var gv=UD.goal_progress||0;
    if(mg)mg.textContent=gv+'%';if(gp)gp.textContent=gv+'%';
    if(gf)setTimeout(function(){gf.style.width=gv+'%';},300);
  }
  /* Pulse topics */
  if(UD.pulse_topics&&UD.pulse_topics.length){
    var tags=document.getElementById('hub-pulse-tags');
    if(tags)tags.innerHTML=UD.pulse_topics.map(function(t){
      return '<span style="padding:5px 13px;border-radius:999px;background:var(--u-secc);color:var(--u-sec);font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;"><span class="material-symbols-outlined" style="font-size:13px;">school</span>'+esc(t.label)+'</span>';
    }).join('');
  }
  /* Recent sessions */
  if(UD.sessions&&UD.sessions.length){
    var rs=document.getElementById('hub-recent-section');var rt=document.getElementById('hub-recent-tiles');
    if(rs&&rt){rs.style.display='block';
      rt.innerHTML=UD.sessions.slice(0,6).map(function(s){
        return '<div class="umat-session-tile" data-sk="'+esc(s.session_key)+'" data-cid="'+s.courseid+'" data-cn="'+esc(s.course_name)+'">'+
          '<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+esc(s.course_short||'GEN')+'</span><span class="umat-session-time">'+esc(s.time_label)+'</span></div>'+
          '<h4>'+esc(s.course_name)+' Session</h4><p>'+esc(s.preview)+'</p>'+
          '<div class="umat-session-tile-foot"><div class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</div>'+
          '<button class="umat-resume-btn" type="button">Resume →</button></div></div>';
      }).join('');
      rt.querySelectorAll('.umat-session-tile').forEach(function(t){
        t.addEventListener('click',function(){resumeSession(t.dataset.sk,parseInt(t.dataset.cid)||0,t.dataset.cn||'');});
      });
    }
  }
  /* Populate course selects */
  if(UD.courses&&UD.courses.length){
    var sels=['hub-course-sel','hub-lec-course-sel','hub-lib-course-sel'];
    sels.forEach(function(sid){
      var sel=document.getElementById(sid);if(!sel)return;
      sel.innerHTML='<option value="0">All Courses</option>'+
        UD.courses.map(function(c){return '<option value="'+c.id+'">'+esc(c.shortname)+' — '+esc(c.fullname.substring(0,40))+'</option>';}).join('');
    });
  }
}

/* Courses */
function loadCourses(){
  var g=document.getElementById('hub-courses-grid');
  if(UD.courses&&UD.courses.length){renderCourseTiles(UD.courses,g);return;}
  ajax('local_umat_ai_get_my_courses',{},function(r){renderCourseTiles(r.courses||[],g);},function(){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load courses.</p></div>';});
}
function renderCourseTiles(courses,g){
  if(!g)return;
  if(!courses.length){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>No enrolled courses found.</p></div>';return;}
  g.className='yt-grid';
  g.innerHTML=courses.map(function(c){
    return '<div class="yt-tile" data-cid="'+c.id+'" data-cname="'+esc(c.fullname)+'">'+
      '<div class="yt-thumb yt-bg-course">'+
        '<div class="yt-course-ov">'+
          '<div class="yt-course-code">'+esc(c.shortname)+'</div>'+
          '<div class="yt-course-name">'+esc(c.fullname)+'</div>'+
        '</div>'+
      '</div>'+
      '<div class="yt-meta">'+
        '<div class="yt-av yt-av-course"><span class="material-symbols-outlined">menu_book</span></div>'+
        '<div class="yt-text">'+
          '<h4 class="yt-title">'+esc(c.fullname)+'</h4>'+
          '<p class="yt-channel">'+esc(c.shortname)+'</p>'+
        '</div>'+
      '</div>'+
      '<div class="yt-actions">'+
        '<button class="yt-btn" data-act="tutor" onclick="event.stopPropagation()"><span class="material-symbols-outlined">smart_toy</span>AI Tutor</button>'+
        '<button class="yt-btn" data-act="library" onclick="event.stopPropagation()"><span class="material-symbols-outlined">local_library</span>Library</button>'+
      '</div>'+
    '</div>';
  }).join('');
  g.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('[data-act]'))return;
      activeCID=parseInt(tile.dataset.cid)||0;
      var cs=document.getElementById('hub-course-sel');if(cs)cs.value=activeCID;
      switchPane('hub-tutor');
    });
    tile.querySelectorAll('[data-act]').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.stopPropagation();
        activeCID=parseInt(tile.dataset.cid)||0;
        var cs=document.getElementById('hub-course-sel');if(cs)cs.value=activeCID;
        var act=btn.dataset.act;
        if(act==='tutor')switchPane('hub-tutor');
        else if(act==='library'){loaded['hub-library']=false;switchPane('hub-library');}
      });
    });
  });
  var srch=document.getElementById('hub-courses-search');
  if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.yt-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
}

/* Sessions */
function loadSessions(){
  var list=document.getElementById('hub-sess-list');
  if(UD.sessions&&UD.sessions.length){renderSessionTiles(UD.sessions,list);return;}
  ajax('local_umat_ai_get_ai_sessions',{courseid:0,limit:20},function(r){renderSessionTiles(r.sessions||[],list);},function(){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load sessions.</p></div>';});
}
function renderSessionTiles(sessions,container){
  if(!sessions.length){container.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No past sessions yet. Start a conversation in AI Tutor!</p></div>';return;}
  container.innerHTML=sessions.map(function(s){
    return '<div class="umat-session-tile" data-sk="'+esc(s.session_key)+'" data-cid="'+s.courseid+'" data-cn="'+esc(s.course_name)+'">'+
      '<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+esc(s.course_short||'GEN')+'</span><span class="umat-session-time">'+esc(s.time_label)+'</span></div>'+
      '<h4>'+esc(s.course_name||'General')+'</h4><p>'+esc(s.preview)+'</p>'+
      '<div class="umat-session-tile-foot"><div class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</div>'+
      '<button class="umat-resume-btn" type="button">Resume →</button></div></div>';
  }).join('');
  container.querySelectorAll('.umat-session-tile').forEach(function(t){
    t.addEventListener('click',function(){resumeSession(t.dataset.sk,parseInt(t.dataset.cid)||0,t.dataset.cn||'');});
  });
}
function resumeSession(sk,cid,cname){
  sessKey=sk;activeCID=cid||0;
  var cs=document.getElementById('hub-course-sel');if(cs&&cid)cs.value=cid;
  switchPane('hub-tutor');
  ajax('local_umat_ai_get_chat_history',{courseid:cid||1,session_key:sk,limit:50},
    function(r){
      var msgs=document.getElementById('hub-msgs');if(!msgs)return;
      msgs.innerHTML='';
      addWelcome(cname||'your course');
      (r.messages||[]).forEach(function(m){appendMsg(m.question,true,msgs);if(m.answer)appendMsg(m.answer,false,msgs,m.sources||[]);});
    },function(){}
  );
}
function addWelcome(cname){
  var c=document.getElementById('hub-msgs');if(!c)return;
  var d=document.createElement('div');d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Session resumed for <strong>'+esc(cname)+'</strong>. Continue your conversation below.</p></div></div></div>';
  c.appendChild(d);c.scrollTop=c.scrollHeight;
}

/* Lectures */
function populateLecCourseSel(){
  var sel=document.getElementById('hub-lec-course-sel');
  if(sel&&UD.courses){
    sel.innerHTML='<option value="0">All Courses</option>'+
      UD.courses.map(function(c){return '<option value="'+c.id+'">'+esc(c.shortname)+'</option>';}).join('');
    sel.addEventListener('change',function(){if(this.value!=='0')loadLectures(parseInt(this.value));});
  }
}
function loadLectures(cid){
  var g=document.getElementById('hub-lec-grid');
  g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading recordings…</p></div>';
  ajax('local_umat_ai_get_course_recordings',{courseid:cid||0},function(r){
    var recs=r.recordings||[];
    if(!recs.length){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">video_library</span><p>No recordings available for this course yet.</p></div>';return;}
    g.innerHTML=recs.map(function(rec){
      return '<div class="umat-video-tile" data-url="'+esc(rec.url)+'" data-title="'+esc(rec.title)+'" data-segments="'+esc(JSON.stringify(rec.segments||[]))+'" data-duration="'+esc(rec.duration||'')+'">'+
        '<div class="umat-video-thumb"><span class="material-symbols-outlined umat-vid-play-icon">play_circle</span>'+
        (rec.duration?'<span class="umat-duration-badge">'+esc(rec.duration)+'</span>':'')+
        '</div><div class="umat-video-tile-info"><h4 title="'+esc(rec.title)+'">'+esc(rec.title)+'</h4>'+
        '<span class="umat-vid-time">'+esc(rec.time_ago||'')+'</span>'+
        '<a class="umat-video-tile-dl" href="'+esc(rec.url)+'" download title="Download" onclick="event.stopPropagation();"><span class="material-symbols-outlined">download</span></a>'+
        '</div></div>';
    }).join('');
    g.querySelectorAll('.umat-video-tile').forEach(function(t){
      t.addEventListener('click',function(){
        var segs=[];try{segs=JSON.parse(t.dataset.segments||'[]');}catch(e){}
        openHubPlayer(t.dataset.url,t.dataset.title,segs);
      });
    });
  },function(){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load recordings.</p></div>';});
  var srch=document.getElementById('hub-lec-search');
  if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.umat-video-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
}
function openHubPlayer(url,title,segments){
  if(window.umatMaterialViewer)window.umatMaterialViewer.open('video',{
    url:url, name:title||'Lecture Recording',
    segments:segments||[],
    downloadUrl:url
  });
}

/* Library — with course overlay selector */
var hubLibCourseId = 0;
function populateLibCourseSel(){
  var list=document.getElementById('hub-lib-cs-list');
  if(!list||!UD||!UD.courses)return;
  list.innerHTML=UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">menu_book</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  list.querySelectorAll('.umat-cs-item').forEach(function(btn){
    btn.addEventListener('click',function(){
      var cid=parseInt(this.dataset.cid);
      hubLibCourseId=cid;
      document.getElementById('hub-lib-cs-ov').classList.remove('open');
      loadLibrary(cid);
    });
  });
  var srch=document.getElementById('hub-lib-cs-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    list.querySelectorAll('.umat-cs-item').forEach(function(it){
      it.style.display=(!q||it.textContent.toLowerCase().includes(q))?'':'none';
    });
  });
  var closeBtn=document.getElementById('hub-lib-cs-close');
  if(closeBtn)closeBtn.addEventListener('click',function(){document.getElementById('hub-lib-cs-ov').classList.remove('open');});
  var ov=document.getElementById('hub-lib-cs-ov');
  if(ov)ov.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
  var g=document.getElementById('hub-lib-grid');
  if(g&&!g._hubLibPickerInited){g._hubLibPickerInited=true;g.addEventListener('click',function(e){if(e.target.closest('#hub-lib-pick-btn'))openHubLibPicker();});}
}
function loadLibrary(cid){
  var g=document.getElementById('hub-lib-grid');
  var courseId=cid||hubLibCourseId||0;
  if(!courseId){
    g.innerHTML='<div class="umat-lib-picker"><span class="material-symbols-outlined">folder_open</span><p>Select a course to browse its library materials.</p><button type="button" id="hub-lib-pick-btn"><span class="material-symbols-outlined">menu_book</span>Select Course</button></div>';
    return;
  }
  var hdr=document.getElementById('hub-lib-hdr-actions');
  if(hdr){
    var course=(UD.courses||[]).find(function(c){return c.id===courseId;});
    hdr.innerHTML=(course?'<button class="umat-lib-sel-label" id="hub-lib-sel-label" type="button"><span class="material-symbols-outlined">menu_book</span>'+esc(course.shortname)+'</button>':'')+
      '<input type="text" id="hub-lib-search" placeholder="Search materials…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">';
    var lbl=document.getElementById('hub-lib-sel-label');
    if(lbl)lbl.addEventListener('click',openHubLibPicker);
  }
  g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials…</p></div>';
  ajax('local_umat_ai_get_course_materials',{courseid:courseId},function(r){
    var mats=r.materials||[];
    if(!mats.length){g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">folder_open</span><p>No materials found for this course.</p></div>';return;}
    g.className='yt-grid';
    g.innerHTML=mats.map(function(m){
      var mime=(m.mimetype||'').toLowerCase();
      var bg='yt-bg-other',av='yt-av-other',ic='description',ext='FILE';
      if(mime.indexOf('pdf')>=0){bg='yt-bg-pdf';av='yt-av-pdf';ic='picture_as_pdf';ext='PDF';}
      else if(mime.indexOf('video')>=0){bg='yt-bg-video';av='yt-av-video';ic='videocam';ext=mime.indexOf('mp4')>=0?'MP4':'VIDEO';}
      else if(mime.indexOf('wordprocessingml')>=0||mime.indexOf('msword')>=0||mime.indexOf('document')>=0){bg='yt-bg-word';av='yt-av-word';ic='description';ext='DOCX';}
      else if(mime.indexOf('presentationml')>=0||mime.indexOf('powerpoint')>=0){bg='yt-bg-pptx';av='yt-av-pptx';ic='co_present';ext='PPTX';}
      else if(mime.indexOf('spreadsheetml')>=0||mime.indexOf('excel')>=0||mime.indexOf('sheet')>=0){bg='yt-bg-excel';av='yt-av-excel';ic='table_chart';ext='XLSX';}
      else if(mime.indexOf('image')>=0){bg='yt-bg-image';av='yt-av-image';ic='image';ext=mime.indexOf('png')>=0?'PNG':mime.indexOf('jpeg')>=0?'JPG':'IMG';}
      else if(mime.indexOf('audio')>=0){bg='yt-bg-audio';av='yt-av-audio';ic='music_note';ext='AUDIO';}
      var isVideo=mime.indexOf('video')>=0;
      var playIcon=isVideo?'play_arrow':'open_in_new';
      var sz=Math.round((m.filesize||0)/1024)+'KB';
      var badge=m.duration?'<span class="yt-badge">'+esc(m.duration)+'</span>':
        (m.page_count>0?'<span class="yt-badge">'+m.page_count+' pp</span>':'<span class="yt-badge">'+ext+'</span>');
      return '<div class="yt-tile" data-url="'+esc(m.url)+'" data-name="'+esc(m.filename)+'" data-mime="'+esc(m.mimetype)+'" data-fileid="'+(m.id||0)+'">'+
        '<div class="yt-thumb '+bg+'">'+
          '<span class="yt-thumb-icon material-symbols-outlined">'+ic+'</span>'+
          '<div class="yt-play-ov"><span class="material-symbols-outlined">'+playIcon+'</span></div>'+
          badge+
        '</div>'+
        '<div class="yt-meta">'+
          '<div class="yt-av '+av+'"><span class="material-symbols-outlined">'+ic+'</span></div>'+
          '<div class="yt-text">'+
            '<h4 class="yt-title" title="'+esc(m.filename)+'">'+esc(m.filename)+'</h4>'+
            '<p class="yt-channel">'+ext+' · '+sz+'</p>'+
            '<p class="yt-stats">'+esc(m.time_ago||'')+'</p>'+
          '</div>'+
        '</div>'+
        '<div class="yt-actions">'+
          '<button class="yt-btn yt-view-btn"><span class="material-symbols-outlined">visibility</span>View</button>'+
          '<a class="yt-btn" href="'+esc(m.url)+'" download="'+esc(m.filename)+'" onclick="event.stopPropagation()"><span class="material-symbols-outlined">download</span>Download</a>'+
        '</div>'+
      '</div>';
    }).join('');
    g.querySelectorAll('.yt-tile').forEach(function(tile){
      tile.addEventListener('click',function(e){
        if(e.target.closest('a.yt-btn'))return;
        e.preventDefault();
        var mime=tile.dataset.mime||'';
        var url=tile.dataset.url;
        var name=tile.dataset.name;
        if(window.umatMaterialViewer){
          var type=mime.indexOf('video')>=0?'video':mime.indexOf('pdf')>=0?'pdf':mime.indexOf('image')>=0?'image':mime.indexOf('audio')>=0?'audio':'other';
          window.umatMaterialViewer.open(type,{url:url,name:name,downloadUrl:url,mime:mime});
        }else{window.open(url,'_blank');}
      });
      var vb=tile.querySelector('.yt-view-btn');
      if(vb)vb.addEventListener('click',function(e){e.stopPropagation();tile.click();});
    });
    var srch=document.getElementById('hub-lib-search');if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.yt-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
  },function(){g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
}
function openHubLibPicker(){
  var ov=document.getElementById('hub-lib-cs-ov');
  if(ov)ov.classList.add('open');
}
function openHubPdf(url,name){
  if(window.umatMaterialViewer)window.umatMaterialViewer.open('pdf',{
    url:url, name:name||'Document', downloadUrl:url
  });
}

/* Chat */
function qRemaining(){var now=Date.now();qTimes=qTimes.filter(function(t){return now-t<60000;});return Math.max(0,RATE_MAX-qTimes.length);}
function syncRemaining(rem){if(typeof rem!=='number'||rem<0)return;var now=Date.now();while(qRemaining()>rem)qTimes.push(now);}
function updateRate(){var left=qRemaining();var e=document.getElementById('hub-rate');if(e){e.textContent=left+' Q/min';e.style.color=left<=2?'var(--u-ter)':'';}}
setInterval(updateRate,5000); /* refill display as window entries expire */
function appendMsg(text,isUser,container,sources){
  var d=document.createElement('div');
  if(isUser)d.innerHTML='<div class="umat-msg-user"><div class="umat-bubble-user"><p>'+esc(text)+'</p></div></div>';
  else{var srcs='';if(sources&&sources.length)srcs='<div class="umat-src-chips">'+sources.map(function(s){return '<span class="umat-src-chip">'+esc(s)+'</span>';}).join('')+'</div>';
    d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>'+esc(text)+'</p>'+srcs+'</div></div></div>';}
  container.appendChild(d);container.scrollTop=container.scrollHeight;
}
function sendQ(q){
  q=(q||'').trim();if(!q)return;
  if(qRemaining()<=0){appendMsg('Rate limit reached. Please wait a moment before asking again.',false,document.getElementById('hub-msgs'),[]);return;}
  qTimes.push(Date.now());updateRate();
  var ctx=selMat.length>0?'[Referencing: '+selMat.map(function(m){return m.name;}).join(', ')+'] '+q:q;
  var cid=parseInt(document.getElementById('hub-course-sel').value)||activeCID||1;
  var msgs=document.getElementById('hub-msgs');
  appendMsg(q,true,msgs);document.getElementById('hub-input').value='';
  var tid='h_'+Date.now();
  var t=document.createElement('div');t.id=tid;t.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-typing"><span></span><span></span><span></span></div></div></div></div>';
  msgs.appendChild(t);msgs.scrollTop=msgs.scrollHeight;
  _umatStreamChat({
    url:streamUrl,sesskey:moodleSesskey,courseid:cid,question:ctx,session_key:sessKey,
    material_ids:selMat.map(function(m){return m.id;}),msgsId:'hub-msgs',
    onMeta:function(meta){syncRemaining(meta.remaining);updateRate();},
    onDone:function(meta){var e=document.getElementById(tid);if(e)e.parentNode.removeChild(e);syncRemaining(meta.remaining);updateRate();},
    onError:function(err){
      var e=document.getElementById(tid);if(e)e.parentNode.removeChild(e);
      if(err.error==='rate_limit'){qTimes.pop();updateRate();}
      appendMsg(err.message||'Connection error.',false,msgs,[]);
    }
  });
}
var hubIn=document.getElementById('hub-input');var hubSend=document.getElementById('hub-send');
hubSend.addEventListener('click',function(){sendQ(hubIn.value);});
hubIn.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();hubSend.click();}});
document.getElementById('hub-msgs').addEventListener('click',function(e){var chip=e.target.closest('.umat-chip[data-q]');if(chip){hubIn.value=chip.dataset.q;hubSend.click();}});

/* Attachment drawer (enhanced) */
var hubDrawerCtrl = _umatInitAttachDrawer({
  getCourseId:function(){
    var v=parseInt(document.getElementById('hub-course-sel').value);
    return v||activeCID||0;
  },
  drawerId:'hub-attach-drawer',
  attachBtnId:'hub-attach-btn',
  closeBtnId:'hub-drawer-close',
  clearId:'hub-drawer-clear',
  searchId:'hub-drawer-search',
  catsId:'hub-drawer-cats',
  recentId:'hub-drawer-recent',
  listId:'hub-drawer-list',
  confirmId:'hub-drawer-confirm',
  countId:'hub-drawer-count',
  maxSelections:20,
  onConfirm:function(mats){selMat=mats;_umatRenderMatsBar('hub-mat-bar','hub-attach-btn',selMat,function(id){selMat=selMat.filter(function(s){return s.id!=id;});return selMat;});}
});

/* Voice */
(function(){
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  var micBtn=document.getElementById('hub-mic-btn');if(!SR||!micBtn){if(micBtn)micBtn.style.opacity='.4';return;}
  var rec=new SR();rec.continuous=false;rec.interimResults=true;rec.lang='en-US';
  var active=false;
  micBtn.addEventListener('click',function(){if(active){rec.stop();}else{rec.start();active=true;micBtn.classList.add('recording');}});
  rec.onresult=function(e){hubIn.value=Array.from(e.results).map(function(r){return r[0].transcript;}).join('');};
  rec.onend=function(){active=false;micBtn.classList.remove('recording');};
  rec.onerror=function(){active=false;micBtn.classList.remove('recording');};
})();

/* New session */
function newSession(){sessKey='hub_'+Math.random().toString(36).substr(2,18);selMat=[];if(hubDrawerCtrl)hubDrawerCtrl.clear();var msgs=document.getElementById('hub-msgs');if(msgs){msgs.innerHTML='';addWelcome('your courses');}updateRate();}
if(newBtn)newBtn.addEventListener('click',newSession);
if(newBtn2)newBtn2.addEventListener('click',function(){newSession();switchPane('hub-tutor');});

/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'hub-attach-drawer',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}},
  {id:'hub-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
});
</script>
HTML;
    }

    public static function glassmorph_tab_bar(array $tabs, string $attrName, string $containerId): string {
        $realTabs = '';
        $fakeIcons = '';
        $i = 1;
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

            $fakeIcons .= '<div class="umat-glass-fake-box" data-glass-item="' . $i . '">'
                . '<span class="material-symbols-outlined">' . $icon . '</span></div>';
            $i++;
        }

        return '<div class="umat-glass-tabs" id="' . htmlspecialchars($containerId, ENT_QUOTES) . '">'
            . '<div class="umat-glass-spot" data-glass></div>'
            . '<div class="umat-glass-fake">' . $fakeIcons . '</div>'
            . '<div class="umat-glass-tabs-row">' . $realTabs . '</div>'
            . '</div>';
    }

    public static function glassmorph_init_js(): string {
        return '<script>'
            . 'M.util.js_pending("local_umat_ai/glassmorph_nav");'
            . 'M.util.js_pending("local_umat_ai/mobile_navbar");'
            . 'require(["local_umat_ai/glassmorph_nav","local_umat_ai/mobile_navbar"],function(gm,mn){gm.init();mn.init();M.util.js_complete("local_umat_ai/glassmorph_nav");M.util.js_complete("local_umat_ai/mobile_navbar");});'
            . '</script>';
    }
}
