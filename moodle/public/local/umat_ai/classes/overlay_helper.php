<?php

namespace local_umat_ai;

class overlay_helper {

    public static function sidebar_html(array $tabs, string $newBtnLabel, string $closeId, string $platformName = 'UMaT'): string {
        global $CFG;
        $wwwroot = rtrim($CFG->wwwroot, '/');
        $logUrl  = $wwwroot . '/login/logout.php';
        $safeSBPlatform = htmlspecialchars($platformName, ENT_QUOTES);
        $tabHtml = '';
        foreach ($tabs as $t) {
            $active = !empty($t['active']) ? ' active' : '';
            $badge = !empty($t['badge']) ? ' <span class="umat-sb-badge" id="sb-badge-' . htmlspecialchars($t['badge'], ENT_QUOTES) . '" style="display:none;margin-left:auto;background:var(--u-ter);color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:999px;line-height:14px;min-width:16px;text-align:center;"></span>' : '';
            $safeLabel = htmlspecialchars($t['label'], ENT_QUOTES);
            $tabHtml .= '<button class="umat-sb-item' . $active . '" data-sb-tab="'
                . htmlspecialchars($t['id'], ENT_QUOTES) . '" type="button" title="' . $safeLabel . '">'
                . '<span class="material-symbols-outlined">' . htmlspecialchars($t['icon'], ENT_QUOTES) . '</span>'
                . '<span class="umat-sb-item-lbl">' . $safeLabel . '</span>'
                . $badge . '</button>';
        }
        $safeLabel = htmlspecialchars($newBtnLabel, ENT_QUOTES);
        return <<<HTML
<div class="umat-sb">
    <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">school</span></div>
        <div class="umat-sb-brand"><strong>{$safeSBPlatform} Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="{$closeId}" type="button" title="Collapse sidebar">
            <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <button class="umat-sb-expand-btn" id="{$closeId}-exp" type="button" title="Expand sidebar">
            <span class="material-symbols-outlined">chevron_right</span>
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
/* Sidebar collapse/expand + body scroll lock on overlay open */
(function(){var cb=document.getElementById('{$closeId}'),ov=document.getElementById('{$overlayId}'),sb=ov?ov.querySelector('.umat-sb'):null;if(cb&&sb)cb.addEventListener('click',function(){sb.classList.toggle('collapsed');});var eb=document.getElementById('{$closeId}-exp');if(eb&&sb)eb.addEventListener('click',function(){sb.classList.remove('collapsed');});if(ov){var mo=new MutationObserver(function(){document.body.classList.toggle('umat-body-lock',ov.classList.contains('open'));});mo.observe(ov,{attributes:true,attributeFilter:['class']});}})();
/* Mobile nav: slide-to-hide + indicator pill */
document.querySelectorAll('.umat-glass-tabs').forEach(function(nav){var pill=document.createElement('div');pill.className='umat-glass-pill';nav.appendChild(pill);function mv(){var a=nav.querySelector('.umat-glass-tab.active');if(!a)return;var nr=nav.getBoundingClientRect(),tr=a.getBoundingClientRect();pill.style.left=(tr.left-nr.left)+'px';pill.style.width=tr.width+'px';}mv();nav.addEventListener('click',function(e){if(e.target.closest('.umat-glass-tab'))setTimeout(mv,30);});window.addEventListener('resize',mv);});
/* Thumbnail loader */
window.loadYtThumbnails=window.loadYtThumbnails||function(g){if(!g)return;g.querySelectorAll('.yt-tile[data-url]').forEach(function(tile){var th=tile.querySelector('.yt-thumb');if(!th||th._td)return;th._td=1;var url=tile.dataset.url||'',mime=(tile.dataset.mime||'').toLowerCase();if(!url)return;if(mime.includes('image')){var img=document.createElement('img');img.className='yt-thumb-img';img.loading='lazy';img.src=url;th.appendChild(img);}else if(mime.includes('video')){var v=document.createElement('video');v.src=url;v.preload='metadata';v.muted=true;v.style.cssText='position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:12px;';v.addEventListener('loadedmetadata',function(){v.currentTime=Math.min(2,v.duration*0.1);});v.addEventListener('seeked',function(){th.appendChild(v);});v.load();}else if(mime.includes('pdf')){var lo=document.createElement('div');lo.className='yt-thumb-loading';th.appendChild(lo);(function(){var s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';s.onload=function(){window.pdfjsLib&&(window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',pdfjsLib.getDocument(url).promise.then(function(p){return p.getPage(1);}).then(function(pg){var vp=pg.getViewport({scale:1}),sc=Math.min(th.offsetWidth/vp.width,th.offsetHeight/vp.height)||1,vp2=pg.getViewport({scale:sc}),c=document.createElement('canvas');c.className='yt-thumb-canvas';c.width=vp2.width;c.height=vp2.height;lo.remove();th.appendChild(c);pg.render({canvasContext:c.getContext('2d'),viewport:vp2});}).catch(function(){lo.remove();}));};document.head.appendChild(s);})();}else if(mime.includes('word')||mime.includes('document')||mime.includes('presentation')||mime.includes('powerpoint')||mime.includes('spreadsheet')||mime.includes('excel')){var dv=document.createElement('div');dv.className='yt-thumb-doc-preview';for(var i=0;i<6;i++){var dl=document.createElement('div');dl.className='yt-thumb-doc-line';dv.appendChild(dl);}th.appendChild(dv);}});};
new MutationObserver(function(ms){ms.forEach(function(m){m.addedNodes.forEach(function(n){if(n.nodeType!==1)return;if(n.classList&&n.classList.contains('yt-grid'))window.loadYtThumbnails(n);var gs=n.querySelectorAll&&n.querySelectorAll('.yt-grid');if(gs&&gs.length)gs.forEach(function(g){window.loadYtThumbnails(g);});});});}).observe(document.body,{childList:true,subtree:true});
/* AJAX cache (5-min TTL for analytics/struggle) */
if(typeof ajax==='function'&&!window._ajaxCached){window._ajaxCached=1;var _ac={},_at={};window._origAjax=ajax;window.ajax=function(m,a,d,f){if(m.includes('analytics')||m.includes('struggle')){var k=m+':'+JSON.stringify(a),n=Date.now();if(_ac[k]&&n-_at[k]<300000){setTimeout(function(){d(_ac[k]);},0);return;}_origAjax(m,a,function(r){_ac[k]=r;_at[k]=Date.now();d(r);},f);}else _origAjax(m,a,d,f);};}
/* Draggable FABs — separate touch/mouse handlers; drag starts after 6px threshold */
(function(){var D=null,T=6;function C(){if(!D)return;D.el.style.transition='';D.el.classList.remove('umat-fab-dragging');if(D.a){var k='umat_fab_pos_'+D.el.id;if(D.el.style.left)localStorage.setItem(k,D.el.style.left+';'+D.el.style.top);D.el.dataset.umatFabDrag='1';}_updateFabTip(D.el);D=null;}
document.querySelectorAll('.umat-fab').forEach(function(f){var k='umat_fab_pos_'+f.id,s=localStorage.getItem(k);if(s){var p=s.split(';');if(p.length===2){f.style.left=p[0];f.style.top=p[1];f.style.bottom='auto';f.style.right='auto';}}});
/* Tooltip flip: show on opposite side of FAB */
function _updateFabTip(f){if(!f)return;var r=f.getBoundingClientRect(),c=r.left+r.width/2,m=window.innerWidth/2;f.classList.toggle('umat-fab-tip-right',c<m);}
function _updateAllFabTips(){document.querySelectorAll('.umat-fab').forEach(_updateFabTip);}
_updateAllFabTips();window.addEventListener('resize',_updateAllFabTips);
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


    public static function student_overlay(int $courseid, string $courseName, string $wwwroot, object $user, string $userData, string $platformName = 'UMaT'): string {
        $safePlatform = htmlspecialchars($platformName, ENT_QUOTES);
        $safeName  = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCid     = (int)$courseid;
        $jsName    = json_encode($courseName);
        $userName  = fullname($user);
        $safeUser  = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $initials  = strtoupper(mb_substr($user->firstname, 0, 1) . mb_substr($user->lastname, 0, 1));
        $approveUrl = $wwwroot . '/local/umat_ai/approve.php?courseid=' . $courseid;
        $streamUrl = json_encode('/local/umat_ai/chat_stream.php');
        $moodleSesskey = json_encode(sesskey());

        $tabs = [
            ['id' => 'home',      'icon' => 'home',          'label' => 'Home',      'active' => true],
            ['id' => 'ai-tutor',  'icon' => 'smart_toy',     'label' => 'AI Tutor',  'active' => false],
            ['id' => 'courses',   'icon' => 'menu_book',     'label' => 'My Courses','active' => false],
            ['id' => 'library',   'icon' => 'local_library', 'label' => 'Resource Materials', 'active' => false],
            ['id' => 'my-notes',  'icon' => 'note_add',      'label' => 'My Notes',  'active' => false],
            ['id' => 'sessions',   'icon' => 'chat_bubble',   'label' => 'Sessions',   'active' => false],
            ['id' => 'report-issue', 'icon' => 'forum',      'label' => 'Student Issues','active' => false, 'badge' => 'responses'],
        ];
        $sidebar = self::sidebar_html($tabs, 'New Session', 'stu-ws-close', $platformName);
        $sharedJs = self::shared_js('umat-student-ov', 'stu-ws-close');

        // Glassmorphism mobile tab bar (in-overlay)
        $stuGlassTabs = [
            ['id' => 'home',     'icon' => 'home',        'label' => 'Home',     'active' => true],
            ['id' => 'ai-tutor', 'icon' => 'smart_toy',   'label' => 'Tutor',    'active' => false],
            ['id' => 'courses',  'icon' => 'menu_book',   'label' => 'Courses',  'active' => false],
            ['id' => 'library',  'icon' => 'local_library','label' => 'Resource Materials', 'active' => false],
            ['id' => 'my-notes', 'icon' => 'note_add',    'label' => 'Notes',    'active' => false],
            ['id' => 'sessions',   'icon' => 'chat_bubble', 'label' => 'Sessions',  'active' => false],
            ['id' => 'report-issue', 'icon' => 'forum',    'label' => 'Issues',    'active' => false, 'badge' => 'responses'],
        ];
        $stuMobTabs = self::glassmorph_tab_bar($stuGlassTabs, 'sb-tab', 'stu-glass-tabs');

        return <<<HTML

<!-- STUDENT FAB -->
<button class="umat-fab umat-fab-pulse" id="umat-stu-fab" type="button" aria-label="Open AI Assistant">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tip">{$safePlatform} AI Assistant</span>
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
      <button class="umat-cp-feature-tab" data-cp-pane="cp-library" type="button"><span class="material-symbols-outlined">play_circle</span><span>Resources</span></button>
      <button class="umat-cp-feature-tab" data-cp-pane="cp-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span>Courses</span></button>
      <button class="umat-cp-feature-tab" data-cp-pane="cp-notes" type="button"><span class="material-symbols-outlined">note_add</span><span>Notes</span></button>
      <button class="umat-cp-feature-tab" data-cp-pane="cp-sessions" type="button"><span class="material-symbols-outlined">chat_bubble</span><span>Sessions</span></button>
      <button class="umat-cp-feature-tab" data-cp-pane="cp-report" type="button"><span class="material-symbols-outlined">flag</span><span>Report</span></button>
    </div>
    <div class="umat-cp-pane active" id="cp-chat">
      <div class="umat-msgs" id="cp-msgs" style="padding-bottom:80px;">
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
          <button class="umat-quiz-review" id="cp-quiz-review" type="button"><span class="material-symbols-outlined">rate_review</span>Review Answers</button>
          <button class="umat-quiz-close" id="cp-quiz-close-pane" type="button"><span class="material-symbols-outlined">chat</span>Back to Chat</button>
        </div>
      </div>
      <div class="umat-chat-overlay">
        <button class="umat-scroll-bottom" id="cp-scroll-bottom" type="button"><span class="material-symbols-outlined">expand_more</span></button>
        <div class="umat-chatbar">
          <textarea id="cp-input" class="umat-chatbar-input" placeholder="Ask anything…" rows="1" maxlength="900"></textarea>
          <button class="umat-chatbar-btn" id="cp-mic" type="button" title="Voice input"><span class="material-symbols-outlined">mic</span></button>
          <button class="umat-chatbar-send" id="cp-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
        </div>
        <div class="umat-mat-bar" id="cp-mat-bar"></div>
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
      <div style="padding:6px 14px;border-top:1px solid var(--u-olv);">
        <button class="lcp-pane-expand" id="cp-notes-open-btn" type="button" title="Open full notes"><span class="material-symbols-outlined">open_in_full</span> View all notes</button>
      </div>
    </div>
    <div class="umat-cp-pane" id="cp-resources">
      <div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>Indexed course materials will appear here.</p></div>
    </div>
    <!-- COMPACT STUDENT LIBRARY PANE -->
    <div class="umat-cp-pane" id="cp-library" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">play_circle</span>
        <strong style="font-size:12px;">Lectures & Materials</strong>
        <button class="lcp-pane-expand" id="cp-lib-open-btn" type="button" title="Open full library"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div id="cp-lib-body" class="lcp-pane-list" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
    </div>
    <!-- COMPACT STUDENT COURSES PANE -->
    <div class="umat-cp-pane" id="cp-courses" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">menu_book</span>
        <strong style="font-size:12px;">My Courses</strong>
        <button class="lcp-pane-expand" id="cp-courses-open-btn" type="button" title="Open full courses"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div id="cp-courses-list" class="lcp-pane-list" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
    </div>
    <!-- COMPACT STUDENT SESSIONS PANE -->
    <div class="umat-cp-pane" id="cp-sessions" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">chat_bubble</span>
        <strong style="font-size:12px;">Sessions</strong>
        <button class="lcp-pane-expand" id="cp-sess-open-btn" type="button" title="Open full sessions"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div id="cp-sess-body" class="lcp-pane-list" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
    </div>
    <!-- COMPACT STUDENT REPORT PANE -->
    <div class="umat-cp-pane" id="cp-report" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">flag</span>
        <strong style="font-size:12px;">Report Issue</strong>
        <button class="lcp-pane-expand" id="cp-report-open-btn" type="button" title="Open full report"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div id="cp-report-body" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
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
            <button class="umat-qa-btn" data-sb-tab="library" type="button">
              <span class="material-symbols-outlined">play_circle</span>
              <div class="umat-qa-btn-text"><strong>Lectures &amp; Materials</strong><span>Browse recordings and resources</span></div>
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
      <div style="display:flex;flex:1;overflow:hidden;position:relative;">
        <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative;">
          <div id="ws-chips">
            <button class="umat-chip" data-q="Explain the key concept discussed in the most recent lecture." type="button">Explain key concept</button>
            <button class="umat-chip" data-q="Can you compare this topic with what was covered earlier in the course?" type="button">Compare topics</button>
            <button class="umat-chip" data-q="Create a practice quiz on this week's material." type="button">Practice quiz</button>
            <button class="umat-chip" data-q="What are the most common exam questions for this topic?" type="button">Exam prep</button>
          </div>
          <div class="umat-msgs" id="ws-msgs">
            <div class="umat-msg-ai" data-msg-id="msg_0" data-msg-role="ai">
              <div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>
              <div class="umat-msg-ai-wrap">
                <div class="umat-msg-lbl">AI TUTOR</div>
                <div class="umat-bubble-ai"><p>Welcome to your AI Tutor for <strong>{$safeName}</strong>! I can reference your selected course materials for precise answers. Use the attachment button to select specific materials, or ask me anything!</p></div>
              </div>
            </div>
          </div>
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
        </div>
        <div class="umat-msg-nav" id="ws-msg-nav"></div>
      </div>
      <div class="umat-chat-overlay">
        <button class="umat-scroll-bottom" id="ws-scroll-bottom" type="button"><span class="material-symbols-outlined">expand_more</span></button>
        <div class="umat-chatbar">
          <button class="umat-chatbar-btn" id="ws-attach-btn" type="button"><span class="material-symbols-outlined">add</span></button>
          <textarea id="ws-input" class="umat-chatbar-input" placeholder="Ask AI about this course…" rows="1" maxlength="900"></textarea>
          <button class="umat-chatbar-btn" id="ws-mic-btn" type="button" title="Voice input"><span class="material-symbols-outlined">mic</span></button>
          <button class="umat-chatbar-send" id="ws-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
        </div>
        <div class="umat-mat-bar" id="ws-mat-bar"></div>
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
          <button class="umat-quiz-review" id="ws-quiz-review" type="button"><span class="material-symbols-outlined">rate_review</span>Review Answers</button>
          <button class="umat-quiz-close" id="ws-quiz-close-pane" type="button"><span class="material-symbols-outlined">chat</span>Back to Chat</button>
        </div>
      </div>
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

    <!-- LIBRARY TAB — Lecture Recordings + Course Materials -->
    <div class="umat-tab-pane" data-tab="library" style="position:relative;overflow-y:auto;">
      <div class="umat-content-hdr">
        <h2>Course Library</h2>
        <input class="umat-lib-search" id="ws-lib-search" type="text" placeholder="Search recordings & materials…" />
      </div>
      <!-- Lecture Recordings section -->
      <div class="umat-lib-section">
        <div class="umat-lib-section-hdr">
          <span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">play_circle</span>
          <h3>Lecture Recordings</h3>
        </div>
        <div class="umat-video-grid" id="ws-lib-lectures">
          <div class="umat-empty"><span class="material-symbols-outlined">play_circle</span><p>Loading lecture recordings…</p></div>
        </div>
      </div>
      <!-- Course Materials section -->
      <div class="umat-lib-section">
        <div class="umat-lib-section-hdr">
          <span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">local_library</span>
          <h3>Course Materials</h3>
        </div>
        <div class="umat-lib-grid" id="ws-lib-grid">
          <div class="umat-empty"><span class="material-symbols-outlined">local_library</span><p>Loading course materials…</p></div>
        </div>
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

      <!-- STUDENT ISSUES TAB -->
    <div class="umat-tab-pane" data-tab="report-issue">
      <div class="umat-content-hdr">
        <h2><span class="material-symbols-outlined" style="vertical-align:middle;margin-right:6px;">forum</span>Student Issues</h2>
        <button class="umat-sb-new" id="ws-issue-new-btn" type="button">
          <span class="material-symbols-outlined">add</span>
          <span>Report an Issue</span>
        </button>
      </div>
      <div class="umat-issue-app" id="ws-issue-app">
        <section class="umat-issue-view active" id="ws-issue-list-view" aria-label="Issue conversations">
          <div class="umat-issue-list-head">
            <div><strong>Conversations</strong><span>Private messages for this course</span></div>
            <button class="umat-icon-btn" id="ws-issue-refresh" type="button" aria-label="Refresh conversations"><span class="material-symbols-outlined">refresh</span></button>
          </div>
          <div class="umat-issue-list" id="ws-issue-list">
            <div class="umat-empty"><span class="material-symbols-outlined">forum</span><p>Loading conversations...</p></div>
          </div>
        </section>
        <section class="umat-issue-view" id="ws-issue-new-view" aria-label="Report a new issue">
          <div class="umat-issue-viewbar"><button class="umat-icon-btn" id="ws-issue-new-back" type="button" aria-label="Back to conversations"><span class="material-symbols-outlined">arrow_back</span></button><div><strong>Report a New Issue</strong><span>Your message is private between you and the course lecturer.</span></div></div>
          <form class="umat-issue-form" id="ws-issue-form">
            <label>Issue title<input type="text" id="ws-issue-title" maxlength="255" required placeholder="Briefly describe what you need help with"></label>
            <label>Category<select id="ws-issue-cat" required>
              <option value="course_material">Course material</option><option value="assignment">Assignment</option>
              <option value="quiz_examination">Quiz or examination</option><option value="grade_feedback">Grade or feedback</option>
              <option value="live_class_recording">Live class or recording</option><option value="technical_problem">Technical problem</option>
              <option value="access_permission">Access or permission</option><option value="other">Other</option>
            </select></label>
            <label>Description<textarea id="ws-issue-desc" rows="6" maxlength="10000" required placeholder="Describe the issue and what you have already tried"></textarea></label>
            <label class="umat-issue-file-label"><span class="material-symbols-outlined">attach_file</span><span>Optional attachment</span><input type="file" id="ws-issue-file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.gif,.webp,.mp3,.mp4,.wav"></label>
            <div class="umat-issue-form-msg" id="ws-issue-form-msg" role="status" aria-live="polite"></div>
            <div class="umat-issue-form-actions"><button class="umat-issue-secondary" id="ws-issue-new-cancel" type="button">Cancel</button><button class="umat-btn-p" id="ws-issue-submit" type="submit"><span class="material-symbols-outlined">send</span>Send to Lecturer</button></div>
          </form>
        </section>
        <section class="umat-issue-view" id="ws-issue-thread-view" aria-label="Issue conversation">
          <div class="umat-issue-thread-head"><button class="umat-icon-btn" id="ws-issue-thread-back" type="button" aria-label="Back to conversations"><span class="material-symbols-outlined">arrow_back</span></button><div class="umat-issue-thread-title"><strong id="ws-issue-thread-title"></strong><span id="ws-issue-thread-meta"></span></div></div>
          <div class="umat-issue-messages" id="ws-issue-messages" aria-live="polite"></div>
          <div class="umat-issue-send-error" id="ws-issue-send-error" role="alert"></div>
          <div class="umat-issue-composer">
            <label class="umat-issue-attach-btn" for="ws-issue-reply-file" title="Attach a file"><span class="material-symbols-outlined">attach_file</span><span class="sr-only">Attach</span></label>
            <input type="file" id="ws-issue-reply-file" hidden accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.gif,.webp,.mp3,.mp4,.wav">
            <textarea id="ws-issue-reply" rows="1" maxlength="10000" placeholder="Type your message..."></textarea>
            <button class="umat-issue-send-btn" id="ws-issue-send" type="button"><span class="material-symbols-outlined">send</span><span class="sr-only">Send</span></button>
          </div>
        </section>
      </div>
    </div>

  </div><!-- /ov-content -->

  {$stuMobTabs}
</div><!-- /student workspace overlay -->

{$sharedJs}

<script>/* Student overlay JS moved to amd/src/umat_student.js */</script>
HTML;
    }

    public static function lecturer_overlay(int $courseid, string $courseName, string $wwwroot, object $user, string $userData, string $platformName = 'UMaT'): string {
        $safe        = htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8');
        $jsCid       = (int)$courseid;
        $jsName      = json_encode($courseName);
        $jsWwwroot   = json_encode(rtrim($wwwroot, '/'));
        $jsUD        = $userData;
        $uid         = (int)$user->id;
        $uName       = json_encode(fullname($user));
        $uInit       = htmlspecialchars(strtoupper(mb_substr($user->firstname,0,1).mb_substr($user->lastname,0,1)), ENT_QUOTES);
        $logUrl      = $wwwroot . '/login/logout.php';

        $safePlatform = htmlspecialchars($platformName, ENT_QUOTES);
        $enableRb = get_config('local_umat_ai', 'enable_resource_bank') !== '0';
        // Pre-build conditional resource bank HTML snippets (avoids PHP-in-heredoc issues)
        $rbToggleBtn = $enableRb ? '
          <button class="umat-lib-toggle" data-libview="private" type="button" style="flex:1;padding:7px 10px;border:none;border-radius:20px;cursor:pointer;font-size:11px;font-family:inherit;">
            <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;margin-right:3px;">folder_special</span>Private Bank
          </button>' : '';
        $rbViewHtml = $enableRb ? '
        <!-- Private Bank view (hidden initially) -->
        <div id="lec-private-bank-view" style="display:none;flex-direction:column;flex:1;min-height:0;">
          <!-- Toolbar -->
          <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--u-olv);flex-wrap:wrap;">
            <span style="font-size:12px;font-weight:600;color:var(--u-ons);margin-right:4px;">Private Resources</span>
            <button type="button" class="rb-action-btn" id="rb-upload-btn" title="Upload files" style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid var(--u-p);border-radius:var(--u-rp);font-size:11px;font-weight:600;color:var(--u-p);background:var(--u-sflo);cursor:pointer;font-family:inherit;">
              <span class="material-symbols-outlined" style="font-size:14px;">upload</span>Upload
            </button>
            <button type="button" class="rb-action-btn" id="rb-new-folder-btn" title="Create folder" style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:11px;font-weight:500;color:var(--u-ons);background:var(--u-sflo);cursor:pointer;font-family:inherit;">
              <span class="material-symbols-outlined" style="font-size:14px;">create_new_folder</span>Folder
            </button>
            <div style="flex:1;"></div>
            <button type="button" class="rb-batch-btn" id="rb-delete-btn" disabled style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid var(--u-ter);border-radius:var(--u-rp);font-size:11px;font-weight:600;color:var(--u-ter);background:var(--u-sflo);cursor:pointer;font-family:inherit;opacity:.4;">
              <span class="material-symbols-outlined" style="font-size:14px;">delete</span>Delete
            </button>
            <button type="button" class="rb-batch-btn" id="rb-push-btn" disabled style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid var(--u-p);border-radius:var(--u-rp);font-size:11px;font-weight:600;color:var(--u-p);background:var(--u-sflo);cursor:pointer;font-family:inherit;opacity:.4;">
              <span class="material-symbols-outlined" style="font-size:14px;">publish</span>Push to Course
            </button>
          </div>
          <!-- Breadcrumb -->
          <div id="rb-breadcrumb" style="display:flex;align-items:center;gap:4px;padding:6px 12px;font-size:11px;color:var(--u-ol);border-bottom:1px solid var(--u-olv);">
            <span style="cursor:pointer;color:var(--u-p);font-weight:600;" data-rb-root="1">My Resources</span>
          </div>
          <!-- Files area -->
          <div id="rb-content" style="flex:1;overflow-y:auto;padding:8px 12px;">
            <div id="rb-loading" style="text-align:center;padding:40px 0;color:var(--u-ol);font-size:12px;">Loading…</div>
          </div>
          <!-- Hidden file input -->
          <input type="file" id="rb-file-input" multiple style="display:none;">
        </div>
        <!-- Push to Course overlay -->
        <div class="umat-cs-overlay" id="rb-push-ov" style="display:none;">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">publish</span>Push to Course</h3>
              <button class="umat-cs-close" id="rb-push-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="rb-push-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="rb-push-list"></div>
            <div style="padding:12px 16px;display:flex;gap:8px;justify-content:flex-end;border-top:1px solid var(--u-olv);">
              <button type="button" id="rb-push-cancel" style="padding:6px 14px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;background:var(--u-sflo);color:var(--u-ons);cursor:pointer;font-family:inherit;">Cancel</button>
              <button type="button" id="rb-push-confirm" disabled style="padding:6px 14px;border:none;border-radius:var(--u-r8);font-size:12px;font-weight:600;background:var(--u-p);color:#fff;cursor:pointer;font-family:inherit;opacity:.5;">Push Selected</button>
            </div>
          </div>
        </div>
        <!-- RB Upload overlay (drag & drop) -->
        <div class="umat-cs-overlay" id="rb-upload-ov" style="display:none;">
          <div class="umat-cs-modal" style="max-width:420px;">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">cloud_upload</span>Upload Resources</h3>
              <button class="umat-cs-close" id="rb-upload-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div style="padding:16px;">
              <div id="rb-upload-dropzone" style="border:2px dashed var(--u-olv);border-radius:var(--u-r12);padding:32px 16px;text-align:center;cursor:pointer;transition:border-color .2s;background:var(--u-sflo);">
                <span class="material-symbols-outlined" style="font-size:40px;color:var(--u-ol);">cloud_upload</span>
                <p style="margin:8px 0 4px;font-size:13px;font-weight:600;color:var(--u-ons);">Drop files here or click to browse</p>
                <p style="margin:0;font-size:11px;color:var(--u-ol);">Any file type (max 500 MB per file)</p>
              </div>
              <div id="rb-upload-progress" style="display:none;margin-top:12px;">
                <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--u-ol);margin-bottom:4px;">
                  <span id="rb-upload-fname"></span>
                  <span id="rb-upload-pct">0%</span>
                </div>
                <div style="height:4px;background:var(--u-olv);border-radius:2px;overflow:hidden;">
                  <div id="rb-upload-bar" style="height:100%;width:0%;background:var(--u-p);border-radius:2px;transition:width .3s;"></div>
                </div>
              </div>
              <div id="rb-upload-result" style="display:none;margin-top:12px;padding:10px;border-radius:var(--u-r8);font-size:12px;"></div>
              <div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end;">
                <button type="button" id="rb-upload-cancel" style="padding:6px 14px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;background:var(--u-sflo);color:var(--u-ons);cursor:pointer;font-family:inherit;">Cancel</button>
              </div>
            </div>
          </div>
        </div>
        <!-- RB Folder overlay -->
        <div class="umat-cs-overlay" id="rb-folder-ov" style="display:none;">
          <div class="umat-cs-modal" style="max-width:380px;">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">create_new_folder</span>Create Folder</h3>
              <button class="umat-cs-close" id="rb-folder-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div style="padding:16px;">
              <label style="font-size:11px;font-weight:600;color:var(--u-ol);display:block;margin-bottom:4px;">Folder Name</label>
              <input type="text" id="rb-folder-name" placeholder="Enter folder name…" style="width:100%;padding:8px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r6);font-size:13px;background:var(--u-bg);color:var(--u-ons);outline:none;font-family:inherit;box-sizing:border-box;">
              <div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end;">
                <button type="button" id="rb-folder-cancel" style="padding:6px 14px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;background:var(--u-sflo);color:var(--u-ons);cursor:pointer;font-family:inherit;">Cancel</button>
                <button type="button" id="rb-folder-submit" style="padding:6px 14px;border:none;border-radius:var(--u-r8);font-size:12px;font-weight:600;background:var(--u-p);color:#fff;cursor:pointer;font-family:inherit;">Create</button>
              </div>
            </div>
          </div>
        </div>
        <!-- RB Rename overlay -->
        <div class="umat-cs-overlay" id="rb-rename-ov" style="display:none;">
          <div class="umat-cs-modal" style="max-width:380px;">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">edit</span>Rename</h3>
              <button class="umat-cs-close" id="rb-rename-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div style="padding:16px;">
              <label style="font-size:11px;font-weight:600;color:var(--u-ol);display:block;margin-bottom:4px;">New Name</label>
              <input type="text" id="rb-rename-name" placeholder="Enter new name…" style="width:100%;padding:8px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r6);font-size:13px;background:var(--u-bg);color:var(--u-ons);outline:none;font-family:inherit;box-sizing:border-box;">
              <div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end;">
                <button type="button" id="rb-rename-cancel" style="padding:6px 14px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;background:var(--u-sflo);color:var(--u-ons);cursor:pointer;font-family:inherit;">Cancel</button>
                <button type="button" id="rb-rename-submit" style="padding:6px 14px;border:none;border-radius:var(--u-r8);font-size:12px;font-weight:600;background:var(--u-p);color:#fff;cursor:pointer;font-family:inherit;">Rename</button>
              </div>
            </div>
          </div>
        </div>' : '';
        $sharedJs = self::shared_js('lec-ov', 'lec-ov-close');
        $streamUrl = json_encode('/local/umat_ai/chat_stream.php');
        $moodleSesskey = json_encode(sesskey());

        // Glassmorphism mobile tab bar (in-overlay)
        $lecGlassTabs = [
            ['id' => 'lec-home',      'icon' => 'home',         'label' => 'Home',     'active' => true],
            ['id' => 'lec-insights',  'icon' => 'psychology',    'label' => 'Insights','active' => false],
            ['id' => 'lec-quizgen',   'icon' => 'quiz',          'label' => 'Quiz Gen','active' => false],
            ['id' => 'lec-courses',   'icon' => 'menu_book',     'label' => 'Courses',  'active' => false],
            ['id' => 'lec-library',   'icon' => 'local_library', 'label' => 'Resource Materials', 'active' => false],
            ['id' => 'lec-sessions',  'icon' => 'history',       'label' => 'Sessions', 'active' => false],
            ['id' => 'lec-issues',   'icon' => 'forum',          'label' => 'Issues',   'active' => false, 'badge' => 'lec-issues'],
        ];
        $lecMobTabs = self::glassmorph_tab_bar($lecGlassTabs, 'lp', 'lec-glass-tabs');

        global $OUTPUT;
        $struggleDashboardHtml = $OUTPUT->render_from_template('local_umat_ai/struggle_dashboard', [
            'stream_url' => '/local/umat_ai/chat_stream.php',
            'sesskey'    => sesskey(),
        ]);

        return <<<HTML
<!-- ============================================================
     LECTURER FAB + COMPACT PANEL + ANALYTICS OVERLAY
     ============================================================ -->

<!-- FAB -->
<button class="umat-fab umat-fab-pulse" id="lec-fab" type="button" aria-label="Open Analytics" style="position:relative;">
  <span class="material-symbols-outlined">leaderboard</span>
  <span class="umat-fab-tip">Lecturer Analytics</span>
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
      <button class="umat-cp-tab" data-lcp-tab="lcp-ai" type="button">Ask AI</button>
    </div>
    <div class="umat-cp-feature-tabs" aria-label="Quick lecturer tools" role="tablist">
      <button class="umat-cp-feature-tab active" data-lcp-pane="lcp-insights" type="button"><span class="material-symbols-outlined">home</span><span>Home</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-ai" type="button"><span class="material-symbols-outlined">smart_toy</span><span>Ask AI</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-insights-dash" type="button"><span class="material-symbols-outlined">psychology</span><span>Insights</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-quizgen" type="button"><span class="material-symbols-outlined">quiz</span><span>Quiz Gen</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-courses" type="button"><span class="material-symbols-outlined">menu_book</span><span>Courses</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-library" type="button"><span class="material-symbols-outlined">local_library</span><span>Resources</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-sessions" type="button"><span class="material-symbols-outlined">history</span><span>Sessions</span></button>
      <button class="umat-cp-feature-tab" data-lcp-pane="lcp-issues" type="button"><span class="material-symbols-outlined">flag</span><span>Issues</span></button>
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
          <div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI ASSISTANT</div>
            <div class="umat-bubble-ai"><p>Ask me about your course analytics — e.g. <em>"Which topics are students struggling with?"</em></p></div>
            <div class="umat-chips-row">
              <button class="umat-chip" data-lp="Which topics are students struggling with the most?" type="button">Struggle areas</button>
              <button class="umat-chip" data-lp="Summarise student AI questions from this week." type="button">Weekly summary</button>
              <button class="umat-chip" data-lp="Which students appear at risk based on AI usage?" type="button">At-risk students</button>
            </div>
          </div>
        </div>
      </div>
      <div class="umat-attach-drawer umat-drawer-enhanced" id="lcp-attach-drawer">
        <div class="umat-drawer-hdr">
          <div class="umat-drawer-hdr-left">
            <span class="material-symbols-outlined" style="font-size:17px;color:var(--u-p);">attach_file</span>
            <h4>Select Materials</h4>
            <span class="umat-drawer-count" id="lcp-drawer-count">0 selected</span>
          </div>
          <div class="umat-drawer-hdr-actions">
            <button class="umat-drawer-clear-btn" id="lcp-drawer-clear" type="button">Clear</button>
            <button class="umat-drawer-close-btn" id="lcp-drawer-close" type="button"><span class="material-symbols-outlined">close</span></button>
          </div>
        </div>
        <div class="umat-drawer-search-wrap">
          <span class="material-symbols-outlined umat-drawer-search-icon">search</span>
          <input type="text" id="lcp-drawer-search" placeholder="Search materials…">
        </div>
        <div class="umat-drawer-cats" id="lcp-drawer-cats"></div>
        <div class="umat-drawer-recent" id="lcp-drawer-recent"></div>
        <div class="umat-drawer-list" id="lcp-drawer-list"><div class="umat-drawer-loading"><div class="umat-vw-spinner"></div><span>Loading materials&hellip;</span></div></div>
        <div class="umat-drawer-foot">
          <span class="umat-drawer-foot-info">Select materials for AI</span>
          <button class="umat-drawer-confirm" id="lcp-drawer-confirm" type="button"><span class="material-symbols-outlined">check</span> Use Selected</button>
        </div>
      </div>
      <div class="umat-chat-overlay">
        <div class="umat-chatbar">
          <button class="umat-chatbar-btn" id="lcp-attach-btn" type="button"><span class="material-symbols-outlined">add</span></button>
          <textarea id="lcp-input" class="umat-chatbar-input" placeholder="Ask about your course…" rows="1" maxlength="700"></textarea>
          <button class="umat-chatbar-send" id="lcp-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
        </div>
        <div class="umat-mat-bar" id="lcp-mat-bar"></div>
      </div>
    </div>
    <!-- COMPACT INSIGHTS DASHBOARD PANE -->
    <div class="umat-cp-pane" id="lcp-insights-dash" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">psychology</span>
        <strong style="font-size:12px;">Struggle Overview</strong>
        <button class="lcp-pane-expand" id="lcp-dash-open-btn" type="button" title="Open full dashboard"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div class="lcp-dash-tiles" id="lcp-dash-tiles">
        <div class="lcp-dash-tile"><div class="lcp-dash-tile-val" id="lcp-d-students">—</div><div class="lcp-dash-tile-lbl">Students</div></div>
        <div class="lcp-dash-tile"><div class="lcp-dash-tile-val" id="lcp-d-risk" style="color:var(--u-ter);">—</div><div class="lcp-dash-tile-lbl">At Risk</div></div>
        <div class="lcp-dash-tile"><div class="lcp-dash-tile-val" id="lcp-d-questions">—</div><div class="lcp-dash-tile-lbl">Questions</div></div>
        <div class="lcp-dash-tile"><div class="lcp-dash-tile-val" id="lcp-d-score">—</div><div class="lcp-dash-tile-lbl">Avg Score</div></div>
      </div>
      <div class="lcp-pane-section">
        <div class="lcp-pane-section-title">Top Struggling Topics</div>
        <div id="lcp-d-topics" class="lcp-pane-list"><div class="lcp-pane-loading">Loading…</div></div>
      </div>
      <div class="lcp-pane-section">
        <div class="lcp-pane-section-title">At-Risk Students</div>
        <div id="lcp-d-students-list" class="lcp-pane-list"><div class="lcp-pane-loading">Loading…</div></div>
      </div>
      <div class="lcp-pane-section">
        <div class="lcp-pane-section-title">Recent Questions</div>
        <div id="lcp-d-questions-list" class="lcp-pane-list"><div class="lcp-pane-loading">Loading…</div></div>
      </div>
    </div>
    <!-- COMPACT QUIZ GENERATOR PANE -->
    <div class="umat-cp-pane" id="lcp-quizgen" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">quiz</span>
        <strong style="font-size:12px;">Quiz Generator</strong>
        <button class="lcp-pane-expand" id="lcp-qgen-open-btn" type="button" title="Open full generator"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div style="padding:8px 14px;display:flex;flex-direction:column;gap:6px;">
        <select id="lcp-qgen-course" style="width:100%;padding:6px 8px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:11px;background:var(--u-bg);color:var(--u-ons);font-family:inherit;"></select>
        <textarea id="lcp-qgen-topic" placeholder="Paste content or enter topic…" rows="2" style="width:100%;padding:6px 8px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:11px;background:var(--u-bg);color:var(--u-ons);font-family:inherit;resize:vertical;"></textarea>
        <div style="display:flex;gap:6px;">
          <select id="lcp-qgen-count" style="flex:1;padding:6px 8px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:11px;background:var(--u-bg);color:var(--u-ons);font-family:inherit;">
            <option value="5">5 Qs</option><option value="10" selected>10 Qs</option><option value="20">20 Qs</option>
          </select>
          <select id="lcp-qgen-type" style="flex:1;padding:6px 8px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:11px;background:var(--u-bg);color:var(--u-ons);font-family:inherit;">
            <option value="mcq">MCQ</option><option value="short">Short Answer</option><option value="mixed">Mixed</option>
          </select>
          <select id="lcp-qgen-diff" style="flex:1;padding:6px 8px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:11px;background:var(--u-bg);color:var(--u-ons);font-family:inherit;">
            <option value="easy">Easy</option><option value="medium" selected>Medium</option><option value="hard">Hard</option>
          </select>
        </div>
        <button class="umat-btn-p" id="lcp-qgen-gen" type="button" style="justify-content:center;font-size:12px;"><span class="material-symbols-outlined" style="font-size:16px;">auto_awesome</span>Generate Quiz</button>
      </div>
      <div id="lcp-qgen-result" style="padding:0 14px 14px;"></div>
    </div>
    <!-- COMPACT COURSES PANE -->
    <div class="umat-cp-pane" id="lcp-courses" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">menu_book</span>
        <strong style="font-size:12px;">My Courses</strong>
      </div>
      <div id="lcp-courses-list" class="lcp-pane-list" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
    </div>
    <!-- COMPACT RESOURCE MATERIALS PANE -->
    <div class="umat-cp-pane" id="lcp-library" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">local_library</span>
        <strong style="font-size:12px;">Resource Materials</strong>
        <button class="lcp-pane-expand" id="lcp-lib-open-btn" type="button" title="Open full library"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div id="lcp-lib-body" class="lcp-pane-list" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
    </div>
    <!-- COMPACT SESSIONS PANE -->
    <div class="umat-cp-pane" id="lcp-sessions" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">history</span>
        <strong style="font-size:12px;">Sessions</strong>
        <button class="lcp-pane-expand" id="lcp-sess-open-btn" type="button" title="Open full sessions"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div id="lcp-sess-body" class="lcp-pane-list" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
    </div>
    <!-- COMPACT ISSUES PANE -->
    <div class="umat-cp-pane" id="lcp-issues" style="overflow-y:auto;">
      <div class="lcp-pane-hdr">
        <span class="material-symbols-outlined" style="font-size:16px;color:var(--u-p);">forum</span>
        <strong style="font-size:12px;">Student Issues</strong>
        <button class="lcp-pane-expand" id="lcp-iss-open-btn" type="button" title="Open full issues"><span class="material-symbols-outlined">open_in_full</span></button>
      </div>
      <div id="lcp-iss-body" class="lcp-pane-list" style="padding:8px 14px;"><div class="lcp-pane-loading">Loading…</div></div>
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
        <div class="umat-sb-brand"><strong>{$safePlatform} Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="lec-ov-close" type="button" title="Collapse sidebar">
          <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <button class="umat-sb-expand-btn" id="lec-ov-close-exp" type="button" title="Expand sidebar">
          <span class="material-symbols-outlined">chevron_right</span>
        </button>
      </div>
      <nav class="umat-sb-nav">
        <button class="umat-sb-item active" data-lp="lec-home" type="button" title="Home"><span class="material-symbols-outlined">home</span><span class="umat-sb-item-lbl">Home</span></button>
        <button class="umat-sb-item" data-lp="lec-insights" type="button" title="Insights"><span class="material-symbols-outlined">psychology</span><span class="umat-sb-item-lbl">Insights</span></button>
        <button class="umat-sb-item" data-lp="lec-quizgen" type="button" title="Quiz Generator"><span class="material-symbols-outlined">quiz</span><span class="umat-sb-item-lbl">Quiz Generator</span></button>
        <button class="umat-sb-item" data-lp="lec-courses" type="button" title="My Courses"><span class="material-symbols-outlined">menu_book</span><span class="umat-sb-item-lbl">My Courses</span></button>
        <button class="umat-sb-item" data-lp="lec-library" type="button" title="Resource Materials"><span class="material-symbols-outlined">local_library</span><span class="umat-sb-item-lbl">Resource Materials</span></button>
        <button class="umat-sb-item" data-lp="lec-sessions" type="button" title="Sessions"><span class="material-symbols-outlined">history</span><span class="umat-sb-item-lbl">Sessions</span></button>
        <button class="umat-sb-item" data-lp="lec-issues" type="button" title="Student Issues"><span class="material-symbols-outlined">forum</span><span class="umat-sb-item-lbl">Student Issues</span><span class="umat-sb-badge" id="sb-badge-new-issues" style="display:none;margin-left:auto;background:var(--u-ter);color:#fff;font-size:9px;font-weight:700;padding:1px 5px;border-radius:999px;line-height:14px;min-width:16px;text-align:center;"></span></button>
      </nav>
      <div class="umat-sb-divider"></div>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'" title="Sign Out">
          <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
      </div>
    </div>

    <!-- CONTENT -->
    <div class="umat-ov-content" style="width:100%;min-width:0;flex:1;">
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
          <div class="umat-metrics-row">
            <div class="umat-metric-card"><div class="umat-metric-icon mi-g"><span class="material-symbols-outlined">group</span></div><div><div class="umat-metric-val" id="lec-met-active">—</div><div class="umat-metric-lbl">Active students</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-w"><span class="material-symbols-outlined">school</span></div><div><div class="umat-metric-val" id="lec-met-friction">—</div><div class="umat-metric-lbl">Friction topic</div></div></div>
            <div class="umat-metric-card"><div class="umat-metric-icon mi-r"><span class="material-symbols-outlined">trending_up</span></div><div><div class="umat-metric-val" id="lec-met-engagement">—</div><div class="umat-metric-lbl">Engagement</div></div></div>
          </div>
          <div class="umat-home-section" style="margin-top:20px;">
            <h3>Quick Actions</h3>
            <div class="umat-quick-actions-grid">
              <button class="umat-qa-btn" data-lp="lec-insights" type="button"><span class="material-symbols-outlined">psychology</span><div class="umat-qa-btn-text"><strong>Insights Dashboard</strong><span>Student struggle &amp; course analytics</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-quizgen" type="button"><span class="material-symbols-outlined">quiz</span><div class="umat-qa-btn-text"><strong>Quiz Generator</strong><span>AI-generated course quizzes</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-courses" type="button"><span class="material-symbols-outlined">menu_book</span><div class="umat-qa-btn-text"><strong>My Courses</strong><span>Switch course analytics</span></div></button>
              <button class="umat-qa-btn" data-lp="lec-library" type="button"><span class="material-symbols-outlined">local_library</span><div class="umat-qa-btn-text"><strong>Resource Materials</strong><span>Materials &amp; recordings</span></div></button>
            </div>
          </div>
        </div>
      </div>

            <!-- INSIGHTS DASHBOARD (unified analytics + struggle) -->
      <div class="umat-tab-pane" id="lec-insights" style="overflow-y:auto;position:relative;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">psychology</span> Struggle Dashboard <span id="ins-course-label"></span></h2>
          <div style="display:flex;gap:6px;align-items:center;">
            <button class="umat-content-hdr-btn" id="ins-cs-btn" type="button" title="Select course"><span class="material-symbols-outlined">menu_book</span><span id="ins-cs-label">All Courses</span></button>
            <span class="umat-pill pill-info" id="ins-mode-badge">v2.0</span>
            <button class="umat-content-hdr-btn ins-topbar-refresh" id="ins-refresh-btn" type="button" aria-label="Refresh insights" title="Refresh insights" onclick="if(window.struggleDashboard)window.struggleDashboard.refresh();">
              <span class="material-symbols-outlined" id="ins-refresh-icon">refresh</span>
            </button>
          </div>
        </div>
        <div class="umat-cs-overlay" id="ins-cs-ov">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">menu_book</span>Select a Course</h3>
              <button class="umat-cs-close" id="ins-cs-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="ins-cs-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="ins-cs-list"></div>
          </div>
        </div>
        {$struggleDashboardHtml}
      </div>
<!-- QUIZ GENERATOR -->
      <div class="umat-tab-pane" id="lec-quizgen" style="overflow-y:auto;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">quiz</span> Quiz Generator <span id="qgen-course-label"></span></h2>
          <div style="display:flex;gap:8px;align-items:center;">
            <button class="umat-content-hdr-btn" id="qgen-cs-btn" type="button" title="Select course"><span class="material-symbols-outlined">menu_book</span><span id="qgen-cs-label">All Courses</span></button>
          </div>
        </div>
        <div class="umat-cs-overlay" id="qgen-cs-ov">
          <div class="umat-cs-modal">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">menu_book</span>Select a Course</h3>
              <button class="umat-cs-close" id="qgen-cs-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div class="umat-cs-search"><input type="text" id="qgen-cs-search" placeholder="Filter courses…"></div>
            <div class="umat-cs-list" id="qgen-cs-list"></div>
          </div>
        </div>
        <div id="qgen-body" style="padding:0 20px 20px;">
          <div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading Quiz Generator…</p></div>
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
      <div class="umat-tab-pane" id="lec-library" style="position:relative;min-height:0;">
        <style>
          .umat-lib-toggle{background:var(--u-sfl);color:var(--u-ol);font-weight:600;transition:all .15s;}
          .umat-lib-toggle.active{background:var(--u-p);color:#fff;font-weight:700;}
        </style>
        <!-- Toggle: Course Materials / Private Bank (pill style) -->
        <div style="display:flex;padding:8px 12px 6px;gap:6px;flex-shrink:0;">
          <button class="umat-lib-toggle active" data-libview="course" type="button" style="flex:1;padding:7px 10px;border:none;border-radius:20px;cursor:pointer;font-size:11px;font-family:inherit;">
            <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;margin-right:3px;">menu_book</span>Course Materials
          </button>
          {$rbToggleBtn}
        </div>
        <!-- Course Materials view (default) — fills remaining height -->
        <div id="lec-lib-course-view" style="display:flex;flex-direction:column;flex:1;min-height:0;">
          <div class="umat-content-hdr">
            <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">local_library</span> Library</h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" id="lec-lib-hdr-actions">
              <button type="button" id="lec-upload-rec-btn" style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border:1px solid var(--u-p);border-radius:var(--u-rp);font-size:12px;font-weight:600;color:var(--u-p);background:var(--u-sflo);cursor:pointer;font-family:inherit;"><span class="material-symbols-outlined" style="font-size:15px;">upload_file</span>Upload Recording</button>
              <input type="text" id="lec-lib-search" placeholder="Search materials…" style="padding:6px 12px;border:1px solid var(--u-olv);border-radius:var(--u-rp);font-size:12px;outline:none;font-family:inherit;color:var(--u-ons);background:var(--u-sfl);width:min(140px,35vw);">
            </div>
          </div>
          <!-- Upload modal -->
          <div class="umat-cs-overlay" id="lec-upload-ov" style="display:none;">
            <div class="umat-cs-modal" style="max-width:420px;">
              <div class="umat-cs-modal-hdr">
                <h3><span class="material-symbols-outlined">upload_file</span>Upload Lecture Recording</h3>
                <button class="umat-cs-close" id="lec-upload-close" type="button"><span class="material-symbols-outlined">close</span></button>
              </div>
              <div style="padding:16px;">
                <div id="lec-upload-dropzone" style="border:2px dashed var(--u-olv);border-radius:var(--u-r12);padding:32px 16px;text-align:center;cursor:pointer;transition:border-color .2s;">
                  <span class="material-symbols-outlined" style="font-size:40px;color:var(--u-ol);">cloud_upload</span>
                  <p style="margin:8px 0 4px;font-size:13px;font-weight:600;color:var(--u-ons);">Drop audio/video file here</p>
                  <p style="margin:0;font-size:11px;color:var(--u-ol);">MP3, WAV, MP4, WebM, MKV (max 500 MB)</p>
                  <input type="file" id="lec-upload-file" accept="audio/*,video/*" style="display:none;">
                </div>
                <div id="lec-upload-progress" style="display:none;margin-top:12px;">
                  <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--u-ol);margin-bottom:4px;">
                    <span id="lec-upload-fname">Uploading…</span>
                    <span id="lec-upload-pct">0%</span>
                  </div>
                  <div style="height:4px;background:var(--u-olv);border-radius:2px;overflow:hidden;">
                    <div id="lec-upload-bar" style="height:100%;width:0%;background:var(--u-p);border-radius:2px;transition:width .3s;"></div>
                  </div>
                </div>
                <div id="lec-upload-result" style="display:none;margin-top:12px;padding:10px;border-radius:var(--u-r8);font-size:12px;"></div>
                <div style="display:flex;gap:8px;margin-top:14px;justify-content:flex-end;">
                  <button type="button" id="lec-upload-cancel" style="padding:6px 14px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;background:var(--u-sflo);color:var(--u-ons);cursor:pointer;font-family:inherit;">Cancel</button>
                  <button type="button" id="lec-upload-submit" disabled style="padding:6px 14px;border:none;border-radius:var(--u-r8);font-size:12px;font-weight:600;background:var(--u-p);color:#fff;cursor:pointer;font-family:inherit;opacity:.5;">Upload & Transcribe</button>
                </div>
              </div>
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
          <!-- Materials grid — fills remaining height -->
          <div class="umat-lib-grid" id="lec-lib-grid" style="flex:1;overflow-y:auto;min-height:0;">
            <div class="umat-lib-picker">
              <span class="material-symbols-outlined">folder_open</span>
              <p>Select a course to browse its library materials.</p>
              <button type="button" id="lec-lib-pick-btn"><span class="material-symbols-outlined">menu_book</span>Select Course</button>
            </div>
          </div>
        </div>
        {$rbViewHtml}
        <!-- Viewers (using shared material_viewer) -->
      </div>

      <!-- SESSIONS (LECTURER) -->
      <div class="umat-tab-pane" id="lec-sessions" style="position:relative;overflow:hidden;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">history</span> AI Chat Sessions</h2>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" id="lec-sess-hdr-actions"></div>
        </div>
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

      <!-- STUDENT ISSUES (LECTURER) -->
      <div class="umat-tab-pane" id="lec-issues" style="width:100%;min-width:0;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">forum</span> Student Issues <span class="umat-badge-num" id="lec-issues-count"></span></h2>
          <button class="umat-content-hdr-btn" id="lec-issues-refresh" type="button"><span class="material-symbols-outlined">refresh</span></button>
        </div>
        <div class="umat-issue-app umat-issue-lecturer" id="lec-issue-app">
          <section class="umat-issue-view active" id="lec-issue-list-view">
            <div class="umat-issue-filters">
              <label><span class="sr-only">Search conversations</span><span class="material-symbols-outlined">search</span><input type="search" id="lec-issues-search" placeholder="Search student, title, or message"></label>
              <select id="lec-issues-course" aria-label="Filter by course"><option value="0">All authorized courses</option></select>
              <select id="lec-issues-category" aria-label="Filter by category"><option value="">All categories</option><option value="course_material">Course material</option><option value="assignment">Assignment</option><option value="quiz_examination">Quiz or examination</option><option value="grade_feedback">Grade or feedback</option><option value="live_class_recording">Live class or recording</option><option value="technical_problem">Technical problem</option><option value="access_permission">Access or permission</option><option value="other">Other</option></select>
            </div>
            <div class="umat-issue-list" id="lec-issues-body"><div class="umat-empty"><span class="material-symbols-outlined">forum</span><p>Loading student issues...</p></div></div>
          </section>
          <section class="umat-issue-view" id="lec-issue-thread-view">
            <div class="umat-issue-thread-head"><button class="umat-icon-btn" id="lec-issue-thread-back" type="button" aria-label="Back to inbox"><span class="material-symbols-outlined">arrow_back</span></button><div class="umat-issue-thread-title"><strong id="lec-issue-thread-title"></strong><span id="lec-issue-thread-meta"></span></div></div>
            <div class="umat-issue-messages" id="lec-issue-messages" aria-live="polite"></div>
            <div class="umat-issue-send-error" id="lec-issue-send-error" role="alert"></div>
            <div class="umat-issue-composer"><label class="umat-issue-attach-btn" for="lec-issue-reply-file" title="Attach a file"><span class="material-symbols-outlined">attach_file</span><span class="sr-only">Attach</span></label><input type="file" id="lec-issue-reply-file" hidden accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg,.gif,.webp,.mp3,.mp4,.wav"><textarea id="lec-issue-reply" rows="1" maxlength="10000" placeholder="Type your message..."></textarea><button class="umat-issue-send-btn" id="lec-issue-send" type="button"><span class="material-symbols-outlined">send</span><span class="sr-only">Send</span></button></div>
          </section>
        </div>
      </div>

    </div><!-- /content -->

    <!-- AI FAB + Mini Panel (inside overlay, only visible when dashboard is open) -->
<button class="umat-fab umat-fab-pulse" id="lec-ai-fab" type="button" style="position:fixed;bottom:100px!important;right:28px!important;z-index:100001!important;" aria-label="Ask AI Assistant">
  <span class="material-symbols-outlined">smart_toy</span>
  <span class="umat-fab-tip">Ask AI Assistant</span>
</button>
    <div id="lec-ai-mini" class="umat-ai-glass-mini">
      <div class="umat-ai-glass-mini-hdr">
        <div class="umat-ai-glass-mini-title"><span class="material-symbols-outlined">smart_toy</span> AI Assistant</div>
        <button id="lec-ai-mini-close" class="umat-ai-glass-mini-close" type="button" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div class="umat-msgs umat-ai-glass-mini-msgs" id="lec-mini-msgs">
        <div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI ASSISTANT</div><div class="umat-bubble-ai"><p>Ask me about your course analytics, student patterns, or teaching recommendations.</p></div></div></div>
      </div>
      <div class="umat-chatbar">
        <textarea id="lec-mini-input" class="umat-chatbar-input" placeholder="Ask about analytics…" rows="1"></textarea>
        <button class="umat-chatbar-send" id="lec-mini-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
      </div>
    </div>
  </div><!-- /ov-body -->

  {$lecMobTabs}
</div>

{$sharedJs}

<script>(function(){
var CID = {$jsCid};
var CN  = {$jsName};
var UD  = {$jsUD};
var lecLoaded = {}, anLoaded = {};
var wwwroot  = {$jsWwwroot};
var streamUrl = {$streamUrl};
var moodleSesskey = {$moodleSesskey};

/* Fallback ajax when AMD is unavailable */
if(typeof ajax!=='function'){
  window.ajax=function(m,a,d,f){
    var x=new XMLHttpRequest();
    x.open('POST','/lib/ajax/service.php?sesskey='+encodeURIComponent(moodleSesskey));
    x.setRequestHeader('Content-Type','application/json');
    x.onload=function(){if(x.status===200){try{var r=JSON.parse(x.responseText);if(r&&r[0]){if(r[0].error){console.error('[umat-ajax]',m,r[0].error);(f||function(){})(r[0].error);}else{console.log('[umat-ajax]',m,'OK');(d||function(){})(r[0].data);}}else{console.warn('[umat-ajax]',m,'unexpected:',r);(f||function(){})(new Error('Unexpected'));}}catch(e){console.error('[umat-ajax]',m,'parse:',e);(f||function(){})(e);}}else{console.error('[umat-ajax]',m,'HTTP',x.status);(f||function(){})(new Error('HTTP '+x.status));}};
    x.onerror=function(){console.error('[umat-ajax]',m,'network');(f||function(){})(new Error('Network'));};
    x.send(JSON.stringify([{index:0,methodname:m,args:a}]));
  };
}

/* Fallback esc when AMD module hasn't loaded */
if(typeof esc!=='function'){
  window.esc=function(s){if(s==null)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(String(s)));return d.innerHTML;};
}

/* ---- Lecturer course alert banner (shown when CID=0 on non-course pages) ---- */
function _lecCourseAlert(tabName){
  var c=(UD&&UD.courses)||[];
  if(!c.length)return '';
  return '<div class="umat-course-alert"><span class="material-symbols-outlined">warning</span>'+
    '<div class="umat-course-alert-text"><strong>Select a course</strong> to view '+esc(tabName)+'.</div></div>'+
    '<div class="umat-course-alert-chips" style="padding:8px 16px 0;">'+c.slice(0,10).map(function(cv){
      return '<button class="umat-chip" data-cid="'+cv.id+'" type="button">'+esc(cv.shortname||cv.fullname)+'</button>';
    }).join('')+'</div>';
}
function _lecWireAlertChips(container,onSelect){
  if(!container)return;
  container.querySelectorAll('.umat-course-alert-chips .umat-chip').forEach(function(b){
    b.addEventListener('click',function(){onSelect(parseInt(this.dataset.cid)||0);});
  });
}

/* Fallback ESC key handler when AMD umatshared.js fails to load */
if(typeof _umatInitEsc!=='function'){
  window._umatInitEsc=function(layers){
    document.addEventListener('keydown',function(e){
      if(e.key!=='Escape')return;
      for(var i=0;i<layers.length;i++){
        var el=document.getElementById(layers[i].id);
        if(el&&layers[i].isOpen(el)){layers[i].close(el);e.preventDefault();return;}
      }
    });
  };
}

/* Fallback loadAnalytics when AMD umat_lecturer.js fails to load */
if(typeof loadAnalytics!=='function'){
  window.loadAnalytics=function(cid){
    anLoaded[cid||'0']=true;
    var label=document.getElementById('lec-an-course-label');
    var overview=document.getElementById('lec-an-overview');
    var detail=document.getElementById('lec-an-detail');
    var csLabel=document.getElementById('lec-an-cs-label');
    if(cid){
      if(overview)overview.style.display='none';
      if(detail)detail.style.display='';
      var anCourse=(UD.courses||[]).find(function(c){return c.id===cid;});
      if(csLabel)csLabel.textContent=anCourse?anCourse.fullname||anCourse.shortname:'Course '+cid;
      if(label)label.textContent=cid===CID?CN:'Loading\u2026';
      ajax('local_umat_ai_get_analytics',{courseid:cid,days:30},function(d){
        var s=function(id,v){var e=document.getElementById(id);if(e)e.textContent=v;};
        s('an-v-active',(d.active_studients||d.active_students||0)+' / '+(d.enrolled_students||0));
        s('an-s-active','of '+(d.enrolled_students||0)+' enrolled');
        s('an-pill-active',Math.round((d.active_students||0)/Math.max(d.enrolled_students||1,1)*100)+'% active');
        s('an-v-time',(d.avg_questions_per_session||0)+' Q');
        s('an-v-str',d.struggle_index||'N/A');
        s('an-v-int',(d.total_interactions||0).toLocaleString());
        s('an-pill-int','+'+(d.total_interactions||0));
        var tot=Math.max(d.enrolled_students||0,1);
        var h=d.high_performers||0,risk=Math.max(0,(d.enrolled_students||0)-(d.active_students||0)),track=Math.max(0,(d.active_students||0)-h);
        s('an-p-high',h+' students');s('an-p-track',track+' students');s('an-p-risk',risk+' students');
        setTimeout(function(){
          var pb=function(id,n,t){var e=document.getElementById(id);if(e)e.style.width=Math.min(100,Math.round(n/t*100))+'%';};
          pb('an-pb-high',h,tot);pb('an-pb-track',track,tot);pb('an-pb-risk',risk,tot);
        },300);
        ['an-chart','an-chart-labels'].forEach(function(id){var e=document.getElementById(id);if(e)e.style.display='none';});
        var badge=document.getElementById('an-q-badge');if(badge)badge.textContent='Aggregation of '+(d.total_interactions||0)+'+ chats';
        var qList=document.getElementById('an-q-list');
        if(qList){
          if(!d.top_questions||!d.top_questions.length){qList.innerHTML='<div style="text-align:center;padding:32px;color:var(--u-ol);font-size:13px;">No questions logged yet.</div>';return;}
          qList.innerHTML=d.top_questions.map(function(q,i){
            var acts=['Prepare Response','Generate AI Summary','Add to FAQ','Create Quiz','Schedule Review'];
            var displayText=(q.text||'').replace(/^\[Referencing:\s*[^\]]+\]\s*/i,'');
            return '<div class="umat-q-row">'+
              '<div class="umat-q-votes"><div class="v-n">'+q.ask_count+'</div><div class="v-l">votes</div></div>'+
              '<div class="umat-q-content"><div class="umat-q-text">&ldquo;'+esc(displayText)+'&rdquo;</div><div class="umat-q-related">Related to: <span>Course Materials</span></div></div>'+
              '<div class="umat-q-action"><button class="umat-q-action-btn" type="button">'+esc(acts[i%acts.length])+'</button></div></div>';
          }).join('');
        }
      },function(){var s=document.getElementById('an-v-active');if(s)s.textContent='Error';});
    }else{
      if(overview)overview.style.display='';
      if(detail)detail.style.display='none';
      if(csLabel)csLabel.textContent='All Courses';
      if(label)label.textContent='';
      var courses=UD&&UD.courses||[];
      if(!courses.length){document.getElementById('ov-an-kpis').innerHTML='<div class="ov-loading">No courses assigned.</div>';return;}
      var agg={active_students:0,enrolled_students:0,total_interactions:0,questions_per_session:[],daily_counts:{},per_course:[],all_questions:[],high_total:0,risk_total:0,track_total:0},done=0;
      courses.forEach(function(c){
        ajax('local_umat_ai_get_analytics',{courseid:c.id,days:30},function(d){
          agg.active_students+=d.active_students;agg.enrolled_students+=d.enrolled_students;
          agg.total_interactions+=d.total_interactions;
          agg.questions_per_session.push(d.avg_questions_per_session||0);
          agg.high_total+=d.high_performers||0;agg.risk_total+=Math.max(0,d.enrolled_students-d.active_students);
          agg.track_total+=Math.max(0,d.active_students-(d.high_performers||0));
          (d.top_questions||[]).forEach(function(q){agg.all_questions.push(q);});
          (d.daily_counts||[]).forEach(function(day){agg.daily_counts[day.label]=(agg.daily_counts[day.label]||0)+day.count;});
          agg.per_course.push({id:c.id,name:c.shortname,label:c.fullname,active:d.active_students,enrolled:d.enrolled_students,interactions:d.total_interactions,struggle:d.struggle_index,depth:d.avg_questions_per_session});
          done++;if(done===courses.length)renderAnalyticsOverview(agg);
        },function(){done++;if(done===courses.length)renderAnalyticsOverview(agg);});
      });
    }
  };
}

/* Fallback renderAnalyticsOverview when AMD umat_lecturer.js fails to load */
if(typeof renderAnalyticsOverview!=='function'){
  window.renderAnalyticsOverview=function(agg){
    if(!agg||!agg.per_course||!agg.per_course.length){var k=document.getElementById('ov-an-kpis');if(k)k.innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">info</span><p>No analytics data available yet.</p></div>';return;}
    var active=agg.active_students,enrolled=agg.enrolled_students,totalInt=agg.total_interactions;
    var avgDepth=(agg.questions_per_session.length?agg.questions_per_session.reduce(function(a,b){return a+b;})/agg.questions_per_session.length:0).toFixed(1);
    var pct=Math.round(active/Math.max(enrolled,1)*100);
    var kpiEl=document.getElementById('ov-an-kpis');
    if(kpiEl)kpiEl.innerHTML=
      '<div class="ov-kpi"><div class="ov-kpi-icon ak-g"><span class="material-symbols-outlined">group</span></div><div class="ov-kpi-val">'+active+' <span class="ov-kpi-sub">/ '+enrolled+'</span></div><div class="ov-kpi-lbl">Active Students <span class="ov-kpi-pct">'+pct+'%</span></div></div>'+
      '<div class="ov-kpi"><div class="ov-kpi-icon ak-s"><span class="material-symbols-outlined">timer</span></div><div class="ov-kpi-val">'+avgDepth+' <span class="ov-kpi-sub">Q</span></div><div class="ov-kpi-lbl">Avg Session Depth</div></div>'+
      '<div class="ov-kpi"><div class="ov-kpi-icon ak-r"><span class="material-symbols-outlined">psychology_alt</span></div><div class="ov-kpi-val">'+agg.per_course.length+' <span class="ov-kpi-sub">courses</span></div><div class="ov-kpi-lbl">Courses Tracked</div></div>'+
      '<div class="ov-kpi"><div class="ov-kpi-icon ak-w"><span class="material-symbols-outlined">forum</span></div><div class="ov-kpi-val">'+totalInt.toLocaleString()+'</div><div class="ov-kpi-lbl">Total Interactions</div></div>';
    var maxActive=Math.max.apply(null,agg.per_course.map(function(c){return c.active;}));
    var barsEl=document.getElementById('ov-an-bars');
    if(barsEl)barsEl.innerHTML=agg.per_course.sort(function(a,b){return b.active-a.active;}).map(function(c){
      var w=maxActive?Math.round(c.active/maxActive*100):0;
      return '<div class="ov-bar-row"><div class="ov-bar-label"><span class="ov-bar-course">'+esc(c.name)+'</span><span class="ov-bar-val">'+c.active+'/'+c.enrolled+'</span></div><div class="ov-bar-track"><div class="ov-bar-fill ov-bar-an" style="width:'+w+'%"></div></div></div>';
    }).join('');
    var h=agg.high_total||0,tk=agg.track_total||0,rk=agg.risk_total||0,tot=Math.max(h+tk+rk,1);
    var hp=Math.round(h/tot*100),tp=Math.round(tk/tot*100),rp=100-hp-tp;
    var donutEl=document.getElementById('ov-an-donut');
    if(donutEl)donutEl.innerHTML='<div class="ov-donut"><svg viewBox="0 0 36 36"><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-olv)" stroke-width="3.8"/><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-p)" stroke-width="3.8" stroke-dasharray="'+hp+' '+(100-hp)+'" stroke-dashoffset="25" stroke-linecap="round"/><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-warn, #f59e0b)" stroke-width="3.8" stroke-dasharray="'+tp+' '+(100-tp)+'" stroke-dashoffset="'+(25+hp)+'" stroke-linecap="round"/><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-ter)" stroke-width="3.8" stroke-dasharray="'+rp+' '+(100-rp)+'" stroke-dashoffset="'+(25+hp+tp)+'" stroke-linecap="round"/><text x="18" y="20.5" text-anchor="middle" font-size="6" font-weight="700" fill="var(--u-ons)">'+active+'</text><text x="18" y="25" text-anchor="middle" font-size="2.5" fill="var(--u-ol)">active</text></svg></div>'+
    '<div class="ov-donut-legend"><div class="ov-donut-legend-item"><span class="ov-dot" style="background:var(--u-p)"></span>High Performers <strong>'+h+'</strong></div>'+
    '<div class="ov-donut-legend-item"><span class="ov-dot" style="background:var(--u-warn, #f59e0b)"></span>On Track <strong>'+tk+'</strong></div>'+
    '<div class="ov-donut-legend-item"><span class="ov-dot" style="background:var(--u-ter)"></span>At Risk <strong>'+rk+'</strong></div></div>';
    ['ov-an-chart','ov-an-chart-labels'].forEach(function(id){var e=document.getElementById(id);if(e)e.style.display='none';});
    var sq=agg.all_questions.sort(function(a,b){return b.ask_count-a.ask_count;}).slice(0,10);
    var qEl=document.getElementById('ov-an-questions');
    if(qEl){
      if(!sq.length){qEl.innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">question_answer</span><p>No questions logged yet.</p></div>';return;}
      qEl.innerHTML=sq.map(function(q,i){
        return '<div class="ov-q-row"><div class="ov-q-rank">#'+(i+1)+'</div><div class="ov-q-text">&ldquo;'+esc(q.text)+'&rdquo;</div><div class="ov-q-count"><span class="material-symbols-outlined">thumb_up</span>'+q.ask_count+'</div></div>';
      }).join('');
    }
  };
}

/* Fallback _umat* helpers when AMD umatshared.js fails to load */
if(typeof _umatAppendUser!=='function'){
  window._umatAppendUser=function(id,t){var c=document.getElementById(id);if(!c)return;var d=document.createElement('div');d.className='umat-msg umat-msg-user';d.innerHTML='<div class="umat-msg-bubble">'+esc(t)+'</div>';c.appendChild(d);c.scrollTop=c.scrollHeight;};
  window._umatAppendAi=function(id,t,s){var c=document.getElementById(id);if(!c)return;var d=document.createElement('div');d.className='umat-msg umat-msg-ai';d.innerHTML='<div class="umat-msg-bubble umat-msg-bubble-ai"><div class="umat-msg-label">AI ASSISTANT</div><div class="umat-msg-text">'+esc(t)+'</div>'+(s&&s.length?'<div class="umat-msg-src">Sources: '+s.map(function(x){return esc(x.name||x);}).join(', ')+'</div>':'')+'</div>';c.appendChild(d);c.scrollTop=c.scrollHeight;};
  window._umatShowTyping=function(id,tid){var c=document.getElementById(id);if(!c)return;var d=document.createElement('div');d.className='umat-msg umat-msg-ai';d.id=tid;d.innerHTML='<div class="umat-msg-bubble umat-msg-bubble-ai"><div class="umat-msg-label">AI ASSISTANT</div><div class="umat-typing"><span></span><span></span><span></span></div></div>';c.appendChild(d);c.scrollTop=c.scrollHeight;};
  window._umatHideTyping=function(tid){var e=document.getElementById(tid);if(e)e.remove();};
  window._umatStreamChat=function(o){
    if(!o)return;
    _umatHideTyping(o.typingId);
    if(!o.courseid){_umatAppendAi(o.msgsId,'Please open a course page first.',[]);return;}
    _umatAppendAi(o.msgsId,'AI service is loading\u2026 If this persists, try refreshing the page.',[]);
    if(o.onDone)o.onDone();
  };
  window._umatStreamInline=function(o){
    if(!o)return;
    var r=document.getElementById(o.targetId);
    if(r&&o.question)r.innerHTML='<div class="sd-stream-error">AI service unavailable (AMD modules failed to load). Please refresh and try again.</div>';
    if(o.onDone)o.onDone();
  };
  window._umatInitScrollToBottom=function(id){var e=document.getElementById(id);if(e&&typeof e.scrollTop!=='undefined'){var o=new MutationObserver(function(){e.scrollTop=e.scrollHeight;});o.observe(e,{childList:true,subtree:true,characterData:true});window._umatScrollObs=window._umatScrollObs||{};window._umatScrollObs[id]=o;}};
}

/* ---- Scroll-to-bottom FABs ---- */
if(typeof _umatInitScrollToBottom==='function')_umatInitScrollToBottom('lcp-msgs');
if(typeof _umatInitScrollToBottom==='function')_umatInitScrollToBottom('ws-msgs');

/* Load struggle_dashboard AMD module (fault-tolerant, max 30 retries) */
!function loadStruggleDash(c){c=c||0;if(c>30)return;typeof require==='function'?require(['local_umat_ai/struggle_dashboard'],function(d){window.struggleDashboard=d;},function(){setTimeout(function(){loadStruggleDash(c+1);},1000);}):setTimeout(function(){loadStruggleDash(c+1);},50);}();


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
        '<button class="yt-btn" data-act="library" onclick="event.stopPropagation()"><span class="material-symbols-outlined">local_library</span>Resource Materials</button>'+
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
      switchPane('lec-insights');loadAnalytics(CID);
    });
    /* Action buttons */
    tile.querySelectorAll('[data-act]').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.stopPropagation();
        CID=parseInt(tile.dataset.cid)||CID;CN=tile.dataset.cname||CN;
        var lbl=document.getElementById('lec-an-course-label');if(lbl)lbl.textContent=CN;
        var ctx=document.getElementById('lec-ctx-label');if(ctx)ctx.textContent=CN;
        var act=btn.dataset.act;
        if(act==='analytics'){anLoaded[CID]=false;switchPane('lec-insights');loadAnalytics(CID);}
        else if(act==='library'){lecLoaded['lec-library']=false;switchPane('lec-library');loadLibrary();}
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
function updateBodyLock(){document.body.classList.toggle('umat-body-lock',!(!document.querySelector('.umat-ov.open,.umat-cp-ov.open')));}

function openPanel(){if(window.innerWidth<640){cpOv.classList.remove('open');lecOv.classList.add('open');switchPane('lec-home');}else{cpOv.classList.add('open');}fab.setAttribute('aria-expanded','true');if(!panelDataLoaded){loadPanelData();panelDataLoaded=true;}updateBodyLock();}
function closePanel(){cpOv.classList.remove('open');fab.setAttribute('aria-expanded','false');updateBodyLock();}
function openDash(){console.log('[dash] openDash called');closePanel();lecOv.classList.add('open');switchPane('lec-home');updateBodyLock();}
function closeDash(){lecOv.classList.remove('open');openPanel();}

if(fab)fab.addEventListener('click',openPanel);
if(cpClose)cpClose.addEventListener('click',closePanel);
if(cpOv)cpOv.addEventListener('click',function(e){if(e.target===cpOv)closePanel();});
if(expand)expand.addEventListener('click',openDash);
if(lecOv)lecOv.addEventListener('click',function(e){if(e.target===lecOv)closeDash();});
var dashBtn=document.getElementById('lcp-dash-btn');if(dashBtn)dashBtn.addEventListener('click',openDash);
var openDashBtn=document.getElementById('lcp-open-dash');if(openDashBtn)openDashBtn.addEventListener('click',openDash);

/* Compact panel tabs */
var lcpPaneLoaded={};
function showLcpPane(t){
  document.querySelectorAll('[data-lcp-tab]').forEach(function(x){x.classList.toggle('active',x.dataset.lcpTab===t);});
  document.querySelectorAll('#lec-cp [data-lcp-pane]').forEach(function(x){x.classList.toggle('active',x.dataset.lcpPane===t);});
  document.querySelectorAll('#lec-cp .umat-cp-pane').forEach(function(x){x.classList.toggle('active',x.id===t);});
  if(!lcpPaneLoaded[t]){lcpPaneLoaded[t]=true;try{loadLcpPane(t);}catch(e){console.error('[lcp] loadLcpPane('+t+') error:',e);lcpPaneLoaded[t]=false;var pane=document.getElementById(t);if(pane){var el=pane.querySelector('.lcp-pane-loading');if(el)el.innerHTML='<span style="color:var(--u-ter);">Failed to load. Tap to retry.</span>';}}}
}
document.querySelectorAll('[data-lcp-tab]').forEach(function(b){
  b.addEventListener('click',function(){showLcpPane(b.dataset.lcpTab);});
});
document.querySelectorAll('#lec-cp [data-lcp-pane]').forEach(function(b){
  b.addEventListener('click',function(){showLcpPane(b.dataset.lcpPane);});
});
function loadLcpPane(t){
  if(t==='lcp-insights-dash')return loadLcpInsightsDash();
  if(t==='lcp-quizgen')return loadLcpQuizgen();
  if(t==='lcp-courses')return loadLcpCourses();
  if(t==='lcp-library')return loadLcpLibraryPane();
  if(t==='lcp-sessions')return loadLcpSessionsPane();
  if(t==='lcp-issues'){closePanel();lecOv.classList.add('open');switchPane('lec-issues');updateBodyLock();return;}
}
/* Open-full buttons */
var lcpDashOpen=document.getElementById('lcp-dash-open-btn');if(lcpDashOpen)lcpDashOpen.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-insights');updateBodyLock();});
var lcpQgenOpen=document.getElementById('lcp-qgen-open-btn');if(lcpQgenOpen)lcpQgenOpen.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-quizgen');updateBodyLock();});
var lcpLibOpen=document.getElementById('lcp-lib-open-btn');if(lcpLibOpen)lcpLibOpen.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-library');updateBodyLock();});
var lcpSessOpen=document.getElementById('lcp-sess-open-btn');if(lcpSessOpen)lcpSessOpen.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-sessions');updateBodyLock();});
var lcpIssOpen=document.getElementById('lcp-iss-open-btn');if(lcpIssOpen)lcpIssOpen.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-issues');updateBodyLock();});

/* ── Lecturer compact pane loaders ── */
function loadLcpInsightsDash(){
  var courses=(UD&&UD.courses)||[];
  var targetCid=CID||0;
  function render(data){
    var s=data.summary||data;var topics=data.topic_matrix||[];var students=data.student_narratives||data.students||[];var questions=data.common_questions||[];
    document.getElementById('lcp-d-students').textContent=s.total_students||0;
    document.getElementById('lcp-d-risk').textContent=s.at_risk_count||s.risk_students||0;
    document.getElementById('lcp-d-questions').textContent=s.total_questions||0;
    document.getElementById('lcp-d-score').textContent=(s.avg_quiz_score!=null?Math.round(s.avg_quiz_score)+'%':'—');
    var topEl=document.getElementById('lcp-d-topics');
    topEl.innerHTML=topics.length?topics.slice(0,5).map(function(t){
      var pct=Math.min(100,Math.max(5,t.struggle_score||0));var color=pct>60?'#dc2626':pct>30?'#f59e0b':'#22c55e';
      return '<div class="lcp-pane-row"><div class="lcp-pane-row-body"><div class="lcp-pane-row-top"><span class="lcp-pane-row-name">'+esc(t.topic||'Topic')+'</span><span style="font-size:10px;color:'+color+';font-weight:700;">'+pct+'%</span></div><div class="lcp-pane-bar-wrap"><div class="lcp-pane-bar" style="width:'+pct+'%;background:'+color+';"></div></div></div></div>';
    }).join(''):'<div class="lcp-pane-empty">No topic data yet.</div>';
    var stEl=document.getElementById('lcp-d-students-list');
    stEl.innerHTML=students.length?students.slice(0,5).map(function(st){
      var risk=st.risk_level||st.risk||'low';var rc=risk==='high'?'#dc2626':risk==='medium'?'#f59e0b':'#22c55e';
      return '<div class="lcp-pane-row"><div class="lcp-pane-row-body"><div class="lcp-pane-row-top"><span class="lcp-pane-row-name">'+esc(st.name||st.fullname||'Student')+'</span><span style="font-size:9px;padding:2px 6px;border-radius:999px;background:'+rc+'18;color:'+rc+';font-weight:700;">'+risk+'</span></div><div class="lcp-pane-row-sub">'+esc((st.struggle_topics||[]).slice(0,2).join(', ')||st.summary||'')+'</div></div></div>';
    }).join(''):'<div class="lcp-pane-empty">No at-risk students.</div>';
    var qEl=document.getElementById('lcp-d-questions-list');
    qEl.innerHTML=questions.length?questions.slice(0,5).map(function(q){
      return '<div class="lcp-pane-row"><div class="lcp-pane-row-body"><div class="lcp-pane-row-top"><span class="lcp-pane-row-name" style="font-weight:500;">'+esc((q.text||q.question||'').substring(0,80))+'</span></div><div class="lcp-pane-row-sub">'+esc(q.topic||'')+(q.count?' · '+q.count+' students':'')+'</div></div></div>';
    }).join(''):'<div class="lcp-pane-empty">No questions yet.</div>';
  }
  if(targetCid){ajax('local_umat_ai_get_struggle_insights',{courseid:targetCid,days:60},function(d){render(d);},function(){console.error('[lcp] insights load failed');});}
  else if(courses.length){var loaded=0,agg={summary:{total_students:0,at_risk_count:0,total_questions:0,avg_quiz_score:null},topic_matrix:[],student_narratives:[],common_questions:[]};courses.forEach(function(c){ajax('local_umat_ai_get_struggle_insights',{courseid:c.id,days:60},function(d){var s=d.summary||{};agg.summary.total_students+=s.total_students||0;agg.summary.at_risk_count+=s.at_risk_count||s.risk_students||0;agg.summary.total_questions+=s.total_questions||0;(d.topic_matrix||[]).forEach(function(t){agg.topic_matrix.push(t);});(d.student_narratives||d.students||[]).forEach(function(st){agg.student_narratives.push(st);});(d.common_questions||[]).forEach(function(q){agg.common_questions.push(q);});loaded++;if(loaded>=courses.length){agg.topic_matrix.sort(function(a,b){return(b.struggle_score||0)-(a.struggle_score||0);});agg.student_narratives.sort(function(a,b){var order={high:0,medium:1,low:2};return(order[a.risk_level||a.risk||'low']||2)-(order[b.risk_level||b.risk||'low']||2);});render(agg);}},function(){loaded++;if(loaded>=courses.length)render(agg);});});}else{document.getElementById('lcp-d-topics').innerHTML='<div class="lcp-pane-empty">No courses available.</div>';document.getElementById('lcp-d-students-list').innerHTML='';document.getElementById('lcp-d-questions-list').innerHTML='';}
}
function loadLcpQuizgen(){
  var courses=(UD&&UD.courses)||[];var sel=document.getElementById('lcp-qgen-course');
  if(sel&&!sel.options.length){
    sel.innerHTML='<option value="">Select course…</option>';
    courses.forEach(function(c){var o=document.createElement('option');o.value=c.id;o.textContent=c.shortname||c.fullname;sel.appendChild(o);});if(CID)sel.value=CID;
  }
  var genBtn=document.getElementById('lcp-qgen-gen');if(genBtn&&!genBtn._wired){genBtn._wired=true;genBtn.addEventListener('click',function(){
    var cid=parseInt(sel.value)||CID;if(!cid){alert('Select a course first.');return;}
    var topic=document.getElementById('lcp-qgen-topic').value.trim();var count=document.getElementById('lcp-qgen-count').value;var type=document.getElementById('lcp-qgen-type').value;
    var diffEl=document.getElementById('lcp-qgen-diff');var difficulty=diffEl?diffEl.value:'medium';
    /* Map compact panel values to new API params */
    var qtypes={};if(type==='mixed'){qtypes={multichoice:Math.ceil(count/2),truefalse:Math.floor(count/4),shortanswer:Math.floor(count/4)};}else if(type==='mcq'){qtypes={multichoice:parseInt(count)};}else{qtypes={shortanswer:parseInt(count)};}
    var res=document.getElementById('lcp-qgen-result');res.innerHTML='<div style="display:flex;align-items:center;gap:8px;padding:16px;"><div class="umat-vw-spinner"></div><span style="font-size:12px;color:var(--u-ol);">Generating '+count+' '+type+' questions…</span></div>';genBtn.disabled=true;genBtn.style.opacity='.5';
    ajax('local_umat_ai_generate_quiz_draft',{courseid:cid,source_type:'text',content:topic||'General review',question_types:JSON.stringify(qtypes),difficulty:difficulty},function(r){
      genBtn.disabled=false;genBtn.style.opacity='1';var qs=r.questions||[];
      if(!qs.length){res.innerHTML='<div class="lcp-pane-empty"><span class="material-symbols-outlined">quiz</span><p>No questions generated. Try a different topic.</p></div>';return;}
      var mcCount=qs.filter(function(q){return(q.type||'').indexOf('mc')!==-1||(q.options&&q.options.length);}).length;
      var shortCount=qs.length-mcCount;
      res.innerHTML='<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0 8px;border-bottom:1px solid var(--u-olv);margin-bottom:8px;">'+
        '<span style="font-size:12px;font-weight:700;color:var(--u-ons);">'+qs.length+' Questions</span>'+
        '<span style="font-size:10px;color:var(--u-ol);">'+(mcCount?mcCount+' MCQ':'')+(mcCount&&shortCount?' · ':'')+(shortCount?shortCount+' Short':'')+'</span>'+
      '</div>'+
      qs.slice(0,8).map(function(q,i){
        var isMcq=q.options&&q.options.length;var typeTag=isMcq?'MCQ':'Short';
        return '<div class="lcp-pane-row" style="padding:6px 0;border-bottom:1px solid var(--u-olv);">'+
          '<div style="display:flex;gap:6px;align-items:flex-start;">'+
            '<span style="width:18px;height:18px;border-radius:50%;background:var(--u-sflo);color:var(--u-p);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;flex-shrink:0;">'+(i+1)+'</span>'+
            '<div style="min-width:0;flex:1;">'+
              '<div style="font-size:11px;font-weight:600;color:var(--u-ons);line-height:1.4;">'+esc((q.question||q.text||'').substring(0,120))+'</div>'+
              (isMcq?'<div style="font-size:9px;color:var(--u-ol);margin-top:2px;">'+q.options.map(function(o){return '○ '+esc(typeof o==='string'?o:(o.text||o));}).join(' · ')+'</div>':'')+
            '</div>'+
            '<span style="font-size:8px;padding:1px 4px;border-radius:999px;background:var(--u-sflo);color:var(--u-ol);font-weight:600;flex-shrink:0;">'+typeTag+'</span>'+
          '</div>'+
        '</div>';
      }).join('')+
      (qs.length>8?'<div style="text-align:center;padding:8px;font-size:10px;color:var(--u-ol);">+'+(qs.length-8)+' more — open full view to see all</div>':'')+
      '<div style="display:flex;gap:6px;padding-top:8px;">'+
        '<button class="umat-btn-p" id="lcp-qgen-to-course" type="button" style="flex:1;justify-content:center;font-size:11px;padding:6px;"><span class="material-symbols-outlined" style="font-size:14px;">school</span>Add to Course</button>'+
        '<button class="lcp-pane-expand" id="lcp-qgen-full-btn" type="button" style="flex:1;font-size:11px;padding:6px;"><span class="material-symbols-outlined" style="font-size:14px;">open_in_full</span>Full View</button>'+
      '</div>';
      var fullBtn=document.getElementById('lcp-qgen-full-btn');if(fullBtn)fullBtn.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-quizgen');updateBodyLock();});
    },function(){genBtn.disabled=false;genBtn.style.opacity='1';res.innerHTML='<div class="lcp-pane-empty"><span class="material-symbols-outlined">error_outline</span><p>Generation failed. Try again.</p></div>';});
  });}
}
function loadLcpCourses(){
  var body=document.getElementById('lcp-courses-list');var courses=(UD&&UD.courses)||[];
  if(!courses.length){body.innerHTML='<div class="lcp-pane-empty">No courses found.</div>';return;}
  var colors=['#006B2F','#d97706','#7c3aed','#dc2626','#0891b2','#059669','#c026d3','#2563eb'];
  body.style.display='grid';body.style.gridTemplateColumns='1fr 1fr';body.style.gap='6px';
  body.innerHTML=courses.map(function(c,i){
    var clr=colors[i%colors.length];
    var initials=(c.shortname||'').substring(0,2).toUpperCase();
    var enrolled=c.enrolled_count||c.enrolled||'';
    return '<div class="lcp-pane-row lcp-pane-clickable" data-cid="'+c.id+'" data-name="'+esc(c.fullname||'')+'" style="cursor:pointer;border:1px solid var(--u-olv);border-radius:var(--u-r10);padding:10px;display:flex;gap:8px;align-items:center;">'+
      '<div style="width:34px;height:34px;border-radius:var(--u-r8);background:'+clr+';color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0;">'+esc(initials)+'</div>'+
      '<div style="min-width:0;flex:1;">'+
        '<div style="font-size:12px;font-weight:700;color:var(--u-ons);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(c.shortname||c.fullname)+'</div>'+
        '<div style="font-size:10px;color:var(--u-ol);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(c.fullname||'')+'</div>'+
        (enrolled?'<div style="font-size:9px;color:var(--u-p);margin-top:2px;font-weight:600;">'+enrolled+' students</div>':'')+
      '</div>'+
      '<span class="material-symbols-outlined" style="font-size:16px;color:var(--u-ol);flex-shrink:0;">chevron_right</span>'+
    '</div>';
  }).join('');
  body.querySelectorAll('.lcp-pane-clickable').forEach(function(el){
    el.addEventListener('click',function(){CID=parseInt(el.dataset.cid)||CID;CN=el.dataset.name||CN;lcpPaneLoaded={};closePanel();lecOv.classList.add('open');switchPane('lec-insights');updateBodyLock();});
  });
}
function loadLcpLibraryPane(){
  var body=document.getElementById('lcp-lib-body');var courses=(UD&&UD.courses)||[];
  function _lcpMime(mt){
    if(!mt)return 'Course material';var m=mt.toLowerCase();
    if(m.indexOf('pdf')!==-1)return 'PDF Document';if(m.indexOf('powerpoint')!==-1||m.indexOf('presentationml')!==-1)return 'PowerPoint Presentation';
    if(m.indexOf('wordprocessingml')!==-1||m.indexOf('msword')!==-1)return 'Word Document';if(m.indexOf('spreadsheetml')!==-1||m.indexOf('excel')!==-1)return 'Spreadsheet';
    if(m.indexOf('video/')!==-1)return 'Video';if(m.indexOf('audio/')!==-1)return 'Audio';if(m.indexOf('image/')!==-1)return 'Image';
    var parts=m.split('/');return (parts[parts.length-1]||mt).replace(/[\.\-]/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();}).substring(0,30);
  }
  function _lcpIcon(mt){
    var m=(mt||'').toLowerCase();if(m.indexOf('pdf')!==-1)return 'picture_as_pdf';if(m.indexOf('video')!==-1)return 'play_circle';if(m.indexOf('audio')!==-1)return 'headphones';if(m.indexOf('image')!==-1)return 'image';if(m.indexOf('powerpoint')!==-1||m.indexOf('presentation')!==-1)return 'slideshow';if(m.indexOf('word')!==-1||m.indexOf('document')!==-1)return 'description';if(m.indexOf('sheet')!==-1||m.indexOf('excel')!==-1)return 'table_chart';return 'description';
  }
  function _fmtSize(bytes){if(!bytes||isNaN(bytes))return '';if(bytes<1024)return bytes+' B';if(bytes<1048576)return (bytes/1024).toFixed(1)+' KB';return (bytes/1048576).toFixed(1)+' MB';}
  function showCourses(){
    body.innerHTML=courses.length?courses.slice(0,8).map(function(c,i){
      var colors=['#006B2F','#d97706','#7c3aed','#dc2626','#0891b2','#059669'];
      var clr=colors[i%colors.length];var initials=(c.shortname||'').substring(0,2).toUpperCase();
      return '<div class="lcp-pane-row lcp-pane-clickable" data-cid="'+c.id+'" style="cursor:pointer;display:flex;gap:8px;align-items:center;padding:8px;border:1px solid var(--u-olv);border-radius:var(--u-r10);margin-bottom:4px;">'+
        '<div style="width:28px;height:28px;border-radius:var(--u-r6);background:'+clr+';color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;flex-shrink:0;">'+esc(initials)+'</div>'+
        '<div style="min-width:0;flex:1;"><div style="font-size:12px;font-weight:600;color:var(--u-ons);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(c.shortname||c.fullname)+'</div><div style="font-size:10px;color:var(--u-ol);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(c.fullname||'')+'</div></div>'+
        '<span class="material-symbols-outlined" style="font-size:16px;color:var(--u-ol);">chevron_right</span>'+
      '</div>';
    }).join(''):'<div class="lcp-pane-empty">No courses available.</div>';
    body.querySelectorAll('.lcp-pane-clickable').forEach(function(el){
      el.addEventListener('click',function(){
        var cid=parseInt(el.dataset.cid);body.innerHTML='<div class="lcp-pane-loading" style="display:flex;align-items:center;gap:6px;"><button class="lcp-lib-back" type="button" style="background:none;border:none;cursor:pointer;padding:2px;"><span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span></button>Loading materials…</div>';
        var backBtn=body.querySelector('.lcp-lib-back');if(backBtn)backBtn.addEventListener('click',function(){showCourses();});
        ajax('local_umat_ai_get_course_materials',{courseid:cid},function(r){
          var mats=r.materials||[];
          var header='<div style="display:flex;align-items:center;gap:6px;padding:4px 0 8px;"><button class="lcp-lib-back" type="button" style="background:none;border:none;cursor:pointer;padding:2px;"><span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span></button><span style="font-size:12px;font-weight:700;color:var(--u-ons);">'+mats.length+' Materials</span></div>';
          body.innerHTML=header+(mats.length?mats.map(function(m){
            return '<div class="lcp-pane-row" style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--u-olv);">'+
              '<span class="material-symbols-outlined" style="font-size:18px;color:var(--u-p);">'+_lcpIcon(m.mimetype||m.type)+'</span>'+
              '<div style="min-width:0;flex:1;"><div style="font-size:12px;font-weight:600;color:var(--u-ons);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(m.filename||m.name||'Material')+'</div>'+
              '<div style="font-size:10px;color:var(--u-ol);display:flex;gap:8px;">'+_lcpMime(m.mimetype||m.type)+(m.filesize?' · '+_fmtSize(m.filesize):'')+'</div></div>'+
            '</div>';
          }).join(''):'<div class="lcp-pane-empty">No materials for this course.</div>');
          body.querySelector('.lcp-lib-back').addEventListener('click',function(){showCourses();});
        },function(){body.innerHTML='<div class="lcp-pane-empty">Could not load materials.</div>';});
      });
    });
  }
  showCourses();
}
function loadLcpSessionsPane(){
  var body=document.getElementById('lcp-sess-body');
  function _timeAgo(ts){if(!ts)return '';var diff=Date.now()/1000-ts;var m=Math.floor(diff/60);if(m<1)return 'Just now';if(m<60)return m+'m ago';var h=Math.floor(m/60);if(h<24)return h+'h ago';var dd=Math.floor(h/24);return dd+'d ago';}
  body.innerHTML='<div id="lcp-sess-list"><div class="lcp-pane-loading">Loading sessions…</div></div>';
  ajax('local_umat_ai_get_lecturer_sessions',{courseid:CID||0,limit:8},function(r){
    var list=document.getElementById('lcp-sess-list');
    var sessions=r.sessions||[];
    if(!sessions.length){list.innerHTML='<div class="lcp-pane-empty">No AI sessions yet.</div>';return;}
    list.innerHTML=sessions.map(function(s){
      var msgs=s.message_count||s.msg_count||'';var preview=(s.preview||s.last_message||'').substring(0,80);
      return '<div class="lcp-pane-row" style="padding:8px;border:1px solid var(--u-olv);border-radius:var(--u-r10);margin-bottom:4px;cursor:pointer;" data-sk="'+esc(s.session_key||s.key||'')+'">'+
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">'+
          '<span style="font-size:11px;font-weight:700;color:var(--u-ons);">'+esc(s.course_name||s.course||'AI Session')+'</span>'+
          '<span style="font-size:9px;color:var(--u-ol);">'+esc(s.time_label||'')+'</span>'+
        '</div>'+
        '<div style="font-size:10px;color:var(--u-ol);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(preview||'No messages')+'</div>'+
        (msgs?'<div style="font-size:9px;color:var(--u-p);margin-top:3px;font-weight:600;">'+msgs+' messages</div>':'')+
      '</div>';
    }).join('');
    list.querySelectorAll('.lcp-pane-row[data-sk]').forEach(function(card){
      card.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-sessions');updateBodyLock();});
    });
  },function(){
    var list=document.getElementById('lcp-sess-list');
    if(list)list.innerHTML='<div class="lcp-pane-empty">Could not load sessions.</div>';
  });
}
function loadLcpIssuesPane(){
  var body=document.getElementById('lcp-iss-body');
  if(!CID){body.innerHTML=_lecCourseAlert('student issues');_lecWireAlertChips(body,function(cid){CID=cid;lcpPaneLoaded['lcp-issues']=false;showLcpPane('lcp-issues');});return;}
  body.innerHTML='<div class="lcp-pane-loading">Loading issues…</div>';
  ajax('local_umat_ai_list_issue_conversations',{inbox:'lecturer',courseid:CID,category:'',query:''},function(r){
    var convs=r.conversations||[];
    if(!convs.length){body.innerHTML='<div class="lcp-pane-empty"><span class="material-symbols-outlined">forum</span><p>No student issues.</p></div>';return;}
    var labels={concept_confusion:'Concept',material_error:'Material',technical_issue:'Technical',suggestion:'Suggestion',other:'Other'};
    body.innerHTML=convs.slice(0,10).map(function(c){return '<div class="lcp-pane-row" style="padding:8px;border:1px solid var(--u-olv);border-radius:var(--u-r10);margin-bottom:4px;cursor:pointer;" data-conversation-id="'+c.id+'"><div style="display:flex;justify-content:space-between;align-items:center;"><strong style="font-size:11px;color:var(--u-ons);">'+esc(c.title)+'</strong>'+(c.unreadcount?'<span class="umat-issue-unread" style="flex-shrink:0;">'+c.unreadcount+'</span>':'')+'</div><div style="font-size:10px;color:var(--u-ol);margin-top:2px;">'+esc(c.studentname)+' · '+(labels[c.category]||c.category)+'</div></div>';}).join('');
    body.querySelectorAll('[data-conversation-id]').forEach(function(el){el.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-issues');setTimeout(function(){openLecturerIssue(parseInt(el.dataset.conversationId));},200);});});},function(){body.innerHTML='<div class="lcp-pane-empty">Could not load issues.</div>';});
}

var lcpMsgs=document.getElementById('lcp-msgs');
if(lcpMsgs)lcpMsgs.addEventListener('click',function(e){
  var chip=e.target.closest('.umat-chip[data-lp]');
  if(chip){switchToAI(chip.dataset.lp);}
});
function switchToAI(q){
  showLcpPane('lcp-ai');
  if(q){document.getElementById('lcp-input').value=q;document.getElementById('lcp-send').click();}
}

function renderLecCourseGrid(gridId, clickAction){
  var grid=document.getElementById(gridId);
  if(!grid||!UD||!UD.courses)return;
  grid.innerHTML=UD.courses.map(function(c){
    return '<div class="yt-tile" data-cid="'+c.id+'" data-cname="'+esc(c.fullname)+'">'+
      '<div class="yt-thumb" style="background:linear-gradient(135deg,'+(c.color||'var(--u-p)')+',rgba(0,0,0,.2));">'+
        '<span class="yt-initials">'+esc(c.shortname.substring(0,2).toUpperCase())+'</span>'+
      '</div>'+
      '<div class="yt-info"><div class="yt-title">'+esc(c.fullname)+'</div><div class="yt-meta">'+esc(c.shortname)+'</div></div>'+
      '<div class="yt-actions"><button class="yt-btn" data-action="select" type="button"><span class="material-symbols-outlined">arrow_forward</span>View</button></div>'+
    '</div>';
  }).join('');
  grid.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('[data-action]'))return;
      var cid=parseInt(tile.dataset.cid);var cname=tile.dataset.cname;
      if(typeof clickAction==='function')clickAction(cid,cname);
    });
    tile.querySelectorAll('[data-action]').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.stopPropagation();
        var cid=parseInt(tile.dataset.cid);var cname=tile.dataset.cname;
        if(typeof clickAction==='function')clickAction(cid,cname);
      });
    });
  });
}

/* Sidebar & mobile tab pane switching */
function switchPane(name){
  console.log('[dash] switchPane('+name+') called');
  document.querySelectorAll('#lec-ov .umat-tab-pane').forEach(function(p){p.classList.remove('active');});
  document.querySelectorAll('#lec-sb [data-lp], #lec-glass-tabs [data-lp]').forEach(function(b){b.classList.toggle('active',b.dataset.lp===name);});
  var pane=document.getElementById(name);if(pane)pane.classList.add('active');
  /* AI FAB always visible — AI Assistant accessible from all tabs including insights */
  var aiFab=document.getElementById('lec-ai-fab');
  if(aiFab)aiFab.style.display='';
  if(!lecLoaded[name]){lecLoaded[name]=true;loadPaneData(name);}
  else if(name==='lec-insights'){if(window.struggleDashboard)window.struggleDashboard.init(resolveInsightsCid());else loadInsights(resolveInsightsCid());}
  else if(name==='lec-sessions'){
    /* Reset inline chat view back to session list */
    var chatEl=document.getElementById('lec-sess-chat');
    if(chatEl)chatEl.style.display='none';
    var sp=document.getElementById('lec-sessions');
    if(sp)sp.querySelectorAll('.umat-content-hdr,.umat-sess-toggle,.umat-cs-overlay,.umat-sessions-list').forEach(function(el){el.style.display='';});
  }
}
document.querySelectorAll('#lec-sb [data-lp], #lec-glass-tabs [data-lp]').forEach(function(b){
  b.addEventListener('click',function(){switchPane(b.dataset.lp);});
});
document.addEventListener('click',function(e){
  var btn=e.target.closest('[data-lp]');
  if(btn && btn.closest('#lec-home')){switchPane(btn.dataset.lp);}
});

/* Home init */
function initHome(){
  console.log('[umat] initHome CID=',CID);
  if(!CID){console.warn('[umat] initHome: no CID');return;}
  var d=new Date(),dEl=document.getElementById('lec-home-date');
  if(dEl)dEl.textContent=d.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  /* Use panel data if already loaded */
  if(panelDataLoaded){console.log('[umat] initHome: panel data already loaded');return;}
  ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(data){
    console.log('[umat] analytics data:',data);
    var ms=document.getElementById('lec-met-active');
    if(ms)ms.textContent=data.active_students+'/'+data.enrolled_students;
  },function(err){console.error('[umat] analytics error:',err);});
  ajax('local_umat_ai_get_struggle_dashboard_data',{courseid:CID},function(data){
    console.log('[umat] struggle data:',data);
    var fEl=document.getElementById('lec-met-friction');
    var eEl=document.getElementById('lec-met-engagement');
    if(fEl&&data.kpis&&data.kpis.top_topic){
      var tt=data.kpis.top_topic;
      fEl.textContent=tt.name+' ('+tt.gauge_value+'%)';
      fEl.title=tt.ai_insight||'';
    }
    if(eEl&&data.kpis&&data.kpis.engagement_score!=null){
      eEl.textContent=data.kpis.engagement_score+'%';
    }
  },function(err){console.error('[umat] struggle data error:',err);});
}

function loadPaneData(name){
  
  
  if(name==='lec-courses')loadLecturerCourses();
  if(name==='lec-library'){populateLibCourseSel();loadLibrary(lecLibCourseId);}
  if(name==='lec-sessions'){populateSessCourseSel();loadSessions(lecSessCourseId);}
  if(name==='lec-issues')initLecturerIssues();
  if(name==='lec-insights'){populateInsightsCourseSel();if(window.struggleDashboard){window.struggleDashboard.init(resolveInsightsCid());}else{loadInsights(resolveInsightsCid());}}
  if(name==='lec-quizgen')loadQuizGenUI();
  if(name==='lec-home')initHome();
}
/* Expose to window so AMD modules can delegate */
window.loadPaneData=loadPaneData;

/* Load panel (compact) data */
function loadPanelData(){
  if(!CID){
    var gl=document.getElementById('lcp-gap-title');if(gl)gl.textContent='All Courses Mode';
    var gd=document.getElementById('lcp-gap-desc');if(gd)gd.textContent='Select a course from Courses, Analytics, or Struggle tabs to view per-course data.';
    var db=document.getElementById('lcp-open-dash');if(db)db.textContent='Open Dashboard Overview';
    return;
  }
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
    var ms=document.getElementById('lec-met-active');if(ms)ms.textContent=d.active_students+'/'+d.enrolled_students;
    /* Top questions */
    var ql=document.getElementById('lcp-q-list');
    if(ql&&d.top_questions&&d.top_questions.length){
      ql.innerHTML=d.top_questions.slice(0,5).map(function(q){
        var displayText=(q.text||'').replace(/^\[Referencing:\s*[^\]]+\]\s*/i,'');
        return '<div style="padding:8px;background:var(--u-sf);border:1px solid var(--u-olv);border-radius:var(--u-r8);">'+
          '<div style="font-size:12px;color:var(--u-ons);margin-bottom:3px;">'+esc(displayText)+'</div>'+
          '<div style="font-size:10px;color:var(--u-ol);"><b style="color:var(--u-p);">'+q.ask_count+'</b> students asked</div></div>';
      }).join('');
    }
  },function(){});
}

/* Analytics load & render */
function loadInsightsLegacy(cid){
  anLoaded[cid||'0']=true;
  var label=document.getElementById('lec-an-course-label');
  var overview=document.getElementById('lec-an-overview');
  var detail=document.getElementById('lec-an-detail');
  var csLabel=document.getElementById('lec-an-cs-label');
  if(cid){
    if(overview)overview.style.display='none';
    if(detail)detail.style.display='';
    var anCourse=(UD.courses||[]).find(function(c){return c.id===cid;});
    if(csLabel)csLabel.textContent=anCourse?anCourse.fullname||anCourse.shortname:'Course '+cid;
    document.getElementById('lec-an-course-label').textContent=cid===CID?CN:'Loading…';
    ajax('local_umat_ai_get_analytics',{courseid:cid,days:30},function(d){
      var s=function(id,v){var e=document.getElementById(id);if(e)e.textContent=v;};
      s('an-v-active',d.active_students+' / '+d.enrolled_students);
      s('an-s-active','of '+d.enrolled_students+' enrolled');
      s('an-pill-active',Math.round(d.active_students/Math.max(d.enrolled_students,1)*100)+'% active');
      s('an-v-time',d.avg_questions_per_session+' Q');
      s('an-v-str',d.struggle_index);
      s('an-v-int',d.total_interactions.toLocaleString());
      s('an-pill-int','+'+d.total_interactions);
      drawChart(d.daily_counts,d.max_daily||1);
      var tot=Math.max(d.enrolled_students,1);
      var h=d.high_performers||0,risk=Math.max(0,d.enrolled_students-d.active_students),track=Math.max(0,d.active_students-h);
      s('an-p-high',h+' students');s('an-p-track',track+' students');s('an-p-risk',risk+' students');
      setTimeout(function(){
        var pb=function(id,n,tot){var e=document.getElementById(id);if(e)e.style.width=Math.min(100,Math.round(n/tot*100))+'%';};
        pb('an-pb-high',h,tot);pb('an-pb-track',track,tot);pb('an-pb-risk',risk,tot);
      },300);
      buildHeatmap(d.daily_counts,d.max_daily||1,d.struggle_index);
      var badge=document.getElementById('an-q-badge');if(badge)badge.textContent='Aggregation of '+d.total_interactions+'+ chats';
      var qList=document.getElementById('an-q-list');
      if(qList){
        if(!d.top_questions||!d.top_questions.length){qList.innerHTML='<div style="text-align:center;padding:32px;color:var(--u-ol);font-size:13px;">No questions logged yet.</div>';return;}
        var acts=['Prepare Response','Generate AI Summary','Add to FAQ','Create Quiz','Schedule Review'];
        qList.innerHTML=d.top_questions.map(function(q,i){
          var displayText=(q.text||'').replace(/^\[Referencing:\s*[^\]]+\]\s*/i,'');
          return '<div class="umat-q-row">'+
            '<div class="umat-q-votes"><div class="v-n">'+q.ask_count+'</div><div class="v-l">votes</div></div>'+
            '<div class="umat-q-content"><div class="umat-q-text">&ldquo;'+esc(displayText)+'&rdquo;</div><div class="umat-q-related">Related to: <span>Course Materials</span></div></div>'+
            '<div class="umat-q-action"><button class="umat-q-action-btn" type="button">'+esc(acts[i%acts.length])+'</button></div></div>';
        }).join('');
      }
    },function(){var s=document.getElementById('an-v-active');if(s)s.textContent='Error';});
  }else{
    if(overview)overview.style.display='';
    if(detail)detail.style.display='none';
    if(csLabel)csLabel.textContent='All Courses';
    if(label)label.textContent='';
    /* Load all courses analytics in parallel */
    var courses=UD&&UD.courses||[];
    if(!courses.length){document.getElementById('ov-an-kpis').innerHTML='<div class="ov-loading">No courses assigned.</div>';return;}
    var agg={active_students:0,enrolled_students:0,total_interactions:0,questions_per_session:[],daily_counts:{},per_course:[],all_questions:[],high_total:0,risk_total:0,track_total:0},done=0;
    courses.forEach(function(c){
      ajax('local_umat_ai_get_analytics',{courseid:c.id,days:30},function(d){
        agg.active_students+=d.active_students;agg.enrolled_students+=d.enrolled_students;
        agg.total_interactions+=d.total_interactions;
        agg.questions_per_session.push(d.avg_questions_per_session||0);
        agg.high_total+=d.high_performers||0;agg.risk_total+=Math.max(0,d.enrolled_students-d.active_students);
        agg.track_total+=Math.max(0,d.active_students-(d.high_performers||0));
        (d.top_questions||[]).forEach(function(q){agg.all_questions.push(q);});
        (d.daily_counts||[]).forEach(function(day){agg.daily_counts[day.label]=(agg.daily_counts[day.label]||0)+day.count;});
        agg.per_course.push({id:c.id,name:c.shortname,label:c.fullname,active:d.active_students,enrolled:d.enrolled_students,interactions:d.total_interactions,struggle:d.struggle_index,depth:d.avg_questions_per_session});
        done++;if(done===courses.length)renderAnalyticsOverview(agg);
      },function(){done++;if(done===courses.length)renderAnalyticsOverview(agg);});
    });
  }
}

/* ── Render Analytics Overview (all courses) ── */
function renderAnalyticsOverview(agg){
  if(!agg||!agg.per_course||!agg.per_course.length){
    document.getElementById('ov-an-kpis').innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">info</span><p>No analytics data available yet.</p></div>';
    return;
  }
  var active=agg.active_students,enrolled=agg.enrolled_students,totalInt=agg.total_interactions;
  var avgDepth=(agg.questions_per_session.length?agg.questions_per_session.reduce(function(a,b){return a+b;})/agg.questions_per_session.length:0).toFixed(1);
  var pct=Math.round(active/Math.max(enrolled,1)*100);
  /* KPI cards */
  document.getElementById('ov-an-kpis').innerHTML=
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-g"><span class="material-symbols-outlined">group</span></div><div class="ov-kpi-val">'+active+' <span class="ov-kpi-sub">/ '+enrolled+'</span></div><div class="ov-kpi-lbl">Active Students <span class="ov-kpi-pct">'+pct+'%</span></div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-s"><span class="material-symbols-outlined">timer</span></div><div class="ov-kpi-val">'+avgDepth+' <span class="ov-kpi-sub">Q</span></div><div class="ov-kpi-lbl">Avg Session Depth</div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-r"><span class="material-symbols-outlined">psychology_alt</span></div><div class="ov-kpi-val">'+agg.per_course.length+' <span class="ov-kpi-sub">courses</span></div><div class="ov-kpi-lbl">Courses Tracked</div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-w"><span class="material-symbols-outlined">forum</span></div><div class="ov-kpi-val">'+totalInt.toLocaleString()+'</div><div class="ov-kpi-lbl">Total Interactions</div></div>';
  /* Course comparison bars */
  var maxActive=Math.max.apply(null,agg.per_course.map(function(c){return c.active;}));
  document.getElementById('ov-an-bars').innerHTML=agg.per_course.sort(function(a,b){return b.active-a.active;}).map(function(c){
    var w=maxActive?Math.round(c.active/maxActive*100):0;
    return '<div class="ov-bar-row"><div class="ov-bar-label"><span class="ov-bar-course">'+esc(c.name)+'</span><span class="ov-bar-val">'+c.active+'/'+c.enrolled+'</span></div><div class="ov-bar-track"><div class="ov-bar-fill ov-bar-an" style="width:'+w+'%"></div></div></div>';
  }).join('');
  /* Engagement donut */
  var h=agg.high_total||0,tk=agg.track_total||0,rk=agg.risk_total||0,tot=Math.max(h+tk+rk,1);
  var hp=Math.round(h/tot*100),tp=Math.round(tk/tot*100),rp=100-hp-tp;
  var donut='<div class="ov-donut"><svg viewBox="0 0 36 36"><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-olv)" stroke-width="3.8"/><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-p)" stroke-width="3.8" stroke-dasharray="'+hp+' '+(100-hp)+'" stroke-dashoffset="25" stroke-linecap="round"/><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-warn, #f59e0b)" stroke-width="3.8" stroke-dasharray="'+tp+' '+(100-tp)+'" stroke-dashoffset="'+(25+hp)+'" stroke-linecap="round"/><path d="M18 2a16 16 0 0 1 0 32 16 16 0 0 1 0-32" fill="none" stroke="var(--u-ter)" stroke-width="3.8" stroke-dasharray="'+rp+' '+(100-rp)+'" stroke-dashoffset="'+(25+hp+tp)+'" stroke-linecap="round"/><text x="18" y="20.5" text-anchor="middle" font-size="6" font-weight="700" fill="var(--u-ons)">'+active+'</text><text x="18" y="25" text-anchor="middle" font-size="2.5" fill="var(--u-ol)">active</text></svg></div>'+
    '<div class="ov-donut-legend"><div class="ov-donut-legend-item"><span class="ov-dot" style="background:var(--u-p)"></span>High Performers <strong>'+h+'</strong></div>'+
    '<div class="ov-donut-legend-item"><span class="ov-dot" style="background:var(--u-warn, #f59e0b)"></span>On Track <strong>'+tk+'</strong></div>'+
    '<div class="ov-donut-legend-item"><span class="ov-dot" style="background:var(--u-ter)"></span>At Risk <strong>'+rk+'</strong></div></div>';
  document.getElementById('ov-an-donut').innerHTML=donut;
  /* Daily trend chart */
  var days=Object.keys(agg.daily_counts).sort();var maxV=Math.max.apply(null,days.map(function(d){return agg.daily_counts[d];}))||1;
  drawOverviewChart('ov-an-chart','ov-an-chart-labels',days.map(function(d){return{label:d,count:agg.daily_counts[d]};}),maxV);
  /* Top questions */
  var sq=agg.all_questions.sort(function(a,b){return b.ask_count-a.ask_count;}).slice(0,10);
  var qEl=document.getElementById('ov-an-questions');
  if(qEl){
    if(!sq.length){qEl.innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">question_answer</span><p>No questions logged yet.</p></div>';return;}
    qEl.innerHTML=sq.map(function(q,i){
      return '<div class="ov-q-row"><div class="ov-q-rank">#'+(i+1)+'</div><div class="ov-q-text">&ldquo;'+esc(q.text)+'&rdquo;</div><div class="ov-q-count"><span class="material-symbols-outlined">thumb_up</span>'+q.ask_count+'</div></div>';
    }).join('');
  }
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

function drawOverviewChart(canvasId,labelId,daily,maxV){
  var canvas=document.getElementById(canvasId);if(!canvas||!daily||!daily.length)return;
  var ctx=canvas.getContext('2d');
  var W=canvas.offsetWidth||600,H=140;canvas.width=W;canvas.height=H;
  var n=daily.length,pad={l:28,r:8,t:12,b:22};
  var cW=W-pad.l-pad.r,cH=H-pad.t-pad.b;
  var bW=Math.max(4,(cW/n)*0.65);
  var labDiv=document.getElementById(labelId);if(labDiv)labDiv.innerHTML='';
  ctx.clearRect(0,0,W,H);
  [.25,.5,.75,1].forEach(function(f){
    var y=pad.t+cH*(1-f);ctx.strokeStyle='rgba(0,0,0,.06)';ctx.lineWidth=1;
    ctx.beginPath();ctx.moveTo(pad.l,y);ctx.lineTo(pad.l+cW,y);ctx.stroke();
  });
  daily.forEach(function(d,i){
    var x=pad.l+(i/n)*cW+(cW/n-bW)/2;
    var bH=Math.max(2,(d.count/maxV)*cH),y=pad.t+cH-bH;
    var g=ctx.createLinearGradient(0,y,0,pad.t+cH);g.addColorStop(0,'#006b2f');g.addColorStop(1,'rgba(0,135,61,.3)');
    ctx.fillStyle=g;ctx.beginPath();
    if(ctx.roundRect){ctx.roundRect(x,y,bW,bH,[2,2,0,0]);}else{ctx.rect(x,y,bW,bH);}ctx.fill();
    ctx.fillStyle='#5b665a';ctx.font='9px Inter,sans-serif';ctx.textAlign='center';
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
var lecLibCourseId = CID || 0;
function populateLibCourseSel(){
  var list=document.getElementById('lec-lib-cs-list');
  if(!list||!UD||!UD.courses)return;
  var activeCid=lecLibCourseId||CID||0;
  list.innerHTML=UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">menu_book</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  /* Pre-highlight active course */
  if(activeCid){
    var match=list.querySelector('[data-cid="'+activeCid+'"]');
    if(match)match.classList.add('umat-cs-item-active');
  }
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
  ajax('local_umat_ai_get_course_materials',{courseid:courseId},function(r){renderLibTiles(r.materials||[],g);if(typeof updateMaterialAnalysis==='function')updateMaterialAnalysis(courseId);},function(e){console.error('[umat] overlay loadLibrary failed:',e&&e.message||e);g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
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

/* ─── Lecture Recording Upload ─── */
var _lecUpFile=null,_lecUpXhr=null;
function openLecUploadModal(){
  var ov=document.getElementById('lec-upload-ov');if(ov)ov.style.display='flex';
  _lecUpReset();
}
function closeLecUploadModal(){
  var ov=document.getElementById('lec-upload-ov');if(ov)ov.style.display='none';
  _lecUpReset();
}
function _lecUpReset(){
  _lecUpFile=null;
  var dz=document.getElementById('lec-upload-dropzone'),pr=document.getElementById('lec-upload-progress'),rs=document.getElementById('lec-upload-result'),sb=document.getElementById('lec-upload-submit');
  if(dz)dz.style.display='block';if(pr)pr.style.display='none';if(rs)rs.style.display='none';
  if(sb){sb.disabled=true;sb.style.opacity='0.5';}
  var fi=document.getElementById('lec-upload-file');if(fi)fi.value='';
}
function _lecUpSelectFile(f){
  if(!f||!f.size)return;
  _lecUpFile=f;
  var sb=document.getElementById('lec-upload-submit');if(sb){sb.disabled=false;sb.style.opacity='1';}
  var dz=document.getElementById('lec-upload-dropzone');
  if(dz)dz.innerHTML='<span class="material-symbols-outlined" style="font-size:32px;color:var(--u-p);">audio_file</span><p style="margin:8px 0 2px;font-size:13px;font-weight:600;color:var(--u-ons);">'+esc(f.name)+'</p><p style="margin:0;font-size:11px;color:var(--u-ol);">'+(f.size>1048576?(f.size/1048576).toFixed(1)+' MB':(f.size/1024).toFixed(0)+' KB')+'</p><input type="file" id="lec-upload-file" accept="audio/*,video/*" style="display:none;">';
  var fi=document.getElementById('lec-upload-file');if(fi)fi.addEventListener('change',function(){if(this.files.length)_lecUpSelectFile(this.files[0]);});
}
function _lecUpSubmit(){
  if(!_lecUpFile)return;
  var dz=document.getElementById('lec-upload-dropzone'),pr=document.getElementById('lec-upload-progress'),rs=document.getElementById('lec-upload-result'),bar=document.getElementById('lec-upload-bar'),pct=document.getElementById('lec-upload-pct'),fn=document.getElementById('lec-upload-fname');
  if(dz)dz.style.display='none';if(pr)pr.style.display='block';if(rs)rs.style.display='none';
  if(fn)fn.textContent=_lecUpFile.name;
  var fd=new FormData();fd.append('audio',_lecUpFile);fd.append('courseid',CID);fd.append('sesskey',M.cfg.sesskey);
  _lecUpXhr=new XMLHttpRequest();
  _lecUpXhr.upload.addEventListener('progress',function(e){if(e.lengthComputable){var p=Math.round(e.loaded/e.total*100);if(bar)bar.style.width=p+'%';if(pct)pct.textContent=p+'%';}});
  _lecUpXhr.addEventListener('load',function(){
    if(pr)pr.style.display='none';if(rs)rs.style.display='block';
    try{var r=JSON.parse(_lecUpXhr.responseText);}catch(e){r={success:false,message:'Invalid response'};}
    if(r.success){rs.style.background='#dcfce7';rs.style.color='#065f46';rs.innerHTML='<strong>&#10003; Upload successful!</strong><br>Job ID: '+esc(r.job_id)+'<br>Transcription is processing…';}
    else{rs.style.background='#fee2e2';rs.style.color='#991b1b';rs.innerHTML='<strong>Upload failed</strong><br>'+esc(r.message||'Unknown error');}
  });
  _lecUpXhr.addEventListener('error',function(){if(pr)pr.style.display='none';if(rs){rs.style.display='block';rs.style.background='#fee2e2';rs.style.color='#991b1b';rs.innerHTML='<strong>Connection error</strong>';}});
  _lecUpXhr.open('POST','/local/umat_ai/upload.php');_lecUpXhr.send(fd);
}
(function(){
  var dz=document.getElementById('lec-upload-dropzone');
  if(dz){
    dz.addEventListener('click',function(){var fi=document.getElementById('lec-upload-file');if(fi)fi.click();});
    dz.addEventListener('dragover',function(e){e.preventDefault();this.style.borderColor='var(--u-p)';});
    dz.addEventListener('dragleave',function(){this.style.borderColor='var(--u-olv)';});
    dz.addEventListener('drop',function(e){e.preventDefault();this.style.borderColor='var(--u-olv)';if(e.dataTransfer.files.length)_lecUpSelectFile(e.dataTransfer.files[0]);});
    var fi=document.getElementById('lec-upload-file');if(fi)fi.addEventListener('change',function(){if(this.files.length)_lecUpSelectFile(this.files[0]);});
  }
  var sub=document.getElementById('lec-upload-submit');if(sub)sub.addEventListener('click',_lecUpSubmit);
  var cls=document.getElementById('lec-upload-close');if(cls)cls.addEventListener('click',closeLecUploadModal);
  var canc=document.getElementById('lec-upload-cancel');if(canc)canc.addEventListener('click',closeLecUploadModal);
  var upBtn=document.getElementById('lec-upload-rec-btn');if(upBtn)upBtn.addEventListener('click',openLecUploadModal);
})();

/* Sessions — with course overlay selector */
var lecSessCourseId = CID || 0;
function populateSessCourseSel(){
  var list=document.getElementById('lec-sess-cs-list');
  if(!list||!UD||!UD.courses)return;
  var activeCid=lecSessCourseId||CID||0;
  list.innerHTML=UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">menu_book</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  /* Pre-highlight active course */
  if(activeCid){
    var match=list.querySelector('[data-cid="'+activeCid+'"]');
    if(match)match.classList.add('umat-cs-item-active');
  }
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

/* Analytics — course overlay selector */
var lecAnalyticsCourseId = 0;
function populateInsightsCourseSel_legacy(){
  var list=document.getElementById('lec-an-cs-list');
  if(!list||!UD||!UD.courses)return;
  if(list._anPickerInited)return;list._anPickerInited=true;
  list.innerHTML='<button class="umat-cs-item" data-cid="0" type="button">'+
    '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">apps</span></div>'+
    '<div class="umat-cs-item-info"><div class="umat-cs-item-name">All Courses</div><div class="umat-cs-item-code">Composite overview</div></div></button>'+
  UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">bar_chart</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  list.querySelectorAll('.umat-cs-item').forEach(function(btn){
    btn.addEventListener('click',function(){
      var cid=parseInt(this.dataset.cid);
      lecAnalyticsCourseId=cid||0;
      document.getElementById('lec-an-cs-ov').classList.remove('open');
      if(cid)loadAnalytics(cid);else{anLoaded['0']=false;loadAnalytics(0);}
    });
  });
  var srch=document.getElementById('lec-an-cs-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    list.querySelectorAll('.umat-cs-item').forEach(function(it){
      it.style.display=(!q||it.textContent.toLowerCase().includes(q))?'':'none';
    });
  });
  var closeBtn=document.getElementById('lec-an-cs-close');
  if(closeBtn)closeBtn.addEventListener('click',function(){document.getElementById('lec-an-cs-ov').classList.remove('open');});
  var ov=document.getElementById('lec-an-cs-ov');
  if(ov)ov.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
  var btn=document.getElementById('lec-an-cs-btn');
  if(btn)btn.addEventListener('click',function(){openAnalyticsPicker();});
}
function openAnalyticsPicker(){
  var ov=document.getElementById('lec-an-cs-ov');
  if(ov)ov.classList.add('open');
}
/* Sessions tab — lecturer's own AI sessions */
function loadSessions(cid){
  /* Reset inline chat if open */
  var chatEl=document.getElementById('lec-sess-chat');
  if(chatEl)chatEl.style.display='none';
  var list=document.getElementById('lec-sess-list');
  var sessPane=document.getElementById('lec-sessions');
  if(sessPane){
    sessPane.querySelectorAll('.umat-content-hdr,.umat-cs-overlay,.umat-sessions-list').forEach(function(el){el.style.display='';});
  }
  var courseId=cid||0;
  var hdr=document.getElementById('lec-sess-hdr-actions');
  if(hdr)hdr.innerHTML='';

  list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading sessions…</p></div>';

  ajax('local_umat_ai_get_lecturer_sessions',{courseid:courseId,limit:20},function(r){
    var sessions=r.sessions||[];
    if(!sessions.length){
      list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No AI sessions yet. Ask the assistant a question!</p></div>';
      return;
    }
    list.innerHTML=sessions.map(function(s){
      return '<div class="umat-session-tile" data-sk="'+esc(s.session_key)+'" data-cid="'+s.courseid+'">'+
        '<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+esc(s.course_short||'AI')+'</span><span class="umat-session-time">'+esc(s.time_label)+'</span></div>'+
        '<h4>'+esc(s.course_name||'AI Session')+'</h4><p>'+esc(s.preview)+'</p>'+
        '<div class="umat-session-tile-foot"><div class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</div>'+
        '<button class="umat-del-session-btn" type="button" title="Delete session"><span class="material-symbols-outlined">delete</span></button></div></div>';
    }).join('');

    /* Wire delete buttons */
    list.querySelectorAll('.umat-del-session-btn').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.stopPropagation();
        if(!confirm('Delete this conversation? This cannot be undone.'))return;
        var tile=btn.closest('.umat-session-tile');
        if(!tile)return;
        btn.disabled=true;
        btn.innerHTML='<span class="material-symbols-outlined">hourglass_empty</span>';
        ajax('local_umat_ai_delete_lecturer_session',{session_key:tile.dataset.sk},function(){
          tile.remove();
          if(!list.querySelector('.umat-session-tile')){
            list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No AI sessions yet. Ask the assistant a question!</p></div>';
          }
        },function(){
          btn.disabled=false;
          btn.innerHTML='<span class="material-symbols-outlined">delete</span>';
        });
      });
    });

    /* Wire tile click → resume session inline in full overlay */
    list.querySelectorAll('.umat-session-tile').forEach(function(tile){
      tile.addEventListener('click',function(e){
        if(e.target.closest('.umat-del-session-btn'))return;
        var sk=tile.dataset.sk,cid=parseInt(tile.dataset.cid)||0;
        var name=tile.querySelector('h4')?tile.querySelector('h4').textContent:'';
        if(window.resumeLecSessionInline)window.resumeLecSessionInline(sk,cid,name);
        else if(window.resumeLecSession)window.resumeLecSession(sk,cid,name);
        else expandLecSession(tile);
      });
    });
  },function(){
    list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load sessions.</p></div>';
  });
}
function expandLecSession(tile){
  var sk=tile.dataset.sk;
  var existing=tile.nextElementSibling;
  if(existing&&existing.classList.contains('umat-session-detail')){
    existing.remove();
    return;
  }
  /* Remove any other open details */
  document.querySelectorAll('.umat-session-detail').forEach(function(d){d.remove();});
  var det=document.createElement('div');
  det.className='umat-session-detail';
  det.style='padding:12px 16px;background:var(--u-sflo);border-radius:var(--u-r8);margin:0 0 10px;border:1px solid var(--u-olv);';
  det.innerHTML='<div style="display:flex;align-items:center;gap:6px;padding:6px 0;font-size:12px;color:var(--u-ol);"><span class="material-symbols-outlined" style="font-size:14px;">hourglass_empty</span> Loading messages…</div>';
  tile.parentNode.insertBefore(det,tile.nextElementSibling);
  ajax('local_umat_ai_get_lecturer_session_detail',{session_key:sk},function(r){
    var msgs=r.messages||[];
    if(!msgs.length){
      det.innerHTML='<div style="padding:10px;text-align:center;color:var(--u-ol);font-size:12px;">No messages found.</div>';
      return;
    }
    det.innerHTML='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid var(--u-olv);">'
      +'<span style="font-size:11px;font-weight:700;color:var(--u-ol);">'+(msgs.length===1?'1 message':msgs.length+' messages')+'</span>'
      +'<button class="umat-collapse-detail-btn" type="button" style="background:none;border:none;cursor:pointer;color:var(--u-p);font-size:11px;font-weight:600;">Collapse</button></div>'
      + msgs.map(function(m){
        return '<div style="margin-bottom:10px;">'
          +'<div class="umat-msg umat-msg-user" style="margin-bottom:4px;"><div class="umat-msg-bubble" style="display:inline-block;background:var(--u-p)20;padding:8px 12px;border-radius:12px 12px 4px 12px;font-size:12px;">'+esc(m.question)+'</div></div>'
          +'<div class="umat-msg umat-msg-ai"><div class="umat-msg-bubble umat-msg-bubble-ai" style="display:inline-block;background:var(--u-sfll);padding:8px 12px;border-radius:12px 12px 12px 4px;font-size:12px;">'
          +(m.answer?'<div class="umat-msg-text">'+esc(m.answer)+'</div>':'<em style="color:var(--u-ol);">No answer recorded.</em>')
          +(m.sources&&m.sources.length?'<div class="umat-msg-src" style="font-size:10px;color:var(--u-ol);margin-top:4px;">Sources: '+m.sources.map(function(x){return esc(typeof x==='string'?x:x.name||'');}).join(', ')+'</div>':'')
          +'</div></div></div>';
      }).join('');
    det.querySelector('.umat-collapse-detail-btn').addEventListener('click',function(){det.remove();});
  },function(){
    det.innerHTML='<div style="padding:10px;text-align:center;color:var(--u-ter);font-size:12px;">Failed to load messages.</div>';
  });
}
function wireSessPicker(){
  var btn=document.getElementById('lec-sess-pick-btn');
  if(btn)btn.addEventListener('click',function(){document.getElementById('lec-sess-cs-ov').classList.add('open');});
}

/* ─── Student Issues (Lecturer) ───────────────────── */
/* Hide notification badges when lecturer views/interacts with issues */
/* Old loadLecturerIssues removed — conversation system handled by initLecturerIssues() in umat_lecturer.js */
function loadLecturerIssues(){initLecturerIssues();}

/* ──────────────────────────────────────────────
   STRUGGLE INSIGHTS
   ────────────────────────────────────────────── */


/* Quiz Generator */
var qgenCid = CID || 0;
function populateQuizGenCourseSel(){
  var list=document.getElementById('qgen-cs-list');
  if(!list||!UD||!UD.courses)return;
  if(list._qgenPopulated)return;list._qgenPopulated=true;
  /* Determine active course for pre-selection */
  var activeCid=qgenCid||CID||0;
  list.innerHTML='<button class="umat-cs-item" data-cid="0" type="button">'+
    '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">apps</span></div>'+
    '<div class="umat-cs-item-info"><div class="umat-cs-item-name">All Courses</div><div class="umat-cs-item-code">Composite overview</div></div></button>'+
  UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">quiz</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  /* Pre-highlight active course */
  if(activeCid){
    var match=list.querySelector('[data-cid="'+activeCid+'"]');
    if(match)match.classList.add('umat-cs-item-active');
    var lbl=document.getElementById('qgen-cs-label');
    if(lbl){var nm=match?match.querySelector('.umat-cs-item-name'):null;lbl.textContent=nm?nm.textContent:(CN||'All Courses');}
  }
  list.querySelectorAll('.umat-cs-item').forEach(function(btn){
    btn.addEventListener('click',function(){
      qgenCid=parseInt(this.dataset.cid)||0;
      document.getElementById('qgen-cs-ov').classList.remove('open');
      var labelEl=document.getElementById('qgen-cs-label');
      if(labelEl)labelEl.textContent=qgenCid?(this.querySelector('.umat-cs-item-name')?.textContent||'Course'):'All Courses';
      require(['local_umat_ai/quizgen_review'],function(QG){QG.init(qgenCid);});
    });
  });
  var srch=document.getElementById('qgen-cs-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    list.querySelectorAll('.umat-cs-item').forEach(function(it){
      it.style.display=(!q||it.textContent.toLowerCase().includes(q))?'':'none';
    });
  });
}
window.loadQuizGenUI=function(){
  populateQuizGenCourseSel();
  // Auto-select current course if on a course page.
  if (!qgenCid && typeof CID !== 'undefined' && CID > 0) {
    qgenCid = CID;
    var labelEl = document.getElementById('qgen-cs-label');
    if (labelEl) labelEl.textContent = typeof CN !== 'undefined' && CN ? CN : 'Course #' + CID;
  }
  require(['local_umat_ai/quizgen_review'],function(QG){QG.init(qgenCid||0);},function(err){
    console.error('[quizgen] AMD load error:',err&&err.message?err.message:err);
    var body=document.getElementById('qgen-body');
    if(body)body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load Quiz Generator. Check that the AMD build file exists.</p></div>';
  });
};
document.querySelectorAll('#qgen-cs-btn').forEach(function(b){
  b.addEventListener('click',function(){document.getElementById('qgen-cs-ov').classList.toggle('open');});
});
document.getElementById('qgen-cs-ov')?.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});



/* ── Insights Dashboard ── */
var insCid = CID || 0;

/* Resolve the active insights course: CID > insCid > first course from UD */
function resolveInsightsCid(){
  if(CID) return CID;
  if(insCid) return insCid;
  if(typeof UD!=='undefined'&&UD&&UD.courses&&UD.courses.length){
    var first=UD.courses[0];
    insCid=first.id;
    console.log('[StruggleDashboard] auto-selected course: '+first.fullname+' (id='+first.id+')');
    var lbl=document.getElementById('ins-cs-label');
    if(lbl) lbl.textContent=first.fullname||first.shortname||'Course';
    return first.id;
  }
  return 0;
}

function populateInsightsCourseSel(){
  var list=document.getElementById('ins-cs-list');
  if(!list||!UD||!UD.courses)return;
  if(list._insPopulated)return; list._insPopulated=true;
  /* Determine active course for pre-selection */
  var activeCid=insCid||CID||0;
  list.innerHTML='<button class="umat-cs-item" data-cid="0" type="button">'+
    '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">apps</span></div>'+
    '<div class="umat-cs-item-info"><div class="umat-cs-item-name">All Courses</div><div class="umat-cs-item-code">Composite overview</div></div></button>'+
  UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">school</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  /* Pre-highlight active course */
  if(activeCid){
    var match=list.querySelector('[data-cid="'+activeCid+'"]');
    if(match)match.classList.add('umat-cs-item-active');
    var lbl=document.getElementById('ins-cs-label');
    if(lbl){var nm=match?match.querySelector('.umat-cs-item-name'):null;lbl.textContent=nm?nm.textContent:(CN||'All Courses');}
  }
  list.querySelectorAll('.umat-cs-item').forEach(function(btn){
    btn.addEventListener('click',function(){
      insCid=parseInt(this.dataset.cid)||0;
      CID=0; // Clear page-context CID so resolveInsightsCid() uses insCid
      // Visual selected state
      list.querySelectorAll('.umat-cs-item').forEach(function(it){it.classList.remove('umat-cs-item-active');});
      this.classList.add('umat-cs-item-active');
      document.getElementById('ins-cs-ov').classList.remove('open');
      var labelEl=document.getElementById('ins-cs-label');
      if(labelEl)labelEl.textContent=insCid?(this.querySelector('.umat-cs-item-name')?.textContent||'Course'):'All Courses';
      if(window.struggleDashboard)window.struggleDashboard.init(insCid);
      else loadInsights(insCid);
    });
  });
  var srch=document.getElementById('ins-cs-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    list.querySelectorAll('.umat-cs-item').forEach(function(it){
      it.style.display=(!q||it.textContent.toLowerCase().includes(q))?'':'none';
    });
  });
}
/* Toggle course selector overlay */
document.querySelectorAll('#ins-cs-btn').forEach(function(b){
  b.addEventListener('click',function(){document.getElementById('ins-cs-ov').classList.toggle('open');});
});
document.getElementById('ins-cs-ov')?.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
document.getElementById('ins-cs-close')?.addEventListener('click',function(){document.getElementById('ins-cs-ov').classList.remove('open');});

function loadInsights(cid){
  insCid=cid||0;
  var csLabel=document.getElementById('ins-course-label');
  if(csLabel)csLabel.textContent=cid?':':'';
  var skeleton=document.getElementById('sd-skeleton');
  var dashboard=document.querySelector('.sd-dashboard');

  if(!cid){
    if(dashboard)dashboard.style.display='';
    if(skeleton)skeleton.style.display='none';
    return;
  }

  if(skeleton)skeleton.style.display='flex';
  var badge=document.getElementById('ins-mode-badge');
  if(badge)badge.textContent='v2.0';

  ajax('local_umat_ai_get_struggle_dashboard_data',{courseid:cid,days:60},
    function(d){
      if(skeleton)skeleton.style.display='none';
      if(badge)badge.textContent=d.kpis?'v2.0 (live)':'v2.0';
      renderInsightsKpiRibbon(d.kpis);
      renderInsightsStudentTable(d.at_risk_students);
      renderInsightsTopicMastery(d.topic_mastery);
      renderInsightsMaterialHealth(d.material_health);
      renderInsightsQuestionsFeed(d.common_questions);
      renderInsightsTopicsFeed(d.scatter_plot_data);
      renderInsightsCourseHealth(d.course_health);
      renderInsightsScatterPlaceholder(d.scatter_plot_data);
    },
    function(e){
      if(skeleton)skeleton.style.display='none';
      var report=document.getElementById('sd-health-report');
      if(report)report.innerHTML='<div class="sd-empty">'+
        esc((e&&e.message)?e.message:'No data yet. Insights appear once students start chatting.')+'</div>';
    }
  );
}

/* ── KPI Ribbon ── */
function renderInsightsKpiRibbon(kpis){
  if(!kpis)return;

  var pctEl=document.getElementById('sd-eng-pct');
  if(pctEl)pctEl.textContent=(kpis.engagement_score||0)+'%';

  var deltaEl=document.getElementById('sd-eng-delta');
  if(deltaEl&&kpis.engagement_trend&&kpis.engagement_trend.length>1){
    var trend=kpis.engagement_trend;
    var delta=trend[trend.length-1]-trend[0];
    deltaEl.textContent=(delta>=0?'+ ':'- ')+Math.abs(Math.round(delta))+'% vs Last Week';
    deltaEl.className='sd-kpi-delta '+(delta>=0?'positive':'negative');
  }

  var riskEl=document.getElementById('sd-atrisk-count');
  if(riskEl)riskEl.textContent=kpis.at_risk_count||0;

  var avatarStack=document.getElementById('sd-atrisk-avatars');
  if(avatarStack&&kpis.at_risk_avatars){
    avatarStack.innerHTML=kpis.at_risk_avatars.map(function(a){
      return a.avatar||'<div class="sd-avatar-sm" title="'+esc(a.name)+'">'+esc(a.name.charAt(0))+'</div>';
    }).join('');
  }

  if(kpis.top_topic){
    var topicName=document.getElementById('sd-topic-name');
    if(topicName)topicName.textContent=kpis.top_topic.name;

    var topicInsight=document.getElementById('sd-topic-insight');
    if(topicInsight)topicInsight.textContent=kpis.top_topic.ai_insight||'';

    var gaugeParent=document.getElementById('sd-topic-gauge');
    if(gaugeParent){
      var val=Math.min(100,Math.max(0,kpis.top_topic.gauge_value||0));
      var gColor=val>=70?'#a5304d':(val>=40?'#f59e0b':'#006b2f');
      gaugeParent.outerHTML='<svg id="sd-topic-gauge" width="80" height="60" viewBox="0 0 80 60">'+
        '<path d="M10,50 A30,30 0 0,1 70,50" fill="none" stroke="#e5e7eb" stroke-width="8" stroke-linecap="round"/>'+
        '<path d="M10,50 A30,30 0 0,1 '+(40+30*Math.cos((val/100*180-180)*Math.PI/180)).toFixed(1)+','+
        (50+30*Math.sin((val/100*180-180)*Math.PI/180)).toFixed(1)+
        '" fill="none" stroke="'+gColor+'" stroke-width="8" stroke-linecap="round"/>'+
        '<text x="40" y="52" text-anchor="middle" font-size="14" font-weight="700" fill="'+gColor+'">'+val+'%</text></svg>';
    }
  }

  if(kpis.top_material){
    var matName=document.getElementById('sd-mat-name');
    if(matName)matName.textContent=kpis.top_material.name;

    var weekdayEl=document.getElementById('sd-mat-weekday-chart');
    if(weekdayEl&&kpis.top_material.weekday_volume){
      var maxV=Math.max.apply(null,kpis.top_material.weekday_volume);
      var days=['Mon','Tue','Wed','Thu','Fri'];
      weekdayEl.outerHTML='<div id="sd-mat-weekday-chart" class="sd-mini-bar">'+
        kpis.top_material.weekday_volume.map(function(v,i){
          var h=maxV?Math.round(v/maxV*28):0;
          return '<div class="sd-mini-bar-col"><div class="sd-mini-bar-fill" style="height:'+h+'px;background:#a5304d;"></div><span>'+days[i]+'</span></div>';
        }).join('')+'</div>';
    }
  }
}

/* ── Scatter Placeholder (no Chart.js) ── */
function renderInsightsScatterPlaceholder(data){
  var canvas=document.getElementById('sd-scatter-plot');
  if(!canvas)return;
  if(!data||!data.length){
    canvas.parentElement.innerHTML='<div class="sd-empty">No scatter data yet. Questions will appear once students start asking.</div>';
    return;
  }
  var sorted=data.slice().sort(function(a,b){return b.friction-a.friction;});
  var cols={critical:'#a5304d',moderate:'#f59e0b',minor:'#006b2f',healthy:'#4ade80'};
  canvas.parentElement.innerHTML='<div class="sd-scatter-table">'+
    '<div class="sd-scatter-hdr"><span>Topic</span><span>Volume</span><span>Friction</span></div>'+
    sorted.slice(0,10).map(function(d){
      var c=cols[d.severity]||'#999';
      return '<div class="sd-scatter-row"><span class="sd-scatter-topic" style="border-left:3px solid '+c+';padding-left:6px;">'+esc(d.topic)+'</span>'+
        '<span>'+d.volume+'</span><span>'+Math.round(d.friction)+'</span></div>';
    }).join('')+'</div>';
}

/* ── Topic Mastery List ── */
function renderInsightsTopicMastery(data){
  var list=document.getElementById('sd-topic-mastery-list');
  if(!list)return;
  if(!data||!data.length){
    list.innerHTML='<div class="sd-empty">No topic data yet.</div>';
    return;
  }
  list.innerHTML=data.map(function(t){
    var pct=t.total_students>0?Math.round(t.students_mastered/t.total_students*100):0;
    var ringColor=t.difficulty==='critical'?'#a5304d':(t.difficulty==='moderate'?'#f59e0b':'#006b2f');
    var ringHtml='<svg class="sd-progress-ring" viewBox="0 0 36 36">'+
      '<circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="2.8"/>'+
      '<circle cx="18" cy="18" r="15.9" fill="none" stroke="'+ringColor+'" stroke-width="2.8" '+
      'stroke-dasharray="100" stroke-dashoffset="'+(100-pct)+'" transform="rotate(-90,18,18)" stroke-linecap="round"/>'+
      '<text x="18" y="18" text-anchor="middle" dominant-baseline="central" font-size="8" font-weight="700" fill="'+ringColor+'">'+pct+'%</text></svg>';
    return '<div class="sd-mastery-row">'+
      ringHtml+
      '<div class="sd-mastery-name">'+esc(t.topic)+'</div>'+
      '<span class="sd-diff-badge '+t.difficulty+'">'+t.difficulty+'</span>'+
    '</div>';
  }).join('');
}

/* ── Student Triage Table ── */
var insStudentData=[];
function renderInsightsStudentTable(students){
  var tbody=document.getElementById('sd-student-tbody');
  if(!tbody)return;
  insStudentData=students||[];
  if(!insStudentData.length){
    tbody.innerHTML='<tr><td colspan="6" class="sd-empty">No at-risk students.</td></tr>';
    return;
  }
  tbody.innerHTML=insStudentData.map(function(s){
    return '<tr>'+
      '<td><input type="checkbox" class="sd-student-cb" data-uid="'+s.id+'"></td>'+
      '<td>'+(s.avatar||'')+'<span class="sd-student-name">'+esc(s.name)+'</span></td>'+
      '<td><span class="sd-risk-badge '+s.risk+'">'+s.risk+'</span></td>'+
      '<td><span class="sd-struggle-trunc" title="'+esc(s.struggle_area)+'">'+esc(s.struggle_area)+'</span></td>'+
      '<td>'+esc(s.last_active)+'</td>'+
      '<td><div class="sd-action-icons">'+
      '<button class="sd-action-icon mail" onclick="insActionStudent('+s.id+',\'mail\')"><span class="material-symbols-outlined">mail</span></button>'+
      '<button class="sd-action-icon video" onclick="insActionStudent('+s.id+',\'video\')"><span class="material-symbols-outlined">videocam</span></button>'+
      '<button class="sd-action-icon trash" onclick="insActionStudent('+s.id+',\'trash\')"><span class="material-symbols-outlined">flag</span></button>'+
      '</div></td></tr>';
  }).join('');
}

function insActionStudent(uid, action){
  var student=insStudentData.find(function(s){return s.id==uid;});
  if(!student)return;
  if(action==='mail'){
    window.open('/message/index.php?user1to='+uid+'&id='+insCid,'_blank');
  }else if(action==='video'){
    var overlay=document.createElement('div');
    overlay.className='sd-bbb-overlay open';
    overlay.innerHTML='<iframe class="sd-bbb-frame" src="/mod/bigbluebuttonbn/index.php?id='+insCid+'" allow="microphone;camera" sandbox="allow-scripts allow-same-origin allow-forms"></iframe>';
    overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});
    document.body.appendChild(overlay);
  }else if(action==='trash'){
    ajax('local_umat_ai_execute_intervention',{courseid:insCid,userid:uid,action:'flagged',message:'Flagged for review from Struggle Dashboard'},
      function(){if(window.Notification)Notification.addNotification({message:'Student flagged for review.',type:'success'});},
      function(){if(window.Notification)Notification.addNotification({message:'Failed to flag student.',type:'error'});}
    );
  }
}

/* ── Material Health (CSS bars) ── */
function renderInsightsMaterialHealth(data){
  var canvas=document.getElementById('sd-material-health-chart');
  if(!canvas)return;
  if(!data||!data.length){
    canvas.parentElement.innerHTML='<div class="sd-empty">No material health data yet.</div>';
    return;
  }
  canvas.parentElement.innerHTML='<div class="sd-mat-health-bars">'+
    data.map(function(m){
      var label=m.name.length>25?m.name.substring(0,22)+'...':m.name;
      return '<div class="sd-mat-bar-row"><div class="sd-mat-bar-label" title="'+esc(m.name)+'">'+esc(label)+'</div>'+
        '<div class="sd-mat-bar-track"><div class="sd-mat-bar-fill complete" style="width:'+Math.min(100,m.pct_complete)+'%" title="% Complete: '+m.pct_complete+'"></div></div>'+
        '<div class="sd-mat-bar-track"><div class="sd-mat-bar-fill questions" style="width:'+Math.min(100,m.pct_questions)+'%" title="% Questions: '+m.pct_questions+'"></div></div>'+
        '<div class="sd-mat-bar-track"><div class="sd-mat-bar-fill correct" style="width:'+Math.min(100,m.pct_correct)+'%" title="% Correct: '+m.pct_correct+'"></div></div>'+
      '</div>';
    }).join('')+
    '<div class="sd-mat-bar-legend"><span class="sd-mat-legend-dot complete"></span>% Complete <span class="sd-mat-legend-dot questions"></span>% Questions <span class="sd-mat-legend-dot correct"></span>% Correct</div></div>';
}

/* ── Questions Feed ── */
function renderInsightsQuestionsFeed(data){
  var feed=document.getElementById('sd-questions-feed');
  if(!feed)return;
  if(!data||!data.length){
    feed.innerHTML='<div class="sd-empty">No questions yet.</div>';
    return;
  }
  feed.innerHTML=data.slice(0,15).map(function(q){
    return '<div class="sd-feed-item">'+
      '<div class="sd-feed-text">'+esc(q.text)+'</div>'+
      '<div class="sd-feed-meta">'+
      '<span class="sd-feed-count">'+q.count+'x</span>'+
      (q.source_material?' &middot; '+esc(q.source_material):'')+
      '</div></div>';
  }).join('');
}

/* ── Topics Feed ── */
function renderInsightsTopicsFeed(scatterData){
  var feed=document.getElementById('sd-topics-feed');
  if(!feed)return;
  if(!scatterData||!scatterData.length){
    feed.innerHTML='<div class="sd-empty">No topics yet.</div>';
    return;
  }
  var sorted=scatterData.slice().sort(function(a,b){return b.volume-a.volume;});
  feed.innerHTML=sorted.slice(0,15).map(function(t){
    var color=t.severity==='critical'?'#a5304d':(t.severity==='moderate'?'#f59e0b':'#006b2f');
    return '<div class="sd-feed-item">'+
      '<div class="sd-feed-text" style="border-left:3px solid '+color+';padding-left:8px;">'+esc(t.topic)+'</div>'+
      '<div class="sd-feed-meta">'+t.volume+' questions &middot; Friction: '+Math.round(t.friction)+'</div></div>';
  }).join('');
}

/* ── Health Report ── */
function renderInsightsCourseHealth(report){
  var reportEl=document.getElementById('sd-health-report');
  var recEl=document.getElementById('sd-recommendations');
  if(!report||!report.summary){
    if(reportEl)reportEl.innerHTML='<div class="sd-empty">AI course health report will appear once enough student data is available.</div>';
    if(recEl)recEl.innerHTML='';
    return;
  }
  if(reportEl)reportEl.innerHTML=esc(report.summary);
  if(recEl&&report.recommendations){
    recEl.innerHTML=report.recommendations.map(function(r){
      return '<span class="sd-rec-chip">'+esc(r)+'</span>';
    }).join('');
  }
  var btn=document.getElementById('sd-ai-strategy-btn');
  if(btn&&!btn.dataset.bound){
    btn.dataset.bound='1';
    btn.addEventListener('click',function(){
      if(this.disabled)return;
      this.disabled=true;
      this.innerHTML='<span class="material-symbols-outlined umat-spin">progress_activity</span> Generating\u2026';
      var q='Suggest tailored student outreach strategies for struggling students in this course. Include specific actions, messaging tone, and priority order.';
      if(typeof _umatStreamInline==='function'){
        _umatStreamInline({
          url:streamUrl,sesskey:moodleSesskey,courseid:insCid||CID,question:q,
          session_key:'sd_ai_'+(insCid||CID),targetId:'sd-health-report',
          onDone:function(){
            btn.disabled=false;
            btn.innerHTML='<span class="material-symbols-outlined">auto_awesome</span> Ask AI for tailored student outreach strategies';
          },
          onError:function(err){
            btn.disabled=false;
            btn.innerHTML='<span class="material-symbols-outlined">auto_awesome</span> Ask AI for tailored student outreach strategies';
            var el=document.getElementById('sd-health-report');
            if(el)el.innerHTML='<div class="sd-stream-error">'+esc(err.message||'AI unavailable.')+'</div>';
          }
        });
      }
    });
  }
}

/* ── NLQ Search ── */
document.getElementById('sd-nlq-btn')?.addEventListener('click',submitNLQ);
document.getElementById('sd-nlq-input')?.addEventListener('keydown',function(e){if(e.key==='Enter')submitNLQ();});

function submitNLQ(){
  if(window.struggleDashboard && typeof window.struggleDashboard.submitNLQ==='function'){
    window.struggleDashboard.submitNLQ();
    return;
  }
  var input=document.getElementById('sd-nlq-input');
  var query=input?.value.trim();
  if(!query||!insCid)return;
  var response=document.getElementById('sd-nlq-response');
  if(response){
    response.style.display='block';
    _umatStreamInline({
      url:streamUrl,sesskey:moodleSesskey,courseid:insCid||CID,question:query,
      session_key:'lec_nlq_'+(insCid||CID),targetId:'sd-nlq-response',
      onError:function(err){response.innerHTML='<div class="sd-nlq-error">'+esc(err.message||'Could not process your query.')+'</div>';}
    });
  }
}

/* Compact panel + Mini panel chat handlers delegated to AMD umat_lecturer.js module.
   Do NOT add duplicate sendLecQ / event listeners here — the AMD module owns them. */
if(typeof _umatInitScrollToBottom==='function')_umatInitScrollToBottom('lec-mini-msgs');

/* Init home on overlay open */
initHome();
document.getElementById('lec-home-date').textContent=(function(){var d=new Date();return d.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});})();
/* Populate course selectors */
populateLibCourseSel();
populateInsightsCourseSel_legacy();
populateInsightsCourseSel_legacy();
/* Auto-load analytics + struggle when overlay opens */
if(expand)expand.addEventListener('click',function(){setTimeout(function(){
  var anCid=CID||lecAnalyticsCourseId;
  if(!lecLoaded['lec-analytics']){lecLoaded['lec-analytics']=true;loadAnalytics(anCid);}
  var stCid=resolveInsightsCid();
  loadInsights(stCid);
},100);});
/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'lec-ai-mini',isOpen:function(e){return e.style.display==='flex';},close:function(e){e.style.display='none';}},
  {id:'lec-ov',isOpen:function(e){return e.classList.contains('open');},close:closeDash},
  {id:'lec-cp-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
</script>
HTML;
    }

    public static function hub_overlay(string $wwwroot, object $user, string $userData, string $platformName = 'UMaT'): string {
        $safePlatform = htmlspecialchars($platformName, ENT_QUOTES);
        $uid     = (int)$user->id;
        $uName   = json_encode(fullname($user));
        $uInit   = htmlspecialchars(strtoupper(mb_substr($user->firstname,0,1).mb_substr($user->lastname,0,1)), ENT_QUOTES);
        $jsWwwroot = json_encode(rtrim($wwwroot, '/'));
        $jsUD    = $userData; // raw JSON string from preload_user_data()
        $logUrl  = $wwwroot . '/login/logout.php';
        $streamUrl = json_encode('/local/umat_ai/chat_stream.php');
        $moodleSesskey = json_encode(sesskey());
        $sharedJs = self::shared_js('hub-ov', 'hub-ov-close');

        // Glassmorphism mobile tab bar (in-overlay)
        $hubGlassTabs = [
            ['id' => 'hub-home',     'icon' => 'home',          'label' => 'Home',     'active' => true],
            ['id' => 'hub-tutor',    'icon' => 'smart_toy',     'label' => 'Tutor',    'active' => false],
            ['id' => 'hub-lectures', 'icon' => 'video_library', 'label' => 'Lectures', 'active' => false],
            ['id' => 'hub-courses',  'icon' => 'menu_book',     'label' => 'Courses',  'active' => false],
            ['id' => 'hub-library',  'icon' => 'local_library', 'label' => 'Resource Materials', 'active' => false],
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
        <div class="umat-sb-brand"><strong>{$safePlatform} Moodle</strong><span>AI Enhanced Learning</span></div>
        <button class="umat-sb-close-btn" id="hub-ov-close" type="button" title="Collapse sidebar">
          <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <button class="umat-sb-expand-btn" id="hub-ov-close-exp" type="button" title="Expand sidebar">
          <span class="material-symbols-outlined">chevron_right</span>
        </button>
      </div>
      <nav class="umat-sb-nav">
        <button class="umat-sb-item active" data-hp="hub-home" type="button" title="Home"><span class="material-symbols-outlined">home</span><span class="umat-sb-item-lbl">Home</span></button>
        <button class="umat-sb-item" data-hp="hub-tutor" type="button" title="AI Tutor"><span class="material-symbols-outlined">smart_toy</span><span class="umat-sb-item-lbl">AI Tutor</span></button>
        <button class="umat-sb-item" data-hp="hub-lectures" type="button" title="Lecture Recordings"><span class="material-symbols-outlined">video_library</span><span class="umat-sb-item-lbl">Lecture Recordings</span></button>
        <button class="umat-sb-item" data-hp="hub-courses" type="button" title="My Courses"><span class="material-symbols-outlined">menu_book</span><span class="umat-sb-item-lbl">My Courses</span></button>
        <button class="umat-sb-item" data-hp="hub-library" type="button" title="Resource Materials"><span class="material-symbols-outlined">local_library</span><span class="umat-sb-item-lbl">Resource Materials</span></button>
        <button class="umat-sb-item" data-hp="hub-sessions" type="button" title="Sessions"><span class="material-symbols-outlined">history</span><span class="umat-sb-item-lbl">Sessions</span></button>
      </nav>
      <div class="umat-sb-divider"></div>
      <button class="umat-sb-new" id="hub-new-sess" type="button" title="New Session">
        <span class="material-symbols-outlined">add</span>
        <span class="umat-sb-new-lbl">New Session</span>
      </button>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'" title="Sign Out">
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
        <div style="display:flex;flex:1;overflow:hidden;">
          <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative;">
            <div class="umat-msgs" id="hub-msgs" style="padding-bottom:80px;">
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
            <div class="umat-chat-overlay">
              <button class="umat-scroll-bottom" id="hub-scroll-bottom" type="button"><span class="material-symbols-outlined">expand_more</span></button>
              <div class="umat-chatbar">
                <button class="umat-chatbar-btn" id="hub-attach-btn" type="button"><span class="material-symbols-outlined">add</span></button>
                <textarea id="hub-input" class="umat-chatbar-input" placeholder="Ask anything about your courses…" rows="1" maxlength="900"></textarea>
                <button class="umat-chatbar-btn" id="hub-mic-btn" type="button" title="Voice input"><span class="material-symbols-outlined">mic</span></button>
                <button class="umat-chatbar-send" id="hub-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>
              </div>
              <div class="umat-mat-bar" id="hub-mat-bar"></div>
            </div>
          </div>
        </div>
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
        <!-- Transcriptions section -->
        <div style="margin-top:16px;">
          <div style="padding:0 14px 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);display:flex;align-items:center;gap:6px;">
            <span class="material-symbols-outlined" style="font-size:14px;">subtitles</span> Transcriptions
          </div>
          <div class="umat-video-grid" id="hub-lec-transcripts">
            <div class="umat-empty"><span class="material-symbols-outlined">subtitles_off</span><p>No transcriptions available for this course yet.</p></div>
          </div>
        </div>
        <!-- Transcript viewer overlay -->
        <div class="umat-cs-overlay" id="hub-transcript-ov" style="display:none;">
          <div class="umat-cs-modal" style="max-width:640px;max-height:80vh;">
            <div class="umat-cs-modal-hdr">
              <h3><span class="material-symbols-outlined">subtitles</span><span id="hub-transcript-title">Transcript</span></h3>
              <button class="umat-cs-close" id="hub-transcript-close" type="button"><span class="material-symbols-outlined">close</span></button>
            </div>
            <div style="padding:16px;overflow-y:auto;max-height:calc(80vh - 60px);">
              <div id="hub-transcript-body" style="font-size:13px;line-height:1.7;color:var(--u-ons);white-space:pre-wrap;"></div>
              <div id="hub-transcript-tools" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
                <button type="button" class="umat-chip" data-tool="flashcards"><span class="material-symbols-outlined" style="font-size:14px;">style</span> Flashcards</button>
                <button type="button" class="umat-chip" data-tool="glossary"><span class="material-symbols-outlined" style="font-size:14px;">book</span> Glossary</button>
                <button type="button" class="umat-chip" data-tool="chapters"><span class="material-symbols-outlined" style="font-size:14px;">chapter_add</span> Chapters</button>
              </div>
              <div id="hub-transcript-tools-body" style="margin-top:12px;"></div>
            </div>
          </div>
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

<script>(function(){
var wwwroot  = {$jsWwwroot};
var streamUrl = {$streamUrl};
var moodleSesskey = {$moodleSesskey};
var UD  = {$jsUD};
var sessKey = 'hub_' + Math.random().toString(36).substr(2,18);

/* Fallback ajax when AMD is unavailable */
if(typeof ajax!=='function'){
  window.ajax=function(m,a,d,f){
    var x=new XMLHttpRequest();
    x.open('POST','/lib/ajax/service.php?sesskey='+encodeURIComponent(moodleSesskey));
    x.setRequestHeader('Content-Type','application/json');
    x.onload=function(){if(x.status===200){try{var r=JSON.parse(x.responseText);if(r&&r[0]){if(r[0].error){console.error('[umat-ajax]',m,r[0].error);(f||function(){})(r[0].error);}else{console.log('[umat-ajax]',m,'OK');(d||function(){})(r[0].data);}}else{console.warn('[umat-ajax]',m,'unexpected:',r);(f||function(){})(new Error('Unexpected'));}}catch(e){console.error('[umat-ajax]',m,'parse:',e);(f||function(){})(e);}}else{console.error('[umat-ajax]',m,'HTTP',x.status);(f||function(){})(new Error('HTTP '+x.status));}};
    x.onerror=function(){console.error('[umat-ajax]',m,'network');(f||function(){})(new Error('Network'));};
    x.send(JSON.stringify([{index:0,methodname:m,args:a}]));
  };
}
/* Fallback esc when AMD module hasn't loaded */
if(typeof esc!=='function'){
  window.esc=function(s){if(s==null)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(String(s)));return d.innerHTML;};
}
/* Fallback ESC key handler when AMD umatshared.js fails to load */
if(typeof _umatInitEsc!=='function'){
  window._umatInitEsc=function(layers){
    document.addEventListener('keydown',function(e){
      if(e.key!=='Escape')return;
      for(var i=0;i<layers.length;i++){
        var el=document.getElementById(layers[i].id);
        if(el&&layers[i].isOpen(el)){layers[i].close(el);e.preventDefault();return;}
      }
    });
  };
}
/* Rolling 60s rate-limit window — mirrors the server check, refills as entries expire */
var RATE_MAX = 10;
var qTimes   = [];
var selMat  = [];
var loaded  = {};
var activeCID = 0;

/* ---- Scroll-to-bottom FAB ---- */
if(typeof _umatInitScrollToBottom==='function')_umatInitScrollToBottom('hub-msgs');

/* FAB / overlay toggle */
var fab=document.getElementById('hub-fab');
var ov=document.getElementById('hub-ov');
var ovClose=document.getElementById('hub-ov-close');
var newBtn=document.getElementById('hub-new-sess');
var newBtn2=document.getElementById('hub-new-sess2');

fab.addEventListener('click',function(){ov.classList.add('open');initHome();});
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
        '<button class="yt-btn" data-act="library" onclick="event.stopPropagation()"><span class="material-symbols-outlined">local_library</span>Resource Materials</button>'+
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

/* ─── Transcriptions ─── */
var _hubTranscriptJobId=null;
function loadTranscriptions(cid){
  var g=document.getElementById('hub-lec-transcripts');if(!g)return;
  if(!cid){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">subtitles_off</span><p>Select a course to view transcriptions.</p></div>';return;}
  g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading transcriptions…</p></div>';
  ajax('local_umat_ai_list_transcriptions',{courseid:cid},function(r){
    var jobs=[];try{jobs=typeof r.jobs==='string'?JSON.parse(r.jobs):(r.jobs||[]);}catch(e){jobs=[];}
    if(!jobs.length){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">subtitles_off</span><p>No transcriptions available for this course yet.</p></div>';return;}
    g.innerHTML=jobs.map(function(j){
      var title=j.title||j.filename||j.session_id||'Recording';
      var st=j.status||'unknown';
      var stColor=st==='completed'?'var(--u-p)':st==='processing'?'#d97706':'var(--u-ol)';
      var stLabel=st==='completed'?'Ready':st==='processing'?'Processing…':st;
      return '<div class="umat-video-tile" data-job="'+esc(j.job_id||j.session_id)+'" data-title="'+esc(title)+'" style="cursor:pointer;">'+
        '<div class="umat-video-thumb" style="background:linear-gradient(135deg,rgba(0,107,47,.08),rgba(0,107,47,.02));display:flex;align-items:center;justify-content:center;">'+
        '<span class="material-symbols-outlined" style="font-size:36px;color:var(--u-p);">subtitles</span></div>'+
        '<div class="umat-video-tile-info"><h4 title="'+esc(title)+'">'+esc(title)+'</h4>'+
        '<span style="font-size:10px;color:'+stColor+';font-weight:600;">'+stLabel+'</span></div></div>';
    }).join('');
    g.querySelectorAll('.umat-video-tile').forEach(function(t){
      t.addEventListener('click',function(){openTranscriptViewer(t.dataset.job,t.dataset.title);});
    });
    var srch=document.getElementById('hub-lec-search');
    if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.umat-video-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
  },function(){g.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load transcriptions.</p></div>';});
}
function openTranscriptViewer(jobId,title){
  _hubTranscriptJobId=jobId;
  var ov=document.getElementById('hub-transcript-ov'),tt=document.getElementById('hub-transcript-title'),tb=document.getElementById('hub-transcript-body'),tTools=document.getElementById('hub-transcript-tools-body');
  if(tt)tt.textContent=title||'Transcript';
  if(tb)tb.innerHTML='<div style="text-align:center;padding:20px;"><span class="material-symbols-outlined" style="font-size:24px;color:var(--u-ol);">hourglass_empty</span><p style="font-size:12px;color:var(--u-ol);">Loading transcript…</p></div>';
  if(tTools)tTools.innerHTML='';
  if(ov)ov.style.display='flex';
  ajax('local_umat_ai_get_transcription',{job_id:jobId},function(r){
    if(r.success&&r.transcript){if(tb)tb.textContent=r.transcript;}
    else if(r.status==='processing'){if(tb)tb.innerHTML='<div style="text-align:center;padding:20px;"><span class="material-symbols-outlined" style="font-size:24px;color:#d97706;">hourglass_empty</span><p style="font-size:12px;color:#d97706;">Transcription is still processing…</p><p style="font-size:11px;color:var(--u-ol);">This page will refresh in 10 seconds.</p></div>';setTimeout(function(){openTranscriptViewer(jobId,title);},10000);}
    else{if(tb)tb.innerHTML='<div style="text-align:center;padding:20px;"><span class="material-symbols-outlined" style="font-size:24px;color:var(--u-ter);">error</span><p style="font-size:12px;color:var(--u-ter);">'+esc(r.error||'No transcript available')+'</p></div>';}
  },function(){if(tb)tb.innerHTML='<div style="text-align:center;padding:20px;"><p style="font-size:12px;color:var(--u-ter);">Could not load transcript.</p></div>';});
  document.querySelectorAll('#hub-transcript-tools .umat-chip').forEach(function(btn){
    btn.onclick=function(){_loadStudyTool(this.dataset.tool);};
  });
}
function _loadStudyTool(tool){
  if(!_hubTranscriptJobId)return;
  var body=document.getElementById('hub-transcript-tools-body');if(!body)return;
  body.innerHTML='<div style="text-align:center;padding:12px;"><span class="material-symbols-outlined" style="font-size:20px;color:var(--u-ol);">hourglass_empty</span><p style="font-size:11px;color:var(--u-ol);">Generating '+esc(tool)+'…</p></div>';
  ajax('local_umat_ai_get_study_tools',{job_id:_hubTranscriptJobId,tool:tool},function(r){
    if(!r.success||!r.data){body.innerHTML='<p style="font-size:12px;color:var(--u-ter);">'+esc(r.message||'Failed to generate')+'</p>';return;}
    var data=[];try{data=typeof r.data==='string'?JSON.parse(r.data):r.data;}catch(e){data=[];}
    if(!data.length){body.innerHTML='<p style="font-size:12px;color:var(--u-ol);">No '+esc(tool)+' generated.</p>';return;}
    if(tool==='flashcards'){
      body.innerHTML=data.map(function(fc,i){
        return '<div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r8);padding:12px;margin-bottom:8px;">'+
          '<div style="font-size:12px;font-weight:700;color:var(--u-p);margin-bottom:6px;">Card '+(i+1)+'</div>'+
          '<div style="font-size:13px;font-weight:600;margin-bottom:4px;">'+esc(fc.term||fc.front||fc.question)+'</div>'+
          '<div style="font-size:12px;color:var(--u-ol);">'+esc(fc.definition||fc.back||fc.answer)+'</div></div>';
      }).join('');
    }else if(tool==='glossary'){
      body.innerHTML='<dl style="margin:0;">'+data.map(function(g){
        return '<dt style="font-size:13px;font-weight:700;color:var(--u-p);margin-top:8px;">'+esc(g.term||g.word)+'</dt>'+
          '<dd style="font-size:12px;color:var(--u-ol);margin:2px 0 0 16px;">'+esc(g.definition||g.meaning)+'</dd>';
      }).join('')+'</dl>';
    }else if(tool==='chapters'){
      body.innerHTML=data.map(function(ch,i){
        return '<div style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--u-olv);">'+
          '<span style="font-size:11px;font-weight:700;color:var(--u-p);white-space:nowrap;">'+esc(ch.timestamp||ch.time||('0'+(i+1)))+'</span>'+
          '<span style="font-size:13px;">'+esc(ch.title||ch.label)+'</span></div>';
      }).join('');
    }
  },function(){body.innerHTML='<p style="font-size:12px;color:var(--u-ter);">Connection error.</p>';});
}
(function(){
  var cls=document.getElementById('hub-transcript-close');if(cls)cls.addEventListener('click',function(){document.getElementById('hub-transcript-ov').style.display='none';});
  var ov=document.getElementById('hub-transcript-ov');if(ov)ov.addEventListener('click',function(e){if(e.target===this)this.style.display='none';});
  /* Hook into loadLectures to also load transcriptions */
  var origLoadLectures=window.loadLectures;
  if(typeof origLoadLectures==='function'){
    window.loadLectures=function(cid){origLoadLectures(cid);loadTranscriptions(cid);};
  }
})();

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
      return '<div class="yt-tile" data-url="'+esc(m.url)+'" data-name="'+esc(m.filename)+'" data-mime="'+esc(m.mimetype)+'" data-fileid="'+(m.id||0)+'" data-courseid="'+(courseId||0)+'">'+
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
          var type=mime.indexOf('video')>=0?'video':mime.indexOf('pdf')>=0?'pdf':mime.indexOf('image')>=0?'image':mime.indexOf('audio')>=0?'audio':mime.indexOf('wordprocessingml.document')>=0?'docx':mime.indexOf('msword')>=0?'doc':mime.indexOf('spreadsheetml.sheet')>=0?'xlsx':mime.indexOf('presentationml.presentation')>=0?'pptx':mime.indexOf('ms-powerpoint')>=0?'ppt':(mime.indexOf('text/')===0||mime.indexOf('application/json')===0||mime.indexOf('application/xml')===0||mime.indexOf('application/x-httpd-php')>=0)?'code':'other';
          window.umatMaterialViewer.open(type,{url:url,name:name,downloadUrl:url,mime:mime,materialId:parseInt(tile.dataset.fileid)||0,courseId:parseInt(tile.dataset.courseid)||0});
        }else{window.open(url,'_blank');}
      });
      var vb=tile.querySelector('.yt-view-btn');
      if(vb)vb.addEventListener('click',function(e){e.stopPropagation();tile.click();});
    });
    var srch=document.getElementById('hub-lib-search');if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.yt-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
  },function(){console.error('[umat] hub overlay loadLibrary failed');g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
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

/* Chat + attachment drawer delegated to AMD umat_hub.js module.
   Do NOT add duplicate sendQ / event listeners here — the AMD module owns them. */

/* Attachment drawer (enhanced) — wait for AMD module to load */
!function w(){if(typeof _umatInitAttachDrawer==='function'){window.hubDrawerCtrl=_umatInitAttachDrawer({
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
});}else setTimeout(w,20);}();

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
function newSession(){sessKey='hub_'+Math.random().toString(36).substr(2,18);selMat=[];if(hubDrawerCtrl)hubDrawerCtrl.clear();var msgs=document.getElementById('hub-msgs');if(msgs){msgs.innerHTML='';addWelcome('your courses');}if(typeof _umatInitScrollToBottom==='function')_umatInitScrollToBottom('hub-msgs');updateRate();}
if(newBtn)newBtn.addEventListener('click',newSession);
if(newBtn2)newBtn2.addEventListener('click',function(){newSession();switchPane('hub-tutor');});

/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'hub-attach-drawer',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}},
  {id:'hub-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
</script>
HTML;
    }

    public static function admin_overlay(string $wwwroot, object $user, string $platformName = 'UMaT'): string {
        $safePlatform = htmlspecialchars($platformName, ENT_QUOTES);
        $uid     = (int)$user->id;
        $uName   = json_encode(fullname($user));
        $uInit   = htmlspecialchars(strtoupper(mb_substr($user->firstname,0,1).mb_substr($user->lastname,0,1)), ENT_QUOTES);
        $jsWwwroot = json_encode(rtrim($wwwroot, '/'));
        $logUrl  = $wwwroot . '/login/logout.php';
        $streamUrl = json_encode('/local/umat_ai/chat_stream.php');
        $moodleSesskey = json_encode(sesskey());

        return <<<HTML
<!-- ============================================================
     ADMIN CONTROL FAB + COMPACT PANEL
     ============================================================ -->

<!-- ADMIN FAB -->
<button class="umat-fab umat-fab-admin" id="admin-fab" type="button" aria-label="System Control">
  <span class="material-symbols-outlined">admin_panel_settings</span>
  <span class="umat-fab-tip">System Control</span>
</button>

<!-- ADMIN EXPANDED OVERLAY -->
<div class="umat-ov" id="admin-ov" role="dialog" aria-modal="true" style="z-index:9996;">
  <div class="umat-ov-body" style="flex:1;overflow:hidden;display:flex;">

    <!-- SIDEBAR -->
    <div class="umat-sb" id="admin-sb">
      <div class="umat-sb-head">
        <div class="umat-sb-logo"><span class="material-symbols-outlined">admin_panel_settings</span></div>
        <div class="umat-sb-brand"><strong>System Control</strong><span>{$safePlatform} AI Platform</span></div>
        <button class="umat-sb-close-btn" id="admin-ov-close" type="button" title="Collapse sidebar">
          <span class="material-symbols-outlined">chevron_left</span>
        </button>
        <button class="umat-sb-expand-btn" id="admin-ov-close-exp" type="button" title="Expand sidebar">
          <span class="material-symbols-outlined">chevron_right</span>
        </button>
      </div>
      <nav class="umat-sb-nav">
        <button class="umat-sb-item active" data-aexp-tab="aexp-dashboard" type="button" title="Dashboard">
          <span class="material-symbols-outlined">dashboard</span><span class="umat-sb-item-lbl">Dashboard</span>
        </button>
        <button class="umat-sb-item" data-aexp-tab="aexp-features" type="button" title="Features">
          <span class="material-symbols-outlined">tune</span><span class="umat-sb-item-lbl">Features</span>
        </button>
        <button class="umat-sb-item" data-aexp-tab="aexp-theme" type="button" title="Theme">
          <span class="material-symbols-outlined">palette</span><span class="umat-sb-item-lbl">Theme</span>
        </button>
        <button class="umat-sb-item" data-aexp-tab="aexp-actions" type="button" title="Actions">
          <span class="material-symbols-outlined">bolt</span><span class="umat-sb-item-lbl">Actions</span>
        </button>
      </nav>
      <div class="umat-sb-divider"></div>
      <div class="umat-sb-foot">
        <button class="umat-sb-item" type="button" onclick="window.location.href='{$logUrl}'" title="Sign Out">
          <span class="material-symbols-outlined">logout</span><span class="umat-sb-item-lbl">Sign Out</span>
        </button>
      </div>
    </div>

    <!-- CONTENT -->
    <div class="umat-ov-content" id="admin-ov-content">

      <button class="umat-ov-close-btn" type="button" onclick="document.getElementById('admin-ov').classList.remove('open')" title="Close">
        <span class="material-symbols-outlined">close</span>
      </button>

      <!-- DASHBOARD TAB -->
      <div class="umat-tab-pane active" id="aexp-dashboard" style="overflow-y:auto;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">dashboard</span> System Dashboard</h2>
          <div style="display:flex;gap:6px;align-items:center;">
            <span class="umat-pill pill-info" id="aexp-health-status">● Checking…</span>
            <button class="umat-content-hdr-btn" id="aexp-health-refresh" type="button" title="Refresh"><span class="material-symbols-outlined">refresh</span></button>
          </div>
        </div>
        <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;" id="aexp-health-grid">
          <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
              <div style="width:32px;height:32px;border-radius:var(--u-r8);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;"><span class="material-symbols-outlined" style="font-size:18px;">check_circle</span></div>
              <div><div style="font-size:11px;color:var(--u-ol);">AI Service</div><div style="font-size:22px;font-weight:800;" id="aexp-hlth-service">—</div></div>
            </div>
            <span style="font-size:10px;" id="aexp-hlth-latency"></span>
          </div>
          <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
              <div style="width:32px;height:32px;border-radius:var(--u-r8);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;"><span class="material-symbols-outlined" style="font-size:18px;">storage</span></div>
              <div><div style="font-size:11px;color:var(--u-ol);">ChromaDB</div><div style="font-size:22px;font-weight:800;" id="aexp-hlth-chroma">—</div></div>
            </div>
            <span style="font-size:10px;" id="aexp-hlth-docs"></span>
          </div>
          <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
              <div style="width:32px;height:32px;border-radius:var(--u-r8);background:rgba(165,48,77,.1);color:var(--u-ter);display:flex;align-items:center;justify-content:center;"><span class="material-symbols-outlined" style="font-size:18px;">memory</span></div>
              <div><div style="font-size:11px;color:var(--u-ol);">Memory</div><div style="font-size:22px;font-weight:800;" id="aexp-hlth-memory">—</div></div>
            </div>
            <span style="font-size:10px;" id="aexp-hlth-cron"></span>
          </div>
          <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
              <div style="width:32px;height:32px;border-radius:var(--u-r8);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;"><span class="material-symbols-outlined" style="font-size:18px;">info</span></div>
              <div><div style="font-size:11px;color:var(--u-ol);">Plugin Version</div><div style="font-size:22px;font-weight:800;" id="aexp-hlth-version">—</div></div>
            </div>
            <span style="font-size:10px;cursor:pointer;" id="aexp-hlth-refresh-label">Click to refresh</span>
          </div>
        </div>
      </div>

      <!-- FEATURES TAB -->
      <div class="umat-tab-pane" id="aexp-features" style="overflow-y:auto;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">tune</span> Feature Configuration</h2>
        </div>
        <div style="padding:20px;max-width:640px;">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);margin-bottom:8px;">Feature Toggles</div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;" id="aexp-toggles"></div>
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);margin-bottom:8px;">Configuration</div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;" id="aexp-config"></div>
          <button class="umat-btn-p" id="aexp-save-features" style="justify-content:center;" type="button"><span class="material-symbols-outlined">save</span>Save Changes</button>
          <div id="aexp-save-msg" style="margin-top:6px;font-size:11px;display:none;"></div>
        </div>
      </div>

      <!-- THEME TAB -->
      <div class="umat-tab-pane" id="aexp-theme" style="overflow-y:auto;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">palette</span> Theme Customisation</h2>
        </div>
        <div style="padding:20px;max-width:640px;">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);margin-bottom:8px;">Colour Scheme</div>
          <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;" id="aexp-theme-colors"></div>
          <div id="aexp-theme-preview" style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;margin-bottom:12px;">
            <div style="font-size:12px;font-weight:700;margin-bottom:6px;">Preview</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <span style="padding:4px 12px;border-radius:6px;background:var(--u-p);color:#fff;font-size:11px;">Primary</span>
              <span style="padding:4px 12px;border-radius:6px;background:var(--u-sec);color:#fff;font-size:11px;">Secondary</span>
              <span style="padding:4px 12px;border-radius:6px;background:var(--u-ter);color:#fff;font-size:11px;">Tertiary</span>
              <span style="padding:4px 12px;border-radius:6px;background:var(--u-warn);color:#fff;font-size:11px;">Warning</span>
              <span style="padding:4px 12px;border-radius:6px;background:var(--u-ok);color:#fff;font-size:11px;">Success</span>
            </div>
          </div>
          <button class="umat-btn-p" id="aexp-save-theme" style="justify-content:center;margin-bottom:6px;" type="button"><span class="material-symbols-outlined">palette</span>Save Theme</button>
          <button class="umat-btn-o" id="aexp-reset-theme" style="justify-content:center;" type="button"><span class="material-symbols-outlined">refresh</span>Reset to Defaults</button>
          <div id="aexp-theme-msg" style="margin-top:6px;font-size:11px;display:none;"></div>
        </div>
      </div>

      <!-- ACTIONS TAB -->
      <div class="umat-tab-pane" id="aexp-actions" style="overflow-y:auto;">
        <div class="umat-content-hdr">
          <h2><span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--u-p);">bolt</span> System Actions</h2>
        </div>
        <div style="padding:20px;max-width:640px;">
          <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);margin-bottom:8px;">System Actions</div>
          <div style="display:flex;flex-direction:column;gap:8px;">
            <button class="admin-action-btn" data-action="clear_ai_cache" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
              <span class="material-symbols-outlined" style="color:var(--u-ter);">cleaning_services</span>
              <div><strong>Clear AI Semantic Cache</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Wipe the analytics semantic cache in ChromaDB</span></div>
            </button>
            <button class="admin-action-btn" data-action="trigger_index" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
              <span class="material-symbols-outlined" style="color:var(--u-p);">sync</span>
              <div><strong>Force Re-index Materials</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Queue all course materials for RAG indexing</span></div>
            </button>
            <button class="admin-action-btn" data-action="purge_moodle_cache" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
              <span class="material-symbols-outlined" style="color:#d97706;">cached</span>
              <div><strong>Purge Moodle Caches</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Clear all Moodle cache stores (themes, JS, templates)</span></div>
            </button>
            <button class="admin-action-btn" data-action="purge_theme_cache" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
              <span class="material-symbols-outlined" style="color:#6366f1;">palette</span>
              <div><strong>Purge Theme Cache</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Reset theme CSS and template caches</span></div>
            </button>
            <button class="admin-action-btn" data-action="trigger_cron" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
              <span class="material-symbols-outlined" style="color:var(--u-sec);">schedule</span>
              <div><strong>Trigger Cron Now</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Run all scheduled tasks immediately</span></div>
            </button>
            <a href="{$wwwroot}/admin/settings.php?section=local_umat_ai" target="_blank" class="admin-action-btn" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-decoration:none;color:inherit;box-sizing:border-box;">
              <span class="material-symbols-outlined" style="color:var(--u-ol);">launch</span>
              <div><strong>Open Native Admin Settings</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Moodle admin page for full plugin configuration</span></div>
            </a>
          </div>
          <div id="aexp-action-msg" style="margin-top:12px;font-size:11px;display:none;"></div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ADMIN COMPACT PANEL -->
<div class="umat-cp-ov" id="admin-cp-ov" role="dialog" aria-modal="true">
  <div class="umat-cp umat-cp-admin" id="admin-cp">
    <div class="umat-cp-hdr">
      <div class="umat-cp-hdr-row">
        <div class="umat-cp-av" style="background:rgba(255,255,255,0.2);">
          <span class="material-symbols-outlined" style="color:#fff;">tune</span>
        </div>
        <div class="umat-cp-info">
          <h2 style="color:#fff;">System Control</h2>
          <div class="sub" style="color:rgba(255,255,255,0.7);" id="admin-health-status">● Checking…</div>
        </div>
        <button class="umat-cp-hbtn" id="admin-expand" type="button" aria-label="Expand to full workspace" style="color:#fff;">
          <span class="material-symbols-outlined">open_in_full</span>
        </button>
        <button class="umat-cp-hbtn" id="admin-cp-close" type="button" aria-label="Close" style="color:#fff;">
          <span class="material-symbols-outlined">close</span>
        </button>
      </div>
    </div>
    <div class="umat-cp-tabs umat-cp-tabs-legacy">
      <button class="umat-cp-tab active" data-acp-tab="acp-dashboard" type="button">Dashboard</button>
      <button class="umat-cp-tab" data-acp-tab="acp-features" type="button">Features</button>
      <button class="umat-cp-tab" data-acp-tab="acp-theme" type="button">Theme</button>
      <button class="umat-cp-tab" data-acp-tab="acp-actions" type="button">Actions</button>
    </div>

    <!-- DASHBOARD TAB -->
    <div class="umat-cp-pane active" id="acp-dashboard" style="overflow-y:auto;">
      <div style="padding:14px;display:grid;grid-template-columns:1fr 1fr;gap:9px;" id="acp-health-grid">
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">check_circle</span></div>
          <div style="font-size:10px;color:var(--u-ol);">AI Service</div>
          <div style="font-size:18px;font-weight:800;" id="acp-hlth-service">—</div>
          <span style="font-size:9px;" id="acp-hlth-latency"></span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">storage</span></div>
          <div style="font-size:10px;color:var(--u-ol);">ChromaDB</div>
          <div style="font-size:18px;font-weight:800;" id="acp-hlth-chroma">—</div>
          <span style="font-size:9px;" id="acp-hlth-docs"></span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(0,107,47,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">memory</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Memory</div>
          <div style="font-size:18px;font-weight:800;" id="acp-hlth-memory">—</div>
          <span style="font-size:9px;" id="acp-hlth-cron"></span>
        </div>
        <div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:12px;">
          <div style="width:26px;height:26px;border-radius:var(--u-r6);background:rgba(99,102,241,.1);color:var(--u-p);display:flex;align-items:center;justify-content:center;margin-bottom:6px;"><span class="material-symbols-outlined" style="font-size:15px;">info</span></div>
          <div style="font-size:10px;color:var(--u-ol);">Plugin Version</div>
          <div style="font-size:18px;font-weight:800;" id="acp-hlth-version">—</div>
          <span style="font-size:9px;cursor:pointer;" id="acp-hlth-refresh">Click to refresh</span>
        </div>
      </div>
    </div>

    <!-- FEATURES TAB -->
    <div class="umat-cp-pane" id="acp-features" style="overflow-y:auto;">
      <div style="padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">Feature Toggles</div>
      <div style="padding:0 14px 6px;display:flex;flex-direction:column;gap:8px;" id="acp-toggles"></div>
      <div style="padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">Configuration</div>
      <div style="padding:0 14px 10px;display:flex;flex-direction:column;gap:8px;" id="acp-config"></div>
      <div style="padding:0 14px 14px;">
        <button class="umat-btn-p" id="acp-save-features" style="width:100%;justify-content:center;" type="button"><span class="material-symbols-outlined">save</span>Save Changes</button>
        <div id="acp-save-msg" style="margin-top:6px;font-size:11px;display:none;"></div>
      </div>
    </div>

    <!-- THEME TAB -->
    <div class="umat-cp-pane" id="acp-theme" style="overflow-y:auto;">
      <div style="padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">Colour Scheme</div>
      <div style="padding:0 14px 6px;display:flex;flex-direction:column;gap:10px;" id="acp-theme-colors"></div>
      <div style="padding:10px 14px;">
        <div id="acp-theme-preview" style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:16px;margin-bottom:10px;">
          <div style="font-size:12px;font-weight:700;margin-bottom:6px;">Preview</div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <span style="padding:4px 12px;border-radius:6px;background:var(--u-p);color:#fff;font-size:11px;">Primary</span>
            <span style="padding:4px 12px;border-radius:6px;background:var(--u-sec);color:#fff;font-size:11px;">Secondary</span>
            <span style="padding:4px 12px;border-radius:6px;background:var(--u-ter);color:#fff;font-size:11px;">Tertiary</span>
            <span style="padding:4px 12px;border-radius:6px;background:var(--u-warn);color:#fff;font-size:11px;">Warning</span>
            <span style="padding:4px 12px;border-radius:6px;background:var(--u-ok);color:#fff;font-size:11px;">Success</span>
          </div>
        </div>
        <button class="umat-btn-p" id="acp-save-theme" style="width:100%;justify-content:center;margin-bottom:6px;" type="button"><span class="material-symbols-outlined">palette</span>Save Theme</button>
        <button class="umat-btn-o" id="acp-reset-theme" style="width:100%;justify-content:center;" type="button"><span class="material-symbols-outlined">refresh</span>Reset to Defaults</button>
        <div id="acp-theme-msg" style="margin-top:6px;font-size:11px;display:none;"></div>
      </div>
    </div>

    <!-- ACTIONS TAB -->
    <div class="umat-cp-pane" id="acp-actions" style="overflow-y:auto;">
      <div style="padding:12px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">System Actions</div>
      <div style="padding:0 14px 14px;display:flex;flex-direction:column;gap:8px;">
        <button class="admin-action-btn" data-action="clear_ai_cache" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
          <span class="material-symbols-outlined" style="color:var(--u-ter);">cleaning_services</span>
          <div><strong>Clear AI Semantic Cache</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Wipe the analytics semantic cache in ChromaDB</span></div>
        </button>
        <button class="admin-action-btn" data-action="trigger_index" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
          <span class="material-symbols-outlined" style="color:var(--u-p);">sync</span>
          <div><strong>Force Re-index Materials</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Queue all course materials for RAG indexing</span></div>
        </button>
        <button class="admin-action-btn" data-action="purge_moodle_cache" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
          <span class="material-symbols-outlined" style="color:#d97706;">cached</span>
          <div><strong>Purge Moodle Caches</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Clear all Moodle cache stores (themes, JS, templates)</span></div>
        </button>
        <button class="admin-action-btn" data-action="purge_theme_cache" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
          <span class="material-symbols-outlined" style="color:#6366f1;">palette</span>
          <div><strong>Purge Theme Cache</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Reset theme CSS and template caches</span></div>
        </button>
        <button class="admin-action-btn" data-action="trigger_cron" type="button" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-align:left;">
          <span class="material-symbols-outlined" style="color:var(--u-sec);">schedule</span>
          <div><strong>Trigger Cron Now</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Run all scheduled tasks immediately</span></div>
        </button>
        <a href="{$wwwroot}/admin/settings.php?section=local_umat_ai" target="_blank" class="admin-action-btn" style="display:flex;align-items:center;gap:8px;width:100%;padding:10px 12px;border:1px solid var(--u-olv);border-radius:var(--u-r10);background:var(--u-sflo);cursor:pointer;font-size:12px;text-decoration:none;color:inherit;box-sizing:border-box;">
          <span class="material-symbols-outlined" style="color:var(--u-ol);">launch</span>
          <div><strong>Open Native Admin Settings</strong><span style="display:block;font-size:10px;color:var(--u-ol);">Moodle admin page for full plugin configuration</span></div>
        </a>
      </div>
      <div id="acp-action-msg" style="padding:0 14px 14px;font-size:11px;display:none;"></div>
    </div>

  </div>
</div>

<script>(function(){
var wwwroot  = {$jsWwwroot};
var streamUrl = {$streamUrl};
var moodleSesskey = {$moodleSesskey};

/* Fallback ajax when AMD is unavailable */
if(typeof ajax!=='function'){
  window.ajax=function(m,a,d,f){
    var x=new XMLHttpRequest();
    x.open('POST','/lib/ajax/service.php?sesskey='+encodeURIComponent(moodleSesskey));
    x.setRequestHeader('Content-Type','application/json');
    x.onload=function(){if(x.status===200){try{var r=JSON.parse(x.responseText);if(r&&r[0]){if(r[0].error){console.error('[umat-ajax]',m,r[0].error);(f||function(){})(r[0].error);}else{console.log('[umat-ajax]',m,'OK');(d||function(){})(r[0].data);}}else{console.warn('[umat-ajax]',m,'unexpected:',r);(f||function(){})(new Error('Unexpected'));}}catch(e){console.error('[umat-ajax]',m,'parse:',e);(f||function(){})(e);}}else{console.error('[umat-ajax]',m,'HTTP',x.status);(f||function(){})(new Error('HTTP '+x.status));}};
    x.onerror=function(){console.error('[umat-ajax]',m,'network');(f||function(){})(new Error('Network'));};
    x.send(JSON.stringify([{index:0,methodname:m,args:a}]));
  };
}
if(typeof esc!=='function'){
  window.esc=function(s){if(s==null)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(String(s)));return d.innerHTML;};
}

/* ─── Compact panel controls ─── */
var fab=document.getElementById('admin-fab');
var cpOv=document.getElementById('admin-cp-ov');
var cpClose=document.getElementById('admin-cp-close');
var expOv=document.getElementById('admin-ov');
var expBtn=document.getElementById('admin-expand');
var expClose=document.getElementById('admin-ov-close');
var expCollapse=document.getElementById('admin-ov-close-exp');

function openPanel(){cpOv.classList.add('open');loadHealth();loadConfig();}
function closePanel(){cpOv.classList.remove('open');}
function openExpanded(){cpOv.classList.remove('open');expOv.classList.add('open');loadHealth();loadConfig();loadTheme();}
function closeExpanded(){expOv.classList.remove('open');}
if(fab)fab.addEventListener('click',openPanel);
if(cpClose)cpClose.addEventListener('click',closePanel);
if(cpOv)cpOv.addEventListener('click',function(e){if(e.target===cpOv)closePanel();});
if(expBtn)expBtn.addEventListener('click',openExpanded);
if(expOv)expOv.addEventListener('click',function(e){if(e.target===expOv)closeExpanded();});

/* Sidebar collapse/expand (like lecturer overlay) */
var sb=document.getElementById('admin-sb');
if(expClose&&sb)expClose.addEventListener('click',function(){sb.classList.add('collapsed');});
if(expCollapse&&sb)expCollapse.addEventListener('click',function(){sb.classList.remove('collapsed');});

/* CP tab switching */
function showAcpPane(tabId){
  document.querySelectorAll('[data-acp-tab]').forEach(function(b){b.classList.toggle('active',b.dataset.acpTab===tabId);});
  document.querySelectorAll('#admin-cp .umat-cp-pane').forEach(function(p){p.classList.toggle('active',p.id===tabId);});
}
document.querySelectorAll('[data-acp-tab]').forEach(function(b){
  b.addEventListener('click',function(){showAcpPane(b.dataset.acpTab);});
});

/* Expanded overlay sidebar tab switching */
function showAexpPane(tabId){
  document.querySelectorAll('#admin-sb [data-aexp-tab]').forEach(function(b){b.classList.toggle('active',b.dataset.aexpTab===tabId);});
  document.querySelectorAll('#admin-ov-content .umat-tab-pane').forEach(function(p){p.classList.toggle('active',p.id===tabId);});
}
document.querySelectorAll('#admin-sb [data-aexp-tab]').forEach(function(b){
  b.addEventListener('click',function(){showAexpPane(b.dataset.aexpTab);});
});

/* ─── Health Dashboard (populates both CP + expanded) ─── */
var _healthTimer=null;
function _setHealthBadge(el,state,text){
  if(!el)return;
  el.className='umat-hlth-badge '+state;
  if(state==='loading'){el.innerHTML='<span class="spinner"></span> Checking\u2026';}
  else{el.textContent=text;}
}
function _setHealthVal(id,val,sub){
  var el=document.getElementById(id);
  if(!el)return;
  el.textContent=val;
  el.className='umat-hlth-val';
}
function _setHealthSub(id,val,isLoading){
  var el=document.getElementById(id);
  if(!el)return;
  if(isLoading){el.innerHTML='<span class="spinner"></span> Loading\u2026';el.className='umat-hlth-sub loading';}
  else{el.innerHTML=val;el.className='umat-hlth-sub';}
}
function _fillHealth(d,errMsg){
  var now=new Date();
  var ts=now.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  var online=d&&d.online;
  var err=d&&d.error_detail?d.error_detail:(errMsg||'');
  /* CP header status */
  var statusEl=document.getElementById('admin-health-status');
  if(statusEl){
    statusEl.innerHTML=online
      ? '\u25cf AI Online ('+d.latency_ms+'ms)'
      : '\u25cf AI Offline'+(err?' - '+err:'');
    statusEl.style.color=online?'#4ade80':'#f87171';
  }
  /* Expanded header badge */
  var aexpStatus=document.getElementById('aexp-health-status');
  if(aexpStatus)_setHealthBadge(aexpStatus,online?'online':'offline',online?'\u25cf Online ('+d.latency_ms+'ms)':'\u25cf Offline'+(err?' - '+err:''));
  /* Populate both CP + expanded panels */
  ['acp','aexp'].forEach(function(pfx){
    _setHealthVal(pfx+'-hlth-service',online?'Online':'Offline');
    var latEl=document.getElementById(pfx+'-hlth-latency');
    if(latEl){
      if(online){latEl.textContent=d.latency_ms+'ms latency';latEl.className='umat-hlth-badge online';}
      else{latEl.textContent=err||'Unreachable';latEl.className='umat-hlth-badge offline';}
    }
    _setHealthVal(pfx+'-hlth-chroma',online?(d.chroma_collections||0):'-');
    _setHealthSub(pfx+'-hlth-docs',online?(d.chroma_documents||0)+' total documents':(err?'Error: '+err:'Unavailable'),false);
    _setHealthVal(pfx+'-hlth-memory',online?(d.python_memory_mb||0).toFixed(1)+' MB':'-');
    var cronLabel=d&&d.cron_fresh?'<span style="color:var(--u-p);">Running</span>':'<span style="color:var(--u-ter);">Stale</span>';
    var cronEl=document.getElementById(pfx+'-hlth-cron');
    if(cronEl){cronEl.innerHTML='Cron: '+cronLabel;cronEl.className='umat-hlth-sub';}
    _setHealthVal(pfx+'-hlth-version',d&&d.plugin_version?d.plugin_version:'-');
    /* Last-checked footer */
    var footEl=document.getElementById(pfx+'-hlth-footer');
    if(!footEl){
      footEl=document.createElement('div');
      footEl.id=pfx+'-hlth-footer';
      footEl.className='umat-hlth-footer';
      var card=document.getElementById(pfx+'-hlth-version');
      if(card){card.closest('div')&&card.closest('div').appendChild(footEl);}
    }
    if(footEl){
      footEl.innerHTML='Last checked: '+ts
        +' <button type="button" data-refresh="1">Refresh</button>';
      footEl.querySelector('[data-refresh]').addEventListener('click',function(){
        if(_healthTimer)clearTimeout(_healthTimer);
        loadHealth();
      });
    }
  });
}
function _healthLoading(){
  var statusEl=document.getElementById('admin-health-status');
  if(statusEl){statusEl.innerHTML='\u25cf Checking\u2026';statusEl.style.color='#9ca3af';}
  var aexpStatus=document.getElementById('aexp-health-status');
  if(aexpStatus)_setHealthBadge(aexpStatus,'loading');
  ['acp','aexp'].forEach(function(pfx){
    _setHealthVal(pfx+'-hlth-service','...');
    var latEl=document.getElementById(pfx+'-hlth-latency');
    if(latEl){latEl.textContent='';latEl.className='';}
    _setHealthVal(pfx+'-hlth-chroma','...');
    _setHealthSub(pfx+'-hlth-docs','',true);
    _setHealthVal(pfx+'-hlth-memory','...');
    _setHealthSub(pfx+'-hlth-cron','',true);
    _setHealthVal(pfx+'-hlth-version','...');
  });
}
function loadHealth(){
  console.log('[admin-health] loadHealth() called');
  if(_healthTimer)clearTimeout(_healthTimer);
  _healthLoading();
  var x=new XMLHttpRequest();
  x.open('POST','/lib/ajax/service.php?sesskey='+encodeURIComponent(moodleSesskey));
  x.setRequestHeader('Content-Type','application/json');
  x.onload=function(){
    console.log('[admin-health] AJAX response status:',x.status);
    if(x.status!==200){_fillHealth(null,'HTTP '+x.status);return;}
    try{
      var r=JSON.parse(x.responseText);
      console.log('[admin-health] Parsed response:',r);
      if(r&&r[0]&&!r[0].error){
        console.log('[admin-health] Health data:',r[0].data);
        _fillHealth(r[0].data,null);
      }
      else{
        var errMsg=r&&r[0]&&r[0].message?r[0].message:(r&&r[0]&&r[0].error?'Service error':'Unknown error');
        console.error('[admin-health] Error:',errMsg,r&&r[0]);
        _fillHealth(null,errMsg);
      }
    }catch(e){_fillHealth(null,'Invalid response');console.error('[admin-health] Parse error:',e,x.responseText.substring(0,500));}
  };
  x.onerror=function(){_fillHealth(null,'Network error');console.error('[admin-health] Network error');};
  x.onabort=function(){console.log('[admin-health] Request aborted');};
  x.ontimeout=function(){_fillHealth(null,'Timeout');console.error('[admin-health] Request timed out');};
  x.send(JSON.stringify([{index:0,methodname:'local_umat_ai_admin_system_health',args:{}}]));
  /* Auto-refresh every 30s */
  _healthTimer=setTimeout(loadHealth,30000);
}
document.getElementById('aexp-health-refresh').addEventListener('click',function(){
  if(_healthTimer)clearTimeout(_healthTimer);
  loadHealth();
});
var acpRefresh=document.getElementById('acp-hlth-refresh');
if(acpRefresh)acpRefresh.addEventListener('click',function(){
  if(_healthTimer)clearTimeout(_healthTimer);
  loadHealth();
});

/* ─── Features / Config (populates both CP + expanded) ─── */
var _toggleDefs=[
  {key:'enable_student_fab',label:'Enable Student FAB',type:'checkbox'},
  {key:'enable_lecturer_fab',label:'Enable Lecturer FAB',type:'checkbox'},
  {key:'enable_hub_fab',label:'Enable Hub FAB',type:'checkbox'},
  {key:'enable_admin_fab',label:'Enable Admin FAB',type:'checkbox'},
  {key:'enable_resource_bank',label:'Enable Resource Bank',type:'checkbox'},
];
var _cfgDefs=[
  {key:'platform_name',label:'Platform Name',type:'text'},
  {key:'ai_service_url',label:'AI Service URL',type:'text'},
  {key:'ai_service_token',label:'API Bearer Token',type:'password'},
  {key:'rate_limit',label:'Rate Limit (Q/min)',type:'number'},
];
function _renderConfig(config,cId,mId){
  var togHtml=_toggleDefs.map(function(t){
    var checked=config[t.key]==='1'?' checked':'';
    return '<label style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;cursor:pointer;">'+
      '<input type="checkbox" data-config="'+t.key+'" '+checked+' style="accent-color:var(--u-p);">'+t.label+'</label>';
  }).join('');
  document.getElementById(cId).innerHTML=togHtml;
  var cfgHtml=_cfgDefs.map(function(c){
    var val=config[c.key]||'';
    return '<div style="margin-bottom:6px;"><label style="font-size:11px;font-weight:600;display:block;margin-bottom:2px;color:var(--u-ol);">'+c.label+'</label>'+
      '<input type="'+c.type+'" data-config="'+c.key+'" value="'+esc(val)+'" style="width:100%;padding:7px 10px;border:1px solid var(--u-olv);border-radius:var(--u-r6);background:var(--u-bg);font-size:12px;"></div>';
  }).join('');
  document.getElementById(mId).innerHTML=cfgHtml;
}
/* Update platform display name across all visible overlays */
function _applyPlatformName(name){
  var escName=esc(name);
  /* Update sidebar brand texts */
  document.querySelectorAll('.umat-sb-brand strong').forEach(function(el){el.textContent=escName+' Moodle';});
  /* Update admin sidebar subtitle */
  document.querySelectorAll('.umat-sb-brand span').forEach(function(el){
    if(el.textContent.indexOf('AI Platform')>-1)el.textContent=escName+' AI Platform';
  });
  /* Update FAB tooltips */
  document.querySelectorAll('.umat-fab-tip').forEach(function(el){
    el.textContent=escName+' AI Assistant';
  });
}
function loadConfig(){
  ajax('local_umat_ai_admin_get_config',{},function(r){
    var config = typeof r.config_json === 'string' ? JSON.parse(r.config_json) : (r.config_json || {});
    _renderConfig(config,'acp-toggles','acp-config');
    _renderConfig(config,'aexp-toggles','aexp-config');
  },function(){});
}

/* Save features / config (shared, works for both views) */
function _saveFeatures(containerId,msgId){
  var settings={};
  document.querySelectorAll('#'+containerId+' input[data-config]').forEach(function(el){
    settings[el.dataset.config]=el.type==='checkbox'?(el.checked?'1':'0'):el.value;
  });
  var msg=document.getElementById(msgId);
  msg.style.display='block';msg.textContent='Saving…';msg.style.color='var(--u-ol)';
  ajax('local_umat_ai_admin_save_config',{settings_json:JSON.stringify(settings)},function(r){
    if(r.status==='success'){
      msg.textContent='Settings saved successfully.';
      msg.style.color='var(--u-p)';
      /* Live-update platform name if changed */
      if(settings.platform_name)_applyPlatformName(settings.platform_name);
    }else{
      msg.textContent='Failed to save settings.';
      msg.style.color='var(--u-ter)';
    }
  },function(){
    msg.textContent='Connection error.';
    msg.style.color='var(--u-ter)';
  });
}
document.getElementById('acp-save-features').addEventListener('click',function(){_saveFeatures('acp-features','acp-save-msg');});
document.getElementById('aexp-save-features').addEventListener('click',function(){_saveFeatures('aexp-features','aexp-save-msg');});

/* ─── Theme (populates both CP + expanded) ─── */
var themeKeys=['theme_primary','theme_secondary','theme_tertiary','theme_warning','theme_success'];
var themeLabels=['Primary Color','Secondary Color','Tertiary Color','Warning Color','Success Color'];
var themeDefaults=['#006b2f','#16a34a','#a5304d','#d97706','#16a34a'];
var themeCSSVars=['--u-p','--u-sec','--u-ter','--u-warn','--u-ok'];

function _renderTheme(config,containerId,valPrefix){
  var html=themeKeys.map(function(k,i){
    var val=config[k]||themeDefaults[i];
    return '<div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r8);">'+
      '<input type="color" data-theme="'+k+'" value="'+val+'" style="width:32px;height:32px;border:none;border-radius:6px;cursor:pointer;padding:0;">'+
      '<label style="font-size:12px;flex:1;">'+themeLabels[i]+'</label>'+
      '<span style="font-size:10px;color:var(--u-ol);font-family:monospace;" class="theme-val" data-theme-key="'+k+'">'+val+'</span></div>';
  }).join('');
  document.getElementById(containerId).innerHTML=html;
  /* Live preview on color change */
  document.querySelectorAll('#'+containerId+' input[type="color"]').forEach(function(inp){
    inp.addEventListener('input',function(){
      var key=this.dataset.theme;
      document.querySelector('#'+containerId+' .theme-val[data-theme-key="'+key+'"]').textContent=this.value;
    });
  });
}
function loadTheme(){
  ajax('local_umat_ai_admin_get_config',{},function(r){
    var config = typeof r.config_json === 'string' ? JSON.parse(r.config_json) : (r.config_json || {});
    _renderTheme(config,'acp-theme-colors','acp-theme-val-');
    _renderTheme(config,'aexp-theme-colors','aexp-theme-val-');
  },function(){});
}

function _saveTheme(containerId,msgId){
  var settings={};
  document.querySelectorAll('#'+containerId+' input[type="color"]').forEach(function(inp){
    settings[inp.dataset.theme]=inp.value;
  });
  var msg=document.getElementById(msgId);
  msg.style.display='block';msg.textContent='Saving theme…';msg.style.color='var(--u-ol)';
  ajax('local_umat_ai_admin_save_config',{settings_json:JSON.stringify(settings)},function(r){
    if(r.status==='success'){
      msg.textContent='Theme saved! Purge caches from Actions tab to see changes.';
      msg.style.color='var(--u-p)';
    }else{
      msg.textContent='Failed to save theme.';
      msg.style.color='var(--u-ter)';
    }
  },function(){
    msg.textContent='Connection error.';
    msg.style.color='var(--u-ter)';
  });
}
function _resetTheme(msgId){
  var settings={};
  themeKeys.forEach(function(k,i){settings[k]=themeDefaults[i];});
  var msg=document.getElementById(msgId);
  msg.style.display='block';msg.textContent='Resetting to defaults…';msg.style.color='var(--u-ol)';
  ajax('local_umat_ai_admin_save_config',{settings_json:JSON.stringify(settings)},function(r){
    if(r.status==='success'){
      msg.textContent='Defaults restored. Purge theme cache from Actions tab.';
      msg.style.color='var(--u-p)';
      loadTheme();
    }else{
      msg.textContent='Failed to reset.';
      msg.style.color='var(--u-ter)';
    }
  },function(){
    msg.textContent='Connection error.';
    msg.style.color='var(--u-ter)';
  });
}
document.getElementById('acp-save-theme').addEventListener('click',function(){_saveTheme('acp-theme-colors','acp-theme-msg');});
document.getElementById('acp-reset-theme').addEventListener('click',function(){_resetTheme('acp-theme-msg');});
document.getElementById('aexp-save-theme').addEventListener('click',function(){_saveTheme('aexp-theme-colors','aexp-theme-msg');});
document.getElementById('aexp-reset-theme').addEventListener('click',function(){_resetTheme('aexp-theme-msg');});

/* ─── Actions (binds both CP + expanded) ─── */
function _bindActions(containerId,msgId){
  document.querySelectorAll('#'+containerId+' .admin-action-btn[data-action]').forEach(function(btn){
    btn.addEventListener('click',function(){
      var action=this.dataset.action;
      var msg=document.getElementById(msgId);
      msg.style.display='block';msg.textContent='Executing…';msg.style.color='var(--u-ol)';
      ajax('local_umat_ai_admin_execute_action',{action:action},function(r){
        if(r.status==='success'){
          msg.innerHTML='<span style="color:var(--u-p);font-weight:600;">&#10003;</span> '+esc(r.message);
          msg.style.color='var(--u-p)';
        }else{
          msg.textContent='Action failed.';
          msg.style.color='var(--u-ter)';
        }
      },function(){
        msg.textContent='Connection error.';
        msg.style.color='var(--u-ter)';
      });
    });
  });
}
_bindActions('acp-actions','acp-action-msg');
_bindActions('aexp-actions','aexp-action-msg');

/* Init */
loadTheme();

/* Inline ESC handler (umatshared AMD not loaded here) */
function _matEscCb(layers){
  document.addEventListener('keydown',function(e){
    if(e.key!=='Escape')return;
    for(var i=0;i<layers.length;i++){
      var el=document.getElementById(layers[i].id);
      if(el&&layers[i].isOpen(el)){layers[i].close(el);e.preventDefault();return;}
    }
  });
}
/* ESC close */
_matEscCb([
  {id:'admin-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}},
  {id:'admin-cp-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

})();
</script>
HTML;
    }

    /**
     * Login-page issue report toggle — appears as a link below the login form.
     * Clicking it hides the login form and shows an inline report card.
     */
    public static function login_report_overlay(): string {
        return <<<HTML
<!-- LOGIN ISSUE REPORT TOGGLE -->
<div id="lr-wrapper">
  <p class="lr-toggle-link" id="lr-toggle-btn">
    Having trouble logging in?
    <a href="#" id="lr-show-form">Report an issue</a>
  </p>
</div>

<script>
/* Immediately inject toggle link after the login submit button */
(function(){
  var w=document.getElementById('lr-wrapper');
  if(!w){console.log('[umat] lr-wrapper not found');return;}
  var f=document.querySelector('.loginform')||document.querySelector('#page-login-index form')||document.querySelector('form[action*="login"]');
  if(f){
    var sb=f.querySelector('.fitem.fsubmit')||f.querySelector('.login-submit')||f.querySelector('input[type="submit"],button[type="submit"]');
    var target=sb&&sb.parentNode?sb.parentNode:f;
    target.appendChild(w);
    console.log('[umat] lr-wrapper injected into form');
    /* Ensure card is centered on mobile when shown */
    var card=document.getElementById('lr-report-card');
    if(card){card.style.margin='0 auto';}
  }else{
    w.style.display='';
    console.log('[umat] lr-wrapper shown at bottom, no form found');
  }
})();
</script>

<script>
/* Fallback: direct toggle init without AMD (works even if AMD fails) */
(function(){
  var toggle=document.getElementById('lr-show-form');
  var backBtn=document.getElementById('lr-report-back');
  var closeBtn=document.getElementById('lr-close-btn');
  var wrapper=document.getElementById('lr-wrapper');
  var reportCard=document.getElementById('lr-report-card');
  console.log('[umat-login-report-fallback] init:', {toggle:!!toggle, backBtn:!!backBtn, closeBtn:!!closeBtn, wrapper:!!wrapper, reportCard:!!reportCard});
  if(!toggle || !reportCard) return;

  var loginForm=document.querySelector('.loginform')||document.querySelector('#page-login-index form')||document.querySelector('form[action*="login"]');
  console.log('[umat-login-report-fallback] loginForm:', !!loginForm);

  toggle.addEventListener('click', function(e){
    e.preventDefault();
    console.log('[umat-login-report-fallback] toggle clicked');
    if(loginForm) loginForm.style.display='none';
    if(wrapper) wrapper.style.display='none';
    reportCard.style.display='';
  });
  function showLoginForm(){
    reportCard.style.display='none';
    if(loginForm) loginForm.style.display='';
    if(wrapper) wrapper.style.display='';
  }
  if(backBtn) backBtn.addEventListener('click', function(e){e.preventDefault();showLoginForm();});
  if(closeBtn) closeBtn.addEventListener('click', function(e){e.preventDefault();showLoginForm();});
})();
</script>

<!-- REPORT FORM (hidden initially, replaces login form when toggled) -->
<div class="umat-login-report-card" id="lr-report-card" style="display:none;">
  <button class="umat-login-report-close" id="lr-report-back" type="button" aria-label="Back to login">&larr; Back to login</button>
  <div class="umat-login-report-hdr">
    <span class="material-symbols-outlined">feedback</span>
    <h2>Report Login Issue</h2>
    <p>Having trouble logging in? Let your lecturer know.</p>
  </div>
  <div class="umat-login-report-body" id="login-report-body">

    <!-- Step 1: Identify -->
    <div class="umat-login-report-step" id="lr-step-identify">
      <label for="lr-username">Your Student ID or Username</label>
      <input type="text" id="lr-username" class="umat-login-report-input" placeholder="e.g. 2023456 or jdoe" autocomplete="off" />
      <div class="umat-login-report-hint">Enter your student ID, username, or email address.</div>
      <button class="umat-login-report-btn" id="lr-lookup-btn" type="button">
        <span class="material-symbols-outlined">search</span> Find My Courses
      </button>
      <div class="umat-login-report-msg" id="lr-msg"></div>
    </div>

    <!-- Step 2: Course + description (hidden until step 1 succeeds) -->
    <div class="umat-login-report-step" id="lr-step-report" style="display:none;">
      <label for="lr-course">Course</label>
      <select id="lr-course" class="umat-login-report-input"></select>
      <label for="lr-name" style="margin-top:12px;">Your Name <span style="font-weight:400;color:#8aa08e;">(optional)</span></label>
      <input type="text" id="lr-name" class="umat-login-report-input" placeholder="e.g. John Doe" autocomplete="off" />
      <label for="lr-desc" style="margin-top:12px;">Describe the Issue</label>
      <textarea id="lr-desc" class="umat-login-report-input umat-login-report-ta" placeholder="What problem are you having? e.g. I can't log in with my student ID, it says invalid credentials..." rows="4"></textarea>
      <button class="umat-login-report-btn umat-login-report-btn-primary" id="lr-submit-btn" type="button">
        <span class="material-symbols-outlined">send</span> Submit Report
      </button>
      <div class="umat-login-report-msg" id="lr-submit-msg"></div>
    </div>

    <!-- Step 3: Success (hidden until submitted) -->
    <div class="umat-login-report-step" id="lr-step-done" style="display:none;">
      <div class="umat-login-report-success">
        <span class="material-symbols-outlined" style="font-size:48px;color:#006b2f;">check_circle</span>
        <h3>Report Submitted!</h3>
        <p>Your lecturer will review your issue and get back to you.</p>
        <button class="umat-login-report-btn" id="lr-close-btn" type="button">Close &amp; return to login</button>
      </div>
    </div>

  </div>
  <div class="umat-login-report-loader" id="lr-loader" style="display:none;">
    <div class="umat-spinner"></div>
  </div>
</div>
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
            $badgeId = !empty($tab['badge']) ? htmlspecialchars($tab['badge'], ENT_QUOTES) : '';
            $badgeHtml = $badgeId ? ' <span class="umat-gb" id="gtb-' . $badgeId . '" style="display:none;position:absolute;top:2px;right:2px;background:var(--u-ter);color:#fff;font-size:8px;font-weight:700;padding:1px 4px;border-radius:999px;line-height:12px;min-width:14px;text-align:center;"></span>' : '';
            $realTabs .= '<button class="umat-glass-tab' . ($active ? ' active' : '') . '" data-'
                . $attr . '="' . $val . '" type="button">'
                . '<span class="material-symbols-outlined">' . $icon . '</span>'
                . '<span>' . $label . '</span>'
                . $badgeHtml . '</button>';
        }
        return '<div class="umat-glass-tabs" id="' . htmlspecialchars($containerId, ENT_QUOTES) . '">'
            . '<div class="umat-glass-tabs-row">' . $realTabs . '</div>'
            . '</div>';
    }

    public static function glassmorph_init_js(): string {
        return '<script>'
            . 'M.util.js_pending("local_umat_ai/glassmorph_nav");'
            . 'M.util.js_pending("local_umat_ai/mobile_navbar");'
            . '!function c(){typeof require===\'function\'?require(["local_umat_ai/glassmorph_nav","local_umat_ai/mobile_navbar"],function(gm,mn){try{gm&&gm.init&&gm.init();}catch(e){}try{mn&&mn.init&&mn.init();}catch(e){}M.util.js_complete("local_umat_ai/glassmorph_nav");M.util.js_complete("local_umat_ai/mobile_navbar");},function(){M.util.js_complete("local_umat_ai/glassmorph_nav");M.util.js_complete("local_umat_ai/mobile_navbar");}):setTimeout(c,20);}();'
            . '</script>';
    }
}



