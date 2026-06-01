// AMD module: local_umat_ai/umatshared
// Shared helper functions for UMaT AI overlays.
// Used by student, lecturer, and hub workspace overlays.
define([], function() {
    'use strict';

    // ─── HTML Escaping ─────────────────────────────── //
    function _umatEsc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
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
    function _umatAppendUser(cid, q) {
        var c = document.getElementById(cid);
        if (!c) return;
        var d = document.createElement('div');
        d.innerHTML = '<div class="umat-msg-user"><div class="umat-bubble-user"><p>' + _umatEsc(q) + '</p></div></div>';
        c.appendChild(d);
        c.scrollTop = c.scrollHeight;
    }

    // ─── Append AI message bubble ──────────────────── //
    function _umatAppendAi(cid, t, s) {
        var c = document.getElementById(cid);
        if (!c) return;
        var src = '';
        if (s && s.length) {
            src = '<div class="umat-src-chips">' + s.map(function(x) {
                return '<span class="umat-src-chip">' + _umatEsc(x) + '</span>';
            }).join('') + '</div>';
        }
        var d = document.createElement('div');
        d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><p>' + _umatEsc(t) + '</p>' + src + '</div></div></div>';
        c.appendChild(d);
        c.scrollTop = c.scrollHeight;
    }

    // ─── Show typing indicator ─────────────────────── //
    function _umatShowTyping(cid, tid) {
        var c = document.getElementById(cid);
        if (!c) return;
        var d = document.createElement('div');
        d.id = tid;
        d.innerHTML = '<div class="umat-msg-ai"><div class="umat-msg-ai-ic"><span class="material-symbols-outlined">smart_toy</span></div><div class="umat-msg-ai-wrap"><div class="umat-msg-lbl">AI TUTOR</div><div class="umat-bubble-ai"><div class="umat-typing"><span></span><span></span><span></span></div></div></div></div>';
        c.appendChild(d);
        c.scrollTop = c.scrollHeight;
    }

    // ─── Hide typing indicator ─────────────────────── //
    function _umatHideTyping(tid) {
        var e = document.getElementById(tid);
        if (e) e.parentNode.removeChild(e);
    }

    // ─── Voice input init ──────────────────────────── //
    function _umatInitVoice(inp, btn) {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR || !btn) {
            if (btn) btn.style.opacity = '.4';
            return;
        }
        var rec = new SR();
        rec.continuous = false;
        rec.interimResults = true;
        rec.lang = 'en-US';
        var a = false;
        btn.addEventListener('click', function() {
            if (a) { rec.stop(); } else { rec.start(); a = true; btn.classList.add('recording'); }
        });
        rec.onresult = function(e) {
            inp.value = Array.from(e.results).map(function(r) { return r[0].transcript; }).join('');
        };
        rec.onend = function() { a = false; btn.classList.remove('recording'); };
        rec.onerror = function() { a = false; btn.classList.remove('recording'); };
    }

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
                    btn.innerHTML = remaining.length ? '<span class="material-symbols-outlined">attach_file</span>' + remaining.length + ' ref' : '<span class="material-symbols-outlined">attach_file</span>Ref Material';
                }
            });
        });
        if (!btn) return;
        btn.style.color = mats.length ? 'var(--u-p)' : '';
        btn.innerHTML = mats.length ? '<span class="material-symbols-outlined">attach_file</span>' + mats.length + ' ref' : '<span class="material-symbols-outlined">attach_file</span>Ref Material';
    }

    // ─── Init attachment drawer ────────────────────── //
    function _umatInitAttachDrawer(cfg) {
        var d = document.getElementById(cfg.drawerId);
        var ab = document.getElementById(cfg.attachBtnId);
        if (!ab || !d) return;
        var m = [];

        function closeDrawer() { d.classList.remove('open'); }

        function loadMats() {
            var cid = typeof cfg.getCourseId === 'function' ? cfg.getCourseId() : cfg.courseid;
            if (!cid) return;
            var l = document.getElementById(cfg.listId);
            if (!l) return;
            require(['core/ajax'], function(A) {
                A.call([{ methodname: 'local_umat_ai_get_course_materials', args: { courseid: cid } }])[0]
                    .done(function(r) {
                        var ms = r.materials || [];
                        if (!ms.length) {
                            l.innerHTML = '<div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">No materials for this course.</div>';
                            return;
                        }
                        l.innerHTML = ms.map(function(x) {
                            return '<label class="umat-drawer-item"><input type="checkbox" value="' + x.id + '" data-name="' + _umatEsc(x.filename) + '"><div class="umat-drawer-item-icon di-doc"><span class="material-symbols-outlined" style="font-size:16px;">description</span></div><div class="umat-drawer-item-info"><strong>' + _umatEsc(x.filename) + '</strong><span>' + ((x.filesize || 0) / 1024).toFixed(0) + 'KB</span></div></label>';
                        }).join('');
                        l.querySelectorAll('input[type=checkbox]').forEach(function(cb) {
                            cb.addEventListener('change', function() {
                                m = [];
                                l.querySelectorAll('input:checked').forEach(function(c) {
                                    m.push({ id: c.value, name: c.dataset.name });
                                });
                                var cnt = document.getElementById(cfg.countId);
                                if (cnt) cnt.textContent = m.length + ' selected';
                            });
                        });
                    }).fail(function() {
                        l.innerHTML = '<div style="text-align:center;padding:20px;color:var(--u-ol);font-size:13px;">Failed to load materials.</div>';
                    });
            });
        }
        ab.addEventListener('click', function() {
            d.classList.toggle('open');
            if (d.classList.contains('open')) { d.dataset.loaded = '1'; loadMats(); }
        });
        var cb = document.getElementById(cfg.closeBtnId);
        if (cb) cb.addEventListener('click', closeDrawer);
        var cf = document.getElementById(cfg.confirmId);
        if (cf) cf.addEventListener('click', function() { closeDrawer(); if (cfg.onConfirm) cfg.onConfirm(m); });
        document.addEventListener('click', function(e) {
            if (d.classList.contains('open') && !d.contains(e.target) && !ab.contains(e.target)) closeDrawer();
        });
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
    function _renderYtMaterials(mats, g, pfx) {
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
            return '<div class="yt-tile" data-url="' + _umatEsc(m.url || '') + '" data-name="' + _umatEsc(m.filename || '') + '" data-mime="' + _umatEsc(mime) + '" data-fileid="' + (m.id || 0) + '">' +
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
                '<button class="yt-btn yt-analysis-btn" style="display:none" data-analysed="false"><span class="material-symbols-outlined anal-status-dot">circle</span><span class="anal-text">Analyze</span></button>' +
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
                        mime.indexOf('spreadsheetml.sheet') !== -1 ? 'xlsx' :
                        mime.indexOf('presentationml.presentation') !== -1 ? 'pptx' :
                        (mime.indexOf('text/') === 0 || mime.indexOf('application/json') === 0 || mime.indexOf('application/xml') === 0 || mime.indexOf('application/x-httpd-php') !== -1) ? 'code' :
                        'other';
                    window.umatMaterialViewer.open(type, { url: url, name: name, downloadUrl: url, mime: mime });
                } else {
                    window.open(url, '_blank');
                }
            });
            var vb = tile.querySelector('.yt-view-btn');
            if (vb) vb.addEventListener('click', function(e) { e.stopPropagation(); tile.click(); });
        });
    }

    // ─── Video tiles (student Lectures tab) ────────── //
    function renderVideoTiles(recs) {
        var grid = document.getElementById('stu-lec-grid') || document.getElementById('ws-video-grid');
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
            return '<div class="yt-tile" data-idx="' + i + '" data-url="' + _umatEsc(r.url || '') + '" data-title="' + _umatEsc(r.title || 'Lecture Recording') + '" data-segs=\'' + segsData + '\'>' +
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
                '<a class="yt-btn" href="' + _umatEsc(r.url || '#') + '" download onclick="event.stopPropagation()"><span class="material-symbols-outlined">download</span>Download</a>' +
                '</div>' +
                '</div>';
        }).join('');

        grid.querySelectorAll('.yt-tile').forEach(function(tile) {
            tile.addEventListener('click', function(e) {
                if (e.target.closest('a.yt-btn')) return;
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
    function renderLibrary(mats) {
        var grid = document.getElementById('stu-lib-grid') || document.getElementById('ws-lib-grid');
        if (!grid) return;
        if (mats && !Array.isArray(mats)) mats = mats.materials || [];
        _renderYtMaterials(mats || [], grid, 'stu');
    }

    // ─── Lecturer library tiles ────────────────────── //
    function renderLibTiles(materials, g) {
        if (!g) { g = document.getElementById('lec-lib-grid'); }
        _renderYtMaterials(materials, g, 'lec');
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

    // ─── Material Analysis Status ───────────────────── //
    function updateMaterialAnalysis(courseId) {
        if (!courseId) return;
        ajax('local_umat_ai_get_analysis_status', { courseid: courseId },
            function (resp) {
                var materials = resp.materials || [];
                var lookup = {};
                materials.forEach(function (m) {
                    lookup[m.fileid] = m;
                });
                document.querySelectorAll('.yt-tile[data-fileid]').forEach(function (tile) {
                    var fid = parseInt(tile.dataset.fileid);
                    var info = lookup[fid];
                    var btn = tile.querySelector('.yt-analysis-btn');
                    if (!btn) return;
                    if (!info) {
                        btn.style.display = 'none';
                        return;
                    }
                    btn.style.display = '';
                    btn.dataset.materialId = info.material_id || 0;
                    btn.dataset.analysed = info.is_analyzed ? 'true' : 'false';
                    var dot = btn.querySelector('.anal-status-dot');
                    var txt = btn.querySelector('.anal-text');
                    if (info.is_analyzed) {
                        dot.textContent = 'check_circle';
                        dot.style.color = '#4caf50';
                        txt.textContent = info.last_analysis ? (info.last_analysis.summary ? 'Analysis' : 'Analyzed') : 'Analyzed';
                        btn.title = info.last_analysis && info.last_analysis.summary ? info.last_analysis.summary : 'Material has been analyzed';
                        btn.onclick = function (e) {
                            e.stopPropagation();
                            // Show analysis modal / details (future)
                        };
                    } else {
                        dot.textContent = 'radio_button_unchecked';
                        dot.style.color = '#999';
                        txt.textContent = 'Analyze';
                        btn.title = 'Request AI analysis of this material';
                        btn.onclick = function (e) {
                            e.stopPropagation();
                            var mid = parseInt(this.dataset.materialId);
                            if (!mid) return;
                            var self = this;
                            var dot2 = self.querySelector('.anal-status-dot');
                            var txt2 = self.querySelector('.anal-text');
                            dot2.textContent = 'sync';
                            dot2.style.color = '#ff9800';
                            txt2.textContent = 'Analyzing\u2026';
                            self.disabled = true;
                            ajax('local_umat_ai_request_analysis', {
                                courseid: courseId,
                                material_id: mid,
                                analysis_type: 'full_analysis',
                                scope: '',
                                force: false,
                            }, function (res) {
                                self.disabled = false;
                                if (res.success) {
                                    dot2.textContent = 'check_circle';
                                    dot2.style.color = '#4caf50';
                                    txt2.textContent = 'Analyzed';
                                    self.dataset.analysed = 'true';
                                } else {
                                    dot2.textContent = 'error';
                                    dot2.style.color = '#f44336';
                                    txt2.textContent = 'Failed';
                                    setTimeout(function () {
                                        dot2.textContent = 'radio_button_unchecked';
                                        dot2.style.color = '#999';
                                        txt2.textContent = 'Analyze';
                                    }, 3000);
                                }
                            });
                        };
                    }
                });
            },
            function () { /* silently fail */ }
        );
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

        // Voice
        _umatInitVoice: _umatInitVoice,

        // Materials bar
        _umatRenderMatsBar: _umatRenderMatsBar,

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
        renderCourses: renderCourses,
        renderLibrary: renderLibrary,
        renderLibTiles: renderLibTiles,
        _renderYtMaterials: _renderYtMaterials,

        // ESC handler
        _umatInitEsc: _umatInitEsc,

        // AJAX
        ajax: ajax,

        // Analysis
        updateMaterialAnalysis: updateMaterialAnalysis,
    };
});
