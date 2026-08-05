define(['local_umat_ai/umatshared','local_umat_ai/material_viewer','local_umat_ai/touch_utils'],function(S,M,T){
return{init:function(data){for(var k in S)window[k]=S[k];window.umatMaterialViewer=M;
window.renderVideoTiles=S.renderVideoTiles;window.renderCourses=S.renderCourses;
window.renderLibrary=S.renderLibrary;window.renderLibTiles=S.renderLibTiles;
window.esc=S.esc;
(function(){
'use strict';
var CID = data.courseId;
var CN    = data.courseName;
var UID   = data.userId;
var UD = typeof data.userData === 'string' ? JSON.parse(data.userData) : data.userData || {};
var PENDING = data.pending || 0;
var streamUrl = data.streamUrl;
var moodleSesskey = data.moodleSesskey;
var anLoaded = {};
var lecLoaded= {};
var struggleCache = {};
var lecSessionKey = ''; /* current session key for chat; empty = fresh session */

/* ---- Lecturer Issue Conversation State ---- */
var lecturerIssueConversations=[];
var lecturerIssueConversationId=0;
var lecturerIssueLoading=false;
var lecturerIssueFailedSend=null;
var lecturerIssueReadObserver=null;
var lecturerIssueSearchTimer=null;
var lecturerIssuesReady=false;
var lecturerIssueCategoryLabels={course_material:'Course material',assignment:'Assignment',quiz_examination:'Quiz or examination',grade_feedback:'Grade or feedback',live_class_recording:'Live class or recording',technical_problem:'Technical problem',access_permission:'Access or permission',other:'Other'};

/* ─── Resume session in CP Ask AI tab (called from CP sessions) ─── */
function resumeLecSession(sk, cid, cname) {
  lecSessionKey = sk;
  if (cid) CID = cid;
  openPanel();
  showLcpPane('lcp-ai');
  var msgs = document.getElementById('lcp-msgs');
  if (!msgs) return;
  msgs.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading session…</p></div>';
  ajax('local_umat_ai_get_chat_history', {courseid: CID, session_key: sk, limit: 50}, function(r) {
    msgs.innerHTML = '';
    var w = document.createElement('div');
    w.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>'
      + '<div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI ASSISTANT</div>'
      + '<div class="umat-bubble-ai"><p>Session resumed for <strong>' + esc(cname || 'your course') + '</strong>. Continue your conversation below.</p></div></div></div>';
    msgs.appendChild(w);
    (r.messages || []).forEach(function(m) {
      _umatAppendUser('lcp-msgs', m.question, []);
      if (m.answer) _umatAppendAi('lcp-msgs', m.answer, m.sources || [], m.citations || []);
    });
    msgs.scrollTop = msgs.scrollHeight;
  }, function() {
    msgs.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load session history.</p></div>';
  });
}
window.resumeLecSession = resumeLecSession;

/* ─── Resume session inline in full overlay sessions tab ─── */
function resumeLecSessionInline(sk, cid, cname) {
  var sessPane = document.getElementById('lec-sessions');
  if (!sessPane) return;
  /* Hide session list UI, show inline chat */
  var listUI = sessPane.querySelectorAll('.umat-content-hdr, .umat-sess-toggle, .umat-cs-overlay, .umat-sessions-list');
  listUI.forEach(function(el){ el.style.display = 'none'; });

  /* Build inline chat panel */
  var chatId = 'lec-sess-chat';
  var chatEl = document.getElementById(chatId);
  if (!chatEl) {
    chatEl = document.createElement('div');
    chatEl.id = chatId;
    chatEl.style.cssText = 'display:flex;flex-direction:column;position:absolute;inset:0;background:var(--u-bg);z-index:5;';
    sessPane.appendChild(chatEl);
  }
  chatEl.innerHTML = ''
    /* Header with back button + session title */
    + '<div style="display:flex;align-items:center;gap:8px;padding:10px 16px;border-bottom:1px solid var(--u-olv);flex-shrink:0;">'
    +   '<button id="lec-sess-chat-back" type="button" style="background:none;border:none;cursor:pointer;padding:4px;border-radius:var(--u-r6);display:flex;align-items:center;color:var(--u-p);" title="Back to sessions">'
    +     '<span class="material-symbols-outlined">arrow_back</span></button>'
    +   '<div style="flex:1;min-width:0;">'
    +     '<div style="font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(cname || 'AI Session') + '</div>'
    +     '<div style="font-size:10px;color:var(--u-ol);">Resumed session</div>'
    +   '</div>'
    +   '<button id="lec-sess-chat-close" type="button" style="background:none;border:none;cursor:pointer;padding:4px;border-radius:var(--u-r6);color:var(--u-ol);" title="End session">'
    +     '<span class="material-symbols-outlined">close</span></button>'
    + '</div>'
    /* Messages area */
    + '<div class="umat-msgs" id="lec-sess-chat-msgs" style="flex:1;overflow-y:auto;padding:16px;"></div>'
    /* Chatbar */
    + '<div class="umat-chat-overlay" style="position:relative;">'
    +   '<div class="umat-chatbar">'
    +     '<textarea id="lec-sess-chat-input" class="umat-chatbar-input" placeholder="Continue the conversation…" rows="1" maxlength="700"></textarea>'
    +     '<button class="umat-chatbar-send" id="lec-sess-chat-send" type="button"><span class="material-symbols-outlined">arrow_upward</span></button>'
    +   '</div>'
    + '</div>';
  chatEl.style.display = 'flex';

  /* Wire back button */
  var backBtn = document.getElementById('lec-sess-chat-back');
  if (backBtn) backBtn.addEventListener('click', function() {
    chatEl.style.display = 'none';
    listUI.forEach(function(el){ el.style.display = ''; });
  });
  var closeBtn = document.getElementById('lec-sess-chat-close');
  if (closeBtn) closeBtn.addEventListener('click', function() {
    chatEl.style.display = 'none';
    listUI.forEach(function(el){ el.style.display = ''; });
  });

  /* Load history */
  var msgsEl = document.getElementById('lec-sess-chat-msgs');
  msgsEl.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading session…</p></div>';

  ajax('local_umat_ai_get_chat_history', {courseid: cid || CID, session_key: sk, limit: 50}, function(r) {
    msgsEl.innerHTML = '';
    /* Welcome */
    var w = document.createElement('div');
    w.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div>'
      + '<div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI ASSISTANT</div>'
      + '<div class="umat-bubble-ai"><p>Session resumed for <strong>' + esc(cname || 'your course') + '</strong>. Continue your conversation below.</p></div></div></div>';
    msgsEl.appendChild(w);
    (r.messages || []).forEach(function(m) {
      _umatAppendUser('lec-sess-chat-msgs', m.question, []);
      if (m.answer) _umatAppendAi('lec-sess-chat-msgs', m.answer, m.sources || [], m.citations || []);
    });
    msgsEl.scrollTop = msgsEl.scrollHeight;
  }, function() {
    msgsEl.innerHTML = '<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load session history.</p></div>';
  });

  /* Wire send */
  var chatInp = document.getElementById('lec-sess-chat-input');
  var chatSend = document.getElementById('lec-sess-chat-send');
  function sendInlineSessQ() {
    var q = (chatInp.value || '').trim();
    if (!q) return;
    _umatAppendUser('lec-sess-chat-msgs', q, []);
    chatInp.value = '';
    var tid = 'lt_sess_' + Date.now();
    _umatShowTyping('lec-sess-chat-msgs', tid);
    _umatStreamChat({
      url: streamUrl,
      sesskey: moodleSesskey,
      courseid: cid || CID,
      question: q,
      session_key: sk,
      material_ids: [],
      msgsId: 'lec-sess-chat-msgs',
      sendBtnId: 'lec-sess-chat-send',
      sendInputId: 'lec-sess-chat-input',
      typingId: tid,
      onMeta: function(){},
      onDone: function(){ _umatHideTyping(tid); },
      onError: function(err){
        _umatHideTyping(tid);
        _umatAppendAi('lec-sess-chat-msgs', err.message || 'An error occurred.', []);
      }
    });
  }
  if (chatSend) chatSend.addEventListener('click', sendInlineSessQ);
  if (chatInp) chatInp.addEventListener('keypress', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (chatSend) chatSend.click(); }
  });
}
window.resumeLecSessionInline = resumeLecSessionInline;


/* ----- LECTURER COURSE TILES ----- */
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
          '<p class="yt-channel">'+esc(c.shortname||'')+(enrolled?' \u00b7 '+enrolled+' students':'')+'</p>'+
          '<p class="yt-stats">'+sessions+' sessions'+(pending>0?' \u00b7 '+pending+' outputs pending':'')+'</p>'+
        '</div>'+
      '</div>'+
      '<div class="yt-actions">'+
        '<button class="yt-btn yt-act-btn" data-act="analytics"><span class="material-symbols-outlined">bar_chart</span>Analytics</button>'+
        '<button class="yt-btn yt-act-btn" data-act="library"><span class="material-symbols-outlined">local_library</span>Resource Materials</button>'+
        (pending>0?'<button class="yt-btn yt-act-btn" data-act="review" style="border-color:var(--u-ter);color:var(--u-ter);"><span class="material-symbols-outlined">fact_check</span>Review</button>':'')+
      '</div>'+
    '</div>';
  }).join('');

  /* Tile body click -> analytics */
  g.querySelectorAll('.yt-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('[data-act]'))return;
      CID=parseInt(tile.dataset.cid)||CID;CN=tile.dataset.cname||CN;
      var lbl=document.getElementById('lec-an-course-label');if(lbl)lbl.textContent=CN;
      var ctx=document.getElementById('lec-ctx-label');if(ctx)ctx.textContent=CN;
      anLoaded[CID]=false;
      switchPane('lec-analytics');loadAnalytics(CID);
    });
    T.makeTouchable(tile);
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
        if (T) T.makeTouchable(btn);
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
var expand=document.getElementById('lec-expand');
var panelDataLoaded=false;
function updateBodyLock(){document.body.classList.toggle('umat-body-lock',!(!document.querySelector('.umat-ov.open,.umat-cp-ov.open')));}

function openPanel(){window.openPanel&&window.openPanel();}
function closePanel(){window.closePanel&&window.closePanel();}
function openDash(){window.openDash&&window.openDash();}
function closeDash(){window.closeDash&&window.closeDash();}

/* NOTE: FAB, close, expand button handlers are owned by the IIFE — do NOT bind duplicates here */

/* Compact panel tabs — delegate to IIFE's showLcpPane (has loadLcpPane + lcpPaneLoaded) */
function showLcpPane(t){window.showLcpPane&&window.showLcpPane(t);}
/* Only bind open-full / feature-tab handlers here; data-lcp-tab and data-lcp-pane clicks are owned by the IIFE */
document.querySelectorAll('#lec-cp [data-lcp-open]').forEach(function(b){
  b.addEventListener('click',function(){renderLcpFeature(b.dataset.lcpOpen);});
});
function setLcpFeatureActive(name){
  document.querySelectorAll('#lec-cp [data-lcp-pane]').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('#lec-cp [data-lcp-open]').forEach(function(b){b.classList.toggle('active',b.dataset.lcpOpen===name);});
}
function renderLcpFeature(name){
  var meta={
    'lec-analytics':['bar_chart','Analytics','Course performance'],'lec-struggle':['psychology','Struggle','Learning gaps'],'lec-courses':['menu_book','Courses','Your teaching courses'],'lec-library':['local_library','Resource Materials','Course materials'],'lec-sessions':['history','Sessions','AI interaction history'],'lec-review':['fact_check','Review','Pending AI outputs'],    'lec-issues':['flag','Issues','Student complaints'],
    'lec-quiz-review':['rate_review','Quiz Review','Student quiz responses']
  }[name]||['widgets','Feature','Quick view'];
  showLcpPane('lcp-feature');setLcpFeatureActive(name);
  document.getElementById('lcp-feature-icon').textContent=meta[0];document.getElementById('lcp-feature-title').textContent=meta[1];document.getElementById('lcp-feature-sub').textContent=meta[2];
  var body=document.getElementById('lcp-feature-body');body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading '+meta[1].toLowerCase()+'...</p></div>';
  if(name==='lec-courses')return renderLcpCourses(body);
  if(name==='lec-review')return renderLcpReview(body);
  if(name==='lec-analytics'||name==='lec-struggle')return renderLcpAnalytics(body,name);
  if(name==='lec-library')return renderLcpLibrary(body);
  if(name==='lec-sessions')return renderLcpSessions(body);
  if(name==='lec-issues'){closePanel();lecOv.classList.add('open');switchPane('lec-issues');updateBodyLock();return;}
  if(name==='lec-quiz-review')return renderLcpQuizReview(body);
}
function renderLcpCourses(body){var courses=(UD&&UD.courses)||[];if(!courses.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses found.</p></div>';return;}body.innerHTML=courses.map(function(c){return '<button class="umat-cp-list-card as-btn" data-cid="'+c.id+'" data-name="'+esc(c.fullname||'')+'" type="button"><strong>'+esc(c.shortname||c.fullname)+'</strong><p>'+esc(c.fullname||'')+'</p></button>';}).join('');body.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){CID=parseInt(b.dataset.cid)||CID;CN=b.dataset.name||CN;renderLcpFeature('lec-analytics');});});}
function renderLcpAnalytics(body,name){if(!CID){var courses=(UD&&UD.courses)||[];if(!courses.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>No courses available.</p></div>';return;}body.innerHTML='<div class="umat-cp-help" style="padding:10px 14px 2px;font-size:10px;color:var(--u-ol);font-weight:600;">Select a course or view composite:</div><div id="lcp-cs-bar" style="padding:4px 14px 6px;display:flex;flex-wrap:wrap;gap:4px;">'+courses.slice(0,16).map(function(c){return '<button class="umat-chip" data-cid="'+c.id+'" type="button">'+esc(c.shortname||c.fullname)+'</button>';}).join('')+'</div><div id="lcp-ov-body" style="padding:0 14px 14px;"><div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading overview\u2026</p></div></div>';body.querySelectorAll('#lcp-cs-bar .umat-chip').forEach(function(b){b.addEventListener('click',function(){CID=parseInt(this.dataset.cid)||CID;renderLcpFeature(name);});});var ovBody=document.getElementById('lcp-ov-body'),agg=name==='lec-struggle'?{total_questions:0,total_students:0,total_issues:0,open_issues:0,per_course:[],all_topics:[],all_students:[],topic_map:{}}:{active_students:0,enrolled_students:0,total_interactions:0,per_course:[],all_questions:[],high_total:0,risk_total:0,track_total:0},done=0;courses.forEach(function(c){ajax(name==='lec-struggle'?'local_umat_ai_get_struggle_insights':'local_umat_ai_get_analytics',{courseid:c.id,days:name==='lec-struggle'?60:30},function(d){if(name==='lec-struggle'){var s=d.summary||{};agg.total_questions+=s.total_questions||0;agg.total_students+=s.total_students||0;agg.total_issues+=s.total_issues||0;agg.open_issues+=s.open_issues||0;var sc='N/A';if(d.topic_matrix&&d.topic_matrix.length){var scs=d.topic_matrix.map(function(t){return t.struggle_score||0;});sc=Math.round(scs.reduce(function(a,b){return a+b;})/scs.length);d.topic_matrix.forEach(function(t){var k=t.topic;if(agg.topic_map[k]){agg.topic_map[k].question_count+=t.question_count;agg.topic_map[k].student_count+=t.student_count;agg.topic_map[k].struggle_score=(agg.topic_map[k].struggle_score+t.struggle_score)/2;}else{agg.topic_map[k]=JSON.parse(JSON.stringify(t));}});}(d.at_risk_students||[]).forEach(function(s){s.course_name=c.shortname;agg.all_students.push(s);});agg.per_course.push({id:c.id,name:c.shortname,questions:s.total_questions||0,students:s.total_students||0,struggle:sc});}else{agg.active_students+=d.active_students;agg.enrolled_students+=d.enrolled_students;agg.total_interactions+=d.total_interactions;agg.high_total+=d.high_performers||0;agg.risk_total+=Math.max(0,d.enrolled_students-d.active_students);agg.track_total+=Math.max(0,d.active_students-(d.high_performers||0));(d.top_questions||[]).forEach(function(q){agg.all_questions.push(q);});agg.per_course.push({id:c.id,name:c.shortname,active:d.active_students,enrolled:d.enrolled_students,interactions:d.total_interactions,struggle:d.struggle_index});}done++;if(done===courses.length){if(name==='lec-struggle'){var tm=Object.keys(agg.topic_map);var hi=0,md=0,lo=0;tm.forEach(function(k){var sc=agg.topic_map[k].struggle_score||0;if(sc>=60)hi++;else if(sc>=30)md++;else lo++;});ovBody.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">quiz</span><strong>'+agg.total_questions+'</strong><small>total questions</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">people</span><strong>'+agg.total_students+'</strong><small>students</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">flag</span><strong>'+agg.total_issues+'</strong><small>issues ('+agg.open_issues+' open)</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">donut_small</span><strong>'+tm.length+'</strong><small>topics (H:'+hi+' M:'+md+' L:'+lo+')</small></div></div>'+((agg.all_students||[]).slice(0,5).map(function(s){return '<div class="umat-cp-list-card"><strong>'+esc(s.fullname||'Student')+'</strong><p>'+esc(s.course_name||'')+'\u00a0\u00b7\u00a0'+esc(s.topic||'')+'</p></div>';}).join('')||'<div class="umat-empty"><span class="material-symbols-outlined">check_circle</span><p>No at-risk students.</p></div>');}else{ovBody.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">group</span><strong>'+agg.active_students+'/'+agg.enrolled_students+'</strong><small>active students</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">forum</span><strong>'+agg.total_interactions+'</strong><small>total interactions</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">trending_up</span><strong>'+agg.high_total+'</strong><small>high performers</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">warning</span><strong>'+agg.risk_total+'</strong><small>at risk</small></div></div>'+((agg.all_questions||[]).sort(function(a,b){return b.ask_count-a.ask_count;}).slice(0,5).map(function(q){return '<div class="umat-cp-list-card"><strong>'+esc(q.text)+'</strong><p>'+q.ask_count+' students asked</p></div>';}).join('')||'<div class="umat-empty"><span class="material-symbols-outlined">forum</span><p>No questions yet.</p></div>');}}},function(){done++;if(done===courses.length)ovBody.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load some courses.</p></div>';});});return;}ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(d){if(name==='lec-struggle'){body.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">psychology</span><strong>'+esc(d.struggle_index||'N/A')+'</strong><small>struggle index</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">forum</span><strong>'+((d.top_questions||[]).length)+'</strong><small>top questions</small></div></div>'+((d.top_questions||[]).slice(0,6).map(function(q){return '<div class="umat-cp-list-card"><strong>'+esc(q.text)+'</strong><p>'+q.ask_count+' students asked</p></div>';}).join('')||'<div class="umat-empty"><span class="material-symbols-outlined">check_circle</span><p>No struggle questions yet.</p></div>');return;}body.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">group</span><strong>'+d.active_students+'/'+d.enrolled_students+'</strong><small>active students</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">forum</span><strong>'+d.total_interactions+'</strong><small>AI interactions</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">psychology</span><strong>'+esc(d.struggle_index||'N/A')+'</strong><small>struggle index</small></div><div class="umat-cp-mini-card"><span class="material-symbols-outlined">timer</span><strong>'+esc(d.avg_questions_per_session||'0')+'</strong><small>avg Q/session</small></div></div>';},function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load analytics.</p></div>';});}
function renderLcpLibrary(body){var courses=(UD&&UD.courses)||[];body.innerHTML=(courses.length?'<p class="umat-cp-help">Choose a course to view materials in this panel.</p>'+courses.slice(0,10).map(function(c){return '<button class="umat-cp-list-card as-btn" data-cid="'+c.id+'" type="button"><strong>'+esc(c.shortname||c.fullname)+'</strong><p>'+esc(c.fullname||'')+'</p></button>';}).join(''):'<div class="umat-empty"><span class="material-symbols-outlined">local_library</span><p>No courses available.</p></div>');body.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){var cid=parseInt(b.dataset.cid);body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials...</p></div>';ajax('local_umat_ai_get_course_materials',{courseid:cid},function(r){var mats=r.materials||[];body.innerHTML=mats.length?mats.slice(0,12).map(function(m){return '<div class="umat-cp-list-card"><strong>'+esc(m.filename||m.name||'Material')+'</strong><p>'+esc(m.mimetype||m.type||'Course material')+'</p></div>';}).join(''):'<div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>No materials for this course.</p></div>';},function(){console.error('[umat] lecturer renderLcpLibrary failed');body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load materials.</p></div>';});});});}
function renderLcpSessions(body){
  body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading sessions...</p></div>';
  ajax('local_umat_ai_get_lecturer_sessions',{courseid:CID||0,limit:5},function(r){
    var sessions=r.sessions||[];
    if(!sessions.length){
      body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No AI sessions yet. Ask the assistant a question!</p></div>';
      return;
    }
    body.innerHTML='<div style="padding:10px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--u-ol);">Recent Sessions</div>'
      + sessions.map(function(s){
        return '<div class="umat-cp-list-card" data-sk="'+esc(s.session_key)+'" data-cid="'+s.courseid+'" style="cursor:pointer;display:flex;align-items:center;gap:8px;">'
          + '<div style="flex:1;min-width:0;"><strong>'+esc(s.course_name||'AI Session')+'</strong>'
          + '<p style="margin:2px 0 0;font-size:11px;color:var(--u-onsv);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(s.preview)+'</p>'
          + '<small style="font-size:10px;color:var(--u-ol);">'+esc(s.time_label)+' &middot; '+s.msg_count+' messages</small></div>'
          + '<button class="umat-cp-del-session" type="button" title="Delete session" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--u-ter);flex-shrink:0;">'
          + '<span class="material-symbols-outlined" style="font-size:18px;">delete</span></button></div>';
      }).join('');

    /* Wire delete buttons */
    body.querySelectorAll('.umat-cp-del-session').forEach(function(btn){
      btn.addEventListener('click',function(e){
        e.stopPropagation();
        var card=e.target.closest('.umat-cp-list-card');
        if(!card)return;
        if(!confirm('Delete this conversation? This cannot be undone.'))return;
        btn.disabled=true;
        btn.innerHTML='<span class="material-symbols-outlined" style="font-size:18px;">hourglass_empty</span>';
        ajax('local_umat_ai_delete_lecturer_session',{session_key:card.dataset.sk},function(){
          card.remove();
          if(!body.querySelector('.umat-cp-list-card')){
            body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No AI sessions yet.</p></div>';
          }
        },function(){
          btn.disabled=false;
          btn.innerHTML='<span class="material-symbols-outlined" style="font-size:18px;">delete</span>';
        });
      });
    });

    /* Wire card click → resume session in chat */
    body.querySelectorAll('.umat-cp-list-card').forEach(function(card){
      card.addEventListener('click',function(e){
        if(e.target.closest('.umat-cp-del-session'))return;
        var sk=card.dataset.sk,cid=parseInt(card.dataset.cid)||0;
        var name=card.querySelector('strong')?card.querySelector('strong').textContent:'';
        resumeLecSession(sk,cid,name);
      });
    });
  });
}
function renderLcpReview(body){body.innerHTML='<div class="umat-cp-mini-grid"><div class="umat-cp-mini-card"><span class="material-symbols-outlined">pending_actions</span><strong>'+PENDING+'</strong><small>pending outputs</small></div></div><div class="umat-cp-list-card"><strong>Review workflow</strong><p>Use the full review table for approvals. You can ask AI for a summary here first.</p><button class="umat-btn-p" type="button" id="lcp-review-ask"><span class="material-symbols-outlined">smart_toy</span>Summarise pending work</button></div>';var ask=document.getElementById('lcp-review-ask');if(ask)ask.addEventListener('click',function(){showLcpPane('lcp-ai');document.getElementById('lcp-input').value='Summarise pending AI outputs for this course.';document.getElementById('lcp-send').click();});}
function renderLcpIssues(body){_umatLecMarkViewed();if(!CID){var courses=(UD&&UD.courses)||[];if(!courses.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>No courses available.</p></div>';return;}body.innerHTML='<div class="umat-cp-help" style="padding:10px 14px 2px;font-size:10px;color:var(--u-ol);font-weight:600;">Select a course to view its issues:</div><div id="lcp-iss-cs-bar" style="padding:4px 14px 6px;display:flex;flex-wrap:wrap;gap:4px;">'+courses.slice(0,16).map(function(c){return '<button class="umat-chip" data-cid="'+c.id+'" type="button">'+esc(c.shortname||c.fullname)+'</button>';}).join('')+'</div>';body.querySelectorAll('#lcp-iss-cs-bar .umat-chip').forEach(function(b){b.addEventListener('click',function(){CID=parseInt(this.dataset.cid)||CID;renderLcpFeature('lec-issues');});});return;}body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading issues\u2026</p></div>';ajax('local_umat_ai_list_issue_conversations',{inbox:'lecturer',courseid:CID,category:'',query:''},function(r){var convs=r.conversations||[];if(!convs.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">forum</span><p>No student issues.</p></div>';return;}body.innerHTML=convs.slice(0,10).map(function(c){return '<div style="padding:8px 10px;border-bottom:1px solid var(--u-olv);font-size:12px;display:flex;justify-content:space-between;align-items:center;cursor:pointer;" data-conversation-id="'+c.id+'"><div style="min-width:0;flex:1;"><strong>'+esc(c.title)+'</strong><br><span style="font-size:11px;color:var(--u-onsv);">'+esc(c.studentname)+' \u00b7 '+esc(lecturerIssueCategoryLabels[c.category]||c.category)+'</span></div>'+(c.unreadcount?'<span class="umat-issue-unread" style="flex-shrink:0;">'+c.unreadcount+'</span>':'')+'</div>';}).join('');body.querySelectorAll('[data-conversation-id]').forEach(function(el){el.addEventListener('click',function(){closePanel();lecOv.classList.add('open');switchPane('lec-issues');setTimeout(function(){openLecturerIssue(parseInt(el.dataset.conversationId));},200);});});},function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load issues.</p></div>';});}
function renderLcpQuizReview(body){var ajax=ajax||window.ajax||function(m,a,d,f){require(['core/ajax'],function(Ajax){Ajax.call([{methodname:m,args:a}])[0].done(d).fail(f);});};if(!CID){var courses=(UD&&UD.courses)||[];if(!courses.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>No courses available.</p></div>';return;}body.innerHTML='<div class="umat-cp-help" style="padding:10px 14px 2px;font-size:10px;color:var(--u-ol);font-weight:600;">Select a course to view student quiz responses:</div><div style="padding:4px 14px 6px;display:flex;flex-wrap:wrap;gap:4px;">'+courses.slice(0,16).map(function(c){return '<button class="umat-chip" data-cid="'+c.id+'" type="button">'+esc(c.shortname||c.fullname)+'</button>';}).join('')+'</div>';body.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){CID=parseInt(this.dataset.cid)||CID;renderLcpQuizReview(body);});});return;}body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading quiz responses\u2026</p></div>';ajax('local_umat_ai_get_course_quiz_attempts',{courseid:CID,userid:0,status:''},function(r){var attempts=r.attempts||[];if(!attempts.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">rate_review</span><p>No quiz attempts from students yet.</p></div>';return;}body.innerHTML='<div style="margin-bottom:8px;font-size:12px;font-weight:700;color:var(--u-ol);">'+attempts.length+' attempt'+(attempts.length>1?'s':'')+' across students</div>';var students={};attempts.forEach(function(a){if(!students[a.userid])students[a.userid]={userid:a.userid,fullname:a.fullname,email:a.email,attempts:[]};students[a.userid].attempts.push(a);});var studentIds=Object.keys(students);body.innerHTML+=studentIds.map(function(uid){var s=students[uid];var lastA=s.attempts[0];var scoreStr=lastA.score!==null?lastA.score+'/'+lastA.total:'-/ '+lastA.total;return '<div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r8);padding:10px;margin-bottom:6px;cursor:pointer;" data-user="'+s.userid+'"><strong style="font-size:13px;">'+esc(s.fullname)+'</strong><div style="font-size:11px;color:var(--u-onsv);">'+s.attempts.length+' quiz'+(s.attempts.length>1?'zes':'')+' \u00b7 Latest: '+scoreStr+'</div></div>';}).join('');body.querySelectorAll('[data-user]').forEach(function(el){el.addEventListener('click',function(){var uid=parseInt(el.dataset.user);showLecQuizStudentDetail(body,students[uid]);});});},function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load quiz responses.</p></div>';});}
function showLecQuizStudentDetail(body,student){body.innerHTML='<div style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;"><strong style="font-size:14px;">'+esc(student.fullname)+'\u2019s Quizzes</strong><button class="umat-chip" id="lcp-quiz-back" type="button"><span class="material-symbols-outlined" style="font-size:14px;">arrow_back</span> Back</button></div>';student.attempts.forEach(function(a){var scoreStr=a.score!==null?a.score+'/'+a.total:'-/ '+a.total;var statusCls=a.status==='completed'?'background:#dcfce7;color:#065f46;':'background:#fef3c7;color:#92400e;';var d=new Date(a.timecreated*1000);var dateStr=d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});body.innerHTML+='<div style="background:var(--u-bg);border:1px solid var(--u-olv);border-radius:var(--u-r8);padding:10px;margin-bottom:6px;cursor:pointer;" data-aid="'+a.attempt_id+'"><div style="display:flex;justify-content:space-between;"><strong style="font-size:12px;">'+esc(a.quiz_title)+'</strong><span style="font-size:10px;padding:1px 6px;border-radius:999px;'+statusCls+'font-weight:600;">'+a.status+'</span></div><div style="font-size:11px;color:var(--u-onsv);margin-top:3px;">Score: '+scoreStr+' \u00b7 '+dateStr+'</div></div>';});body.querySelectorAll('[data-aid]').forEach(function(el){el.addEventListener('click',function(){var aid=parseInt(el.dataset.aid);showLecQuizAnswerDetail(body,aid,student);});});var backBtn=document.getElementById('lcp-quiz-back');if(backBtn)backBtn.addEventListener('click',function(){renderLcpQuizReview(body);});}
function showLecQuizAnswerDetail(body,aid,student){body.innerHTML='<div style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;"><button class="umat-chip" id="lcp-quiz-ans-back" type="button"><span class="material-symbols-outlined" style="font-size:14px;">arrow_back</span> Back to student</button></div><div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading answers\u2026</p></div>';ajax('local_umat_ai_get_course_quiz_attempts',{courseid:CID,userid:0,status:''},function(r){var all=r.attempts||[];var attempt=null;all.forEach(function(a){if(a.attempt_id===aid)attempt=a;});if(!attempt){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Attempt not found.</p></div>';return;}var questions=JSON.parse(attempt.questions_json||'[]');var answers=JSON.parse(attempt.answers_json||'{}');var graded=JSON.parse(attempt.graded_json||'{}');var scoreStr=attempt.score!==null?attempt.score+'/'+attempt.total:'-/ '+attempt.total;var header='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><div><strong style="font-size:14px;">'+esc(attempt.quiz_title)+'</strong><div style="font-size:11px;color:var(--u-onsv);">'+esc(attempt.fullname)+' \u00b7 Score: '+scoreStr+'</div></div><button class="umat-chip" id="lcp-quiz-ans-back2" type="button"><span class="material-symbols-outlined" style="font-size:14px;">arrow_back</span> Back</button></div>';body.innerHTML=header;questions.forEach(function(q,i){var ans=answers[i];var g=graded[i];var isGraded=g!==undefined;var isCorrect=isGraded&&g.correct;var statusIcon=isGraded?(isCorrect?'check_circle':'cancel'):'hourglass_empty';var statusColor=isGraded?(isCorrect?'var(--u-sec)':'var(--u-ter)'):'var(--u-ol)';var ansDisplay='';if(q.type==='objective'||q.type==='truefalse'){var selOpt=ans!==undefined&&q.options&&q.options[ans];ansDisplay='<div style="font-size:11px;margin-top:4px;"><strong>Student:</strong> '+esc(selOpt||'Not answered')+'</div><div style="font-size:11px;color:var(--u-sec);"><strong>Correct:</strong> '+esc(q.options&&q.options[q.correct]||q.correct)+'</div>';}else{ansDisplay='<div style="font-size:11px;margin-top:4px;"><strong>Student:</strong><br>'+esc(ans||'Not answered')+'</div>'+(q.correct?'<div style="font-size:11px;color:var(--u-sec);margin-top:4px;"><strong>Expected:</strong> '+esc(q.correct)+'</div>':'');}var expl=isGraded&&g.explanation?'<div style="font-size:10px;color:var(--u-onsv);margin-top:4px;padding:6px;background:var(--u-sflo);border-radius:4px;"><strong>AI Feedback:</strong> '+esc(g.explanation)+'</div>':'';body.innerHTML+='<div style="background:var(--u-bg);border:1px solid var(--u-olv);border-radius:var(--u-r8);padding:10px;margin-bottom:6px;"><div style="display:flex;justify-content:space-between;align-items:flex-start;"><div style="flex:1;"><strong style="font-size:12px;">Q'+(i+1)+':</strong> <span style="font-size:12px;">'+esc(q.question)+'</span></div><span class="material-symbols-outlined" style="color:'+statusColor+';font-size:18px;">'+statusIcon+'</span></div>'+ansDisplay+expl+'</div>';});var backBtn=document.getElementById('lcp-quiz-ans-back');if(backBtn)backBtn.addEventListener('click',function(){showLecQuizStudentDetail(body,student);});var backBtn2=document.getElementById('lcp-quiz-ans-back2');if(backBtn2)backBtn2.addEventListener('click',function(){renderLcpQuizReview(body);});},function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load answers.</p></div>';});}
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

/* Sidebar & mobile tab pane switching.
 *
 * There is exactly ONE switchPane implementation, and it lives in the inline
 * overlay script (overlay_helper::lecturer_overlay). This module used to define
 * a second copy with its own lecLoaded map, and both were bound to the same
 * '#lec-sb [data-lp], #lec-glass-tabs [data-lp]' buttons. A single click ran
 * both, both saw a cold lecLoaded entry, and loadPaneData ran twice — which is
 * why opening Insights fired two identical get_struggle_insights requests while
 * only one "[dash] switchPane" line appeared in the console.
 *
 * This delegates to the canonical implementation instead. window.switchPane is
 * still exported because generated markup calls it via inline onclick.
 */
function switchPane(name){
  if(window.__umatSwitchPane){return window.__umatSwitchPane(name);}
  /* Inline script not present (module loaded stand-alone) — minimal fallback. */
  document.querySelectorAll('#lec-ov .umat-tab-pane').forEach(function(p){p.classList.remove('active');});
  document.querySelectorAll('#lec-sb [data-lp], #lec-glass-tabs [data-lp]').forEach(function(b){b.classList.toggle('active',b.dataset.lp===name);});
  var pane=document.getElementById(name);if(pane)pane.classList.add('active');
  if(!lecLoaded[name]){lecLoaded[name]=true;loadPaneData(name);}
}
window.switchPane=switchPane;
window.initLecturerIssues=initLecturerIssues;
window.renderLcpFeature=renderLcpFeature;
/* Handle data-lp clicks from compact panel -> open full overlay.
   These selectors are unique to this module; the sidebar, glass-tab and
   lec-home listeners are bound by the inline script and are NOT rebound here. */
document.querySelectorAll('#lec-cp [data-lp^="lec-"]').forEach(function(b){
  b.addEventListener('click',function(){closePanel();openDash();switchPane(b.dataset.lp);});
});
/* NOTE: sidebar and glass-tab data-lp clicks are owned by the IIFE — do NOT bind duplicate handlers here */

/* Home init */
function initHome(){
  var d=new Date(),dEl=document.getElementById('lec-home-date');
  if(dEl)dEl.textContent=d.toLocaleDateString('en-GB',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  /* The inline overlay script already populated the home cards (it runs
     before this AMD module resolves). Skip to avoid duplicate requests. */
  if(window.__umatHomeLoaded)return;
  window.__umatHomeLoaded=true;
  var ms=document.getElementById('lec-met-active');
  if(!CID){
    /* All Courses mode — aggregate active students across lecturer courses */
    var courses=(UD&&UD.courses)||[];
    if(!courses.length){if(ms)ms.textContent='0/0';return;}
    var agg={active:0,enrolled:0},done=0;
    courses.forEach(function(c){
      ajax('local_umat_ai_get_analytics',{courseid:c.id,days:30},function(data){
        agg.active+=data.active_students||0;agg.enrolled+=data.enrolled_students||0;
        if(++done===courses.length&&ms)ms.textContent=agg.active+'/'+agg.enrolled;
      },function(){if(++done===courses.length&&ms)ms.textContent=agg.active+'/'+agg.enrolled;});
    });
    return;
  }
  /* Use panel data if already loaded */
  if(panelDataLoaded)return;
  ajax('local_umat_ai_get_analytics',{courseid:CID,days:30},function(data){
    var mi=document.getElementById('lec-met-int');
    if(ms)ms.textContent=data.active_students+'/'+data.enrolled_students;
    if(mi)mi.textContent=data.total_interactions.toLocaleString();
  },function(){});
}

/* Delegate to the IIFE-scoped loadPaneData exposed on window */
function loadPaneData(name){window.loadPaneData&&window.loadPaneData(name);}

/* Refresh review pane */
var reviewRefresh=document.getElementById('lec-review-refresh');
if(reviewRefresh)reviewRefresh.addEventListener('click',loadReviewPane);

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
    var anLabel=document.getElementById('lec-an-course-label');if(anLabel)anLabel.textContent=cid===CID?CN:'Loading...';
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
    if(!courses.length){var _el=document.getElementById('ov-an-kpis');if(_el)_el.innerHTML='<div class="ov-loading">No courses assigned.</div>';return;}
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

/* ---- Render Analytics Overview (all courses) ---- */
function renderAnalyticsOverview(agg){
  var kpis=document.getElementById('ov-an-kpis');
  if(!kpis) return;
  if(!agg||!agg.per_course||!agg.per_course.length){
    kpis.innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">info</span><p>No analytics data available yet.</p></div>';
    return;
  }
  var active=agg.active_students,enrolled=agg.enrolled_students,totalInt=agg.total_interactions;
  var avgDepth=(agg.questions_per_session.length?agg.questions_per_session.reduce(function(a,b){return a+b;})/agg.questions_per_session.length:0).toFixed(1);
  var pct=Math.round(active/Math.max(enrolled,1)*100);
  /* KPI cards */
  kpis.innerHTML=
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-g"><span class="material-symbols-outlined">group</span></div><div class="ov-kpi-val">'+active+' <span class="ov-kpi-sub">/ '+enrolled+'</span></div><div class="ov-kpi-lbl">Active Students <span class="ov-kpi-pct">'+pct+'%</span></div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-s"><span class="material-symbols-outlined">timer</span></div><div class="ov-kpi-val">'+avgDepth+' <span class="ov-kpi-sub">Q</span></div><div class="ov-kpi-lbl">Avg Session Depth</div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-r"><span class="material-symbols-outlined">psychology_alt</span></div><div class="ov-kpi-val">'+agg.per_course.length+' <span class="ov-kpi-sub">courses</span></div><div class="ov-kpi-lbl">Courses Tracked</div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-w"><span class="material-symbols-outlined">forum</span></div><div class="ov-kpi-val">'+totalInt.toLocaleString()+'</div><div class="ov-kpi-lbl">Total Interactions</div></div>';
  /* Course comparison bars */
  var maxActive=Math.max.apply(null,agg.per_course.map(function(c){return c.active;}));
  var barsEl=document.getElementById('ov-an-bars');
  if(barsEl) barsEl.innerHTML=agg.per_course.sort(function(a,b){return b.active-a.active;}).map(function(c){
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
  var donutEl=document.getElementById('ov-an-donut');
  if(donutEl) donutEl.innerHTML=donut;
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
  for(var c=0;c<n;c++)html+='<div class="umat-heatmap-label">L'+(c+1)+'</div>';
  days.forEach(function(day,row){
    html+='<div class="umat-hm-row-lbl">'+day+'</div>';
    for(var col=0;col<n;col++){
      var base=daily[col]?daily[col].count:0;
      var va=[1,.8,1.2,.6,.9][row]*[1,.7,1.1,.85,.95,.6,1.3,.8,.75,1][col%10];
      var val=Math.round(base*va*.5);var pct=val/(maxV||1);
      var bg=pct<.15?'#dbeafe':pct<.4?'#93c5fd':pct<.7?'#4ade80':'var(--u-p)';
      var color=pct>=.7?'#fff':'rgba(0,0,0,.5)';
      html+='<div class="umat-hm-cell" style="background:'+bg+';color:'+color+';" title="'+day+' \u00b7 L'+(col+1)+': '+val+'">'+(val>0?val:'')+'</div>';
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

/* Library -- with course overlay selector */
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

/* Initialize the inline course picker for the Library tab.
   If the lecturer has only one course, auto-select it.
   Otherwise, show the inline course selector list. */
function initLibCoursePicker(){
  var courses=(UD&&UD.courses)||[];
  var inline=document.getElementById('lec-lib-cs-inline');
  var hdr=document.getElementById('lec-lib-hdr');
  var grid=document.getElementById('lec-lib-grid');
  /* If a course was already selected, just re-show the materials view */
  if(lecLibCourseId){selectLibCourse(lecLibCourseId);return;}
  /* If only one course, auto-select */
  if(courses.length===1){
    lecLibCourseId=courses[0].id;
    selectLibCourse(courses[0].id);
    return;
  }
  /* Multiple courses — show the inline picker */
  if(inline)inline.style.display='flex';
  if(hdr)hdr.style.display='none';
  if(grid)grid.style.display='none';
  var list=document.getElementById('lec-lib-cs-inline-list');
  if(!list)return;
  list.innerHTML=courses.map(function(c){
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
      selectLibCourse(cid);
    });
  });
}

/* Switch from course picker to the materials view for a given course */
function selectLibCourse(courseId){
  var inline=document.getElementById('lec-lib-cs-inline');
  var hdr=document.getElementById('lec-lib-hdr');
  var grid=document.getElementById('lec-lib-grid');
  if(inline)inline.style.display='none';
  if(hdr)hdr.style.display='flex';
  if(grid)grid.style.display='';
  loadLibrary(courseId);
}

/* Re-populate the inline course list (used when switching back to picker) */
function populateLibInlineList(){
  var courses=(UD&&UD.courses)||[];
  var list=document.getElementById('lec-lib-cs-inline-list');
  if(!list)return;
  list.innerHTML=courses.map(function(c){
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
      selectLibCourse(cid);
    });
  });
}

function loadLibrary(cid){
  var g=document.getElementById('lec-lib-grid');
  var courseId=cid||lecLibCourseId||CID||0;
  if(!courseId){
    /* No course selected — show inline course picker */
    var inline=document.getElementById('lec-lib-cs-inline');
    var hdr=document.getElementById('lec-lib-hdr');
    if(inline)inline.style.display='flex';
    if(hdr)hdr.style.display='none';
    if(g)g.style.display='none';
    return;
  }
  g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials...</p></div>';
  ajax('local_umat_ai_get_course_materials',{courseid:courseId},function(r){renderLibTiles(r.materials||[],g,courseId);},function(e){console.error('[umat] lecturer loadLibrary failed:',e&&e.message||e);g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
}
function openLecLibPicker(){
  /* Show inline course picker again */
  var inline=document.getElementById('lec-lib-cs-inline');
  var hdr=document.getElementById('lec-lib-hdr');
  var grid=document.getElementById('lec-lib-grid');
  if(inline){inline.style.display='flex';populateLibInlineList();}
  if(hdr)hdr.style.display='none';
  if(grid)grid.style.display='none';
  lecLibCourseId=0;
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

/* ---- SMART SEARCH (Lecturer Library) ----
   Replaced the old body-level modal (rendered behind the overlay). Smart
   Search now lives inline in the lecturer library search bar via the shared
   _umatSmartSearch helper (wired in overlay_helper.php). */

/* Sessions — lecturer's own AI sessions only */
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

/* Sessions tab — lecturer's own AI sessions only */
function loadSessions(cid){
  var list=document.getElementById('lec-sess-list');
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

    /* Wire tile click → resume session in chat */
    list.querySelectorAll('.umat-session-tile').forEach(function(tile){
      tile.addEventListener('click',function(e){
        if(e.target.closest('.umat-del-session-btn'))return;
        var sk=tile.dataset.sk,cid=parseInt(tile.dataset.cid)||0;
        var name=tile.querySelector('h4')?tile.querySelector('h4').textContent:'';
        resumeLecSessionInline(sk,cid,name);
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

/* Analytics -- course overlay selector */
var lecAnalyticsCourseId = 0;
function populateAnalyticsCourseSel(){
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

/* ---- Review Outputs pane ---- */
function fmtDate(ts){var d=new Date(ts*1000);return d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});}
function outTypeIcon(t){if(t==='summary')return 'summarize';if(t==='notes')return 'notes';if(t==='quiz')return 'quiz';return 'description';}
function outTypeLbl(t){if(t==='summary')return 'Summary';if(t==='notes')return 'Notes';if(t==='quiz')return 'Quiz';return t;}

function loadReviewPane(){
  var body=document.getElementById('lec-review-body');
  if(!body)return;
  if(!CID){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>Select a course from the courses pane above to view pending outputs.</p></div>';return;}
  body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading pending outputs...</p></div>';
  ajax('local_umat_ai_get_pending_outputs',{courseid:CID},function(r){
    renderReviewOutputs(r);
  },function(e){
    var m=e&&e.message?esc(e.message):'Could not load pending outputs. Check console.';
    body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>'+m+'</p></div>';
  });
}

/* ----- Student Issues (Lecturer) — Conversation Inbox ----- */
function lecturerIssueClientId(){return 'ui_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,12);}
function lecturerIssueTime(ts){if(!ts)return '';var d=new Date(ts*1000),now=new Date();var opts={hour:'numeric',minute:'2-digit'};if(d.toDateString()===now.toDateString())return d.toLocaleTimeString([],opts);return d.toLocaleDateString([],{month:'short',day:'numeric'})+' '+d.toLocaleTimeString([],opts);}
function lecturerIssueInitials(name){return (name||'Student').split(/\s+/).slice(0,2).map(function(part){return part.charAt(0);}).join('').toUpperCase();}
function lecturerIssueReceiptHtml(receipt){if(!receipt)return '';var label=receipt==='viewed'?'Viewed':(receipt==='delivered'?'Delivered':'Sent');return '<span class="umat-issue-mini-receipt '+(receipt==='viewed'?'viewed':'')+'" aria-label="'+label+'" title="'+label+'">'+(receipt==='sent'?'&#10003;':'&#10003;&#10003;')+'</span>';}
function lecturerIssueView(name){['list','thread'].forEach(function(view){var el=document.getElementById('lec-issue-'+view+'-view');if(el)el.classList.toggle('active',view===name);});}
function lecturerIssueDraftKey(){return 'umat_lec_issue_draft_'+(lecturerIssueConversationId||'none');}
function saveLecturerIssueDraft(){var input=document.getElementById('lec-issue-reply');if(input&&lecturerIssueConversationId){try{sessionStorage.setItem(lecturerIssueDraftKey(),input.value);}catch(e){}}}
function restoreLecturerIssueDraft(){var input=document.getElementById('lec-issue-reply');if(!input)return;try{input.value=sessionStorage.getItem(lecturerIssueDraftKey())||'';}catch(e){input.value='';}}
function clearLecturerIssueDraft(){try{sessionStorage.removeItem(lecturerIssueDraftKey());}catch(e){}}

function initLecturerIssues(){
  _umatLecMarkViewed();
  if(!lecturerIssuesReady){
    lecturerIssuesReady=true;
    var courseSelect=document.getElementById('lec-issues-course');
    if(courseSelect){courseSelect.innerHTML='<option value="0">All authorized courses</option>'+((UD&&UD.courses)||[]).map(function(c){return '<option value="'+c.id+'">'+esc(c.shortname||c.fullname)+'</option>';}).join('');courseSelect.value=CID?String(CID):'0';courseSelect.addEventListener('change',function(){loadLecturerIssueInbox(false);});}
    var category=document.getElementById('lec-issues-category');if(category)category.addEventListener('change',function(){loadLecturerIssueInbox(false);});
    var search=document.getElementById('lec-issues-search');if(search)search.addEventListener('input',function(){clearTimeout(lecturerIssueSearchTimer);lecturerIssueSearchTimer=setTimeout(function(){loadLecturerIssueInbox(true);},300);});
    var refresh=document.getElementById('lec-issues-refresh');if(refresh)refresh.addEventListener('click',function(){loadLecturerIssueInbox(false);});
    var back=document.getElementById('lec-issue-thread-back');if(back)back.addEventListener('click',function(){saveLecturerIssueDraft();lecturerIssueConversationId=0;try{sessionStorage.removeItem('umat_lec_issue_active');}catch(e){}lecturerIssueView('list');loadLecturerIssueInbox(true);});
    var reply=document.getElementById('lec-issue-reply');if(reply){reply.addEventListener('input',saveLecturerIssueDraft);reply.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendLecturerIssueMessage();}});}
    var send=document.getElementById('lec-issue-send');if(send)send.addEventListener('click',function(){sendLecturerIssueMessage();});
    var error=document.getElementById('lec-issue-send-error');if(error)error.addEventListener('click',function(e){if(e.target.closest('[data-issue-retry]')&&lecturerIssueFailedSend)sendLecturerIssueMessage(lecturerIssueFailedSend);});
    var linked=parseInt(new URLSearchParams(window.location.search).get('umat_issue'))||0;
    try{lecturerIssueConversationId=linked||parseInt(sessionStorage.getItem('umat_lec_issue_active'))||0;}catch(e){lecturerIssueConversationId=linked;}
  }
  loadLecturerIssueInbox(false);
  if(lecturerIssueConversationId)openLecturerIssue(lecturerIssueConversationId);
}
function loadLecturerIssueInbox(quiet){
  if(lecturerIssueLoading)return;lecturerIssueLoading=true;
  var list=document.getElementById('lec-issues-body');if(!list)return;
  var courseSelect=document.getElementById('lec-issues-course');var category=document.getElementById('lec-issues-category');var search=document.getElementById('lec-issues-search');
  var courseid=courseSelect?parseInt(courseSelect.value)||0:(CID||0);
  if(!quiet)list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading student issues...</p></div>';
  ajax('local_umat_ai_list_issue_conversations',{inbox:'lecturer',courseid:courseid,category:category?category.value:'',query:search?search.value.trim():''},function(r){lecturerIssueLoading=false;lecturerIssueConversations=r.conversations||[];renderLecturerIssueInbox();updateLecturerIssueBadge(r.totalunread||0);if(lecturerIssueConversationId&&document.getElementById('lec-issue-thread-view').classList.contains('active'))loadLecturerIssueMessages(true);},function(error){lecturerIssueLoading=false;if(!quiet)list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Could not load Student Issues.</p><button class="umat-chip" id="lec-issues-list-retry" type="button">Retry</button></div>';var retry=document.getElementById('lec-issues-list-retry');if(retry)retry.addEventListener('click',function(){loadLecturerIssueInbox(false);});});
}
function renderLecturerIssueInbox(){
  var list=document.getElementById('lec-issues-body');if(!list)return;
  if(!lecturerIssueConversations.length){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">forum</span><p>No student issues have been reported for this course.</p></div>';return;}
  list.innerHTML=lecturerIssueConversations.map(function(c){var identity=c.studentidnumber?' \u00b7 '+c.studentidnumber:'';return '<button class="umat-issue-row '+(c.unreadcount?'unread':'')+'" data-conversation-id="'+c.id+'" type="button"><span class="umat-issue-avatar">'+esc(lecturerIssueInitials(c.studentname))+'</span><span class="umat-issue-row-main"><span class="umat-issue-row-title"><strong>'+esc(c.studentname)+'</strong><span class="umat-issue-category">'+esc(lecturerIssueCategoryLabels[c.category]||c.category)+'</span></span><span class="umat-issue-preview"><strong>'+esc(c.title)+':</strong> '+esc(c.lastmessage||'No messages yet')+'</span><span class="umat-issue-row-sub">'+esc(c.courseshortname+identity)+'</span></span><span class="umat-issue-row-side"><time>'+esc(lecturerIssueTime(c.lastmessagetime))+'</time>'+(c.unreadcount?'<span class="umat-issue-unread">'+(c.unreadcount>99?'99+':c.unreadcount)+'</span>':lecturerIssueReceiptHtml(c.latestsentreceipt))+'</span></button>';}).join('');
  list.querySelectorAll('[data-conversation-id]').forEach(function(row){row.addEventListener('click',function(){openLecturerIssue(parseInt(row.dataset.conversationId));});});
}
function openLecturerIssue(id){lecturerIssueConversationId=id;_umatLecMarkViewed();try{sessionStorage.setItem('umat_lec_issue_active',String(id));}catch(e){}lecturerIssueView('thread');restoreLecturerIssueDraft();loadLecturerIssueMessages(false);}
function loadLecturerIssueMessages(preserveScroll){
  if(!lecturerIssueConversationId)return;var expected=lecturerIssueConversationId;var box=document.getElementById('lec-issue-messages');if(!box)return;var nearBottom=box.scrollHeight-box.scrollTop-box.clientHeight<90;var oldTop=box.scrollTop;if(!preserveScroll)box.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading conversation...</p></div>';
  ajax('local_umat_ai_get_issue_messages',{conversationid:expected},function(r){if(expected!==lecturerIssueConversationId)return;var c=r.conversation;document.getElementById('lec-issue-thread-title').textContent=c.title;document.getElementById('lec-issue-thread-meta').textContent=c.studentname+(c.studentidnumber?' \u00b7 '+c.studentidnumber:'')+' \u00b7 '+c.coursename+' \u00b7 '+(lecturerIssueCategoryLabels[c.category]||c.category);renderLecturerIssueMessages(r.messages||[]);if(!preserveScroll||nearBottom)box.scrollTop=box.scrollHeight;else box.scrollTop=oldTop;},function(){if(expected!==lecturerIssueConversationId)return;box.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error_outline</span><p>Conversation could not be loaded.</p><button class="umat-chip" id="lec-issue-thread-retry" type="button">Retry</button></div>';var retry=document.getElementById('lec-issue-thread-retry');if(retry)retry.addEventListener('click',function(){loadLecturerIssueMessages(false);});});
}
function renderLecturerIssueMessages(messages){var box=document.getElementById('lec-issue-messages');if(!box)return;box.innerHTML=messages.map(renderLecturerIssueMessage).join('');observeLecturerIssueMessages();}
function renderLecturerIssueMessage(m){var attachments=(m.attachments||[]).map(function(a){return '<a class="umat-issue-attachment" href="'+esc(a.url)+'" target="_blank" rel="noopener"><span class="material-symbols-outlined">description</span><span>'+esc(a.filename)+'</span></a>';}).join('');var label=m.receipt==='viewed'?'Viewed':(m.receipt==='delivered'?'Delivered':'Sent');var receipt=m.ismine?'<span class="umat-issue-receipt '+(m.receipt==='viewed'?'viewed':'')+'" aria-label="'+label+'">'+(m.receipt==='sent'?'&#10003;':'&#10003;&#10003;')+'</span>':'';return '<article class="umat-issue-message '+(m.ismine?'mine':'')+'" data-message-id="'+m.id+'" data-other="'+(m.ismine?'0':'1')+'" data-viewed="'+(m.viewedat?'1':'0')+'"><span class="umat-issue-sender">'+esc(m.ismine?'You':m.sendername)+'</span><div class="umat-issue-bubble"><div class="umat-issue-body">'+esc(m.body)+'</div>'+attachments+'<div class="umat-issue-message-meta"><time>'+esc(lecturerIssueTime(m.timecreated))+'</time>'+receipt+'</div></div></article>';}
function observeLecturerIssueMessages(){
  if(lecturerIssueReadObserver)lecturerIssueReadObserver.disconnect();var root=document.getElementById('lec-issue-messages');if(!root||!window.IntersectionObserver)return;var pending={};var timer=null;
  lecturerIssueReadObserver=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting&&entry.intersectionRatio>=.35){var el=entry.target;if(el.dataset.other==='1'&&el.dataset.viewed!=='1'){pending[el.dataset.messageId]=true;el.dataset.viewed='1';lecturerIssueReadObserver.unobserve(el);}}});if(Object.keys(pending).length&&!timer){timer=setTimeout(function(){var ids=Object.keys(pending).map(Number);pending={};timer=null;ajax('local_umat_ai_mark_issue_messages_viewed',{conversationid:lecturerIssueConversationId,messageids:ids},function(){loadLecturerIssueInbox(true);},function(){ids.forEach(function(id){var el=root.querySelector('[data-message-id="'+id+'"]');if(el){el.dataset.viewed='0';lecturerIssueReadObserver.observe(el);}});});},250);}}, {root:root,threshold:[.35]});
  root.querySelectorAll('[data-other="1"][data-viewed="0"]').forEach(function(el){lecturerIssueReadObserver.observe(el);});
}
function uploadLecturerIssueAttachment(messageid,file,done,fail){var form=new FormData();form.append('sesskey',moodleSesskey);form.append('messageid',messageid);form.append('attachment',file);fetch((window.M&&window.M.cfg?window.M.cfg.wwwroot:'')+'/local/umat_ai/issue_attachment.php',{method:'POST',body:form,credentials:'same-origin'}).then(function(response){return response.json().then(function(data){if(!response.ok||!data.success)throw new Error(data.message||'Upload failed.');return data;});}).then(done).catch(function(error){fail(error.message||'Upload failed.');});}
function appendLecturerPendingMessage(item){var box=document.getElementById('lec-issue-messages');if(!box)return;var el=document.createElement('article');el.className='umat-issue-message mine';el.id='lec-issue-temp-'+item.clientid;el.innerHTML='<span class="umat-issue-sender">You</span><div class="umat-issue-bubble"><div class="umat-issue-body">'+esc(item.body)+'</div><div class="umat-issue-message-meta"><span>Sending...</span></div></div>';box.appendChild(el);box.scrollTop=box.scrollHeight;}
function showLecturerIssueError(text){var el=document.getElementById('lec-issue-send-error');if(!el)return;el.innerHTML=esc(text)+' <button class="umat-issue-retry" data-issue-retry type="button">Retry</button>';el.classList.add('show');}
function clearLecturerIssueError(){var el=document.getElementById('lec-issue-send-error');if(el){el.classList.remove('show');el.innerHTML='';}}
function sendLecturerIssueMessage(retry){
  if(!lecturerIssueConversationId)return;_umatLecMarkViewed();var input=document.getElementById('lec-issue-reply');var fileInput=document.getElementById('lec-issue-reply-file');var item=retry||{body:input.value.trim(),file:fileInput.files[0]||null,clientid:lecturerIssueClientId()};if(!item.body)return;var btn=document.getElementById('lec-issue-send');btn.disabled=true;btn.setAttribute('aria-busy','true');clearLecturerIssueError();var previous=document.getElementById('lec-issue-temp-'+item.clientid);if(previous)previous.remove();appendLecturerPendingMessage(item);
  ajax('local_umat_ai_send_issue_message',{conversationid:lecturerIssueConversationId,body:item.body,clientid:item.clientid},function(r){var temp=document.getElementById('lec-issue-temp-'+item.clientid);if(temp)temp.querySelector('.umat-issue-message-meta').innerHTML='<span>'+esc(lecturerIssueTime(r.message.timecreated))+'</span><span class="umat-issue-receipt" aria-label="Sent">&#10003;</span>';var finish=function(){btn.disabled=false;btn.removeAttribute('aria-busy');lecturerIssueFailedSend=null;if(input.value.trim()===item.body)input.value='';if(fileInput)fileInput.value='';clearLecturerIssueDraft();setTimeout(function(){loadLecturerIssueMessages(true);loadLecturerIssueInbox(true);},350);};if(item.file)uploadLecturerIssueAttachment(r.message.id,item.file,finish,function(error){btn.disabled=false;btn.removeAttribute('aria-busy');lecturerIssueFailedSend=item;showLecturerIssueError('Attachment not sent. '+error);});else finish();},function(error){btn.disabled=false;btn.removeAttribute('aria-busy');lecturerIssueFailedSend=item;var temp=document.getElementById('lec-issue-temp-'+item.clientid);if(temp){temp.classList.add('failed');temp.querySelector('.umat-issue-message-meta').innerHTML='<span>Message not sent.</span>';}showLecturerIssueError((error&&error.message)||'Message not sent.');});
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
        body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">fact_check</span><p>All outputs reviewed! dYZ%</p></div>';
    },600);
  }
}

/* -------------------------------------------------------
   STRUGGLE INSIGHTS
   ---------------------------------------------------------- */
function loadStruggleInsights(cid){
  var overview=document.getElementById('stru-overview');
  var loading=document.getElementById('stru-loading');
  var error=document.getElementById('stru-error');
  var content=document.getElementById('stru-content');
  var csLabel=document.getElementById('stru-cs-label');
  var courseLabel=document.getElementById('stru-course-label');
  if(cid){
    if(overview)overview.style.display='none';
    var stCourse=(UD.courses||[]).find(function(c){return c.id===cid;});
    if(csLabel)csLabel.textContent=stCourse?stCourse.fullname||stCourse.shortname:'Course '+cid;
    if(courseLabel)courseLabel.textContent=cid===CID?CN:'Loading\u2026';
  }else{
    if(overview)overview.style.display='';
    if(content)content.style.display='none';
    if(loading)loading.style.display='none';
    if(error)error.style.display='none';
    if(csLabel)csLabel.textContent='All Courses';
    if(courseLabel)courseLabel.textContent='';
    /* Load all courses struggle data in parallel */
    var courses=UD&&UD.courses||[];
    if(!courses.length){document.getElementById('ov-stru-kpis').innerHTML='<div class="ov-loading">No courses assigned.</div>';return;}
    var agg={total_questions:0,total_students:0,total_issues:0,open_issues:0,per_course:[],all_topics:[],all_students:[],topic_map:{}};
    var done=0;
    courses.forEach(function(c){
      ajax('local_umat_ai_get_struggle_insights',{courseid:c.id,days:60},function(d){
        if(d.summary){
          agg.total_questions+=d.summary.total_questions||0;
          agg.total_students+=d.summary.total_students||0;
          agg.total_issues+=d.summary.total_issues||0;
          agg.open_issues+=d.summary.open_issues||0;
        }
        var struggleScore='N/A';
        if(d.topic_matrix&&d.topic_matrix.length){
          var scores=d.topic_matrix.map(function(t){return t.struggle_score||0;});
          struggleScore=Math.round(scores.reduce(function(a,b){return a+b;})/scores.length);
          d.topic_matrix.forEach(function(t){
            var key=t.topic;
            if(agg.topic_map[key]){agg.topic_map[key].question_count+=t.question_count;agg.topic_map[key].student_count+=t.student_count;agg.topic_map[key].struggle_score=(agg.topic_map[key].struggle_score+t.struggle_score)/2;}else{agg.topic_map[key]=JSON.parse(JSON.stringify(t));}
          });
        }
        (d.at_risk_students||[]).forEach(function(s){s.course_name=c.shortname;agg.all_students.push(s);});
        agg.per_course.push({id:c.id,name:c.shortname,active:0,questions:d.summary?d.summary.total_questions||0:0,students:d.summary?d.summary.total_students||0:0,struggle:struggleScore});
        done++;if(done===courses.length)renderStruggleOverview(agg);
      },function(){done++;if(done===courses.length)renderStruggleOverview(agg);});
    });
    return;
  }
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
    function(e){
      if(loading)loading.style.display='none';
      if(error){
        error.style.display='';
        var msg = (e && e.message) ? e.message : (typeof e === 'string' ? e : 'No data yet. Struggle insights appear once students start chatting with the AI Tutor.');
        document.getElementById('stru-error-text').textContent = msg;
        console.warn('Struggle insights error:', e);
      }
    }
  );
}

/* ---- Render Struggle Overview (all courses) ---- */
function renderStruggleOverview(agg){
  var kpis=document.getElementById('ov-stru-kpis');
  if(!kpis) return;
  if(!agg||!agg.per_course||!agg.per_course.length){
    kpis.innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">info</span><p>No struggle data available yet.</p></div>';
    return;
  }
  var tq=agg.total_questions,ts=agg.total_students,ti=agg.total_issues,oi=agg.open_issues;
  /* Compute severity distribution from topic_map */
  var sHigh=0,sMed=0,sLow=0;
  Object.keys(agg.topic_map).forEach(function(k){
    var sc=agg.topic_map[k].struggle_score||0;
    if(sc>=60)sHigh++;else if(sc>=30)sMed++;else sLow++;
  });
  var sTotal=sHigh+sMed+sLow;
  /* KPI row with severity donut */
  kpis.innerHTML=
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-g"><span class="material-symbols-outlined">quiz</span></div><div class="ov-kpi-val">'+tq+' <span class="ov-kpi-sub">questions</span></div><div class="ov-kpi-lbl">Total Asked</div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-s"><span class="material-symbols-outlined">people</span></div><div class="ov-kpi-val">'+ts+' <span class="ov-kpi-sub">students</span></div><div class="ov-kpi-lbl">Students Engaged</div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-r"><span class="material-symbols-outlined">flag</span></div><div class="ov-kpi-val">'+ti+' <span class="ov-kpi-sub">issues</span></div><div class="ov-kpi-lbl">'+oi+' open</div></div>'+
    '<div class="ov-kpi"><div class="ov-kpi-icon ak-w"><span class="material-symbols-outlined">school</span></div><div class="ov-kpi-val">'+agg.per_course.length+' <span class="ov-kpi-sub">courses</span></div><div class="ov-kpi-lbl">Courses Monitored</div></div>'+
    '<div class="stru-donut-kpi">'+makeSeverityDonut(sHigh,sMed,sLow,sTotal)+'<div class="ov-kpi-lbl" style="margin-top:4px;">Severity Distribution</div></div>';
  /* Course struggle comparison bars */
  var maxQ=Math.max.apply(null,agg.per_course.map(function(c){return c.questions;}));
  var struBarsEl=document.getElementById('ov-stru-bars');
  if(struBarsEl) struBarsEl.innerHTML=agg.per_course.sort(function(a,b){return b.questions-a.questions;}).map(function(c){
    var w=maxQ?Math.round(c.questions/maxQ*100):0;
    var struggleLabel=typeof c.struggle==='number'?c.struggle+'/100':'?"';
    return '<div class="ov-bar-row"><div class="ov-bar-label"><span class="ov-bar-course">'+esc(c.name)+'</span><span class="ov-bar-val">'+c.questions+' Q</span></div><div class="ov-bar-track"><div class="ov-bar-fill ov-bar-stru" style="width:'+w+'%"></div></div><div class="ov-bar-score">'+struggleLabel+'</div></div>';
  }).join('');
  /* Top struggled topics */
  var topics=Object.keys(agg.topic_map).map(function(k){return agg.topic_map[k];}).sort(function(a,b){return b.struggle_score-a.struggle_score;}).slice(0,8);
  var tEl=document.getElementById('ov-stru-topics');
  if(tEl){
    if(!topics.length){tEl.innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">psychology</span><p>No topic data yet.</p></div>';}
    else{
      tEl.innerHTML=topics.map(function(t){
        var pct=Math.min(100,t.struggle_score);var sev=pct>=60?'high':(pct>=30?'medium':'low');
        return '<div class="ov-topic-row"><div class="ov-topic-dot struggle-'+sev+'"></div><div class="ov-topic-info"><div class="ov-topic-name">'+esc(t.topic)+'</div><div class="ov-topic-meta">'+t.question_count+' questions from '+t.student_count+' students</div></div><div class="ov-topic-score struggle-'+sev+'">'+t.struggle_score+'</div></div>';
      }).join('');
    }
  }
  /* At-risk students */
  var students=agg.all_students.sort(function(a,b){return b.risk_score-a.risk_score;}).slice(0,15);
  var sEl=document.getElementById('ov-stru-students');
  if(sEl){
    if(!students.length){sEl.innerHTML='<div class="ov-placeholder"><span class="material-symbols-outlined">person_search</span><p>No at-risk students identified.</p></div>';return;}
    sEl.innerHTML='<div class="ov-student-hdr"><span>Student</span><span>Course</span><span>Questions</span><span>Risk</span><span>Activity</span></div>'+
    students.map(function(s){
      var riskPill=s.risk_level==='high'?'<span class="umat-pill pill-high">High</span>':
        (s.risk_level==='medium'?'<span class="umat-pill pill-warn">Med</span>':'<span class="umat-pill pill-ok">Low</span>');
      return '<div class="ov-student-row"><div class="ov-student-info"><img src="'+esc(s.profileimageurl)+'" alt="" onerror="this.style.display=\'none\'" class="ov-student-avatar"><span>'+esc(s.fullname)+'</span></div><span class="ov-student-course">'+esc(s.course_name||'')+'</span><span>'+s.question_count+'</span><span>'+riskPill+' ('+s.risk_score+')</span><span>'+esc(s.last_active||'')+'</span></div>';
    }).join('');
  }
}

function renderStruggleSummary(summary){
  if(!summary)return;
  var badge=document.getElementById('stru-mode-badge');
  if(badge)badge.textContent=summary.ai_service_used?'AI Engine':'PHP Engine';
  /* Enhanced KPI bar with visual cards */
  var bar=document.getElementById('stru-summary-bar');
  if(bar){
    var tq=summary.total_questions||0,ts=summary.total_students||0;
    bar.innerHTML=
      '<div class="stru-kpi-card"><div class="stru-kpi-icon stru-kpi-q"><span class="material-symbols-outlined">quiz</span></div><div class="stru-kpi-body"><div class="stru-kpi-val">'+tq+'</div><div class="stru-kpi-lbl">Questions</div></div></div>'+
      '<div class="stru-kpi-card"><div class="stru-kpi-icon stru-kpi-s"><span class="material-symbols-outlined">people</span></div><div class="stru-kpi-body"><div class="stru-kpi-val">'+ts+'</div><div class="stru-kpi-lbl">Students</div></div></div>'+
      '<div class="stru-kpi-card"><div class="stru-kpi-icon stru-kpi-w"><span class="material-symbols-outlined">psychology_alt</span></div><div class="stru-kpi-body"><div class="stru-kpi-val">'+esc(summary.worst_topic||'\u2014')+'</div><div class="stru-kpi-lbl">Most Struggled Topic</div></div></div>'+
      (summary.total_issues!==undefined?'<div class="stru-kpi-card stru-kpi-click" data-act="lec-issues"><div class="stru-kpi-icon stru-kpi-i"><span class="material-symbols-outlined">flag</span></div><div class="stru-kpi-body"><div class="stru-kpi-val">'+summary.total_issues+'</div><div class="stru-kpi-lbl">'+summary.open_issues+' open issue'+(summary.open_issues!==1?'s':'')+'</div></div></div>':'');
    bar.querySelectorAll('.stru-kpi-click[data-act]').forEach(function(el){
      el.addEventListener('click',function(){switchPane(el.dataset.act);});
      T.makeTouchable(el);
    });
  }
  var issuesEl=document.getElementById('stru-issues-summary');
  if(issuesEl&&summary.total_issues!==undefined){
    issuesEl.style.display='flex';
    var cnt=summary.total_issues,open=summary.open_issues;
    issuesEl.innerHTML='<span class="material-symbols-outlined" style="color:var(--u-ter);">flag</span><div><strong>'+cnt+' issue'+(cnt!==1?'s':'')+' reported</strong><span class="umat-text-label" style="display:block;">'+open+' open'+((summary.top_issue_topics||[]).length?' \u00b7 '+summary.top_issue_topics.slice(0,3).join(', '):'')+'</span></div>';
  }
  /* AI Overall Summary */
  var aiSumEl=document.getElementById('stru-ai-summary');
  if(aiSumEl){
    if(summary.ai_overall_summary){
      aiSumEl.style.display='';
      aiSumEl.innerHTML='<span class="material-symbols-outlined" style="color:var(--u-p);font-size:18px;">auto_awesome</span><div class="stru-ai-summary-text">'+esc(summary.ai_overall_summary)+'</div>';
    }else{
      aiSumEl.style.display='none';
    }
  }
  /* AI Course Health Report */
  var healthEl=document.getElementById('stru-course-health');
  if(healthEl&&summary.ai_course_health){
    try{
      var ch=typeof summary.ai_course_health==='string'?JSON.parse(summary.ai_course_health):summary.ai_course_health;
      var healthIcon=ch.overall_health==='healthy'?'check_circle':
        (ch.overall_health==='moderate'?'warning':'error');
      var healthColor=ch.overall_health==='healthy'?'var(--u-p)':
        (ch.overall_health==='moderate'?'#f59e0b':'#dc2626');
      var findings=(ch.key_findings||[]).map(function(f){return '<li>'+esc(f)+'</li>';}).join('');
      var recs=(ch.recommendations||[]).map(function(r){return '<li>'+esc(r)+'</li>';}).join('');
      healthEl.style.display='block';
      healthEl.innerHTML=
        '<div class="stru-health-header"><span class="material-symbols-outlined" style="color:'+healthColor+';">'+healthIcon+'</span><strong>Course Health: '+ch.overall_health.charAt(0).toUpperCase()+ch.overall_health.slice(1)+'</strong></div>'+
        '<div class="stru-health-body">'+
          (ch.summary?'<p class="stru-health-summary">'+esc(ch.summary)+'</p>':'')+
          (findings?'<div class="stru-health-section"><strong>Key Findings</strong><ul>'+findings+'</ul></div>':'')+
          (ch.worst_topic_analysis?'<div class="stru-health-section"><strong>Worst Topic Analysis</strong><p>'+esc(ch.worst_topic_analysis)+'</p></div>':'')+
          (ch.student_risk_summary?'<div class="stru-health-section"><strong>Student Risk Summary</strong><p>'+esc(ch.student_risk_summary)+'</p></div>':'')+
          (recs?'<div class="stru-health-section"><strong>Recommendations</strong><ul>'+recs+'</ul></div>':'')+
          (ch.event_pattern_insight?'<div class="stru-health-section"><strong>Event Pattern Insight</strong><p>'+esc(ch.event_pattern_insight)+'</p></div>':'')+
        '</div>';
    }catch(e){
      healthEl.style.display='none';
    }
  }else if(healthEl){
    healthEl.style.display='none';
  }
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
    var ringColor=sev==='high'?'#dc2626':(sev==='medium'?'#f59e0b':'#16a34a');
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
    /* SVG score ring */
    var r=15.9,c=100,off=c-pct/100*c;
    var scoreRing='<svg class="stru-svg-ring" width="36" height="36" viewBox="0 0 36 36"><circle cx="18" cy="18" r="'+r+'" fill="none" stroke="#e5e7eb" stroke-width="2.8"/><circle cx="18" cy="18" r="'+r+'" fill="none" stroke="'+ringColor+'" stroke-width="2.8" stroke-dasharray="'+c+'" stroke-dashoffset="'+off+'" transform="rotate(-90,18,18)" stroke-linecap="round"/><text x="18" y="18" text-anchor="middle" dominant-baseline="central" font-size="10" font-weight="800" fill="'+ringColor+'">'+t.struggle_score+'</text></svg>';
    return '<div class="struggle-topic-card struggle-'+sev+'">'+
      '<div class="struggle-topic-header">'+
        '<div class="struggle-topic-name"><strong>'+esc(t.topic)+'</strong> '+diffPill+'</div>'+
        '<div class="struggle-score-wrap">'+scoreRing+trendHtml+'</div>'+
      '</div>'+
      '<div class="struggle-topic-body">'+
        '<span class="stru-topic-stat"><span class="material-symbols-outlined">quiz</span> <strong>'+t.question_count+'</strong></span>'+
        '<span class="stru-topic-stat"><span class="material-symbols-outlined">people</span> <strong>'+t.student_count+'</strong></span>'+
        '<span class="stru-topic-stat"><span class="material-symbols-outlined">trending_up</span> <strong>'+pct+'%</strong></span>'+
      '</div>'+
      '<div class="struggle-topic-body-sub">'+
        '<span>'+t.question_count+' questions by '+t.student_count+' student'+(t.student_count!==1?'s':'')+'</span>'+
      '</div>'+
      (t.event_sources?makeEventSourceBar(t.event_sources):'')+
      (matChips?'<div class="struggle-topic-materials">'+matChips+'</div>':'')+
      (t.ai_recommendation?'<div class="struggle-topic-ai"><span class="material-symbols-outlined" style="font-size:15px;color:var(--u-p);">auto_awesome</span><span>'+esc(t.ai_recommendation)+'</span></div>':'')+
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
      '<div class="struggle-group-header" data-toggle-target>'+
        '<span class="material-symbols-outlined struggle-toggle">keyboard_arrow_down</span>'+
        '<strong>'+esc(sec.section_name)+'</strong>'+
        '<span class="umat-pill pill-info">'+(sec.materials||[]).length+' items</span>'+
      '</div>'+
      '<div class="struggle-group-body">'+matHtml+'</div>'+
    '</div>';
  }).join('');
  list.querySelectorAll('[data-toggle-target]').forEach(function(hdr){
    hdr.addEventListener('click',function(){
      var body=this.nextElementSibling;
      if(body){
        var isHidden=body.style.display==='none';
        body.style.display=isHidden?'':'none';
        var toggle=this.querySelector('.struggle-toggle');
        if(toggle)toggle.textContent=isHidden?'keyboard_arrow_down':'keyboard_arrow_right';
      }
    });
    T.makeTouchable(hdr);
  });
}

function renderAtRiskStudents(students){
  var list=document.getElementById('stru-student-list');
  if(!list)return;
  if(!students||!students.length){
    list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">person_search</span><p>No at-risk student data yet.</p></div>';
    return;
  }
  list.innerHTML='<div class="struggle-student-header">'+
    '<span>Student</span><span>Questions</span><span>Issues</span><span>Struggle Topics</span><span>Risk</span><span>Activity</span>'+
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
      '<span class="struggle-student-issues" style="text-align:center;">'+(s.issue_count>0?'<span class="umat-pill" style="background:var(--u-ter);color:#fff;">'+s.issue_count+'</span>':'<span style="color:var(--u-ol);font-size:11px;">0</span>')+'</span>'+
      '<span class="struggle-student-topics">'+topicTags+'</span>'+
      '<span class="struggle-student-risk">'+riskPill+' ('+s.risk_score+')</span>'+
      '<span class="struggle-student-active">'+esc(s.last_active)+'</span>'+
      (s.ai_risk_factors&&s.ai_risk_factors.length?'<div class="struggle-student-ai-factors"><span class="material-symbols-outlined" style="font-size:13px;color:var(--u-p);">insight</span>'+s.ai_risk_factors.map(function(f){return '<span class="struggle-factor-tag">'+esc(f)+'</span>';}).join('')+'</div>':'')+
      (s.ai_recommendation?'<div class="struggle-student-ai-recs">'+esc(s.ai_recommendation)+'</div>':'')+
    '</div>';
  }).join('');
}

/* ---- SVG Score Ring (reusable) ---- */
function makeScoreRing(pct,color,label){
  var r=15.9,c=100,off=c-Math.min(100,pct)/100*c;
  return '<svg class="stru-svg-ring" width="36" height="36" viewBox="0 0 36 36"><circle cx="18" cy="18" r="'+r+'" fill="none" stroke="#e5e7eb" stroke-width="2.8"/><circle cx="18" cy="18" r="'+r+'" fill="none" stroke="'+color+'" stroke-width="2.8" stroke-dasharray="'+c+'" stroke-dashoffset="'+off+'" transform="rotate(-90,18,18)" stroke-linecap="round"/><text x="18" y="18" text-anchor="middle" dominant-baseline="central" font-size="10" font-weight="800" fill="'+color+'">'+(label||pct)+'</text></svg>';
}

/* ---- Event Source Bar (visual breakdown for a topic) ---- */
function makeEventSourceBar(es){
  if(!es)return '';
  var total=0,items=[];
  var sources={chat_questions:'chat',quiz_failures:'quiz',repeated_views:'views',assignment_failures:'assign',issue_reports:'issues'};
  var icons={chat_questions:'forum',quiz_failures:'quiz',repeated_views:'visibility',assignment_failures:'assignment',issue_reports:'flag'};
  var cols={chat_questions:'var(--u-p)',quiz_failures:'#dc2626',repeated_views:'#f59e0b',assignment_failures:'#8b5cf6',issue_reports:'#ef4444'};
  Object.keys(sources).forEach(function(k){total+=es[k]||0;});
  if(!total)return '';
  Object.keys(sources).forEach(function(k){
    var v=es[k]||0;
    if(v>0)items.push({key:k,val:v,pct:Math.round(v/total*100),label:sources[k],icon:icons[k],color:cols[k]});
  });
  return '<div class="stru-event-bar" title="Struggle signal sources">'+
    items.map(function(i){
      return '<span class="stru-event-seg" style="flex:'+i.val+';background:'+i.color+';" title="'+i.label+': '+i.val+'"></span>';
    }).join('')+
    '<span class="stru-event-label">'+
    items.map(function(i){
      return '<span class="stru-event-tag" style="color:'+i.color+';"><span class="material-symbols-outlined" style="font-size:10px;">'+i.icon+'</span>'+i.val+'</span>';
    }).join('')+
    '</span></div>';
}

/* ---- Severity Donut Chart (SVG) ---- */
function makeSeverityDonut(high,med,low,total){
  if(!total)return '<div style="text-align:center;color:var(--u-ol);font-size:11px;">No topics</div>';
  var hF=high/total,mF=med/total,lF=low/total;
  var r=20,per=2*Math.PI*r,circ=per;
  var hLen=hF*per,mLen=mF*per,lLen=lF*per;
  var dashH=hLen>0?hLen+','+(circ-hLen):'0,'+circ;
  var dashM=mLen>0?mLen+','+(circ-mLen):'0,'+circ;
  var dashL=lLen>0?lLen+','+(circ-lLen):'0,'+circ;
  var offH=0,offM=-hLen,offL=-(hLen+mLen);
  return '<div class="stru-donut-wrap"><svg class="stru-donut-svg" width="56" height="56" viewBox="0 0 44 44"><circle cx="22" cy="22" r="'+r+'" fill="none" stroke="#e5e7eb" stroke-width="3.5"/>'+
    (hLen>0?'<circle cx="22" cy="22" r="'+r+'" fill="none" stroke="#dc2626" stroke-width="3.5" stroke-dasharray="'+dashH+'" stroke-dashoffset="'+offH+'" transform="rotate(-90,22,22)" stroke-linecap="round"/>':'')+
    (mLen>0?'<circle cx="22" cy="22" r="'+r+'" fill="none" stroke="#f59e0b" stroke-width="3.5" stroke-dasharray="'+dashM+'" stroke-dashoffset="'+offM+'" transform="rotate(-90,22,22)" stroke-linecap="round"/>':'')+
    (lLen>0?'<circle cx="22" cy="22" r="'+r+'" fill="none" stroke="#16a34a" stroke-width="3.5" stroke-dasharray="'+dashL+'" stroke-dashoffset="'+offL+'" transform="rotate(-90,22,22)" stroke-linecap="round"/>':'')+
    '<text x="22" y="22" text-anchor="middle" dominant-baseline="central" font-size="12" font-weight="800" fill="var(--u-ons)">'+total+'</text></svg><div class="stru-donut-legend"><span class="stru-legend-h"><span class="stru-legend-dot" style="background:#dc2626;"></span>High <strong>'+high+'</strong></span><span class="stru-legend-m"><span class="stru-legend-dot" style="background:#f59e0b;"></span>Med <strong>'+med+'</strong></span><span class="stru-legend-l"><span class="stru-legend-dot" style="background:#16a34a;"></span>Low <strong>'+low+'</strong></span></div></div>';
}

/* Struggle -- course overlay selector */
var lecStruggleCourseId = 0;
function populateStruggleCourseSel(){
  var list=document.getElementById('stru-cs-list');
  if(!list||!UD||!UD.courses)return;
  if(list._struPickerInited)return;list._struPickerInited=true;
  list.innerHTML='<button class="umat-cs-item" data-cid="0" type="button">'+
    '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">apps</span></div>'+
    '<div class="umat-cs-item-info"><div class="umat-cs-item-name">All Courses</div><div class="umat-cs-item-code">Composite overview</div></div></button>'+
  UD.courses.map(function(c){
    return '<button class="umat-cs-item" data-cid="'+c.id+'" type="button">'+
      '<div class="umat-cs-item-icon"><span class="material-symbols-outlined">psychology</span></div>'+
      '<div class="umat-cs-item-info"><div class="umat-cs-item-name">'+esc(c.fullname)+'</div>'+
      '<div class="umat-cs-item-code">'+esc(c.shortname)+'</div></div>'+
      '<span class="umat-cs-item-check material-symbols-outlined">check_circle</span></button>';
  }).join('');
  list.querySelectorAll('.umat-cs-item').forEach(function(btn){
    btn.addEventListener('click',function(){
      var cid=parseInt(this.dataset.cid);
      lecStruggleCourseId=cid||0;
      document.getElementById('stru-cs-ov').classList.remove('open');
      struggleCache['0']=false;
      if(cid)loadStruggleInsights(cid);else{struggleCache['0']=false;loadStruggleInsights(0);}
    });
  });
  var srch=document.getElementById('stru-cs-search');
  if(srch)srch.addEventListener('input',function(){
    var q=this.value.toLowerCase();
    list.querySelectorAll('.umat-cs-item').forEach(function(it){
      it.style.display=(!q||it.textContent.toLowerCase().includes(q))?'':'none';
    });
  });
  var closeBtn=document.getElementById('stru-cs-close');
  if(closeBtn)closeBtn.addEventListener('click',function(){document.getElementById('stru-cs-ov').classList.remove('open');});
  var ov=document.getElementById('stru-cs-ov');
  if(ov)ov.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
  var btn=document.getElementById('stru-cs-btn');
  if(btn)btn.addEventListener('click',function(){openStrugglePicker();});
}
function openStrugglePicker(){
  var ov=document.getElementById('stru-cs-ov');
  if(ov)ov.classList.add('open');
}

/* Compact panel lecturer AI send (streaming) */
var lcpSelMats=[];
function sendLecQ(q){
  q=(q||'').trim();if(!q)return;
  if(!CID){_umatAppendAi('lcp-msgs','Please open a course page first to ask about its analytics.',[]);return;}
  var replyTxt=(typeof _getReplyContext==='function')?_getReplyContext():null;
  if(replyTxt){q='[Replying to: "'+replyTxt+'"] '+q;_clearReplyContext();var rp=document.getElementById('umat-reply-preview');if(rp)rp.remove();}
  if(lcpSelMats.length>0){q='[Referencing: '+lcpSelMats.map(function(m){return m.name;}).join(', ')+'] '+q;}
  _umatAppendUser('lcp-msgs',q,lcpSelMats);
  var inp=document.getElementById('lcp-input');if(inp)inp.value='';
  var tid='lt_'+Date.now();_umatShowTyping('lcp-msgs',tid);
  
  _umatStreamChat({
    url: streamUrl,
    sesskey: moodleSesskey,
    courseid: CID,
    question: q,
    session_key: lecSessionKey || ('lec_cp_' + CID),
    material_ids: lcpSelMats.map(function(m){return m.id;}),
    msgsId: 'lcp-msgs',
    sendBtnId: 'lcp-send',
    sendInputId: 'lcp-input',
    typingId: tid,
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
  var replyTxt=(typeof _getReplyContext==='function')?_getReplyContext():null;
  if(replyTxt){q='[Replying to: "'+replyTxt+'"] '+q;_clearReplyContext();var rp=document.getElementById('umat-reply-preview');if(rp)rp.remove();}
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
    sendBtnId: 'lec-mini-send',
    sendInputId: 'lec-mini-input',
    typingId: tid,
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
/* Populate course selectors */
populateLibCourseSel();
populateAnalyticsCourseSel();
populateStruggleCourseSel();
/* Auto-load analytics + struggle when overlay opens */
if(expand)expand.addEventListener('click',function(){setTimeout(function(){
  var anCid=CID||lecAnalyticsCourseId;
  if(!lecLoaded['lec-analytics']){lecLoaded['lec-analytics']=true;loadAnalytics(anCid);}
  var stCid=CID||lecStruggleCourseId;
  if(struggleCache[stCid||'0'])return;
  struggleCache[stCid||'0']=false;
  struggleCache['0']=false;
  loadStruggleInsights(stCid);
},100);});
/* Lecturer issue badge: hide immediately on interaction (re-shown by pollIssueCount on 30s timer or by updateLecturerIssueBadge after messages marked read) */
function _umatLecMarkViewed(){
  var b=document.getElementById('sb-badge-new-issues');if(b){b.textContent='';b.style.display='none';}
  var mb=document.getElementById('gtb-lec-issues');if(mb){mb.textContent='';mb.style.display='none';}
}
function updateLecturerIssueBadge(c){
  var b=document.getElementById('sb-badge-new-issues');
  if(b){b.textContent=c>99?'99+':(c||'');b.style.display=c?'':'none';}
  var mb=document.getElementById('gtb-lec-issues');
  if(mb){mb.textContent=c>99?'99+':(c||'');mb.style.display=c?'':'none';}
}
function pollIssueCount(){
  var ecid=CID||0;
  ajax('local_umat_ai_get_issue_unread_count',{inbox:'lecturer',courseid:ecid},function(r){updateLecturerIssueBadge(r.count||0);},function(){});
}
pollIssueCount();
var _lecBadgeTimer=setInterval(pollIssueCount,30000);

/* Lecturer compact panel attachment drawer */
_umatInitAttachDrawer({
  getCourseId:function(){return CID||0;},
  drawerId:'lcp-attach-drawer',
  attachBtnId:'lcp-attach-btn',
  closeBtnId:'lcp-drawer-close',
  clearId:'lcp-drawer-clear',
  searchId:'lcp-drawer-search',
  catsId:'lcp-drawer-cats',
  recentId:'lcp-drawer-recent',
  listId:'lcp-drawer-list',
  confirmId:'lcp-drawer-confirm',
  countId:'lcp-drawer-count',
  maxSelections:20,
  onConfirm:function(mats){lcpSelMats=mats;_umatRenderMatsBar('lcp-mat-bar','lcp-attach-btn',lcpSelMats,function(id){lcpSelMats=lcpSelMats.filter(function(s){return s.id!=id;});return lcpSelMats;});}
});

/* ESC: close nested-first, root-last */
_umatInitEsc([
  {id:'lec-ai-mini',isOpen:function(e){return e.style.display==='flex';},close:function(e){e.style.display='none';}},
  {id:'lec-ov',isOpen:function(e){return e.classList.contains('open');},close:closeDash},
  {id:'lec-cp-ov',isOpen:function(e){return e.classList.contains('open');},close:function(e){e.classList.remove('open');}}
]);

/* ─── RESOURCE BANK (Private Materials) ─── */
var RB = {
  currentFolder: null,
  selected: {},
  _loaded: false,
  _rbAjax: function(m,a,d,f){ajax('local_umat_ai_resource_bank_'+m,a,function(r){if(d)d(r);},function(e){if(f)f(e);else console.error('[rb]',m,e);});},
  /* Format file size */
  _fmtSize: function(b){if(!b)return '';if(b<1024)return b+'B';if(b<1048576)return (b/1024).toFixed(1)+'KB';return (b/1048576).toFixed(1)+'MB';},
  /* Time ago */
  _timeAgo: function(ts){var d=Date.now()/1000-ts;if(d<60)return 'just now';if(d<3600)return Math.floor(d/60)+'m ago';if(d<86400)return Math.floor(d/3600)+'h ago';return Math.floor(d/86400)+'d ago';},
  /* Breadcrumb trail */
  _trail: [],
  /* Load contents of a folder (null = root) */
  load: function(parentId){
    RB.currentFolder = parentId;
    RB.selected = {};
    _updateRbBatchBtns();
    var gc=document.getElementById('rb-content');
    if(gc)gc.innerHTML = '<div class="rb-loading">Loading…</div>';
    RB._rbAjax('list',{parentid:parentId||0},function(r){
      _renderRbItems(r.items||[]);
    },function(e){
      console.error('[rb] load failed:',e);
      if(gc){gc.innerHTML='<div class="rb-empty"><span class="material-symbols-outlined">error</span><p>Failed to load resources. Check your permissions or try refreshing.</p><button class="umat-chip rb-retry-btn" type="button">Retry</button></div>';var rBtn=gc.querySelector('.rb-retry-btn');if(rBtn){rBtn.addEventListener('click',function(){RB.load(RB.currentFolder);});T.makeTouchable(rBtn);}}
    });
  },
  /* Build breadcrumb from trail */
  _renderBreadcrumb: function(){
    var el=document.getElementById('rb-breadcrumb');
    if(!el)return;
    var html='<span style="cursor:pointer;color:var(--u-p);font-weight:600;" data-rb-root="1">My Resources</span>';
    RB._trail.forEach(function(t,i){
      html+=' <span style="color:var(--u-olv);">/</span> <span style="cursor:pointer;color:var(--u-p);" data-rb-folder="'+t.id+'">'+esc(t.name)+'</span>';
    });
    el.innerHTML=html;
    /* Click root */
    var root=el.querySelector('[data-rb-root]');
    if(root)root.addEventListener('click',function(){RB._trail=[];RB.load(null);});
    /* Click trail folders */
    el.querySelectorAll('[data-rb-folder]').forEach(function(s){
      s.addEventListener('click',function(){
        var fid=parseInt(this.dataset.rbFolder);
        var idx=RB._trail.findIndex(function(t){return t.id===fid;});
        if(idx>=0)RB._trail=RB._trail.slice(0,idx);
        RB.load(fid);
      });
    });
  },
  /* Navigate into a folder */
  openFolder: function(id,name){
    RB._trail.push({id:id,name:name});
    RB.load(id);
  },
  /* Toggle library view with active tab indicator (pill style) */
  switchView: function(view){
    document.querySelectorAll('.umat-lib-toggle').forEach(function(b){
      b.classList.toggle('active',b.dataset.libview===view);
    });
    var cv=document.getElementById('lec-lib-course-view');
    if(cv)cv.style.display=view==='course'?'':'none';
    var pv=document.getElementById('lec-private-bank-view');
    if(pv)pv.style.display=view==='private'?'flex':'none';
    if(view==='private'&&!RB._loaded){RB._loaded=true;RB.load(null);}
  }
};

function _renderRbItems(items){
  var g=document.getElementById('rb-content');
  if(!g)return;
  RB._renderBreadcrumb();
  if(!items.length){
    g.innerHTML='<div class="rb-empty"><span class="material-symbols-outlined">folder_off</span><p>This folder is empty.</p></div>';
    return;
  }
  var html='<div class="rb-grid">';
  items.forEach(function(it){
    var icon=it.isfolder?'folder':'description';
    var iconCls=it.isfolder?'rb-folder-icon':'rb-file-icon';
    var sizeLabel=it.isfolder?'':RB._fmtSize(it.filesize);
    var timeLabel=RB._timeAgo(it.timecreated);
    html+='<div class="rb-item" data-rb-id="'+it.id+'" data-rb-folder="'+(it.isfolder?1:0)+'" data-rb-name="'+esc(it.name)+'" data-rb-fileurl="'+esc(it.fileurl||'')+'" data-rb-mime="'+esc(it.mimetype||'')+'">'
      +'<label class="rb-chk" style="position:absolute;top:8px;left:8px;z-index:2;cursor:pointer;display:none;">'
      +'<input type="checkbox" class="rb-cb" data-rb-id="'+it.id+'" style="accent-color:var(--u-p);width:16px;height:16px;cursor:pointer;"></label>'
      +'<div class="rb-thumb" style="background:'+(it.isfolder?'rgba(0,107,47,.08)':'rgba(99,102,241,.08)')+';border-radius:var(--u-r8);width:100%;aspect-ratio:1.6;display:flex;align-items:center;justify-content:center;font-size:32px;color:'+(it.isfolder?'var(--u-p)':'#6366f1')+';position:relative;">'
      +'<span class="material-symbols-outlined">'+icon+'</span>'
      +(it.isfolder?'':'<span class="umat-ext-label">'+_getExtLabel(it.name)+'</span>')
      +'</div>'
      +'<div class="rb-info" style="padding:6px 4px;">'
      +'<div class="rb-name" style="font-size:12px;font-weight:600;color:var(--u-ons);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(it.name)+'">'+esc(it.name)+'</div>'
      +'<div class="umat-text-label" style="margin-top:2px;">'+(it.isfolder?'Folder':sizeLabel+(timeLabel?' \u00b7 '+timeLabel:''))+'</div>'
      +'</div>'
      +'<div class="rb-actions" style="display:flex;gap:4px;padding:4px 4px 0;border-top:1px solid var(--u-olv);margin-top:4px;">'
      +'<button type="button" class="rb-action-push" data-rb-id="'+it.id+'" title="Push to course" style="flex:1;display:inline-flex;align-items:center;justify-content:center;gap:3px;padding:3px 6px;border:none;border-radius:4px;font-size:10px;font-weight:600;color:var(--u-p);background:rgba(0,107,47,.08);cursor:pointer;font-family:inherit;"><span class="material-symbols-outlined" style="font-size:12px;">publish</span>Push</button>'
      +'<button type="button" class="rb-action-del" data-rb-id="'+it.id+'" data-rb-name="'+esc(it.name)+'" title="Delete" style="flex:0;display:inline-flex;align-items:center;justify-content:center;gap:3px;padding:3px 6px;border:none;border-radius:4px;font-size:10px;font-weight:600;color:var(--u-ter);background:rgba(239,68,68,.08);cursor:pointer;font-family:inherit;"><span class="material-symbols-outlined" style="font-size:12px;">delete</span></button>'
      +'</div></div>';
  });
  html+='</div>';
  g.innerHTML=html;

  /* Click handlers */
  g.querySelectorAll('.rb-item').forEach(function(el){
    el.addEventListener('click',function(e){
      if(e.target.closest('.rb-chk'))return;
      var id=parseInt(this.dataset.rbId);
      var isFolder=this.dataset.rbFolder==='1';
      if(isFolder){
        RB.openFolder(id,this.dataset.rbName);
      } else {
        /* Preview file */
        var url=this.dataset.rbFileurl;
        var name=this.dataset.rbName;
        var mime=this.dataset.rbMime||'';
        if(url&&window.umatMaterialViewer){
          var viewType=_mapMimeToViewer(mime,name);
          window.umatMaterialViewer.open(viewType,{url:url,name:name,downloadUrl:url});
        }
      }
    });
    /* Right-click for context menu (rename, delete) */
    el.addEventListener('contextmenu',_rbCtxHandler);
  });

  /* Per-item Push button */
  g.querySelectorAll('.rb-action-push').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var id=parseInt(this.dataset.rbId);
      RB.selected={};
      RB.selected[id]=true;
      _updateRbBatchBtns();
      var rbPushBtn=document.getElementById('rb-push-btn');
      if(rbPushBtn)rbPushBtn.click();
    });
  });
  /* Per-item Delete button */
  g.querySelectorAll('.rb-action-del').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      var id=parseInt(this.dataset.rbId);
      var name=this.dataset.rbName;
      if(!confirm('Delete "'+name+'"? This cannot be undone.'))return;
      RB._rbAjax('delete',{itemids:[id]},function(){RB.load(RB.currentFolder);},function(e){
        console.error('[rb] single delete failed:',e);
        alert('Delete failed: '+(e.message||e.error||'Unknown error'));
      });
    });
  });

  /* Checkbox change → update batch buttons */
  g.querySelectorAll('.rb-cb').forEach(function(cb){
    cb.addEventListener('change',function(){
      if(this.checked)RB.selected[parseInt(this.dataset.rbId)]=true;
      else delete RB.selected[parseInt(this.dataset.rbId)];
      _updateRbBatchBtns();
    });
  });

  /* Show checkboxes on first selection attempt */
  g.querySelectorAll('.rb-item').forEach(function(el){
    el.addEventListener('dblclick',function(e){
      if(e.target.closest('.rb-chk'))return;
      if(!el.querySelector('.rb-chk').style.display||el.querySelector('.rb-chk').style.display==='none'){
        _showRbCheckboxes();
      }
    });
  });
}

function _getExtLabel(name){
  var ext=(name||'').split('.').pop().toUpperCase();
  if(ext.length>4)return 'FILE';
  return ext||'FILE';
}

function _mapMimeToViewer(mime,name){
  if(mime.includes('video'))return 'video';
  if(mime.includes('pdf'))return 'pdf';
  if(mime.includes('image'))return 'image';
  if(mime.includes('audio'))return 'audio';
  if(mime.includes('word')||mime.includes('document')||/\.docx?$/i.test(name))return 'docx';
  if(mime.includes('spreadsheet')||mime.includes('excel')||/\.xlsx?$/i.test(name))return 'xlsx';
  if(mime.includes('presentation')||mime.includes('powerpoint')||/\.pptx?$/i.test(name))return 'pptx';
  if(mime.includes('text')||mime.includes('json')||mime.includes('javascript'))return 'code';
  return 'pdf';
}

function _showRbCheckboxes(){
  document.querySelectorAll('#rb-content .rb-chk').forEach(function(el){el.style.display='block';});
}

function _toggleRbCheckMode(){
  var els=document.querySelectorAll('#rb-content .rb-chk');
  var hidden=!els.length||els[0].style.display==='none'||!els[0].style.display;
  els.forEach(function(el){el.style.display=hidden?'block':'none';});
  if(!hidden){RB.selected={};_updateRbBatchBtns();}
}

function _updateRbBatchBtns(){
  var count=Object.keys(RB.selected).length;
  var hasSel=count>0;
  ['rb-delete-btn','rb-push-btn'].forEach(function(id){
    var btn=document.getElementById(id);
    if(!btn)return;
    btn.disabled=!hasSel;
    btn.style.opacity=hasSel?'1':'.4';
    btn.innerHTML=(id==='rb-push-btn'?'<span class="material-symbols-outlined" style="font-size:14px;">publish</span>Push to Course':'<span class="material-symbols-outlined" style="font-size:14px;">delete</span>Delete')
      +(hasSel?' ('+count+')':'');
  });
}

/* Toggle Library views */
document.querySelectorAll('.umat-lib-toggle').forEach(function(btn){
  btn.addEventListener('click',function(){RB.switchView(this.dataset.libview);});
});

/* ── RB: Upload overlay (drag & drop) ── */
var rbUploadBtn=document.getElementById('rb-upload-btn');
var rbUploadOv=document.getElementById('rb-upload-ov');
var rbUploadDropzone=document.getElementById('rb-upload-dropzone');
var rbUploadProgress=document.getElementById('rb-upload-progress');
var rbUploadBar=document.getElementById('rb-upload-bar');
var rbUploadPct=document.getElementById('rb-upload-pct');
var rbUploadFname=document.getElementById('rb-upload-fname');
var rbUploadResult=document.getElementById('rb-upload-result');

/* Helper: upload files via XHR with progress */
function _uploadRbFiles(files){
  var total=files.length,done=0;
  if(rbUploadProgress)rbUploadProgress.style.display='block';
  if(rbUploadResult)rbUploadResult.style.display='none';
  files.forEach(function(file,i){
    var fd=new FormData();
    fd.append('file',file);
    fd.append('parentid',RB.currentFolder||0);
    fd.append('sesskey',moodleSesskey);
    var xhr=new XMLHttpRequest();
    xhr.open('POST',(window.M&&window.M.cfg?window.M.cfg.wwwroot:'')+'/local/umat_ai/resource_upload.php');
    if(rbUploadFname)xhr.upload&&(xhr.upload.onprogress=function(e){
      if(e.lengthComputable&&rbUploadBar&&rbUploadPct){
        var p=Math.round((done*100000+e.loaded/e.total*100)/total);
        rbUploadBar.style.width=p+'%';rbUploadPct.textContent=Math.round(p)+'%';
      }
    });
    xhr.onload=function(){
      done++;
      try{var resp=JSON.parse(xhr.responseText);if(resp&&resp.success===false){console.error('[rb] upload error:',resp.message);if(rbUploadResult){rbUploadResult.style.display='block';rbUploadResult.style.background='#fee2e2';rbUploadResult.style.color='#991b1b';rbUploadResult.textContent='Upload failed: '+(resp.message||'Unknown error');}return;}}catch(ex){}
      if(done>=total){RB.load(RB.currentFolder);_closeRbUpload();}
    };
    xhr.onerror=function(){done++;console.error('[rb] upload network error');if(rbUploadResult){rbUploadResult.style.display='block';rbUploadResult.style.background='#fee2e2';rbUploadResult.style.color='#991b1b';rbUploadResult.textContent='Network error during upload.';}if(done>=total){RB.load(RB.currentFolder);_closeRbUpload();}};
    xhr.send(fd);
  });
}
function _closeRbUpload(){
  if(rbUploadOv)rbUploadOv.style.display='none';
  if(rbUploadProgress)rbUploadProgress.style.display='none';
  if(rbUploadResult)rbUploadResult.style.display='none';
  if(rbUploadBar)rbUploadBar.style.width='0%';
  if(rbUploadPct)rbUploadPct.textContent='0%';
}
if(rbUploadBtn)rbUploadBtn.addEventListener('click',function(){if(rbUploadOv)rbUploadOv.style.display='flex';});
/* Upload overlay: dropzone click → hidden file input */
var _rbUpFileInput=null;
if(rbUploadDropzone){
  rbUploadDropzone.addEventListener('click',function(){
    var fi=document.createElement('input');fi.type='file';fi.multiple=true;fi.style.display='none';
    fi.addEventListener('change',function(){if(this.files.length){_uploadRbFiles(Array.from(this.files));document.body.removeChild(fi);}});
    document.body.appendChild(fi);fi.click();
  });
  rbUploadDropzone.addEventListener('dragover',function(e){e.preventDefault();this.style.borderColor='var(--u-p)';this.style.background='rgba(0,107,47,.05)';});
  rbUploadDropzone.addEventListener('dragleave',function(){this.style.borderColor='var(--u-olv)';this.style.background='var(--u-sflo)';});
  rbUploadDropzone.addEventListener('drop',function(e){
    e.preventDefault();this.style.borderColor='var(--u-olv)';this.style.background='var(--u-sflo)';
    if(e.dataTransfer.files.length)_uploadRbFiles(Array.from(e.dataTransfer.files));
  });
}
var rbUploadClose=document.getElementById('rb-upload-close');
if(rbUploadClose)rbUploadClose.addEventListener('click',_closeRbUpload);
var rbUploadCancel=document.getElementById('rb-upload-cancel');
if(rbUploadCancel)rbUploadCancel.addEventListener('click',_closeRbUpload);

/* ── RB: Folder creation overlay ── */
var rbFolderOv=document.getElementById('rb-folder-ov');
var rbFolderName=document.getElementById('rb-folder-name');
var rbFolderSubmit=document.getElementById('rb-folder-submit');
var rbNewFolderBtn=document.getElementById('rb-new-folder-btn');
if(rbNewFolderBtn)rbNewFolderBtn.addEventListener('click',function(){
  if(rbFolderName)rbFolderName.value='';
  if(rbFolderOv)rbFolderOv.style.display='flex';
  if(rbFolderName)setTimeout(function(){rbFolderName.focus();},100);
});
if(rbFolderSubmit)rbFolderSubmit.addEventListener('click',function(){
  var name=rbFolderName?rbFolderName.value.trim():'';
  if(!name)return;
  RB._rbAjax('create_folder',{parentid:RB.currentFolder||0,name:name},function(){
    if(rbFolderOv)rbFolderOv.style.display='none';
    RB.load(RB.currentFolder);
  },function(e){
    console.error('[rb] create_folder failed:',e);
    var msg=document.getElementById('rb-folder-ov');
    if(msg){var r=msg.querySelector('.umat-cs-modal');if(r){var d=r.querySelector('div:last-child');if(d){var err=document.createElement('div');err.style.cssText='padding:8px;margin-top:8px;background:#fee2e2;color:#991b1b;border-radius:6px;font-size:12px;';err.textContent='Failed to create folder: '+(e.message||e.error||'Unknown error');d.appendChild(err);setTimeout(function(){err.remove();},4000);}}}
  });
});
var rbFolderClose=document.getElementById('rb-folder-close');
if(rbFolderClose)rbFolderClose.addEventListener('click',function(){if(rbFolderOv)rbFolderOv.style.display='none';});
var rbFolderCancel=document.getElementById('rb-folder-cancel');
if(rbFolderCancel)rbFolderCancel.addEventListener('click',function(){if(rbFolderOv)rbFolderOv.style.display='none';});
/* Enter key in folder name input */
if(rbFolderName)rbFolderName.addEventListener('keydown',function(e){if(e.key==='Enter'&&rbFolderSubmit)rbFolderSubmit.click();});

/* ── RB: Rename overlay ── */
var _rbRenameId=null;
var rbRenameOv=document.getElementById('rb-rename-ov');
var rbRenameName=document.getElementById('rb-rename-name');
var rbRenameSubmit=document.getElementById('rb-rename-submit');
function _openRbRename(id,name){
  _rbRenameId=id;
  if(rbRenameName)rbRenameName.value=name;
  if(rbRenameOv)rbRenameOv.style.display='flex';
  if(rbRenameName)setTimeout(function(){rbRenameName.focus();rbRenameName.select();},100);
}
var rbRenameClose=document.getElementById('rb-rename-close');
if(rbRenameClose)rbRenameClose.addEventListener('click',function(){if(rbRenameOv)rbRenameOv.style.display='none';});
var rbRenameCancel=document.getElementById('rb-rename-cancel');
if(rbRenameCancel)rbRenameCancel.addEventListener('click',function(){if(rbRenameOv)rbRenameOv.style.display='none';});
if(rbRenameSubmit)rbRenameSubmit.addEventListener('click',function(){
  var name=rbRenameName?rbRenameName.value.trim():'';
  if(!name||!_rbRenameId)return;
  RB._rbAjax('rename',{itemid:_rbRenameId,name:name},function(){
    if(rbRenameOv)rbRenameOv.style.display='none';
    _rbRenameId=null;
    RB.load(RB.currentFolder);
  },function(e){
    console.error('[rb] rename failed:',e);
    alert('Rename failed: '+(e.message||e.error||'Unknown error'));
  });
});
if(rbRenameName)rbRenameName.addEventListener('keydown',function(e){if(e.key==='Enter'&&rbRenameSubmit)rbRenameSubmit.click();});

/* ── Right-click context menu for rename/delete ── */
function _rbCtxHandler(e){
  e.preventDefault();
  var el=this;
  var id=parseInt(el.dataset.rbId);
  var name=el.dataset.rbName;
  /* Remove any existing context menu */
  var old=document.querySelector('.rb-ctx-menu');
  if(old)old.remove();
  /* Build context menu */
  var menu=document.createElement('div');
  menu.className='rb-ctx-menu';
  menu.style.cssText='position:fixed;z-index:9999;background:var(--u-bg);border:1px solid var(--u-olv);border-radius:var(--u-r8);padding:4px 0;min-width:140px;box-shadow:0 4px 20px rgba(0,0,0,.15);font-size:12px;';
  /* Rename option */
  var renameItem=document.createElement('div');
  renameItem.textContent='✏️ Rename';
  renameItem.style.cssText='padding:8px 14px;cursor:pointer;display:flex;align-items:center;gap:6px;color:var(--u-ons);';
  renameItem.addEventListener('mouseenter',function(){this.style.background='var(--u-sflo)';});
  renameItem.addEventListener('mouseleave',function(){this.style.background='transparent';});
  renameItem.addEventListener('click',function(){
    menu.remove();
    _openRbRename(id,name);
  });
  menu.appendChild(renameItem);
  /* Delete option */
  var delItem=document.createElement('div');
  delItem.textContent='🗑️ Delete';
  delItem.style.cssText='padding:8px 14px;cursor:pointer;display:flex;align-items:center;gap:6px;color:var(--u-ter);';
  delItem.addEventListener('mouseenter',function(){this.style.background='var(--u-sflo)';});
  delItem.addEventListener('mouseleave',function(){this.style.background='transparent';});
  delItem.addEventListener('click',function(){
    menu.remove();
    if(!confirm('Delete "'+name+'"? This cannot be undone.'))return;
    RB._rbAjax('delete',{itemids:[id]},function(){RB.load(RB.currentFolder);},function(e){
      console.error('[rb] delete failed:',e);
      alert('Delete failed: '+(e.message||e.error||'Unknown error'));
    });
  });
  menu.appendChild(delItem);
  /* Position menu */
  menu.style.left=Math.min(e.clientX,document.documentElement.clientWidth-160)+'px';
  menu.style.top=Math.min(e.clientY,document.documentElement.clientHeight-80)+'px';
  document.body.appendChild(menu);
  /* Click outside closes */
  setTimeout(function(){
    document.addEventListener('click',function _closeCtx(ev){
      if(!menu.contains(ev.target)){menu.remove();document.removeEventListener('click',_closeCtx);}
    });
  },0);
}

/* ── RB: Delete selected ── */
var rbDeleteBtn=document.getElementById('rb-delete-btn');
if(rbDeleteBtn)rbDeleteBtn.addEventListener('click',function(){
  var ids=Object.keys(RB.selected).map(Number);
  if(!ids.length)return;
  if(!confirm('Delete '+ids.length+' item(s)? This cannot be undone.'))return;
  RB._rbAjax('delete',{itemids:ids},function(r){
    RB.load(RB.currentFolder);
  },function(e){
    console.error('[rb] batch delete failed:',e);
    alert('Delete failed: '+(e.message||e.error||'Unknown error'));
  });
});

/* ── RB: Push to course ── */
var rbPushBtn=document.getElementById('rb-push-btn');
if(rbPushBtn)rbPushBtn.addEventListener('click',function(){
  var ids=Object.keys(RB.selected).map(Number);
  if(!ids.length)return;
  var ov=document.getElementById('rb-push-ov');
  var list=document.getElementById('rb-push-list');
  if(!ov||!list)return;
  ov.style.display='flex';
  list.innerHTML='<div class="umat-cs-item" style="opacity:.5;pointer-events:none;">Loading courses…</div>';
  RB._rbAjax('teaching_courses',{},function(r){
    var courses=r.courses||[];
    if(!courses.length){
      list.innerHTML='<div class="umat-cs-item" style="opacity:.5;pointer-events:none;">No courses available.</div>';
      return;
    }
    list.innerHTML=courses.map(function(c){
      return '<div class="umat-cs-item" data-cid="'+c.id+'"><span class="material-symbols-outlined">menu_book</span>'
        +'<div><strong>'+esc(c.fullname)+'</strong><span style="font-size:11px;color:var(--u-ol);display:block;">'+esc(c.shortname)+'</span></div>'
        +'<span class="umat-cs-check" style="margin-left:auto;">radio_button_unchecked</span></div>';
    }).join('');
    var selectedCid=null;
    list.querySelectorAll('.umat-cs-item').forEach(function(el){
      el.addEventListener('click',function(){
        list.querySelectorAll('.umat-cs-item').forEach(function(e){e.classList.remove('selected');e.querySelector('.umat-cs-check').textContent='radio_button_unchecked';});
        this.classList.add('selected');
        this.querySelector('.umat-cs-check').textContent='check_circle';
        selectedCid=parseInt(this.dataset.cid);
        var confirmBtn=document.getElementById('rb-push-confirm');
        if(confirmBtn){confirmBtn.disabled=false;confirmBtn.style.opacity='1';confirmBtn.textContent='Push ('+ids.length+') to '+esc(this.querySelector('strong').textContent);}
      });
    });
    var pushSearch=document.getElementById('rb-push-search');
    if(pushSearch)pushSearch.addEventListener('input',function(){
      var q=this.value.toLowerCase();
      list.querySelectorAll('.umat-cs-item').forEach(function(el){el.style.display=el.textContent.toLowerCase().includes(q)?'':'none';});
    });
    var confirmBtn=document.getElementById('rb-push-confirm');
    if(confirmBtn)confirmBtn.onclick=function(){
      if(!selectedCid)return;
      RB._rbAjax('push',{itemids:ids,courseid:selectedCid},function(r){ov.style.display='none';RB.load(RB.currentFolder);},function(e){
        console.error('[rb] push failed:',e);
        alert('Push to course failed: '+(e.message||e.error||'Unknown error'));
      });
    };
  });
});
var rbPushClose=document.getElementById('rb-push-close');
if(rbPushClose)rbPushClose.addEventListener('click',function(){var ov=document.getElementById('rb-push-ov');if(ov)ov.style.display='none';});
var rbPushCancel=document.getElementById('rb-push-cancel');
if(rbPushCancel)rbPushCancel.addEventListener('click',function(){var ov=document.getElementById('rb-push-ov');if(ov)ov.style.display='none';});

/* ── ESC key for RB overlays ── */
var _rbOverlayIds=['rb-upload-ov','rb-folder-ov','rb-rename-ov','rb-push-ov'];
document.addEventListener('keydown',function(e){
  if(e.key!=='Escape')return;
  _rbOverlayIds.forEach(function(id){
    var el=document.getElementById(id);
    if(el&&el.style.display==='flex')el.style.display='none';
  });
});

/* ── Smart Search inline in the lecturer library search bar ── */
/* Wired here (not in the dashboard boot script) so the shared helper is
   guaranteed to exist when the AMD module loads. 2+ characters triggers an
   AI results dropdown below the input; the local name filter still runs. */
var _lecLibSrch=document.getElementById('lec-lib-search');
if(_lecLibSrch && typeof _umatSmartSearch==='function'){
  _umatSmartSearch(_lecLibSrch,{
    getCourseId:function(){return lecLibCourseId||CID||0;},
    maxResults:6
  });
}

})();
}
};
});
