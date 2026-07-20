define(['local_umat_ai/umatshared','local_umat_ai/material_viewer'],function(S,M){
return{init:function(data){for(var k in S)window[k]=S[k];window.umatMaterialViewer=M;
window.renderVideoTiles=S.renderVideoTiles;window.renderCourses=S.renderCourses;
window.renderLibrary=S.renderLibrary;window.renderLibTiles=S.renderLibTiles;
(function(){
'use strict';
var courseId = data.courseId;
var courseName = data.courseName;
var userData; try { userData = JSON.parse(data.userData); } catch(e) { userData = {}; }
var streamUrl = data.streamUrl;
var moodleSesskey = data.moodleSesskey;
var sessionKey = 'stu_'+Math.random().toString(36).substr(2,18);
/* Rolling 60s rate-limit window ' + '?" mirrors the server check, refills as entries expire */
var RATE_MAX = 10;
var qTimes   = [];
var selectedMats = [];
var lecturesLoaded = false;
var libraryLoaded  = false;
var coursesLoaded  = false;
var notesLoaded    = false;
var sessionsLoaded = false;
var reportLoaded = false;
var ov = document.getElementById('umat-student-ov');

/* Time formatting helper for chat timestamps */
function _umatFmtTime(ts){
  if(!ts)return '';
  var d=new Date(ts*1000),n=new Date();
  var opts={hour:'numeric',minute:'2-digit'};
  if(d.toDateString()===n.toDateString())return d.toLocaleTimeString([],opts);
  var y=n.getFullYear();
  if(d.getFullYear()===y)return d.toLocaleDateString([],{month:'short',day:'numeric'})+' '+d.toLocaleTimeString([],opts);
  return d.toLocaleDateString([],{month:'short',day:'numeric',year:'numeric'})+' '+d.toLocaleTimeString([],opts);
}

/* ---- FAB & compact panel ---- */
var fab     = document.getElementById('umat-stu-fab');
var cpOv    = document.getElementById('stu-cp-ov');
var cpClose = document.getElementById('stu-cp-close');
var expBtn  = document.getElementById('stu-expand-btn');

function updateBodyLock(){document.body.classList.toggle('umat-body-lock',!(!document.querySelector('.umat-ov.open,.umat-cp-ov.open')));}

if(fab)fab.addEventListener('click', function(){ if(window.innerWidth<640){ cpOv.classList.remove('open'); ov.classList.add('open'); switchPane('home'); initHome(); } else { cpOv.classList.add('open'); } updateRate(); checkConn(); initCpNotes(); updateBodyLock(); }); else console.warn('UMaT: #umat-stu-fab not found');
if(cpClose)cpClose.addEventListener('click', function(){ cpOv.classList.remove('open'); updateBodyLock(); });
if(cpOv)cpOv.addEventListener('click', function(e){ if(e.target===cpOv){ cpOv.classList.remove('open'); updateBodyLock(); } });
if(expBtn)expBtn.addEventListener('click', function(){ cpOv.classList.remove('open'); openOverlay(); });

var sbNew=document.getElementById('sb-new-btn'); if(sbNew)sbNew.addEventListener('click', newSession);
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
  _umatCloseQuiz();qz.data=null;qz.answers={};qz.graded={};qz.idx=0;
  try{sessionStorage.removeItem('qz_state');}catch(e){}
}

function openOverlay(){ ov.classList.add('open'); populateHomeTab(); updateBodyLock(); }
function closeOverlay(){ ov.classList.remove('open'); cpOv.classList.add('open'); updateBodyLock(); }
if(ov)ov.addEventListener('click',function(e){if(e.target===ov)closeOverlay();});

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
    home:['home','Home','Course snapshot'],lectures:['play_circle','Lectures','Recent recordings'],courses:['menu_book','Courses','Your enrolled courses'],library:['local_library','Library','Course materials'],sessions:['chat_bubble','Sessions','Recent AI chats'],'report-issue':['flag','Report Issue','Submit a complaint']
  }[name]||['widgets','Feature','Quick view'];
  showCpPane('cp-feature');setCpFeatureActive(name);
  document.getElementById('cp-feature-icon').textContent=meta[0];document.getElementById('cp-feature-title').textContent=meta[1];document.getElementById('cp-feature-sub').textContent=meta[2];
  var body=document.getElementById('cp-feature-body');body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading '+meta[1].toLowerCase()+'' + '?' + '</p></div>';
  if(name==='home')return renderCpHome(body);
  if(name==='courses')return renderCpCourses(body);
  if(name==='sessions')return renderCpSessions(body);
  if(name==='lectures')return renderCpLectures(body);
  if(name==='library')return renderCpLibrary(body);
  if(name==='report-issue')return renderCpReportIssue(body);
}
function renderCpReportIssue(body){
  if(!courseId){var c=(userData&&userData.courses)||[];if(!c.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses available.</p></div>';return;}body.innerHTML='<div class="umat-cp-help" style="padding:10px 14px 2px;font-size:10px;color:var(--u-ol);font-weight:600;">Select a course to report an issue:</div><div style="padding:4px 14px 6px;display:flex;flex-wrap:wrap;gap:4px;">'+c.slice(0,12).map(function(cv){return '<button class="umat-chip" data-cid="'+cv.id+'" type="button">'+_umatEsc(cv.shortname||cv.fullname)+'</button>';}).join('')+'</div>';body.querySelectorAll('.umat-chip').forEach(function(b){b.addEventListener('click',function(){courseId=parseInt(this.dataset.cid)||courseId;renderCpFeature('report-issue');});});return;}
  /* Reports list at top (WhatsApp-style), form below */
  body.innerHTML='<div id="cp-issue-list" style="padding:14px 14px 0;"></div>'
    +'<div style="padding:14px;border-top:1px solid var(--u-olv);">'
    +'<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:var(--u-ol);display:block;margin-bottom:3px;">Category</label>'
    +'<select id="cp-issue-cat" style="width:100%;padding:7px 9px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;">'
    +'<option value="concept_confusion">Concept Confusion</option><option value="material_error">Material Error</option>'
    +'<option value="technical_issue">Technical Issue</option><option value="suggestion">Suggestion</option><option value="other">Other</option></select></div>'
    +'<div style="margin-bottom:12px;"><input type="text" id="cp-issue-topic" placeholder="Topic (optional)" style="width:100%;padding:7px 9px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;"></div>'
    +'<div style="margin-bottom:12px;"><textarea id="cp-issue-desc" placeholder="Describe the issue\u2026" rows="3" style="width:100%;padding:7px 9px;border:1px solid var(--u-olv);border-radius:var(--u-r8);font-size:12px;resize:vertical;"></textarea></div>'
    +'<button class="umat-btn-p" id="cp-issue-submit" type="button" style="width:100%;justify-content:center;font-size:12px;padding:8px;"><span class="material-symbols-outlined" style="font-size:16px;">send</span>Submit</button>'
    +'<div id="cp-issue-msg" style="margin-top:6px;font-size:11px;display:none;"></div></div>';
  loadCpIssues();
  document.getElementById('cp-issue-submit').addEventListener('click',function(){
    var cat=document.getElementById('cp-issue-cat').value;
    var topic=document.getElementById('cp-issue-topic').value.trim();
    var desc=document.getElementById('cp-issue-desc').value.trim();
    var msg=document.getElementById('cp-issue-msg');
    if(desc.length<10){msg.textContent='Please provide more detail.';msg.style.display='block';msg.style.color='var(--u-ter)';return;}
    var btn=this;btn.disabled=true;btn.textContent='Submitting\u2026';
    console.log('[cp-issue] submitting cat='+cat+' cid='+courseId);
    require(['core/ajax'],function(Ajax){
      Ajax.call([{methodname:'local_umat_ai_submit_issue',args:{courseid:courseId,category:cat,topic:topic,description:desc}}])[0]
        .done(function(r){
          console.log('[cp-issue] response',r);
          if(r.success){msg.textContent='Submitted!';msg.style.display='block';msg.style.color='var(--u-sec)';document.getElementById('cp-issue-topic').value='';document.getElementById('cp-issue-desc').value='';loadCpIssues();}else{msg.textContent=r.message||'Failed.';msg.style.display='block';msg.style.color='var(--u-ter)';}
        })
        .fail(function(e){
          console.log('[cp-issue] AJAX fail',e);
          var errMsg=e&&(e.message||e.errorcode||e);
          msg.textContent=errMsg||'Connection error.';msg.style.display='block';msg.style.color='var(--u-ter)';
        })
        .always(function(){btn.disabled=false;btn.innerHTML='<span class="material-symbols-outlined" style="font-size:16px;">send</span>Submit';});
    });
  });
}
function loadCpIssues(){
  var list=document.getElementById('cp-issue-list');if(!list)return;
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_student_issues',args:{courseid:courseId||0}}])[0]
      .done(function(rows){
        if(!rows||!rows.length){list.innerHTML='<div class="umat-empty" style="padding:6px 0;"><span class="material-symbols-outlined" style="font-size:20px;">flag</span><p style="font-size:11px;">No issues reported.</p></div>';return;}
        list.innerHTML=rows.map(function(r){
          var catLabel={'concept_confusion':'Concept Confusion','material_error':'Material Error','technical_issue':'Technical Issue','suggestion':'Suggestion','other':'Other'}[r.category]||r.category;
          var ago='';if(r.timecreated){var d=Math.floor((Date.now()/1000-r.timecreated)/86400);ago=d===0?'today':d+'d ago';}
          return '<div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:8px;padding:10px;margin-bottom:6px;">'
            +'<div style="font-size:11px;font-weight:700;margin-bottom:2px;">'+_umatEsc(r.topic||catLabel)+'</div>'
            +'<p style="font-size:10px;color:var(--u-onsv);margin:0 0 2px;">'+_umatEsc(r.description.replace(/^(.{100}[^\\s]*).*$/,'$1')+(r.description.length>100?'...':''))+'</p>'
            +'<div style="font-size:9px;color:var(--u-ol);">'+catLabel+' A' + ' '+ago+'</div>'
            +(r.lecturer_response?'<div style="margin-top:4px;padding-top:4px;border-top:1px solid var(--u-olv);font-size:10px;color:var(--u-sec);"><strong>Lecturer:</strong> '+_umatEsc(r.lecturer_response)+'</div>':'')
            +'</div>';
        }).join('');
      })
      .fail(function(){list.innerHTML='<div class="umat-empty" style="padding:6px 0;"><span class="material-symbols-outlined">error</span><p style="font-size:11px;">Could not load.</p></div>';});
  });
}
function renderCpHome(body){
  var d=userData||{};var courses=d.courses||[],sessions=d.sessions||[];
  body.innerHTML='<div class="umat-cp-mini-grid">'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">chat_bubble</span><strong>'+(d.week_questions||0)+'</strong><small>questions this week</small></div>'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">history</span><strong>'+(d.week_sessions||0)+'</strong><small>sessions this week</small></div>'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">menu_book</span><strong>'+courses.length+'</strong><small>courses</small></div>'+
    '<div class="umat-cp-mini-card"><span class="material-symbols-outlined">task_alt</span><strong>'+(d.goal_progress||0)+'%</strong><small>goal progress</small></div></div>'+
    (sessions[0]?'<div class="umat-cp-list-card"><strong>Recent session</strong><p>'+_umatEsc(_umatCleanPreview(sessions[0].preview,'Continue your last chat'))+'</p></div>':'');
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
  body.innerHTML=sessions.slice(0,10).map(function(s){return '<div class="umat-cp-list-card" data-sk="'+_umatEsc(s.session_key)+'" data-cid="'+(s.courseid||courseId)+'" style="cursor:pointer;display:flex;align-items:center;gap:8px;"><div style="flex:1;min-width:0;"><strong>'+_umatEsc(s.course_name||'AI Session')+'</strong><p>'+_umatEsc(_umatCleanPreview(s.preview,'Resume chat'))+'</p><small>'+_umatEsc(s.time_label||'')+'</small></div><button class="umat-cp-del-session" type="button" title="Delete session" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--u-ter);flex-shrink:0;"><span class="material-symbols-outlined" style="font-size:18px;">delete</span></button></div>';}).join('');
  body.querySelectorAll('[data-sk]').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('.umat-cp-del-session'))return;
      sessionKey=tile.dataset.sk;courseId=parseInt(tile.dataset.cid)||courseId;showCpPane('cp-chat');var msgs=document.getElementById('cp-msgs');if(!msgs)return;msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p><em>Loading conversation history\u2026</em></p></div></div></div>';require(['core/ajax'],function(A){A.call([{methodname:'local_umat_ai_get_chat_history',args:{courseid:courseId,session_key:sessionKey,limit:50}}])[0].done(function(r){msgs.innerHTML='';var foundQuiz=null;(r.messages||[]).forEach(function(msg){if(msg.question)_umatAppendUser('cp-msgs',msg.question);if(msg.answer){var stripped=_umatStripQuizFromText(msg.answer);if(stripped.quiz)foundQuiz=stripped.quiz;_umatAppendAi('cp-msgs',stripped.text,msg.sources||[]);}});if(!(r.messages||[]).length){msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Welcome back! This session had no previous messages. Ask me anything!</p></div></div></div>';}else if(foundQuiz){_umatProcessQuiz(foundQuiz,'cp-msgs');}else{setTimeout(function(){_umatDetectQuiz('cp-msgs');},500);}}).fail(function(){msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Welcome back! Ready to continue.</p></div></div></div>';});});});
    });
    tile.querySelector('.umat-cp-del-session').addEventListener('click',function(e){
      e.stopPropagation();
      if(!confirm('Delete this conversation? This cannot be undone.'))return;
      var btn=e.currentTarget;
      btn.disabled=true;btn.innerHTML='<span class="material-symbols-outlined" style="font-size:18px;">hourglass_empty</span>';
      ajax('local_umat_ai_delete_session',{session_key:tile.dataset.sk},function(){
        tile.remove();
        if(!body.querySelector('[data-sk]')){
          body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">chat_bubble</span><p>No AI sessions yet.</p></div>';
        }
      },function(){
        btn.disabled=false;btn.innerHTML='<span class="material-symbols-outlined" style="font-size:18px;">delete</span>';
        alert('Could not delete session. Please try again.');
      });
    });
  });
}
}
function renderCpLectures(body){
  if(!courseId){var c=(userData&&userData.courses)||[];if(!c.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses found.</p></div>';return;}body.innerHTML='<div class="umat-cp-help" style="padding:10px 14px 2px;font-size:10px;color:var(--u-ol);font-weight:600;">Select a course to view recordings:</div><div style="padding:4px 14px 6px;display:flex;flex-wrap:wrap;gap:4px;">'+c.slice(0,12).map(function(cv){return '<button class="umat-chip" data-cid="'+cv.id+'" type="button">'+_umatEsc(cv.shortname||cv.fullname)+'</button>';}).join('')+'</div>';body.querySelectorAll('.umat-chip').forEach(function(b){b.addEventListener('click',function(){courseId=parseInt(this.dataset.cid)||courseId;renderCpFeature('lectures');});});return;}
  require(['core/ajax'],function(Ajax){Ajax.call([{methodname:'local_umat_ai_get_course_recordings',args:{courseid:courseId}}])[0].done(function(r){var recs=r.recordings||r||[];if(!recs.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">videocam_off</span><p>No recordings yet.</p></div>';return;}body.innerHTML=recs.slice(0,10).map(function(v){return '<button class="umat-cp-list-card as-btn" type="button"><strong>'+_umatEsc(v.title||'Lecture Recording')+'</strong><p>'+_umatEsc(v.duration||v.time_label||'Recording available')+'</p></button>';}).join('');}).fail(function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load recordings.</p></div>';});});
}
function renderCpLibrary(body){
  if(!courseId){var c=(userData&&userData.courses)||[];if(!c.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses found.</p></div>';return;}body.innerHTML='<div class="umat-cp-help" style="padding:10px 14px 2px;font-size:10px;color:var(--u-ol);font-weight:600;">Select a course to view materials:</div><div style="padding:4px 14px 6px;display:flex;flex-wrap:wrap;gap:4px;">'+c.slice(0,12).map(function(cv){return '<button class="umat-chip" data-cid="'+cv.id+'" type="button">'+_umatEsc(cv.shortname||cv.fullname)+'</button>';}).join('')+'</div>';body.querySelectorAll('.umat-chip').forEach(function(b){b.addEventListener('click',function(){courseId=parseInt(this.dataset.cid)||courseId;renderCpFeature('library');});});return;}
  require(['core/ajax'],function(Ajax){Ajax.call([{methodname:'local_umat_ai_get_course_materials',args:{courseid:courseId}}])[0].done(function(r){var mats=r.materials||[];if(!mats.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">folder_open</span><p>No materials indexed yet.</p></div>';return;}body.innerHTML=mats.slice(0,12).map(function(m){return '<button class="umat-cp-list-card as-btn" data-mid="'+m.id+'" type="button"><strong>'+_umatEsc(m.filename||m.name||'Material')+'</strong><p>'+_umatEsc(m.mimetype||m.type||'Course material')+'</p></button>';}).join('');}).fail(function(err){console.error('[umat] get_course_materials failed:',err&&err.message||err||'unknown');body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load materials.</p></div>';});});
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
  if(name==='report-issue'){ if(!reportLoaded){ initReportIssueTab(); reportLoaded=true; }else loadMyIssues(); markResponsesRead(); pollUnreadCount(); }
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
var _stuRateTimer=setInterval(updateRate,5000);

/* ---- connection status ---- */
var connOnline=null,connTimer=null;
function setConn(state){
  var el=document.getElementById('stu-conn-status');
  if(!el)return;
  if(state==='online'){el.innerHTML='&#9679; Online &amp; Ready';el.style.color='';}
  else if(state==='checking'){el.innerHTML='&#9679; Checking' + '?' + '';el.style.color='#d97706';}
  else{el.innerHTML='<span class="material-symbols-outlined" style="font-size:12px;vertical-align:-2px;">wifi_off</span> Offline ' + '?" retrying' + '?' + '';el.style.color='#fca5a5';}
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
      cont.innerHTML='<div class="umat-session-tile" id="ws-recent-sess-tile" data-sk="'+_umatEsc(s.session_key)+'" data-cid="'+((d.courses&&d.courses[0])?d.courses[0].id:courseId)+'" style="max-width:480px;">'
        +'<div class="umat-session-tile-hdr"><span class="umat-session-badge">'+_umatEsc(s.course_short||'')+'</span><span class="umat-session-time">'+_umatEsc(s.time_label)+'</span></div>'
        +'<h4>'+_umatEsc(s.course_name)+' AI Session</h4>'
        +'<p>'+_umatEsc(_umatCleanPreview(s.preview))+'</p>'
        +'<div class="umat-session-tile-foot"><span class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</span>'
        +'<button class="umat-resume-btn" data-sk="'+_umatEsc(s.session_key)+'" type="button">Resume' + '</button></div></div>';
      var tile=cont.querySelector('.umat-session-tile');
      tile.addEventListener('click',function(){doResumeSession(tile.dataset.sk,tile.dataset.cid);});
      cont.querySelector('.umat-resume-btn').addEventListener('click',function(e){e.stopPropagation();doResumeSession(this.dataset.sk, d.courses&&d.courses[0]?d.courses[0].id:courseId);});
    }
  }
}

/* ---- AI TUTOR chat (streaming) ---- */
function _detectTaskHint(q){
  if(/practice.?question|quiz|test me|generate.?quiz|multiple.?choice/i.test(q)) return 'Generating quiz\u2026';
  if(/summarize|summary|overview of|give me an? (overview|summary)/i.test(q)) return 'Summarizing content\u2026';
  return null;
}
function sendQuestion(q, msgsId){
  q=(q||'').trim();if(!q)return;
  if(qRemaining()<=0){_umatAppendAi(msgsId,'Rate limit reached. Please wait a moment before asking again.',[]); return;}
  qTimes.push(Date.now());updateRate();
  var replyTxt = (typeof _getReplyContext === 'function') ? _getReplyContext() : null;
  if (replyTxt) {
    q = '[Replying to: "' + replyTxt + '"] ' + q;
    _clearReplyContext();
    var rp = document.getElementById('umat-reply-preview');
    if (rp) rp.remove();
  }
  _umatAppendUser(msgsId,q);
  // Reset quiz state so a new quiz from this message doesn't collide with a previous one.
  qz.answers={};qz.graded={};qz.idx=0;qz.active=false;qz.attempt_id=null;qz.data=null;
  var tid='typ_'+Date.now();_umatShowTyping(msgsId,tid);

  var contextQ=selectedMats.length>0?'[Referencing: '+selectedMats.map(function(m){return m.name;}).join(', ')+'] '+q:q;
  var matIds=selectedMats.map(function(m){return m.id;});

  // Determine which send button/input pair to manage
  var sendBtnId = (msgsId === 'cp-msgs') ? 'cp-send' : 'ws-send';
  var sendInputId = (msgsId === 'cp-msgs') ? 'cp-input' : 'ws-input';

  _umatStreamChat({
    url: streamUrl,
    sesskey: moodleSesskey,
    courseid: courseId,
    question: contextQ,
    session_key: sessionKey,
    material_ids: matIds,
    msgsId: msgsId,
    sendBtnId: sendBtnId,
    sendInputId: sendInputId,
    statusText: _detectTaskHint(q),
    onMeta: function(meta){ syncRemaining(meta.remaining); updateRate(); markConn(true); },
    onQuizData: function(payload){
      try{
        if(payload&&payload.quiz&&payload.quiz.questions&&payload.quiz.questions.length){
          _umatProcessQuiz(payload, msgsId);
        }
      }catch(e){}
    },
    onDone: function(meta){
      _umatHideTyping(tid); syncRemaining(meta.remaining); updateRate(); markConn(true);
      setTimeout(function(){ try{_umatDetectQuiz(msgsId);}catch(e){} }, 100);
    },
    onError: function(err){
      _umatHideTyping(tid);
      if(err.error==='rate_limit'){ qTimes.pop(); updateRate(); }
      else { markConn(false); }
      // Show error message with inline retry button
      var retryId = 'retry_' + Date.now();
      _umatAppendAi(msgsId, err.message||'Sorry, an error occurred. Please try again.', []);
      // Add retry button after error message
      var msgsCont = document.getElementById(msgsId);
      if (msgsCont) {
        var lastBubble = msgsCont.querySelector('.umat-bubble-ai:last-of-type');
        if (lastBubble) {
          var retryBtn = document.createElement('button');
          retryBtn.className = 'umat-retry-btn';
          retryBtn.type = 'button';
          retryBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px;">refresh</span>Retry';
          retryBtn.setAttribute('aria-label', 'Retry sending message');
          retryBtn.addEventListener('click', function() {
            retryBtn.parentNode.removeChild(retryBtn);
            sendQuestion(q, msgsId);
          });
          lastBubble.parentNode.appendChild(retryBtn);
        }
      }
    }
  });
}

/* ---- INTERACTIVE QUIZ ENGINE ---- */
var qz={data:null,idx:0,answers:{},graded:{},active:false,attempt_id:null};
setTimeout(function(){_umatLoadQuizState();},200);
function _umatDetectQuiz(msgsId){
  var cont=document.getElementById(msgsId);if(!cont)return;
  var bubbles=cont.querySelectorAll('.umat-bubble-ai');if(!bubbles.length)return;
  var last=bubbles[bubbles.length-1];
  var txt=last.textContent||'';
  var m=txt.match(/\`\`\`(?:json)?\s*(\{[^`]*?"quiz"\s*:[^`]*?\})\s*\`\`\`/s);
  if(!m)return;
  var rawJson=m[1];
  // Remove all <p> elements that are part of the code block
  var inBlock=false;
  last.querySelectorAll('p').forEach(function(p){
    var t=p.textContent.trim();
    if(/^```/.test(t)){
      inBlock=!inBlock;
      if(p.parentNode)p.parentNode.removeChild(p);
      return;
    }
    if(inBlock){if(p.parentNode)p.parentNode.removeChild(p);}
  });
  // Clean up empty paragraphs
  last.querySelectorAll('p').forEach(function(p){
    if(p.textContent.trim()===''&&p.parentNode)p.parentNode.removeChild(p);
  });
  if(qz.data)return;
  try{
    var data=JSON.parse(rawJson);
    if(data&&data.quiz&&data.quiz.questions&&data.quiz.questions.length)_umatProcessQuiz(data, msgsId);
  }catch(e){}
}
function _umatProcessQuiz(data, containerId){
  qz.data=data.quiz;qz.idx=0;qz.answers=qz.answers||{};qz.graded=qz.graded||{};qz.active=true;
  containerId=containerId||'ws-msgs';
  var c=document.getElementById(containerId);if(!c)return;
  var total=qz.data.questions.length;
  var gradedCnt=Object.keys(qz.graded).length;
  var hasProg=gradedCnt>0;
  var oldCard=c.querySelector('.umat-quiz-card');
  if(oldCard)oldCard.parentNode.removeChild(oldCard);
  var card=document.createElement('div');card.className='umat-quiz-card';
  var progHtml='';
  if(hasProg){
    progHtml='<div class="umat-quiz-card-progress"><svg viewBox="0 0 36 36" width="32" height="32"><path d="M18 2a16 16 0 1 1 0 32 16 16 0 1 1 0-32" fill="none" stroke="var(--u-olv)" stroke-width="3"></path><path d="M18 2a16 16 0 1 1 0 32 16 16 0 1 1 0-32" fill="none" stroke="var(--u-p)" stroke-width="3" stroke-dasharray="100" stroke-dashoffset="'+(100-(gradedCnt/total*100))+'" stroke-linecap="round"></path><text x="18" y="20" text-anchor="middle" font-size="8" fill="var(--u-text)">'+gradedCnt+'/'+total+'</text></svg></div>';
  } else {
    progHtml='<div class="umat-quiz-card-progress"><svg viewBox="0 0 36 36" width="32" height="32"><circle cx="18" cy="18" r="16" fill="none" stroke="var(--u-olv)" stroke-width="3"/><circle cx="18" cy="18" r="16" fill="none" stroke="var(--u-p)" stroke-width="3" stroke-dasharray="100" stroke-dashoffset="100" stroke-linecap="round"/><text x="18" y="20" text-anchor="middle" font-size="8" fill="var(--u-ol)">0/'+total+'</text></svg></div>';
  }
  card.innerHTML=progHtml
    +'<div class="umat-quiz-card-info"><strong>'+_umatEsc(data.quiz.title||'Practice Quiz')+'</strong>'
    +'<span>'+total+' questions</span>'
    +(hasProg?' \u00B7 <strong style="color:var(--u-p);">'+gradedCnt+'/'+total+' completed</strong>':'')
    +'</span></div>'
    +'<button class="umat-quiz-card-btn ws-start-quiz" type="button"><span class="material-symbols-outlined">'+(hasProg?'play_circle':'play_arrow')+'</span>'+(hasProg?'Continue Quiz':'Start Quiz')+'</button>';
  c.appendChild(card);
  card.querySelector('.ws-start-quiz').addEventListener('click',function(){_umatOpenQuiz(containerId);});
}
function _umatGetQuizPrefix(containerId){
  return (containerId||'').indexOf('cp-')===0?'cp':'ws';
}
function _umatQ(prefix, id){
  return document.getElementById(prefix+'-'+id);
}
function _umatOpenQuiz(containerId){
  var pref=_umatGetQuizPrefix(containerId||'ws-msgs');
  var pane=_umatQ(pref,'quiz-pane');if(!pane||!qz.data)return;
  pane.style.display='flex';qz.active=true;pane.classList.add('umat-quiz-enter');
  var title=_umatQ(pref,'quiz-title');if(title)title.textContent=qz.data.title||'Practice Quiz';
  var total=_umatQ(pref,'quiz-total');if(total)total.textContent=qz.data.questions.length;
  qz._pref=pref;
  // Find first unanswered question or show score if all done
  var found=false;
  for(var i=0;i<qz.data.questions.length;i++){
    if(qz.graded[i]===undefined){qz.idx=i;found=true;break;}
  }
  if(!found){_umatShowScore();return;}
  _umatRenderCircle();_umatRenderQuestion(qz.idx);
}
function _umatSaveQuizState(){
  try{sessionStorage.setItem('qz_state',JSON.stringify({data:qz.data,answers:qz.answers,graded:qz.graded,idx:qz.idx,attempt_id:qz.attempt_id}));}catch(e){}
  if(qz._saveTimer)clearTimeout(qz._saveTimer);
  qz._saveTimer=setTimeout(function(){
    if(!courseId||!qz.data||!qz.data.questions)return;
    var allGraded=qz.data.questions.every(function(_,i){return qz.graded[i]!==undefined;});
    var score=0,total=qz.data.questions.length;
    Object.keys(qz.graded).forEach(function(k){if(qz.graded[k].correct)score++;});
    require(['core/ajax'],function(Ajax){
      Ajax.call([{methodname:'local_umat_ai_save_quiz_attempt',args:{
        attempt_id:qz.attempt_id||0,courseid:courseId,session_key:sessionKey,
        quiz_title:qz.data.title||'Practice Quiz',
        questions_json:JSON.stringify(qz.data.questions),
        answers_json:JSON.stringify(qz.answers),
        graded_json:JSON.stringify(qz.graded),
        score:allGraded?score:null,total:total,
        status:allGraded?'completed':'in_progress'
      }}])[0]
      .done(function(r){
        qz.attempt_id=r.attempt_id;
        if(allGraded)try{sessionStorage.removeItem('qz_state');}catch(e){}
      })
      .fail(function(e){console.error('Quiz save failed:',e);});
    });
  },800);
}
function _umatLoadQuizState(){
  try{
    var raw=sessionStorage.getItem('qz_state');
    if(raw){
      var s=JSON.parse(raw);
      if(s&&s.data&&s.data.questions&&s.data.questions.length){
        qz.data=s.data;qz.answers=s.answers||{};qz.graded=s.graded||{};qz.idx=s.idx||0;qz.active=false;qz.attempt_id=s.attempt_id||null;
        var containers=['cp-msgs','ws-msgs'];
        for(var ci=0;ci<containers.length;ci++){
          var c=document.getElementById(containers[ci]);
          if(c){
            var hasCard=c.querySelector('.umat-quiz-card');
            if(!hasCard)_umatProcessQuiz({quiz:qz.data}, containers[ci]);
            else _umatUpdateQuizCard();
            break;
          }
        }
        return;
      }
    }
    // Fall back to server if sessionStorage is empty
    if(!courseId)return;
    require(['core/ajax'],function(Ajax){
      Ajax.call([{methodname:'local_umat_ai_get_quiz_attempts',args:{courseid:courseId,status:'in_progress'}}])[0]
        .done(function(r){
          var attempts=r.attempts||[];
          if(!attempts.length)return;
          var latest=attempts[0];
          var questions=JSON.parse(latest.questions_json||'[]');
          if(!questions.length)return;
          qz.data={title:latest.quiz_title,questions:questions};
          qz.answers=JSON.parse(latest.answers_json||'{}');
          qz.graded=JSON.parse(latest.graded_json||'{}');
          qz.idx=0;qz.active=false;qz.attempt_id=latest.attempt_id;
          var msg='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">quiz</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">QUIZ RESUME</div><div class="umat-bubble-ai"><p>You have an incomplete quiz: <strong>'+_umatEsc(latest.quiz_title)+'</strong></p><div class="umat-chips-row"><button class="umat-chip" id="quiz-resume-yes" type="button">Yes, continue</button><button class="umat-chip" id="quiz-resume-no" type="button">No, start fresh</button></div></div></div></div>';
          var containers=['cp-msgs','ws-msgs'];
          for(var ci=0;ci<containers.length;ci++){
            var c=document.getElementById(containers[ci]);
            if(c){c.insertAdjacentHTML('beforeend',msg);break;}
          }
          setTimeout(function(){
            var yesBtn=document.getElementById('quiz-resume-yes');
            if(yesBtn)yesBtn.addEventListener('click',function(){
              _umatProcessQuiz({quiz:qz.data}, 'ws-msgs');
              _umatOpenQuiz('ws-msgs');
            });
            var noBtn=document.getElementById('quiz-resume-no');
            if(noBtn)noBtn.addEventListener('click',function(){
              require(['core/ajax'],function(Ajax){
                Ajax.call([{methodname:'local_umat_ai_delete_quiz_attempt',args:{attempt_id:latest.attempt_id}}])[0].done(function(){});
              });
              qz.data=null;qz.answers={};qz.graded={};qz.idx=0;qz.active=false;qz.attempt_id=null;
              try{sessionStorage.removeItem('qz_state');}catch(e){}
            });
          },100);
        })
        .fail(function(){});
    });
  }catch(e){}
}
function _umatCloseQuiz(){
  var pref=qz._pref||'ws';
  var pane=_umatQ(pref,'quiz-pane');if(!pane)return;
  pane.style.display='none';qz.active=false;pane.classList.remove('umat-quiz-enter');
  _umatSaveQuizState();
}
function _umatRenderCircle(){
  var pref=qz._pref||'ws';
  var c=_umatQ(pref,'quiz-circle');if(!c||!qz.data)return;
  var total=qz.data.questions.length;
  var gradedCnt=Object.keys(qz.graded).length;
  var pct=total?Math.round(gradedCnt/total*100):0;
  var r=36,circ=2*Math.PI*r,dash=pct/100*circ;
  c.innerHTML='<svg viewBox="0 0 80 80" width="72" height="72"><circle cx="40" cy="40" r="'+r+'" fill="none" stroke="var(--u-olv)" stroke-width="5"></circle><circle cx="40" cy="40" r="'+r+'" fill="none" stroke="var(--u-p)" stroke-width="5" stroke-dasharray="'+circ+'" stroke-dashoffset="'+(circ-dash)+'" stroke-linecap="round" transform="rotate(-90 40 40)" style="transition:stroke-dashoffset .5s ease"></circle><text x="40" y="38" text-anchor="middle" font-size="18" font-weight="700" fill="var(--u-text)">'+gradedCnt+'</text><text x="40" y="54" text-anchor="middle" font-size="11" fill="var(--u-ol)">/'+total+'</text></svg>';
}
function _umatNavTo(idx){
  if(!qz.data||idx<0||idx>=qz.data.questions.length)return;
  qz.idx=idx;
  _umatRenderQuestion(idx);
  _umatRenderCircle();
}
function _umatRenderQuestion(idx){
  if(!qz.data||idx<0||idx>=qz.data.questions.length)return;
  var pref=qz._pref||'ws';
  var q=qz.data.questions[idx];qz.idx=idx;
  var idxEl=_umatQ(pref,'quiz-idx');if(idxEl)idxEl.textContent=idx+1;
  _umatRenderCircle();
  var body=_umatQ(pref,'quiz-body');if(!body)return;
  var graded=qz.graded[idx]!==undefined;
  var correct=graded&&qz.graded[idx].correct;
  var head=_umatEsc(q.question);
  var navHtml='<div class="umat-quiz-nav">'
    +'<button class="umat-quiz-nav-btn" id="qz-prev" type="button"'+(idx===0?' disabled':'')+'><span class="material-symbols-outlined">chevron_left</span> Previous</button>'
    +'<span class="umat-quiz-nav-idx">'+(idx+1)+'/'+qz.data.questions.length+'</span>'
    +'<button class="umat-quiz-nav-btn" id="qz-next" type="button"'+(idx>=qz.data.questions.length-1?' disabled':'')+'>Next <span class="material-symbols-outlined">chevron_right</span></button>'
    +'</div>';
  var isOptType=q.type==='objective'||q.type==='truefalse';
  var isTextType=q.type==='fill_in'||q.type==='theoretical';
  if(isOptType){
    var opts=(q.options||[]).map(function(o,i){
      var sel=qz.answers[idx]===i?' selected':'';
      var res='';
      if(graded){
        if(i===q.correct)res=' correct';
        else if(sel)res=' wrong';
      }
      return '<button class="umat-quiz-opt'+sel+res+'" data-opt="'+i+'" type="button"'+(graded?' disabled':'')+'>'
        +'<span class="umat-quiz-opt-radio"></span><span class="umat-quiz-opt-label">'+_umatEsc(o)+'</span>'
        +(graded&&i===q.correct?'<span class="material-symbols-outlined umat-quiz-opt-ic">check_circle</span>':'')
        +(graded&&sel&&i!==q.correct?'<span class="material-symbols-outlined umat-quiz-opt-ic">cancel</span>':'')
        +'</button>';
    }).join('');
    var expl=(graded&&q.explanation)?'<div class="umat-quiz-expl">'+_umatEsc(q.explanation)+'</div>':'';
    body.innerHTML='<div class="umat-quiz-qcard">'
      +'<div class="umat-quiz-qhead">'+head+'</div>'
      +'<div class="umat-quiz-opts">'+opts+'</div>'
      +expl
      +'</div>'
      +navHtml;
    if(!graded){
      body.querySelectorAll('.umat-quiz-opt').forEach(function(b){
        b.addEventListener('click',function(){_umatSelectOption(idx,parseInt(b.dataset.opt));});
      });
    }
  } else if(isTextType){
    var val=qz.answers[idx]||'';
    var gradedInfo=graded?'<div class="umat-quiz-expl"><strong>Your answer:</strong> '+_umatEsc(qz.answers[idx])+'</div>':'';
    body.innerHTML='<div class="umat-quiz-qcard">'
      +'<div class="umat-quiz-qhead">'+head+'</div>'
      +(q.answer_hint?'<div class="umat-quiz-hint"><span class="material-symbols-outlined">lightbulb</span>'+_umatEsc(q.answer_hint)+'</div>':'')
      +(graded?'':('<textarea class="umat-quiz-ta" id="qz-ta" placeholder="Type your answer here\u2026" rows="4">'+_umatEsc(val)+'</textarea>'))
      +(graded&&q.correct?'<div class="umat-quiz-expl"><strong>Expected answer:</strong> '+_umatEsc(q.correct)+'</div>':'')
      +gradedInfo
      +(graded&&q.explanation?'<div class="umat-quiz-expl">'+_umatEsc(q.explanation)+'</div>':'')
      +(graded?'':('<div class="umat-quiz-actions"><button class="umat-quiz-submit" id="qz-submit" type="button">Submit Answer</button></div>'))
      +'</div>'
      +navHtml;
    if(!graded){
      var ta=document.getElementById('qz-ta');
      if(ta)ta.addEventListener('input',function(){qz.answers[idx]=ta.value;});
      var sub=document.getElementById('qz-submit');
      if(sub)sub.addEventListener('click',function(){_umatGradeText(idx);});
    }
  }
  // Wire nav buttons
  var prev=document.getElementById('qz-prev');
  if(prev)prev.addEventListener('click',function(){_umatNavTo(idx-1);});
  var next=document.getElementById('qz-next');
  if(next)next.addEventListener('click',function(){_umatNavTo(idx+1);});
  // Update quiz tile on close
  _umatUpdateQuizCard();
  var doc=document.getElementById('ws-quiz-score');
  if(doc)doc.style.display='none';
}
function _umatUpdateQuizCard(){
  var card=document.querySelector('.umat-quiz-card');if(!card)return;
  var total=qz.data?qz.data.questions.length:0;
  var gradedCnt=Object.keys(qz.graded).length;
  var hasProg=gradedCnt>0;
  var allDone=total>0&&gradedCnt===total;
  var correct=0;
  if(allDone){Object.keys(qz.graded).forEach(function(k){if(qz.graded[k].correct)correct++;});}
  var svg=card.querySelector('.umat-quiz-card-progress svg');
  if(svg){
    var r=16,circ=2*Math.PI*r,pct=total?Math.round(gradedCnt/total*100):0;
    var circles=svg.querySelectorAll('circle');
    if(circles.length>1)circles[1].setAttribute('stroke-dashoffset',circ-(pct/100*circ));
    var txt=svg.querySelector('text');
    if(txt)txt.textContent=allDone?correct+'/'+total:gradedCnt+'/'+total;
  }
  var info=card.querySelector('.umat-quiz-card-info span');
  if(info){
    var base=total+' questions';
    if(allDone){
      base+=' \u00B7 <strong style="color:var(--u-sec);">\u2713 Completed</strong>'
        +' \u00B7 <strong style="color:var(--u-p);">'+correct+'/'+total+'</strong>';
    } else if(hasProg){
      base+=' \u00B7 <strong style="color:var(--u-p);">'+gradedCnt+'/'+total+' completed</strong>';
    }
    info.innerHTML=base;
  }
  var btn=card.querySelector('.umat-quiz-card-btn');
  if(btn){
    var label=allDone?'View Results':(hasProg?'Continue Quiz':'Start Quiz');
    var icon=allDone?'rate_review':(hasProg?'play_circle':'play_arrow');
    btn.innerHTML='<span class="material-symbols-outlined">'+icon+'</span>'+label;
  }
}
function _umatSelectOption(idx,optIdx){
  if(qz.graded[idx]!==undefined)return;
  qz.answers[idx]=optIdx;
  // Auto-grade immediately
  _umatGradeObjective(idx);
}
function _umatGradeObjective(idx){
  if(qz.graded[idx]!==undefined||qz.answers[idx]===undefined)return;
  var q=qz.data.questions[idx];if(!q)return;
  if(q.type!=='objective'&&q.type!=='truefalse')return;
  var correct=qz.answers[idx]===q.correct;
  qz.graded[idx]={correct:correct,explanation:q.explanation||''};
  _umatRenderQuestion(idx);_umatSaveQuizState();
  var allGraded=qz.data.questions.every(function(_,i){return qz.graded[i]!==undefined;});
  if(allGraded){setTimeout(_umatShowScore,600);}
}
function _umatGradeText(idx){
  if(qz.graded[idx]!==undefined)return;
  var ans=(qz.answers[idx]||'').trim();if(!ans)return;
  var q=qz.data.questions[idx];if(!q)return;
  if(q.type!=='fill_in'&&q.type!=='theoretical')return;
  var expected=(q.correct||'').trim();
  var correct=false;
  var explanation=q.explanation||'';
  if(!expected){
    // No expected answer defined — accept any response as correct (backward compat)
    correct=true;
    explanation=explanation||'Answer submitted.';
  } else {
    var alts=expected.split('/').map(function(a){return a.trim().toLowerCase();});
    var sl=ans.toLowerCase();
    for(var ai=0;ai<alts.length;ai++){
      if(sl===alts[ai]||sl.indexOf(alts[ai])!==-1||alts[ai].indexOf(sl)!==-1){
        correct=true;break;
      }
    }
    if(!explanation)explanation=correct?'Correct!':'Incorrect. Expected: '+_umatEsc(expected);
  }
  qz.graded[idx]={correct:correct,explanation:explanation,score:correct?100:0};
  _umatRenderQuestion(idx);_umatSaveQuizState();
  var allGraded=qz.data.questions.every(function(_,i){return qz.graded[i]!==undefined;});
  if(allGraded){setTimeout(_umatShowScore,600);}
}
function _umatShowScore(){
  var pref=qz._pref||'ws';
  var body=_umatQ(pref,'quiz-body');if(!body)body=document.createElement('div');
  body.innerHTML='';
  var total=qz.data.questions.length,correct=0;
  Object.keys(qz.graded).forEach(function(k){if(qz.graded[k].correct)correct++;});
  var score=_umatQ(pref,'quiz-score');if(score)score.style.display='flex';
  var pct=total?Math.round(correct/total*100):0;
  var num=_umatQ(pref,'quiz-score-num');if(num)num.textContent=correct+'/'+total;
  var lbl=_umatQ(pref,'quiz-score-lbl');if(lbl)lbl.textContent=correct===total?'Perfect!':(pct>=70?'Great job!':'Keep practicing!');
  var sub=_umatQ(pref,'quiz-score-sub');if(sub)sub.textContent=pct+'% accuracy';
  var fill=_umatQ(pref,'quiz-score-fill');if(fill)fill.style.width=pct+'%';
  var icon=_umatQ(pref,'quiz-score-icon');if(icon)icon.textContent=pct>=80?'emoji_events':(pct>=50?'sentiment_satisfied':'school');
  _umatRenderCircle();
  _umatUpdateQuizCard();
  // Remove from sessionStorage so completed quiz doesn't restore on page load
  try{sessionStorage.removeItem('qz_state');}catch(e){}
}

/* ---- Quiz History (renders in compact panel + workspace tab) ---- */
function renderCpQuizHistory(body, cid){
  if(!cid){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">quiz</span><p>Select a course first to view quiz history.</p></div>';return;}
  body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading quiz history\u2026</p></div>';
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_quiz_attempts',args:{courseid:cid,status:''}}])[0]
      .done(function(r){
        var attempts=r.attempts||[];
        if(!attempts.length){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">quiz</span><p>No quiz attempts yet. Ask the AI tutor to create a practice quiz!</p></div>';return;}
        body.innerHTML=attempts.slice(0,20).map(function(a){
          var scoreStr=a.score!==null?a.score+'/'+a.total:'-/ '+a.total;
          var statusLabel=a.status==='completed'?'Completed':'In Progress';
          var statusCls=a.status==='completed'?'background:#dcfce7;color:#065f46;':'background:#fef3c7;color:#92400e;';
          var d=new Date(a.timecreated*1000);
          var dateStr=d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
          return '<button class="umat-cp-list-card as-btn" data-aid="'+a.attempt_id+'" type="button" style="text-align:left;">'+
            '<div style="display:flex;justify-content:space-between;align-items:center;">'+
            '<strong style="font-size:13px;">'+_umatEsc(a.quiz_title)+'</strong>'+
            '<span style="font-size:10px;padding:1px 6px;border-radius:999px;'+statusCls+'font-weight:600;">'+statusLabel+'</span></div>'+
            '<p style="font-size:11px;margin:3px 0 0;">'+scoreStr+' A &bullet; '+dateStr+'</p></button>';
        }).join('');
        body.querySelectorAll('[data-aid]').forEach(function(btn){
          btn.addEventListener('click',function(){
            var aid=parseInt(btn.dataset.aid);
            loadQuizAttemptForReview(aid, body);
          });
        });
      })
      .fail(function(){body.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load quiz history.</p></div>';});
  });
}
function renderWsQuizHistory(){
  var list=document.getElementById('quiz-history-list');
  if(!list)return;
  list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading quiz history\u2026</p></div>';
  if(!courseId){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>Select a course to view quiz history.</p></div>';return;}
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_quiz_attempts',args:{courseid:courseId,status:''}}])[0]
      .done(function(r){
        var attempts=r.attempts||[];
        if(!attempts.length){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">quiz</span><p>No quiz attempts yet. Ask the AI tutor to create a practice quiz!</p></div>';return;}
        list.innerHTML='<div class="umat-quiz-history-grid" style="display:flex;flex-direction:column;gap:8px;">'+
          attempts.map(function(a){
            var scoreStr=a.score!==null?a.score+'/'+a.total:'-/ '+a.total;
            var statusLabel=a.status==='completed'?'Completed':'In Progress';
            var statusCls=a.status==='completed'?'background:#dcfce7;color:#065f46;':'background:#fef3c7;color:#92400e;';
            var d=new Date(a.timecreated*1000);
            var dateStr=d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'});
            return '<div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:14px;cursor:pointer;" data-aid="'+a.attempt_id+'">'+
              '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">'+
              '<strong style="font-size:14px;color:var(--u-head);">'+_umatEsc(a.quiz_title)+'</strong>'+
              '<span style="font-size:10px;padding:2px 8px;border-radius:999px;'+statusCls+'font-weight:600;">'+statusLabel+'</span></div>'+
              '<div style="font-size:12px;color:var(--u-onsv);">Score: <strong>'+scoreStr+'</strong> A &bullet; '+dateStr+'</div></div>';
          }).join('')+'</div>';
        list.querySelectorAll('[data-aid]').forEach(function(el){
          el.addEventListener('click',function(){
            var aid=parseInt(el.dataset.aid);
            loadQuizAttemptForReview(aid, list);
          });
        });
      })
      .fail(function(){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load quiz history.</p></div>';});
  });
}
function loadQuizAttemptForReview(aid, container){
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_quiz_attempts',args:{courseid:0,status:'',attempt_id:aid}}])[0]
      .done(function(r){
        var attempt=r.attempts?r.attempts[0]:r;
        if(!attempt||!attempt.questions_json){container.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Could not load quiz.</p></div>';return;}
        var questions=JSON.parse(attempt.questions_json||'[]');
        var answers=JSON.parse(attempt.answers_json||'{}');
        var graded=JSON.parse(attempt.graded_json||'{}');
        if(!questions.length){container.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Quiz data is empty.</p></div>';return;}
        var html='<div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">'+
          '<h3 style="margin:0;font-size:16px;">'+_umatEsc(attempt.quiz_title)+'</h3>'+
          '<button class="umat-chip" id="quiz-review-back" type="button"><span class="material-symbols-outlined" style="font-size:14px;">arrow_back</span> Back</button></div>';
        questions.forEach(function(q,i){
          var ans=answers[i];
          var g=graded[i];
          var isGraded=g!==undefined;
          var isCorrect=isGraded&&g.correct;
          var statusIcon=isGraded?(isCorrect?'check_circle':'cancel'):'hourglass_empty';
          var statusColor=isGraded?(isCorrect?'var(--u-sec)':'var(--u-ter)'):'var(--u-ol)';
          var ansDisplay='';
          var isOptType=q.type==='objective'||q.type==='truefalse';
          var isTextType=q.type==='fill_in'||q.type==='theoretical';
          if(isOptType){
            var selOpt=ans!==undefined&&q.options&&q.options[ans];
            ansDisplay='<div style="font-size:12px;color:var(--u-onsv);margin-top:4px;"><strong>Your answer:</strong> '+_umatEsc(selOpt||'Not answered')+'</div>'+
              '<div style="font-size:12px;color:var(--u-sec);"><strong>Correct answer:</strong> '+_umatEsc(q.options&&q.options[q.correct]||q.correct)+'</div>';
          } else if(isTextType){
            ansDisplay='<div style="font-size:12px;color:var(--u-onsv);margin-top:4px;"><strong>Your answer:</strong><br>'+_umatEsc(ans||'Not answered')+'</div>'+
              (q.correct?'<div style="font-size:12px;color:var(--u-sec);margin-top:4px;"><strong>Expected answer:</strong> '+_umatEsc(q.correct)+'</div>':'');
          }
          var expl=isGraded&&g.explanation?'<div style="font-size:11px;color:var(--u-onsv);margin-top:4px;padding:8px;background:var(--u-sflo);border-radius:6px;"><strong>Feedback:</strong> '+_umatEsc(g.explanation)+'</div>':'';
          html+='<div style="background:var(--u-bg);border:1px solid var(--u-olv);border-radius:var(--u-r8);padding:12px;margin-bottom:8px;">'+
            '<div style="display:flex;justify-content:space-between;align-items:flex-start;">'+
            '<div style="flex:1;"><strong style="font-size:13px;">Q'+(i+1)+':</strong> <span style="font-size:13px;">'+_umatEsc(q.question)+'</span></div>'+
            '<span class="material-symbols-outlined" style="color:'+statusColor+';font-size:20px;">'+statusIcon+'</span></div>'+
            ansDisplay+expl+'</div>';
        });
        html+='<button class="umat-btn-p" id="quiz-review-close" type="button" style="justify-content:center;margin-top:8px;"><span class="material-symbols-outlined">close</span>Close Review</button>';
        container.innerHTML=html;
        var backBtn=document.getElementById('quiz-review-back');
        if(backBtn)backBtn.addEventListener('click',function(){renderWsQuizHistory();});
        var closeBtn=document.getElementById('quiz-review-close');
        if(closeBtn)closeBtn.addEventListener('click',function(){renderWsQuizHistory();});
      })
      .fail(function(e){console.error('Quiz review load failed:',e);container.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load quiz details.</p></div>';});
  });
}
function _umatWireQuizBtns(pref){
  var back=_umatQ(pref,'quiz-back');if(back)back.addEventListener('click',_umatCloseQuiz);
  var close=_umatQ(pref,'quiz-close-pane');if(close)close.addEventListener('click',_umatCloseQuiz);
  var retry=_umatQ(pref,'quiz-retry');if(retry)retry.addEventListener('click',function(){
    qz.answers={};qz.graded={};qz.idx=0;
    var score=_umatQ(pref,'quiz-score');if(score)score.style.display='none';
    _umatRenderQuestion(0);_umatUpdateQuizCard();
    try{sessionStorage.removeItem('qz_state');}catch(e){}
  });
  var review=_umatQ(pref,'quiz-review');if(review)review.addEventListener('click',function(){
    var score=_umatQ(pref,'quiz-score');if(score)score.style.display='none';
    _umatNavTo(0);
  });
}
_umatWireQuizBtns('ws');
_umatWireQuizBtns('cp');

/* workspace AI tutor */
var wsInput=document.getElementById('ws-input'),wsSend=document.getElementById('ws-send');
if(wsSend)wsSend.addEventListener('click',function(){if(this.disabled)return;sendQuestion(wsInput.value,'ws-msgs');wsInput.value='';});
if(wsInput)wsInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(wsSend&&!wsSend.disabled)wsSend.click();}});
/* suggestion chips */
ov.addEventListener('click',function(e){
  var chip=e.target.closest('[data-q]');
  if(chip){sendQuestion(chip.dataset.q,'ws-msgs');}
});

/* compact panel send */
var cpInput=document.getElementById('cp-input'),cpSend=document.getElementById('cp-send');
if(cpSend)cpSend.addEventListener('click',function(){if(this.disabled)return;sendQuestion(cpInput.value,'cp-msgs');cpInput.value='';});
if(cpInput)cpInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(cpSend&&!cpSend.disabled)cpSend.click();}});
/* compact panel scroll-to-bottom */
(function(){
  var cpMsgs=document.getElementById('cp-msgs');
  var cpScrollBtn=document.getElementById('cp-scroll-bottom');
  if(!cpMsgs||!cpScrollBtn)return;
  var timer=null;
  cpMsgs.addEventListener('scroll',function(){
    if(timer)clearTimeout(timer);
    timer=setTimeout(function(){
      var nearBottom=cpMsgs.scrollHeight-cpMsgs.scrollTop-cpMsgs.clientHeight<100;
      cpScrollBtn.classList.toggle('visible',!nearBottom);
    },80);
  });
  cpScrollBtn.addEventListener('click',function(){
    cpMsgs.scrollTo({top:cpMsgs.scrollHeight,behavior:'smooth'});
  });
  var obs=new MutationObserver(function(){
    var nearBottom=cpMsgs.scrollHeight-cpMsgs.scrollTop-cpMsgs.clientHeight<100;
    cpScrollBtn.classList.toggle('visible',!nearBottom);
  });
  obs.observe(cpMsgs,{childList:true,subtree:false});
})();

/* ---- REPORT ISSUE ---- */
function initReportIssueTab(){
  var form=document.getElementById('ws-issue-form-wrap');
  if(!courseId){var c=(userData&&userData.courses)||[];var list=document.getElementById('ws-issue-list');if(list){if(!c.length){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses available.</p></div>';}else{list.innerHTML='<div style="padding:14px;"><div style="font-size:11px;color:var(--u-ol);font-weight:600;margin-bottom:8px;">Select a course to report an issue:</div>'+c.map(function(cv){return '<button class="umat-chip" data-cid="'+cv.id+'" type="button" style="margin:2px;">'+_umatEsc(cv.shortname||cv.fullname)+'</button>';}).join('')+'</div>';list.querySelectorAll('[data-cid]').forEach(function(b){b.addEventListener('click',function(){courseId=parseInt(this.dataset.cid)||courseId;initReportIssueTab();});});}}if(form)form.style.display='none';return;}
  var toggle=document.getElementById('ws-issue-toggle');
  if(toggle) toggle.addEventListener('click',function(){form.style.display=form.style.display==='none'?'block':'none';});
  if(form) form.style.display='none';
  var submit=document.getElementById('ws-issue-submit');
    if(submit) submit.addEventListener('click',function(){
      var cat=document.getElementById('ws-issue-cat').value;
      var topic=document.getElementById('ws-issue-topic').value.trim();
      var desc=document.getElementById('ws-issue-desc').value.trim();
      var msg=document.getElementById('ws-issue-msg');
      if(desc.length<10){msg.textContent='Please provide a more detailed description (at least 10 characters).';msg.style.display='block';msg.style.color='var(--u-ter)';return;}
      submit.disabled=true;submit.textContent='Submitting\u2026';
      console.log('[issue] submitting cat='+cat+' cid='+courseId+' desc='+desc.substring(0,30));
      require(['core/ajax'],function(Ajax){
        Ajax.call([{methodname:'local_umat_ai_submit_issue',args:{courseid:courseId,category:cat,topic:topic,description:desc}}])[0]
          .done(function(r){
            console.log('[issue] response',r);
            if(r.success){
              msg.textContent='Issue reported successfully!';msg.style.display='block';msg.style.color='var(--u-sec)';
              document.getElementById('ws-issue-topic').value='';document.getElementById('ws-issue-desc').value='';
              form.style.display='none';loadMyIssues();
            }else{msg.textContent=r.message||'Failed to submit.';msg.style.display='block';msg.style.color='var(--u-ter)';}
          })
          .fail(function(e){
            console.log('[issue] AJAX fail',e);
            var errMsg=e&&(e.message||e.errorcode||e);
            msg.textContent=errMsg||'Connection error. Please try again.';msg.style.display='block';msg.style.color='var(--u-ter)';
          })
          .always(function(){submit.disabled=false;submit.innerHTML='<span class="material-symbols-outlined">send</span>Submit Report';});
      });
    });
  loadMyIssues();
}
function loadMyIssues(){
  var list=document.getElementById('ws-issue-list');if(!list){console.log('[issues] list not found');return;}
  if(!courseId){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">school</span><p>Select a course above to view your reports.</p></div>';return;}
  console.log('[issues] loading for cid='+courseId);
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_student_issues',args:{courseid:courseId}}])[0]
      .done(function(rows){
        console.log('[issues] got rows',rows,typeof rows,Array.isArray(rows),rows&&rows.length);
        if(!rows||!rows.length){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">flag</span><p>No issues reported yet.</p></div>';return;}
        list.innerHTML=rows.map(function(r){
          var catLabel={'concept_confusion':'Concept Confusion','material_error':'Material Error','technical_issue':'Technical Issue','suggestion':'Suggestion','other':'Other'}[r.category]||r.category;
          var statusLabels={'open':'Open','in_review':'In Review','resolved':'Resolved','closed':'Closed'};
          var statusColors={'open':'var(--u-ter)','in_review':'#d97706','resolved':'var(--u-sec)','closed':'var(--u-ol)'};
          var statusColor=statusColors[r.status]||'var(--u-ol)';
          var statusLabel=statusLabels[r.status]||r.status;
          var ago='';
          if(r.timecreated){var d=Math.floor((Date.now()/1000-r.timecreated)/86400);ago=d===0?'today':d+'d ago';}
          return '<div style="background:var(--u-sflo);border:1px solid var(--u-olv);border-radius:var(--u-r12);padding:14px;margin-bottom:8px;">'
            +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
            +'<span style="font-weight:700;font-size:13px;">'+_umatEsc(r.topic||catLabel)+'</span>'
            +'<span style="font-size:10px;padding:2px 8px;border-radius:999px;background:'+statusColor+'20;color:'+statusColor+';font-weight:700;">'+statusLabel+'</span></div>'
            +'<p style="font-size:12px;color:var(--u-onsv);margin:0 0 4px;">'+_umatEsc(r.description.replace(/^(.{120}[^\\s]*).*$/,'$1')+(r.description.length>120?'...':''))+'</p>'
            +'<div style="font-size:10px;color:var(--u-ol);">'+catLabel+(r.topic?' A' + ' '+_umatEsc(r.topic):'')+' A' + ' '+ago+'</div>'
            +(r.lecturer_response?'<div style="margin-top:6px;padding-top:6px;border-top:1px solid var(--u-olv);font-size:11px;color:var(--u-sec);"><strong>Lecturer response:</strong> '+_umatEsc(r.lecturer_response)+'</div>':'')
            +'</div>';
        }).join('');
      })
      .fail(function(e){console.log('[issues] AJAX fail',e);list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load issues.</p></div>';});
  });
}

/* voice */
var wsMic=document.getElementById('ws-mic-btn');
console.log('[umat] wsMic:',!!wsMic,'wsInput:',!!wsInput,'SR:',!!(window.SpeechRecognition||window.webkitSpeechRecognition));
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
if(plSend)plSend.addEventListener('click',function(){if(this.disabled)return;sendQuestion(plInput.value,'ws-player-msgs');plInput.value='';});
if(plInput)plInput.addEventListener('keypress',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(plSend&&!plSend.disabled)plSend.click();}});

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
  grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">hourglass_empty</span><p>Loading materials' + '?' + '</p></div>';
  require(['core/ajax'],function(Ajax){
    Ajax.call([{methodname:'local_umat_ai_get_course_materials',args:{courseid:courseId}}])[0]
      .done(function(r){renderLibrary(r.materials||[], courseId);if(typeof updateMaterialAnalysis==='function')updateMaterialAnalysis(courseId);if(typeof updateVideoGenerationStatus==='function')updateVideoGenerationStatus(courseId);})
      .fail(function(err){console.error('[umat] loadLibrary failed:',err&&err.message||err||'unknown');grid.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">error</span><p>Failed to load materials.</p></div>';});
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
      +'<p>'+_umatEsc(_umatCleanPreview(s.preview))+'</p>'
      +'<div class="umat-session-tile-foot"><span class="umat-session-meta"><span class="material-symbols-outlined">chat</span>'+s.msg_count+' messages</span>'
       +'<button class="umat-resume-btn" type="button">Resume</button>'
       +'<button class="umat-del-session-btn" type="button" title="Delete session"><span class="material-symbols-outlined">delete</span></button></div></div>';
  }).join('');
  list.querySelectorAll('.umat-session-tile').forEach(function(tile){
    tile.addEventListener('click',function(e){
      if(e.target.closest('.umat-del-session-btn'))return;
      doResumeSession(tile.dataset.sk,tile.dataset.cid);
    });
    tile.querySelector('.umat-resume-btn').addEventListener('click',function(e){
      e.stopPropagation();
      doResumeSession(tile.dataset.sk,tile.dataset.cid);
    });
    tile.querySelector('.umat-del-session-btn').addEventListener('click',function(e){
      e.stopPropagation();
      if(!confirm('Delete this conversation? This cannot be undone.'))return;
      var btn=e.currentTarget;
      btn.disabled=true;btn.innerHTML='<span class="material-symbols-outlined">hourglass_empty</span>';
      ajax('local_umat_ai_delete_session',{session_key:tile.dataset.sk},function(){
        tile.remove();
        var wsList=document.getElementById('ws-sessions-list');
        if(wsList&&!wsList.querySelector('.umat-session-tile')){
          wsList.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">chat_bubble</span><p>No AI chat sessions yet. Start one in the AI Tutor tab!</p></div>';
        }
      },function(){
        btn.disabled=false;btn.innerHTML='<span class="material-symbols-outlined">delete</span>';
        alert('Could not delete session. Please try again.');
      });
    });
  });
}

/* ---- NOTES ' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"?' + '"? */
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
  if(!courseId){var c=(userData&&userData.courses)||[];if(!c.length){list.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">menu_book</span><p>No courses found.</p></div>';return;}list.innerHTML='<div class="umat-cp-help" style="padding:10px 14px 2px;font-size:11px;color:var(--u-ol);font-weight:600;">Select a course to view notes:</div><div style="padding:4px 14px 6px;display:flex;flex-wrap:wrap;gap:4px;">'+c.slice(0,12).map(function(cv){return '<button class="umat-chip" data-cid="'+cv.id+'" type="button">'+_umatEsc(cv.shortname||cv.fullname)+'</button>';}).join('')+'</div>';list.querySelectorAll('.umat-chip').forEach(function(b){b.addEventListener('click',function(){courseId=parseInt(this.dataset.cid)||courseId;loadNotesAndRender();});});return;}
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
    pane.innerHTML+='<div class="umat-cp-note-more"><button class="umat-chip" onclick="switchToTab(\'my-notes\')" type="button">View all '+mine.length+' notes' + '</button></div>';
  }
  pane.querySelectorAll('.umat-cp-note-item').forEach(function(item){
    item.addEventListener('click',function(){openNoteEditor(parseInt(item.dataset.noteId));});
  });
}
function openNoteEditor(noteId){
  var existing=null;
  if(noteId){
    var notes=document.querySelectorAll('.umat-note-card');
    // Find note data from the rendered cards ' + '?" we need it from the ajax call
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
          '<label class="umat-ne-label">Tags <span class="umat-ne-hint">' + '?" tag courses, materials, or chat sessions</span></label>'+
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
      existingTags.push({tag_type:'session',tag_id:0,tag_label:'Current Session ' + '?" '+d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'}),tag_value:sessionKey});
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
  if(type!=='custom'&&!refVal){require(['core/notification'],function(N){N.error({message:'Please select a '+type+' to tag.'});});return;}
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
  function(){require(['core/notification'],function(N){N.error({message:'Failed to save note. Try again.'});});}
  );
}
function deleteNote(id){
  ajax('local_umat_ai_delete_note',{noteid:id},
    function(r){
      if(r.deleted)loadNotesAndRender();
    },
    function(){require(['core/notification'],function(N){N.error({message:'Failed to delete note.'});});}
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
/* ---- Quiz JSON stripper (for session resume) ---- */
function _umatStripQuizFromText(txt){
  var m=txt.match(/\`\`\`(?:json)?\s*(\{[^`]*?"quiz"\s*:[^`]*?\})\s*\`\`\`/s);
  if(!m)return{text:txt,quiz:null};
  try{
    var data=JSON.parse(m[1]);
    if(data&&data.quiz&&data.quiz.questions&&data.quiz.questions.length){
      return{text:txt.replace(m[0],'').trim(),quiz:{quiz:data.quiz}};
    }
  }catch(e){}
  return{text:txt,quiz:null};
}
function _umatCleanPreview(txt,fallback){
  if(!txt)return fallback||'';
  var r=_umatStripQuizFromText(txt);
  return r.text||fallback||'';
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
        var msgsArr=r.messages||[];
        var foundQuiz=null;
        msgsArr.forEach(function(msg){
          if(msg.question)_umatAppendUser('ws-msgs',msg.question);
          if(msg.answer){
            var stripped=_umatStripQuizFromText(msg.answer);
            if(stripped.quiz)foundQuiz=stripped.quiz;
            _umatAppendAi('ws-msgs',stripped.text,msg.sources||[]);
          }
        });
        if(!msgsArr.length){
          msgs.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>Welcome back! This session had no previous messages. Ask me anything!</p></div></div></div>';
        } else if(foundQuiz){
          _umatProcessQuiz(foundQuiz,'ws-msgs');
        } else {
          setTimeout(function(){_umatDetectQuiz('ws-msgs');},500);
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
      if(!note){require(['core/notification'],function(N){N.error({message:'Note not found.'});});return;}
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
    function(){require(['core/notification'],function(N){N.error({message:'Could not load note.'});});}
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

/* ' + '"?' + '"? Notification polling: unread lecturer responses ' + '"?' + '"? */
function markResponsesRead(){
  require(['core/ajax'],function(A){A.call([{methodname:'local_umat_ai_mark_responses_read',args:{courseid:courseId||0}}])[0].done(function(){});});
  var b=document.getElementById('sb-badge-responses');if(b)b.style.display='none';
  var gt=document.querySelector('#stu-glass-tabs [data-sb-tab="report-issue"] .umat-gb');
  if(gt)gt.style.display='none';
}
function pollUnreadCount(){
  require(['core/ajax'],function(A){
    A.call([{methodname:'local_umat_ai_get_unread_response_count',args:{courseid:courseId||0}}])[0].done(function(r){
      var c=r.count||0;
      var b=document.getElementById('sb-badge-responses');
      if(b){b.textContent=c>9?'9+':c;b.style.display=c?'':'none';}
      /* Also badge on glass tabs (mobile) */
      var gt=document.querySelector('#stu-glass-tabs [data-sb-tab="report-issue"]');
      if(gt){var gb=gt.querySelector('.umat-gb');if(!gb){gb=document.createElement('span');gb.className='umat-gb';gb.style.cssText='position:absolute;top:2px;right:2px;background:var(--u-ter);color:#fff;font-size:8px;font-weight:700;padding:1px 4px;border-radius:999px;line-height:12px;min-width:14px;text-align:center;';gt.style.position='relative';gt.appendChild(gb);}gb.textContent=c>9?'9+':c;gb.style.display=c?'':'none';}
    });
  });
}
/* Save quiz state to server before page unload */
window.addEventListener('beforeunload',function(){
  if(qz.active&&courseId&&qz.data){_umatSaveQuizState();}
});

/* ─── Message Nav Strip + Scroll-to-Bottom ─── */
function _rebuildMsgNav(){
  var nav=document.getElementById('ws-msg-nav');
  var msgs=document.getElementById('ws-msgs');
  if(!nav||!msgs)return;
  var msgNodes=msgs.querySelectorAll(':scope > [data-msg-id]');
  var count=msgNodes.length;
  if(!count){nav.innerHTML='';return;}
  var activeMid=null;
  var activeEl=msgs.querySelector('.umat-msg-ai.umat-msg-streaming')||msgs.querySelector('[data-msg-id].umat-msg-user:last-child');
  if(!activeEl)activeEl=msgs.querySelector('[data-msg-id]:last-child');
  if(activeEl)activeMid=activeEl.getAttribute('data-msg-id');
  var sampledCount=Math.min(7,count);
  var sampleInterval=Math.max(1,Math.floor((count-1)/(sampledCount-1||1)));
  var activeIdx=-1;
  msgNodes.forEach(function(m,i){
    if(m.getAttribute('data-msg-id')===activeMid)activeIdx=i;
  });
  if(activeIdx<0)activeIdx=count-1;
  var sampleSet={};
  for(var si=0;si<count;si+=sampleInterval)sampleSet[si]=true;
  if(!sampleSet[activeIdx])sampleSet[activeIdx]=true;
  var sampleKeys=Object.keys(sampleSet).map(Number).sort(function(a,b){return a-b;});
  while(sampleKeys.length>sampledCount){
    var removed=false;
    for(var sii=1;sii<sampleKeys.length-1;sii++){
      if(sampleKeys[sii]!==activeIdx){sampleKeys.splice(sii,1);removed=true;break;}
    }
    if(!removed)break;
  }
  /* Store sampleKeys on nav for active tracking fallback */
  nav._sampleKeys=sampleKeys;
  nav._msgNodes=msgNodes;
  /* Find active index within sampled set */
  var activeSampIdx=-1;
  sampleKeys.forEach(function(sk,i){
    if(msgNodes[sk]&&msgNodes[sk].getAttribute('data-msg-id')===activeMid)activeSampIdx=i;
  });
  if(activeSampIdx<0)activeSampIdx=sampleKeys.length-1;
  var navH=140;
  var gap=navH/(sampleKeys.length-1||1);
  var html=[];
  sampleKeys.forEach(function(sk,i){
    var m=msgNodes[sk];
    var mid=m.getAttribute('data-msg-id');
    var top=Math.round(i*gap)+8;
    var isActive=i===activeSampIdx;
    /* Dial width: widest at active, taper outward */
    var dist=Math.abs(i-activeSampIdx);
    var clamped=Math.min(dist,3);
    var pad=3+clamped*((8-3)/3);
    pad=Math.round(pad*10)/10;
    var preview='';
    var txt=(m.textContent||'').trim().replace(/\s+/g,' ');
    preview=txt.substring(0,70)+(txt.length>70?'...':'');
    html.push('<div class="umat-msg-nav-dash sampled'+(isActive?' active':'')+'" data-target="'+mid+'" style="top:'+top+'px;left:'+pad+'px;right:'+pad+'px;"><div class="umat-msg-nav-dash-tip">'+_umatEsc(preview)+'</div></div>');
  });
  nav.innerHTML=html.join('');
  nav.querySelectorAll('.umat-msg-nav-dash').forEach(function(d){
    d.addEventListener('click',function(e){
      e.stopPropagation();
      var target=document.querySelector('[data-msg-id="'+d.getAttribute('data-target')+'"]');
      if(target)target.scrollIntoView({behavior:'smooth',block:'start'});
    });
  });
}
function _updateMsgNavActive(){
  var msgs=document.getElementById('ws-msgs');
  var nav=document.getElementById('ws-msg-nav');
  if(!msgs||!nav)return;
  var dashes=nav.querySelectorAll('.umat-msg-nav-dash');
  if(!dashes.length)return;
  var scrollH=msgs.scrollHeight-msgs.clientHeight;
  var frac=scrollH>0?msgs.scrollTop/scrollH:0;
  var idx=Math.round(frac*(dashes.length-1));
  idx=Math.max(0,Math.min(idx,dashes.length-1));
  dashes.forEach(function(d,i){
    d.classList.toggle('active',i===idx);
  });
}
function _initMsgNav(){
  var msgs=document.getElementById('ws-msgs');
  var nav=document.getElementById('ws-msg-nav');
  var scrollBtn=document.getElementById('ws-scroll-bottom');
  if(!msgs||!nav||!scrollBtn)return;
  _rebuildMsgNav();
  var scrollTimer=null;
  msgs.addEventListener('scroll',function(){
    if(scrollTimer)clearTimeout(scrollTimer);
    scrollTimer=setTimeout(function(){_updateMsgNavActive();},80);
    var nearBottom=msgs.scrollHeight-msgs.scrollTop-msgs.clientHeight<100;
    scrollBtn.classList.toggle('visible',!nearBottom);
  });
  scrollBtn.addEventListener('click',function(){
    msgs.scrollTo({top:msgs.scrollHeight,behavior:'smooth'});
  });
  var msgObserver=new MutationObserver(function(){
    _rebuildMsgNav();
    _updateMsgNavActive();
    var nearBottom=msgs.scrollHeight-msgs.scrollTop-msgs.clientHeight<100;
    scrollBtn.classList.toggle('visible',!nearBottom);
  });
  msgObserver.observe(msgs,{childList:true,subtree:false});
}
/* Init nav on first AI tutor tab activation */
var _origSwitchToTab=switchToTab;
var _navInited=false;
switchToTab=function(name){
  if(name==='ai-tutor'&&!_navInited){
    _navInited=true;
    setTimeout(_initMsgNav,200);
  }
  _origSwitchToTab(name);
};

pollUnreadCount();
var _stuBadgeTimer=setInterval(pollUnreadCount,30000);
})();
}
};
});
