define(['local_umat_ai/umatshared','local_umat_ai/material_viewer'],function(S,M){
function bindChat(input,sendButton,messages,onSend){
  if(!input||!sendButton||!messages){
    console.warn('[umat] Hub chat controls are missing; chat was not initialized.');
    return null;
  }
  if(sendButton._umatChatControl)return sendButton._umatChatControl;
  function sync(){
    if(sendButton.getAttribute('aria-busy')!=='true')sendButton.disabled=!input.value.trim();
  }
  function submit(){
    if(sendButton.getAttribute('aria-busy')==='true')return;
    var question=input.value.trim();
    if(!question){sync();return;}
    onSend(question);
    sync();
  }
  sendButton.addEventListener('click',submit);
  input.addEventListener('keydown',function(e){
    if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();submit();}
  });
  input.addEventListener('input',function(){
    this.style.height='auto';
    this.style.height=Math.min(this.scrollHeight,200)+'px';
    sync();
  });
  sendButton._umatChatControl={submit:submit,sync:sync};
  sync();
  return sendButton._umatChatControl;
}
return{bindChat:bindChat,init:function(data){for(var k in S)window[k]=S[k];window.umatMaterialViewer=M;
window.renderVideoTiles=S.renderVideoTiles;window.renderCourses=S.renderCourses;
window.renderLibrary=S.renderLibrary;window.renderLibTiles=S.renderLibTiles;window.esc=S.esc;
(function(){
'use strict';
var UD = typeof data.userData === 'string' ? JSON.parse(data.userData) : data.userData || {};
var UID = data.userId;
var streamUrl = data.streamUrl;
var moodleSesskey = data.moodleSesskey;
var sessKey = 'hub_'+Math.random().toString(36).substr(2,18);
var _msgIdCounter = 0;
/* Rolling 60s rate-limit window — mirrors the server check, refills as entries expire */
var RATE_MAX = 10;
var qTimes   = [];
var selMat  = [];
var loaded  = {};
var defaultCID = (UD.courses && UD.courses.length) ? UD.courses[0].id : 0;
var activeCID = defaultCID;

/* FAB / overlay toggle */
var fab=document.getElementById('hub-fab');
var ov=document.getElementById('hub-ov');
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
window.switchPane=switchPane;
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
      '<div class="umat-session-actions"><button class="umat-resume-btn" type="button">Resume →</button>'+
      '<button class="umat-del-session-btn" type="button" title="Delete session"><span class="material-symbols-outlined">delete</span></button></div></div></div>';
  }).join('');
  container.querySelectorAll('.umat-session-tile').forEach(function(t){
    t.addEventListener('click',function(){resumeSession(t.dataset.sk,parseInt(t.dataset.cid)||0,t.dataset.cn||'');});
    var del=t.querySelector('.umat-del-session-btn');
    if(del)del.addEventListener('click',function(e){
      e.stopPropagation();
      if(!confirm('Delete this conversation? This cannot be undone.'))return;
      var btn=e.currentTarget;
      btn.disabled=true;btn.innerHTML='<span class="material-symbols-outlined">hourglass_empty</span>';
      ajax('local_umat_ai_delete_session',{session_key:t.dataset.sk},function(){
        t.remove();
        if(!container.querySelector('.umat-session-tile')){
          container.innerHTML='<div class="umat-empty"><span class="material-symbols-outlined">history</span><p>No past sessions yet.</p></div>';
        }
      },function(){
        btn.disabled=false;btn.innerHTML='<span class="material-symbols-outlined">delete</span>';
        alert('Could not delete session. Please try again.');
      });
    });
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
            '<p class="yt-channel">'+ext+' Â· '+sz+'</p>'+
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
          var type=mime.indexOf('video')>=0?'video':mime.indexOf('pdf')>=0?'pdf':mime.indexOf('image')>=0?'image':mime.indexOf('audio')>=0?'audio':mime.indexOf('wordprocessingml.document')>=0||mime.indexOf('msword')>=0?'docx':mime.indexOf('spreadsheetml.sheet')>=0||mime.indexOf('excel')>=0?'xlsx':mime.indexOf('presentationml.presentation')>=0||mime.indexOf('powerpoint')>=0?'pptx':'other';
          window.umatMaterialViewer.open(type,{url:url,name:name,downloadUrl:url,mime:mime});
        }else{window.open(url,'_blank');}
      });
      var vb=tile.querySelector('.yt-view-btn');
      if(vb)vb.addEventListener('click',function(e){e.stopPropagation();tile.click();});
    });
    var srch=document.getElementById('hub-lib-search');if(srch)srch.addEventListener('input',function(){var q=this.value.toLowerCase();g.querySelectorAll('.yt-tile').forEach(function(t){t.style.display=(!q||t.textContent.toLowerCase().includes(q))?'':'none';});});
  },function(){console.error('[umat] hub loadLibrary failed');g.innerHTML='<div class="umat-empty" style="grid-column:1/-1;"><span class="material-symbols-outlined">error_outline</span><p>Could not load materials.</p></div>';});
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
var _hubRateTimer=setInterval(updateRate,5000);
function appendMsg(text,isUser,container,sources,mats){
  var d=document.createElement('div');
  var mid='msg_'+(++_msgIdCounter);
  d.setAttribute('data-msg-id',mid);
  d.setAttribute('data-msg-role',isUser?'user':'ai');
  if(isUser){
    var refNames=[];var cleanQ=text;
    var refMatch=cleanQ.match(/^\[Referencing:\s*([^\]]+)\]\s*/i);
    if(refMatch){refNames=refMatch[1].split(',').map(function(s){return s.trim();}).filter(Boolean);cleanQ=cleanQ.substring(refMatch[0].length);}
    if(mats&&mats.length&&!refNames.length)refNames=mats.map(function(m){return m.name||m;});
    var chipHtml='';
    if(refNames.length){chipHtml='<div class="umat-ref-chips">'+refNames.map(function(n){return '<span class="umat-ref-chip"><span class="material-symbols-outlined">attach_file</span>'+esc(n)+'</span>';}).join('')+'</div>';}
    d.innerHTML='<div class="umat-msg-user"><div class="umat-bubble-user"><p>'+esc(cleanQ)+'</p></div>'+chipHtml+'<button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div>';
  } else {var srcs='';if(sources&&sources.length)srcs='<div class="umat-src-chips">'+sources.map(function(s){return '<span class="umat-src-chip">'+esc(s)+'</span>';}).join('')+'</div>';
    d.innerHTML='<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-ai-content">'+_umatFormatAI(text)+'</div>'+srcs+'</div><button class="umat-reply-btn" type="button" title="Reply"><span class="material-symbols-outlined">reply</span></button></div></div>';}
  var rb=d.querySelector('.umat-reply-btn');
  if(rb)rb.addEventListener('click',_umatHandleReply);
  container.appendChild(d);container.scrollTop=container.scrollHeight;
}
function sendQ(q){
  q=(q||'').trim();if(!q)return;
  if(qRemaining()<=0){appendMsg('Rate limit reached. Please wait a moment before asking again.',false,document.getElementById('hub-msgs'),[]);return;}
  qTimes.push(Date.now());updateRate();
  var replyTxt=(typeof _getReplyContext==='function')?_getReplyContext():null;
  if(replyTxt){q='[Replying to: "'+replyTxt+'"] '+q;_clearReplyContext();var rp=document.getElementById('umat-reply-preview');if(rp)rp.remove();}
  var ctx=selMat.length>0?'[Referencing: '+selMat.map(function(m){return m.name;}).join(', ')+'] '+q:q;
  var cid=parseInt(document.getElementById('hub-course-sel').value)||activeCID||defaultCID;
  var msgs=document.getElementById('hub-msgs');
  appendMsg(q,true,msgs,undefined,selMat);var hi=document.getElementById('hub-input');if(hi){hi.value='';hi.style.height='auto';}
  var tid='h_'+Date.now();
  _umatShowTyping('hub-msgs', tid);
  _umatStreamChat({
    url:streamUrl,sesskey:moodleSesskey,courseid:cid,question:ctx,session_key:sessKey,
    material_ids:selMat.map(function(m){return m.id;}),msgsId:'hub-msgs',
    sendBtnId:'hub-send',sendInputId:'hub-input',
    typingId:tid,
    onMeta:function(meta){syncRemaining(meta.remaining);updateRate();},
    onDone:function(meta){_umatHideTyping(tid);syncRemaining(meta.remaining);updateRate();if(hubChatControl)hubChatControl.sync();},
    onError:function(err){
      _umatHideTyping(tid);
      if(err.error==='rate_limit'){qTimes.pop();updateRate();}
      if(hubChatControl)hubChatControl.sync();
    }
  });
}
var hubIn=document.getElementById('hub-input');var hubSend=document.getElementById('hub-send');
var hubMsgs=document.getElementById('hub-msgs');
var hubChatControl=bindChat(hubIn,hubSend,hubMsgs,sendQ);
if(hubMsgs&&hubIn&&hubChatControl)hubMsgs.addEventListener('click',function(e){var chip=e.target.closest('.umat-chip[data-q]');if(chip){hubIn.value=chip.dataset.q;hubChatControl.submit();}});
/* scroll-to-bottom */
(function(){var ms=document.getElementById('hub-msgs'),sb=document.getElementById('hub-scroll-bottom');if(!ms||!sb)return;var t=null;ms.addEventListener('scroll',function(){if(t)clearTimeout(t);t=setTimeout(function(){sb.classList.toggle('visible',ms.scrollHeight-ms.scrollTop-ms.clientHeight<100?false:true);},80);});sb.addEventListener('click',function(){ms.scrollTo({top:ms.scrollHeight,behavior:'smooth'});});var mo=new MutationObserver(function(){sb.classList.toggle('visible',ms.scrollHeight-ms.scrollTop-ms.clientHeight<100?false:true);});mo.observe(ms,{childList:true,subtree:false});})();

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
  var micBtn=document.getElementById('hub-mic-btn');
  var hubIn=document.getElementById('hub-input');
  if(!micBtn||!hubIn)return;
  new ChatVoiceInput({input:hubIn, btn:micBtn, sesskey:moodleSesskey});
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
}
};
});

