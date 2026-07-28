define([], function () {
    'use strict';

    var viewerEl = null;
    var activeType = null;
    var activeMedia = null;
    var kbdHandler = null;
    var typeCleanups = {};

    var loadedScripts = {};
    var loadedModules = {};

    function loadScript(url) {
        if (loadedScripts[url]) return loadedScripts[url];
        var p = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = url;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
        loadedScripts[url] = p;
        return p;
    }

    function loadESModule(url) {
        if (loadedModules[url]) return loadedModules[url];
        var p = import(url).then(function (m) { return m; }).catch(function (err) {
            delete loadedModules[url];
            throw err;
        });
        loadedModules[url] = p;
        return p;
    }

    var loadedCss = {};

    function loadCss(url) {
        if (loadedCss[url]) return loadedCss[url];
        var p = new Promise(function (resolve, reject) {
            var l = document.createElement('link');
            l.rel = 'stylesheet';
            l.href = url;
            l.onload = resolve;
            l.onerror = reject;
            document.head.appendChild(l);
        });
        loadedCss[url] = p;
        return p;
    }

    function showLoading(container, msg) {
        container.innerHTML =
            '<div class="umat-vw-loading">' +
                '<div class="umat-vw-spinner"></div>' +
                '<p>' + esc(msg || 'Loading\u2026') + '</p>' +
            '</div>';
    }

    function formatBytes(size) {
        if (!size) return '';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (size > 1024 && i < units.length - 1) { size /= 1024; i++; }
        return (Math.round(size * 100) / 100) + ' ' + units[i];
    }

    function fmtDuration(s) {
        if (!s && s !== 0) return '';
        var m = Math.floor(s / 60);
        var sc = Math.floor(s % 60);
        return m + ':' + (sc < 10 ? '0' : '') + sc;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function showError(container, msg, detail, retryFn) {
        var html = '<div class="umat-vw-empty">' +
            '<span class="material-symbols-outlined">error_outline</span>' +
            '<p>' + esc(msg || 'Could not load.') + '</p>';
        if (detail) html += '<p class="umat-vw-err-detail">' + esc(detail) + '</p>';
        if (retryFn) html += '<button class="umat-vw-retry-btn" id="umat-vw-retry"><span class="material-symbols-outlined">refresh</span> Retry</button>';
        html += '</div>';
        container.innerHTML = html;
        var btn = container.querySelector('#umat-vw-retry');
        if (btn) btn.addEventListener('click', retryFn);
    }

    function ensureViewer() {
        if (viewerEl && viewerEl.parentNode) return viewerEl;
        viewerEl = document.createElement('div');
        viewerEl.id = 'umat-viewer';
        viewerEl.className = 'umat-viewer-panel';
        viewerEl.innerHTML =
            '<div class="umat-viewer-backdrop"></div>' +
            '<div class="umat-viewer-container">' +
                '<div class="umat-viewer-toolbar">' +
                    '<span class="umat-viewer-filename" id="umat-vw-filename"></span>' +
                    '<span class="umat-viewer-meta" id="umat-vw-meta"></span>' +
                    '<div class="umat-viewer-actions">' +
                        '<a class="umat-vw-btn" id="umat-vw-dl" title="Download" download>' +
                            '<span class="material-symbols-outlined">download</span>' +
                        '</a>' +
                        '<button class="umat-vw-btn" id="umat-vw-expand" title="Expand">' +
                            '<span class="material-symbols-outlined">fullscreen</span>' +
                        '</button>' +
                        '<button class="umat-vw-btn" id="umat-vw-close" title="Close (Esc)">' +
                            '<span class="material-symbols-outlined">close</span>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="umat-viewer-body" id="umat-vw-body"></div>' +
            '</div>';
        document.body.appendChild(viewerEl);
        document.getElementById('umat-vw-close').addEventListener('click', closeViewer);
        document.getElementById('umat-vw-expand').addEventListener('click', toggleExpand);
        viewerEl.querySelector('.umat-viewer-backdrop').addEventListener('click', closeViewer);
        return viewerEl;
    }

    function openViewer(type, opts) {
        opts = opts || {};
        activeType = type;
        var el = ensureViewer();
        var body = document.getElementById('umat-vw-body');
        document.getElementById('umat-vw-filename').textContent = opts.name || '';
        var metaEl = document.getElementById('umat-vw-meta');
        metaEl.textContent = opts.size ? formatBytes(opts.size) : (opts.meta || '');
        var dlEl = document.getElementById('umat-vw-dl');
        if (opts.downloadUrl) {
            dlEl.href = opts.downloadUrl;
            dlEl.style.display = '';
        } else {
            dlEl.href = '#';
            dlEl.style.display = 'none';
        }
        body.innerHTML = '';
        el.classList.add('open');

        if (kbdHandler) document.removeEventListener('keydown', kbdHandler);
        kbdHandler = function (e) {
            if (!viewerEl || !viewerEl.classList.contains('open')) return;
            if (e.key === 'Escape') { closeViewer(); return; }
            if (e.key === 'f' || e.key === 'F') {
                var c = viewerEl.querySelector('.umat-viewer-container');
                if (c) {
                    if (document.fullscreenElement) document.exitFullscreen();
                    else c.requestFullscreen();
                }
                return;
            }
            if (activeType === 'video' && activeMedia) {
                if (e.key === ' ') { e.preventDefault(); if (activeMedia.paused) activeMedia.play(); else activeMedia.pause(); }
                if (e.key === 'ArrowLeft') activeMedia.currentTime = Math.max(0, activeMedia.currentTime - 10);
                if (e.key === 'ArrowRight') activeMedia.currentTime = Math.min(activeMedia.duration || 0, activeMedia.currentTime + 10);
                if (e.key === 'ArrowUp') activeMedia.volume = Math.min(1, activeMedia.volume + 0.1);
                if (e.key === 'ArrowDown') activeMedia.volume = Math.max(0, activeMedia.volume - 0.1);
            }
        };
        document.addEventListener('keydown', kbdHandler);

        switch (type) {
            case 'video': viewVideo(body, opts); break;
            case 'image': viewImage(body, opts); break;
            case 'audio': viewAudio(body, opts); break;
            case 'pdf':   viewPdf(body, opts); break;
            case 'docx':  viewDocx(body, opts); break;
            case 'doc':   viewUnsupported(body, opts); break;
            case 'xlsx':  viewXlsx(body, opts); break;
            case 'pptx':  viewPptx(body, opts); break;
            case 'ppt':   viewUnsupported(body, opts); break;
            case 'code':  viewCode(body, opts); break;
            default:
                body.innerHTML = '<div class="umat-vw-empty"><span class="material-symbols-outlined">description</span><p>Preview not available for this file type. <a href="' + esc(opts.downloadUrl || '#') + '" download style="color:var(--u-pri);text-decoration:underline;">Download</a> the file to view it.</p></div>';
        }
    }

    function closeViewer() {
        if (!viewerEl || !viewerEl.classList.contains('open')) return;
        viewerEl.classList.remove('open');
        var body = document.getElementById('umat-vw-body');
        if (body) {
            body.innerHTML = '';
            body.style.padding = '';
            body.style.overflow = '';
        }
        document.getElementById('umat-vw-filename').textContent = '';
        document.getElementById('umat-vw-meta').textContent = '';
        if (activeMedia) {
            activeMedia.pause();
            activeMedia.src = '';
            activeMedia = null;
        }
        if (kbdHandler) {
            document.removeEventListener('keydown', kbdHandler);
            kbdHandler = null;
        }
        // Run type-specific cleanup
        if (typeCleanups[activeType]) {
            typeCleanups[activeType]();
            delete typeCleanups[activeType];
        }
        activeType = null;
    }

    function toggleExpand() {
        var container = viewerEl.querySelector('.umat-viewer-container');
        if (!container) return;
        var btn = document.getElementById('umat-vw-expand');
        var isExpanded = container.classList.toggle('umat-viewer-expanded');
        btn.innerHTML = '<span class="material-symbols-outlined">' + (isExpanded ? 'fullscreen_exit' : 'fullscreen') + '</span>';
        btn.title = isExpanded ? 'Exit fullscreen' : 'Fullscreen';
    }

    // ═══════════════════════════════════════════
    //  VIDEO PLAYER
    // ═══════════════════════════════════════════

    function viewVideo(container, opts) {
        var url = opts.url;
        if (!url) {
            container.innerHTML = '<div class="umat-vw-empty"><span class="material-symbols-outlined">videocam_off</span><p>No video URL provided.</p></div>';
            return;
        }

        var segments = opts.segments || opts.transcript || [];
        var retryCount = 0;

        function render() {
            container.innerHTML =
                '<div class="umat-vw-video-section">' +
                    '<div class="umat-vw-video-wrap">' +
                        '<video id="umat-vw-video" preload="metadata" playsinline></video>' +
                        '<div class="umat-vw-vc">' +
                            '<button class="umat-vw-vc-btn" id="umat-vw-r10"><span class="material-symbols-outlined">replay_10</span></button>' +
                            '<button class="umat-vw-vc-btn umat-vw-pp" id="umat-vw-pp"><span class="material-symbols-outlined">play_arrow</span></button>' +
                            '<button class="umat-vw-vc-btn" id="umat-vw-f10"><span class="material-symbols-outlined">forward_10</span></button>' +
                            '<span class="umat-vw-vc-time"><span id="umat-vw-cur">0:00</span> / <span id="umat-vw-dur">0:00</span></span>' +
                            '<input type="range" id="umat-vw-prog" class="umat-vw-progress" min="0" max="100" value="0">' +
                        '</div>' +
                    '</div>' +
                    (segments.length ? '' +
                    '<div class="umat-vw-transcript">' +
                        '<div class="umat-vw-ts-hdr">' +
                            '<h4><span class="material-symbols-outlined">subtitles</span> Transcript</h4>' +
                            '<div class="umat-vw-ts-srch"><span class="material-symbols-outlined">search</span><input type="text" id="umat-vw-ts-srch" placeholder="Search transcript\u2026"></div>' +
                        '</div>' +
                        '<div class="umat-vw-ts-body" id="umat-vw-ts-body">' +
                            segments.map(function (seg) {
                                var ts = seg.timestamp || fmtDuration(seg.start);
                                return '<div class="umat-vw-ts-seg" data-start="' + (seg.start || 0) + '" data-end="' + (seg.end || 0) + '">' +
                                    '<span class="umat-vw-ts-time">' + esc(ts) + '</span>' +
                                    '<p class="umat-vw-ts-text">' + esc(seg.text) + '</p></div>';
                            }).join('') +
                        '</div>' +
                    '</div>' : '') +
                '</div>';

            var video = document.getElementById('umat-vw-video');
            video.src = url;
            activeMedia = video;

            video.addEventListener('loadedmetadata', function () {
                var dur = document.getElementById('umat-vw-dur');
                var prog = document.getElementById('umat-vw-prog');
                if (dur) dur.textContent = fmtDuration(video.duration);
                if (prog) prog.max = Math.floor(video.duration);

                var metaEl = document.getElementById('umat-vw-meta');
                var parts = [];
                if (opts.size) parts.push(formatBytes(opts.size));
                if (video.videoWidth && video.videoHeight) parts.push(video.videoWidth + ' \u00d7 ' + video.videoHeight);
                parts.push(fmtDuration(video.duration));
                metaEl.textContent = parts.join(' \u00b7 ');
            });

            video.addEventListener('error', function () {
                var msg = video.error ? (video.error.message || 'Unknown error') : 'Failed to load video';
                showError(container, 'Could not load video.', msg, function () {
                    retryCount++;
                    if (retryCount > 3) { showError(container, 'Video failed after multiple attempts.', 'Please try downloading the file instead.', null); return; }
                    render();
                });
            });

            video.addEventListener('timeupdate', function () {
                var cur = document.getElementById('umat-vw-cur');
                var prog = document.getElementById('umat-vw-prog');
                if (cur) cur.textContent = fmtDuration(video.currentTime);
                if (prog) prog.value = Math.floor(video.currentTime);
                if (segments.length) {
                    var tb = document.getElementById('umat-vw-ts-body');
                    if (tb) tb.querySelectorAll('.umat-vw-ts-seg').forEach(function (s) {
                        var a = parseFloat(s.dataset.start || 0);
                        var b = parseFloat(s.dataset.end || 0);
                        var isActive = video.currentTime >= a && video.currentTime <= b;
                        s.classList.toggle('active', isActive);
                        if (isActive) s.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });
                }
            });

            var pp = document.getElementById('umat-vw-pp');
            video.addEventListener('play', function () { if (pp) pp.querySelector('.material-symbols-outlined').textContent = 'pause'; });
            video.addEventListener('pause', function () { if (pp) pp.querySelector('.material-symbols-outlined').textContent = 'play_arrow'; });
            if (pp) pp.addEventListener('click', function () { if (video.paused) video.play(); else video.pause(); });

            var prog = document.getElementById('umat-vw-prog');
            if (prog) prog.addEventListener('input', function () { video.currentTime = parseInt(this.value); });

            document.getElementById('umat-vw-r10').addEventListener('click', function () { video.currentTime = Math.max(0, video.currentTime - 10); });
            document.getElementById('umat-vw-f10').addEventListener('click', function () { video.currentTime = Math.min(video.duration || 0, video.currentTime + 10); });

            var tsSrch = document.getElementById('umat-vw-ts-srch');
            if (tsSrch) tsSrch.addEventListener('input', function () {
                var q = this.value.toLowerCase();
                var tb = document.getElementById('umat-vw-ts-body');
                if (tb) tb.querySelectorAll('.umat-vw-ts-seg').forEach(function (s) {
                    var txt = s.querySelector('.umat-vw-ts-text');
                    s.style.display = (!q || (txt && txt.textContent.toLowerCase().indexOf(q) !== -1)) ? '' : 'none';
                });
            });

            var tb = document.getElementById('umat-vw-ts-body');
            if (tb) tb.addEventListener('click', function (e) {
                var seg = e.target.closest('.umat-vw-ts-seg');
                if (seg) video.currentTime = parseFloat(seg.dataset.start || 0);
            });

            video.play().catch(function () {});
        }

        render();
    }

    // ═══════════════════════════════════════════
    //  IMAGE LIGHTBOX
    // ═══════════════════════════════════════════

    function viewImage(container, opts) {
        var url = opts.downloadUrl || opts.url;
        if (!url) {
            container.innerHTML = '<div class="umat-vw-empty"><span class="material-symbols-outlined">image</span><p>No image URL provided.</p></div>';
            return;
        }
        var images = opts.images || [{ url: url, name: opts.name, size: opts.size, width: opts.width, height: opts.height }];
        var currentIdx = opts.imageIndex || 0;
        var zoomed = false;

        function renderImage(idx) {
            var imgData = images[idx];
            if (!imgData) return;
            var imgUrl = imgData.downloadUrl || imgData.url;
            zoomed = false;

            container.innerHTML =
                '<div class="umat-vw-image-wrap">' +
                    (images.length > 1 ? '<button class="umat-vw-gallery-nav umat-vw-gallery-prev" id="umat-vw-gprev"><span class="material-symbols-outlined">chevron_left</span></button>' : '') +
                    '<div class="umat-vw-image-stage">' +
                        '<img id="umat-vw-img" src="' + esc(imgUrl) + '" alt="' + esc(imgData.name || '') + '">' +
                        '<div class="umat-vw-img-dims" id="umat-vw-img-dims"></div>' +
                    '</div>' +
                    (images.length > 1 ? '<button class="umat-vw-gallery-nav umat-vw-gallery-next" id="umat-vw-gnext"><span class="material-symbols-outlined">chevron_right</span></button>' : '') +
                '</div>';

            var img = document.getElementById('umat-vw-img');

            // Auto-detect dimensions on load
            img.addEventListener('load', function () {
                var w = imgData.width || opts.width || img.naturalWidth;
                var h = imgData.height || opts.height || img.naturalHeight;
                var sz = imgData.size || opts.size;
                var dimsEl = document.getElementById('umat-vw-img-dims');
                if (w && h) {
                    var dimsText = w + ' \u00d7 ' + h + 'px';
                    if (sz) dimsText += ' \u00b7 ' + formatBytes(sz);
                    dimsEl.textContent = dimsText;
                } else if (sz) {
                    dimsEl.textContent = formatBytes(sz);
                }
            });

            img.addEventListener('click', function () {
                zoomed = !zoomed;
                img.style.cursor = zoomed ? 'zoom-out' : 'zoom-in';
                img.classList.toggle('zoomed', zoomed);
            });

            var prevBtn = document.getElementById('umat-vw-gprev');
            var nextBtn = document.getElementById('umat-vw-gnext');
            if (prevBtn) prevBtn.addEventListener('click', function (e) { e.stopPropagation(); currentIdx = (currentIdx - 1 + images.length) % images.length; renderImage(currentIdx); });
            if (nextBtn) nextBtn.addEventListener('click', function (e) { e.stopPropagation(); currentIdx = (currentIdx + 1) % images.length; renderImage(currentIdx); });

            document.getElementById('umat-vw-filename').textContent = imgData.name || '';
        }

        renderImage(currentIdx);
    }

    // ═══════════════════════════════════════════
    //  AUDIO PLAYER
    // ═══════════════════════════════════════════

    function viewAudio(container, opts) {
        var url = opts.downloadUrl || opts.url;
        if (!url) {
            container.innerHTML = '<div class="umat-vw-empty"><span class="material-symbols-outlined">music_off</span><p>No audio URL provided.</p></div>';
            return;
        }
        container.innerHTML =
            '<div class="umat-vw-audio-wrap">' +
                '<div class="umat-vw-album-art">' +
                    '<div class="umat-vw-album-circle">' +
                        '<span class="material-symbols-outlined">music_note</span>' +
                    '</div>' +
                '</div>' +
                '<audio id="umat-vw-audio" controls src="' + esc(url) + '"></audio>' +
            '</div>';
        activeMedia = document.getElementById('umat-vw-audio');
    }

    // ═══════════════════════════════════════════
    //  PDF VIEWER
    // ═══════════════════════════════════════════

    function viewPdf(container, opts) {
        var url = opts.downloadUrl || opts.url;
        if (!url) { showError(container, 'No PDF URL provided.'); return; }

        var name = opts.name || 'document.pdf';

        var pluginIdx = url.indexOf('pluginfile.php');
        var basePath = pluginIdx !== -1 ? url.substring(0, pluginIdx) : '';
        var proxyUrl = basePath + 'local/umat_ai/pdf_proxy.php?url=' + encodeURIComponent(url);

        container.style.padding = '0';
        container.style.overflow = 'hidden';
        container.innerHTML =
            '<div class="umat-vw-pdf-wrap">' +
                '<iframe src="' + esc(proxyUrl) + '" title="PDF" class="umat-vw-pdf-iframe"></iframe>' +
            '</div>';
    }

    // ═══════════════════════════════════════════
    //  DOCX VIEWER  (silurus/ooxml WASM + mammoth fallback)
    // ═══════════════════════════════════════════

    function viewDocx(container, opts) {
        var url = opts.downloadUrl || opts.url;
        if (!url) {
            showError(container, 'No document URL provided.');
            return;
        }
        showLoading(container, 'Loading document\u2026');

        // Track cleanup
        var docViewer = null;
        var docCanvas = null;
        var mammothFallback = false;

        typeCleanups['docx'] = function () {
            if (docViewer && docViewer.destroy) { try { docViewer.destroy(); } catch (e) {} }
            docViewer = null; docCanvas = null;
        };

        function trySilurus() {
            showLoading(container, 'Rendering with WASM engine\u2026');
            container.style.padding = '0';
            container.style.overflow = 'hidden';

            loadESModule(SILURUS_CDN).then(function (mod) {
                // Create a full-size canvas
                var canvas = document.createElement('canvas');
                canvas.className = 'umat-vw-docx-canvas';
                canvas.style.cssText = 'width:100%;height:100%;display:block;';
                container.innerHTML = '';
                container.appendChild(canvas);
                docCanvas = canvas;

                // Fetch the file
                return fetch(url).then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.arrayBuffer();
                }).then(function (buf) {
                    var viewer = new mod.docx.DocxViewer(canvas, {
                        wasmUrl: SILURUS_WASM,
                        mode: 'main',
                    });
                    docViewer = viewer;
                    return viewer.load(buf);
                }).then(function () {
                    document.getElementById('umat-vw-meta').textContent = '';
                });
            }).catch(function (err) {
                // Silurus failed, fall back to mammoth
                console.warn('[umat] silurus/ooxml failed, falling back to mammoth:', err.message);
                tryFallbackMammoth();
            });
        }

        function tryFallbackMammoth() {
            mammothFallback = true;
            container.style.padding = '';
            container.style.overflow = '';
            showLoading(container, 'Processing document\u2026');

            function doConvert() {
                showLoading(container, 'Processing document\u2026');
                fetch(url).then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.arrayBuffer();
                }).then(function (buf) {
                    return mammoth.convertToHtml({ arrayBuffer: buf });
                }).then(function (result) {
                    container.innerHTML =
                        '<div class="umat-vw-docx-body">' +
                            result.value +
                        '</div>';
                    var msgs = result.messages || [];
                    var warnings = msgs.filter(function (m) { return m.type === 'warning'; });
                    if (warnings.length) {
                        var wbar = document.createElement('div');
                        wbar.className = 'umat-vw-docx-warn';
                        wbar.innerHTML = '<span class="material-symbols-outlined">warning</span> ' +
                            esc(warnings.length + ' conversion warning(s) \u2014 some formatting may differ');
                        container.querySelector('.umat-vw-docx-body').parentNode.insertBefore(wbar, container.querySelector('.umat-vw-docx-body'));
                    }
                    document.getElementById('umat-vw-meta').textContent = '';
                }).catch(function (err) {
                    showError(container, 'Could not render this document.', err.message, doConvert);
                });
            }

            if (window.mammoth) {
                doConvert();
            } else {
                loadScript(MAMMOTH_CDN).then(doConvert).catch(function () {
                    showError(container, 'Failed to load the document viewer library.', 'Check your internet connection or download the file instead.');
                });
            }
        }

        // Start with silurus WASM; if it fails, mammoth fallback kicks in
        trySilurus();
    }

    // ═══════════════════════════════════════════
    //  XLSX VIEWER (SheetJS)
    // ═══════════════════════════════════════════

    function viewXlsx(container, opts) {
        var url = opts.downloadUrl || opts.url;
        if (!url) {
            showError(container, 'No spreadsheet URL provided.');
            return;
        }
        showLoading(container, 'Loading spreadsheet\u2026');

        var CDN_XLSX = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
        var workbook = null;
        var sheetNames = [];
        var activeSheetIdx = 0;
        var rawData = [];
        var sortCol = -1;
        var sortDir = 'asc';
        var globalFilter = '';
        var colFilters = {};
        var colWidths = {};
        var dragCol = -1;
        var dragStartX = 0;
        var dragStartW = 0;

        function getCellValue(sheet, addr) {
            var cell = sheet[addr];
            if (!cell) return '';
            if (cell.w) return cell.w;
            if (cell.v === undefined || cell.v === null) return '';
            return String(cell.v);
        }

        function loadSheetData(idx) {
            var name = sheetNames[idx];
            if (!name) return [];
            var sheet = workbook.Sheets[name];
            if (!sheet) return [];
            var ref = sheet['!ref'];
            if (!ref) return [];
            var range = XLSX.utils.decode_range(ref);
            var rows = [];
            for (var r = range.s.r; r <= range.e.r; r++) {
                var row = [];
                for (var c = range.s.c; c <= range.e.c; c++) {
                    var addr = XLSX.utils.encode_col(c) + (r + 1);
                    row.push(getCellValue(sheet, addr));
                }
                rows.push(row);
            }
            return rows;
        }

        function formatRows(rows) {
            var filtered = rows.slice(0); // copy
            // Global filter
            if (globalFilter) {
                var q = globalFilter.toLowerCase();
                filtered = filtered.filter(function (row) {
                    return row.some(function (cell) {
                        return cell.toLowerCase().indexOf(q) !== -1;
                    });
                });
            }
            // Column filters
            var cfKeys = Object.keys(colFilters);
            if (cfKeys.length) {
                filtered = filtered.filter(function (row) {
                    return cfKeys.every(function (k) {
                        var col = parseInt(k);
                        var val = colFilters[k].toLowerCase();
                        return row[col] && row[col].toLowerCase().indexOf(val) !== -1;
                    });
                });
            }
            // Sort
            if (sortCol >= 0) {
                var col = sortCol;
                var dir = sortDir === 'asc' ? 1 : -1;
                filtered.sort(function (a, b) {
                    var va = (a[col] || '').toLowerCase();
                    var vb = (b[col] || '').toLowerCase();
                    if (va < vb) return -1 * dir;
                    if (va > vb) return 1 * dir;
                    return 0;
                });
            }
            return filtered;
        }

        function renderSheet() {
            rawData = loadSheetData(activeSheetIdx);
            var rows = rawData.slice(0);
            var headers = rows.length ? rows[0] : [];
            var body = rows.slice(1);
            var filtered = formatRows(body);
            var startRow = 1;

            var html = '<div class="umat-vw-xlsx-wrap">';

            // Sheet tabs
            html += '<div class="umat-vw-xlsx-tabs">';
            sheetNames.forEach(function (sn, i) {
                html += '<button class="umat-vw-xlsx-tab' + (i === activeSheetIdx ? ' active' : '') + '" data-idx="' + i + '">' + esc(sn) + '</button>';
            });
            html += '</div>';

            // Toolbar: global search + CSV export
            html += '<div class="umat-vw-xlsx-toolbar">';
            html += '<div class="umat-vw-xlsx-srch"><span class="material-symbols-outlined">search</span><input type="text" id="umat-vw-xlsx-srch" placeholder="Search all cells\u2026" value="' + esc(globalFilter) + '"></div>';
            html += '<button class="umat-vw-xlsx-csv-btn" id="umat-vw-xlsx-csv"><span class="material-symbols-outlined">file_download</span> CSV</button>';
            html += '</div>';

            // Table wrapper
            html += '<div class="umat-vw-xlsx-table-wrap">';
            html += '<table class="umat-vw-xlsx-table">';

            // Header row
            html += '<thead><tr>';
            html += '<th class="umat-vw-xlsx-rn" data-col="-1">#</th>';
            headers.forEach(function (h, ci) {
                var w = colWidths[ci] ? ' style="width:' + colWidths[ci] + 'px;min-width:' + colWidths[ci] + 'px;"' : '';
                var sortIcon = '';
                if (sortCol === ci) sortIcon = sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward';
                html += '<th data-col="' + ci + '"' + w + '>' +
                    '<div class="umat-vw-xlsx-th-inner">' +
                        '<span class="umat-vw-xlsx-th-text">' + esc(h) + '</span>' +
                        '<span class="umat-vw-xlsx-sort-icon material-symbols-outlined">' + (sortIcon || 'unfold_more') + '</span>' +
                        '<span class="umat-vw-xlsx-resize-handle" data-col="' + ci + '"></span>' +
                    '</div>' +
                    '<div class="umat-vw-xlsx-filter-row">' +
                        '<input type="text" class="umat-vw-xlsx-col-filter" data-col="' + ci + '" placeholder="Filter" value="' + esc(colFilters[ci] || '') + '">' +
                    '</div>' +
                '</th>';
            });
            html += '</tr></thead>';

            // Body
            html += '<tbody>';
            if (!filtered.length) {
                html += '<tr><td colspan="' + (headers.length + 1) + '" class="umat-vw-xlsx-empty">No matching rows</td></tr>';
            } else {
                filtered.forEach(function (row, ri) {
                    html += '<tr>';
                    html += '<td class="umat-vw-xlsx-rn">' + (startRow + ri) + '</td>';
                    headers.forEach(function (_, ci) {
                        html += '<td>' + esc(row[ci] || '') + '</td>';
                    });
                    html += '</tr>';
                });
            }
            html += '</tbody></table>';
            html += '</div>';

            // Status bar
            html += '<div class="umat-vw-xlsx-status">' +
                'Showing ' + filtered.length + ' of ' + body.length + ' rows \u00b7 Sheet ' + (activeSheetIdx + 1) + ' of ' + sheetNames.length +
                '</div>';

            html += '</div>';
            container.innerHTML = html;

            // Update meta
            if (opts.size) {
                document.getElementById('umat-vw-meta').textContent = formatBytes(opts.size);
            }

            // Sheet tab clicks
            container.querySelectorAll('.umat-vw-xlsx-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    activeSheetIdx = parseInt(this.dataset.idx);
                    sortCol = -1;
                    globalFilter = '';
                    colFilters = {};
                    renderSheet();
                });
            });

            // Global search
            var srchInput = document.getElementById('umat-vw-xlsx-srch');
            if (srchInput) {
                srchInput.addEventListener('input', function () {
                    globalFilter = this.value;
                    renderSheet();
                });
            }

            // Column filter inputs
            container.querySelectorAll('.umat-vw-xlsx-col-filter').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    var col = parseInt(this.dataset.col);
                    if (this.value) colFilters[col] = this.value;
                    else delete colFilters[col];
                    renderSheet();
                });
                inp.addEventListener('click', function (e) { e.stopPropagation(); });
            });

            // Column header click → sort
            container.querySelectorAll('.umat-vw-xlsx-th-text').forEach(function (el) {
                el.addEventListener('click', function () {
                    var th = this.closest('th');
                    var ci = parseInt(th.dataset.col);
                    if (sortCol === ci) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    else { sortCol = ci; sortDir = 'asc'; }
                    renderSheet();
                });
            });

            // Resizable columns
            container.querySelectorAll('.umat-vw-xlsx-resize-handle').forEach(function (handle) {
                handle.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dragCol = parseInt(this.dataset.col);
                    dragStartX = e.clientX;
                    dragStartW = colWidths[dragCol] || 120;
                    document.addEventListener('mousemove', onDrag);
                    document.addEventListener('mouseup', endDrag);
                });
            });

            function onDrag(e) {
                if (dragCol < 0) return;
                var diff = e.clientX - dragStartX;
                colWidths[dragCol] = Math.max(40, dragStartW + diff);
                renderSheet();
            }

            function endDrag() {
                dragCol = -1;
                document.removeEventListener('mousemove', onDrag);
                document.removeEventListener('mouseup', endDrag);
            }

            // CSV export
            var csvBtn = document.getElementById('umat-vw-xlsx-csv');
            if (csvBtn) {
                csvBtn.addEventListener('click', function () {
                    var filename = (opts.name || 'spreadsheet').replace(/\.[^.]+$/, '') + '.csv';
                    var allRows = [rawData[0]].concat(filtered);
                    var csv = allRows.map(function (row) {
                        return row.map(function (cell) {
                            var v = String(cell || '');
                            if (v.indexOf(',') !== -1 || v.indexOf('"') !== -1 || v.indexOf('\n') !== -1) {
                                return '"' + v.replace(/"/g, '""') + '"';
                            }
                            return v;
                        }).join(',');
                    }).join('\n');
                    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = filename;
                    a.click();
                    URL.revokeObjectURL(a.href);
                });
            }
        }

        function doLoad() {
            fetch(url).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.arrayBuffer();
            }).then(function (buf) {
                workbook = XLSX.read(buf, { type: 'array' });
                sheetNames = workbook.SheetNames;
                if (!sheetNames.length) {
                    showError(container, 'This workbook appears to be empty.');
                    return;
                }
                renderSheet();
            }).catch(function (err) {
                showError(container, 'Could not read spreadsheet.', err.message, doLoad);
            });
        }

        if (window.XLSX) {
            doLoad();
        } else {
            loadScript(CDN_XLSX).then(doLoad).catch(function () {
                showError(container, 'Failed to load spreadsheet viewer library.', 'Check your internet connection or download the file instead.');
            });
        }
    }

    // ═══════════════════════════════════════════
    //  PPTX VIEWER  (silurus/ooxml WASM + PHP server-side fallback)
    // ═══════════════════════════════════════════

    function viewPptx(container, opts) {
        var url = opts.downloadUrl || opts.url;
        if (!url) {
            showError(container, 'No presentation URL provided.');
            return;
        }
        showLoading(container, 'Loading presentation\u2026');

        var pptxViewer = null;
        var pptxCanvas = null;
        var useFallback = false;
        var fallbackState = { slides: [], currentSlide: 1, fullscreenActive: false, pptMode: '', slideW: 960, slideH: 540 };

        typeCleanups['pptx'] = function () {
            if (pptxViewer && pptxViewer.destroy) { try { pptxViewer.destroy(); } catch (e) {} }
            pptxViewer = null; pptxCanvas = null;
            // Clean up fallback keyboard handler if active
            if (fallbackState._navHandler) {
                document.removeEventListener('keydown', fallbackState._navHandler);
                fallbackState._navHandler = null;
            }
        };

        function trySilurus() {
            showLoading(container, 'Rendering with WASM engine\u2026');
            container.style.padding = '0';
            container.style.overflow = 'hidden';

            loadESModule(SILURUS_CDN).then(function (mod) {
                var canvas = document.createElement('canvas');
                canvas.className = 'umat-vw-pptx-canvas';
                canvas.style.cssText = 'width:100%;height:100%;display:block;';
                container.innerHTML = '';
                container.appendChild(canvas);
                pptxCanvas = canvas;

                return fetch(url).then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.arrayBuffer();
                }).then(function (buf) {
                    var viewer = new mod.pptx.PptxViewer(canvas, {
                        wasmUrl: SILURUS_WASM,
                        mode: 'main',
                    });
                    pptxViewer = viewer;
                    return viewer.load(buf);
                }).then(function () {
                    // Get slide count from the viewer
                    // (silurus doesn't expose slide count directly on viewer, but we can try)
                    document.getElementById('umat-vw-meta').textContent = opts.size ? formatBytes(opts.size) : '';
                });
            }).catch(function (err) {
                console.warn('[umat] silurus/ooxml PPTX failed, falling back to server-side:', err.message);
                useFallback = true;
                fallbackPptxRender();
            });
        }

        // ─── Server-side fallback (existing PHP renderer) ───
        function buildApiUrl() {
            var a = document.createElement('a');
            a.href = url;
            var parts = a.pathname.split('/');
            var idx = parts.indexOf('local');
            var root = idx > 0 ? parts.slice(0, idx).join('/') : '';
            return a.origin + root + '/local/umat_ai/pptx_render.php?action=slides&url=' + encodeURIComponent(url);
        }

        function fallbackPptxRender() {
            container.style.padding = '';
            container.style.overflow = '';
            showLoading(container, 'Rendering slides\u2026');
            var fs = fallbackState;

            fetch(buildApiUrl())
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    if (data.error) { showError(container, data.error); return; }
                    fs.slides = data.slides || [];
                    if (!fs.slides.length) {
                        showError(container, 'No slides found.');
                        return;
                    }
                    fs.pptMode = data.mode || 'images';
                    if (fs.pptMode === 'html') {
                        fs.slideW = data.width || 960;
                        fs.slideH = data.height || 540;
                    }
                    document.getElementById('umat-vw-meta').textContent = data.total + ' slides' +
                        (opts.size ? ' \u00b7 ' + formatBytes(opts.size) : '');
                    renderFallbackLayout(data);
                })
                .catch(function (err) {
                    showError(container, 'Could not load presentation.', err.message, fallbackPptxRender);
                });
        }

        function renderFallbackLayout(data) {
            var fs = fallbackState;
            var slides = fs.slides;
            var mode = fs.pptMode;
            var imgBase = data.imgBase || '';
            var html = mode === 'html' ?
                '<div class="umat-vw-pptx-sidebar" id="umat-vw-pptx-sidebar"></div>' +
                '<div class="umat-vw-pptx-main">' +
                    '<div class="umat-vw-pptx-stage" id="umat-vw-pptx-stage">' +
                        '<div class="umat-vw-pptx-slide-wrap" id="umat-vw-pptx-slide-wrap"></div>' +
                        '<button class="umat-vw-pptx-nav umat-vw-pptx-prev" id="umat-vw-pptx-prev"><span class="material-symbols-outlined">chevron_left</span></button>' +
                        '<button class="umat-vw-pptx-nav umat-vw-pptx-next" id="umat-vw-pptx-next"><span class="material-symbols-outlined">chevron_right</span></button>' +
                    '</div>' +
                    '<div class="umat-vw-pptx-footer">' +
                        '<span id="umat-vw-pptx-counter">1 / ' + slides.length + '</span>' +
                        '<button class="umat-vw-pptx-fs-btn" id="umat-vw-pptx-fs"><span class="material-symbols-outlined">fullscreen</span> Fullscreen</button>' +
                    '</div>' +
                '</div>' :
                '<div class="umat-vw-pptx-sidebar" id="umat-vw-pptx-sidebar"></div>' +
                '<div class="umat-vw-pptx-main">' +
                    '<div class="umat-vw-pptx-stage" id="umat-vw-pptx-stage">' +
                        '<img id="umat-vw-pptx-img" src="" alt="Slide">' +
                        '<button class="umat-vw-pptx-nav umat-vw-pptx-prev" id="umat-vw-pptx-prev"><span class="material-symbols-outlined">chevron_left</span></button>' +
                        '<button class="umat-vw-pptx-nav umat-vw-pptx-next" id="umat-vw-pptx-next"><span class="material-symbols-outlined">chevron_right</span></button>' +
                    '</div>' +
                    '<div class="umat-vw-pptx-footer">' +
                        '<span id="umat-vw-pptx-counter">1 / ' + slides.length + '</span>' +
                        '<button class="umat-vw-pptx-fs-btn" id="umat-vw-pptx-fs"><span class="material-symbols-outlined">fullscreen</span> Fullscreen</button>' +
                    '</div>' +
                '</div>';

            container.innerHTML = '<div class="umat-vw-pptx">' + html + '</div>';

            // Render thumbnails
            var sb = document.getElementById('umat-vw-pptx-sidebar');
            if (sb) {
                sb.innerHTML = slides.map(function (s, i) {
                    var idx = i + 1;
                    var thumbSrc = mode === 'html' ? '' : (s.src ? s.src.replace('action=slides', 'action=slide') : '');
                    var thumbHtml = mode === 'html' ?
                        '<div class="umat-vw-pptx-thumb-slide" style="width:148px;height:' + Math.round(148 * fs.slideH / fs.slideW) + 'px;background:' + (s.bg || '#fff') + ';position:relative;overflow:hidden;">' +
                            (s.elements || []).slice(0, 5).map(function (el) {
                                if (el.type === 'image') {
                                    return '<img style="position:absolute;left:' + Math.round(el.x * 148 / fs.slideW) + 'px;top:' + Math.round(el.y * 148 / fs.slideW) + 'px;width:' + Math.round(el.w * 148 / fs.slideW) + 'px;height:' + Math.round(el.h * 148 / fs.slideW) + 'px;" src="' + esc(imgBase + el.img + '&ext=' + el.ext) + '" alt="" loading="lazy">';
                                }
                                return '';
                            }).join('') +
                        '</div>' :
                        '<img src="' + esc(thumbSrc) + '" alt="Slide ' + idx + '" loading="lazy">';
                    return '<div class="umat-vw-pptx-thumb" data-slide="' + idx + '">' + thumbHtml + '<span class="umat-vw-pptx-thumb-num">' + idx + '</span></div>';
                }).join('');
                sb.addEventListener('click', function (e) {
                    var thumb = e.target.closest('.umat-vw-pptx-thumb');
                    if (thumb) goToSlideFallback(parseInt(thumb.dataset.slide), data);
                });
            }

            document.getElementById('umat-vw-pptx-prev').addEventListener('click', function () {
                if (fs.currentSlide > 1) goToSlideFallback(fs.currentSlide - 1, data);
            });
            document.getElementById('umat-vw-pptx-next').addEventListener('click', function () {
                if (fs.currentSlide < slides.length) goToSlideFallback(fs.currentSlide + 1, data);
            });
            document.getElementById('umat-vw-pptx-fs').addEventListener('click', function () {
                var main = document.getElementById('umat-vw-pptx-main');
                if (!main) return;
                var isFs = main.classList.toggle('umat-vw-pptx-fs');
                document.getElementById('umat-vw-pptx-sidebar').style.display = isFs ? 'none' : '';
                this.innerHTML = isFs ?
                    '<span class="material-symbols-outlined">fullscreen_exit</span> Exit' :
                    '<span class="material-symbols-outlined">fullscreen</span> Fullscreen';
            });

            // Nav handler
            if (fs._navHandler) document.removeEventListener('keydown', fs._navHandler);
            fs._navHandler = function (e) {
                if (!viewerEl || !viewerEl.classList.contains('open') || activeType !== 'pptx' || !useFallback) return;
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); if (fs.currentSlide < slides.length) goToSlideFallback(fs.currentSlide + 1, data); }
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); if (fs.currentSlide > 1) goToSlideFallback(fs.currentSlide - 1, data); }
            };
            document.addEventListener('keydown', fs._navHandler);

            goToSlideFallback(1, data);
        }

        function goToSlideFallback(idx, data) {
            var fs = fallbackState;
            fs.currentSlide = idx;
            var slide = fs.slides[idx - 1];
            if (!slide) return;

            if (fs.pptMode === 'html') {
                var wrap = document.getElementById('umat-vw-pptx-slide-wrap');
                if (wrap) {
                    var bg = slide.bg || '#ffffff';
                    var elems = (slide.elements || []).map(function (el) {
                        if (el.type === 'text') {
                            return '<div style="position:absolute;left:' + el.x + 'px;top:' + el.y + 'px;width:' + el.w + 'px;height:' + el.h + 'px;overflow:hidden;word-wrap:break-word;">' +
                                (el.lines || []).map(function (line) {
                                    return '<div style="white-space:pre-wrap;">' +
                                        line.map(function (run) {
                                            return '<span style="font-size:' + run.size + 'px;color:' + run.color + ';font-family:' + esc(run.font || 'Calibri') + ';' +
                                                (run.bold ? 'font-weight:bold;' : '') + (run.italic ? 'font-style:italic;' : '') + '">' + esc(run.text || '') + '</span>';
                                        }).join('') +
                                    '</div>';
                                }).join('') +
                            '</div>';
                        } else if (el.type === 'image') {
                            return '<img style="position:absolute;left:' + el.x + 'px;top:' + el.y + 'px;width:' + el.w + 'px;height:' + el.h + 'px;" src="' + esc((data.imgBase || '') + el.img + '&ext=' + el.ext) + '" alt="">';
                        }
                        return '';
                    }).join('');
                    wrap.innerHTML = '<div class="umat-vw-pptx-slide" style="width:' + fs.slideW + 'px;height:' + fs.slideH + 'px;background:' + bg + ';position:relative;overflow:hidden;">' + elems + '</div>';
                    // Scale
                    var stage = document.getElementById('umat-vw-pptx-stage');
                    var maxW = (stage ? stage.clientWidth : wrap.clientWidth) - 40;
                    var maxH = (stage ? stage.clientHeight : window.innerHeight * 0.7) - 40;
                    var scale = Math.min(maxW / fs.slideW, maxH / fs.slideH, 2);
                    var slideEl = wrap.querySelector('.umat-vw-pptx-slide');
                    if (slideEl) {
                        slideEl.style.transform = 'scale(' + scale + ')';
                        slideEl.style.transformOrigin = 'top left';
                    }
                    wrap.style.width = Math.round(fs.slideW * scale) + 'px';
                    wrap.style.height = Math.round(fs.slideH * scale) + 'px';
                }
            } else {
                var img = document.getElementById('umat-vw-pptx-img');
                if (img) {
                    img.src = slide.src ? slide.src.replace('action=slides', 'action=slide') : '';
                }
            }

            var counter = document.getElementById('umat-vw-pptx-counter');
            if (counter) counter.textContent = idx + ' / ' + fs.slides.length;

            (container.querySelectorAll('.umat-vw-pptx-thumb') || []).forEach(function (t) {
                t.classList.toggle('active', parseInt(t.dataset.slide) === idx);
                if (parseInt(t.dataset.slide) === idx) t.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });

            var prev = document.getElementById('umat-vw-pptx-prev');
            var next = document.getElementById('umat-vw-pptx-next');
            if (prev) prev.style.display = idx > 1 ? '' : 'none';
            if (next) next.style.display = idx < fs.slides.length ? '' : 'none';
        }

        // Start with silurus WASM; fall back to PHP if it fails
        trySilurus();
    }

    // ═══════════════════════════════════════════
    //  CODE / TEXT VIEWER (Prism.js)
    // ═══════════════════════════════════════════

    var SILURUS_CDN = 'https://cdn.jsdelivr.net/npm/@silurus/ooxml@0.74.2/+esm';
    var SILURUS_WASM = 'https://cdn.jsdelivr.net/npm/@silurus/ooxml@0.74.2/dist/'; // WASM files are in dist/
    var MAMMOTH_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js';
    var PRISM_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0';
    var EXT_LANG = {
        php: 'php', py: 'python', js: 'javascript', ts: 'typescript',
        jsx: 'jsx', tsx: 'tsx', mjs: 'javascript', cjs: 'javascript',
        html: 'html', htm: 'html', css: 'css', scss: 'scss', less: 'less',
        json: 'json', xml: 'xml', svg: 'xml', sql: 'sql', md: 'markdown',
        yaml: 'yaml', yml: 'yaml', toml: 'toml', ini: 'ini', cfg: 'ini',
        sh: 'bash', bash: 'bash', zsh: 'bash', bat: 'batch', ps1: 'powershell',
        c: 'c', cpp: 'cpp', h: 'c', hpp: 'cpp', cs: 'csharp', java: 'java',
        rb: 'ruby', go: 'go', rs: 'rust', swift: 'swift', kt: 'kotlin',
        kts: 'kotlin', scala: 'scala', dart: 'dart', lua: 'lua', pl: 'perl',
        pm: 'perl', r: 'r', pas: 'pascal', prg: 'pascal', vhdl: 'vhdl',
        tex: 'latex', bib: 'latex', dockerfile: 'docker', makefile: 'makefile',
        cmake: 'cmake', patch: 'diff', diff: 'diff', txt: 'plaintext',
        csv: 'csv', env: 'plaintext', log: 'plaintext', '': 'plaintext'
    };

    function langExt(ext) {
        return EXT_LANG[ext] || EXT_LANG[ext.toLowerCase ? ext.toLowerCase() : ''] || 'plaintext';
    }

    function viewCode(container, opts) {
        var url = opts.downloadUrl || opts.url;
        if (!url) { showError(container, 'No file URL provided.'); return; }

        var name = opts.name || '';
        var ext = opts.ext || (name.lastIndexOf('.') >= 0 ? name.slice(name.lastIndexOf('.') + 1) : '');
        var lang = langExt(ext);
        var isPlain = lang === 'plaintext';

        showLoading(container, 'Loading code\u2026');

        // Load Prism core + CSS
        Promise.all([
            loadScript(PRISM_CDN + '/prism.min.js'),
            loadCss(PRISM_CDN + '/themes/prism-tomorrow.min.css'),
            isPlain ? Promise.resolve() : loadScript(PRISM_CDN + '/components/prism-' + lang + '.min.js'),
            loadCss(PRISM_CDN + '/plugins/line-numbers/prism-line-numbers.min.css'),
            loadScript(PRISM_CDN + '/plugins/line-numbers/prism-line-numbers.min.js')
        ].filter(Boolean)).then(function () {
            // Fetch file content
            fetch(url).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            }).then(function (text) {
                var wc = text.split(/\s+/).filter(function (w) { return w.length; }).length;
                var lines = text.split('\n').length;

                container.innerHTML =
                    '<div class="umat-vw-code">' +
                        '<div class="umat-vw-code-bar">' +
                            '<span class="umat-vw-code-bar-left">' +
                                '<span class="umat-vw-code-lang">' + esc(lang) + '</span>' +
                                '<span class="umat-vw-code-stat">' + lines + ' lines \u00b7 ' + formatBytes(new Blob([text]).size) + (opts.size ? ' (' + formatBytes(opts.size) + ')' : '') + '</span>' +
                                '<span class="umat-vw-code-stat">' + wc + ' words</span>' +
                            '</span>' +
                            '<button class="umat-vw-code-copy" id="umat-vw-code-copy-btn">' +
                                '<span class="material-symbols-outlined">content_copy</span> Copy' +
                            '</button>' +
                        '</div>' +
                        '<div class="umat-vw-code-body">' +
                            '<pre class="line-numbers"><code class="language-' + esc(lang) + '">' + esc(text) + '</code></pre>' +
                        '</div>' +
                    '</div>';

                Prism.highlightAll();

                // Copy button
                document.getElementById('umat-vw-code-copy-btn').addEventListener('click', function () {
                    navigator.clipboard.writeText(text).then(function () {
                        var btn = document.getElementById('umat-vw-code-copy-btn');
                        btn.innerHTML = '<span class="material-symbols-outlined">check</span> Copied!';
                        setTimeout(function () { btn.innerHTML = '<span class="material-symbols-outlined">content_copy</span> Copy'; }, 2000);
                    }).catch(function () {
                        var ta = document.createElement('textarea');
                        ta.value = text;
                        ta.style.position = 'fixed'; ta.style.left = '-9999px';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        document.body.removeChild(ta);
                    });
                });
            }).catch(function (err) {
                showError(container, 'Failed to load file.', err.message, function () { viewCode(container, opts); });
            });
        }).catch(function () {
            showError(container, 'Failed to load highlighting library.', 'Check your internet connection.');
        });
    }

    // ═══════════════════════════════════════════
    //  OLD FORMAT TEXT VIEWER (fetches extracted text from AI service)
    // ═══════════════════════════════════════════

    function viewUnsupported(container, opts) {
        var mid = opts.materialId;
        var cid = opts.courseId;
        var name = opts.name || 'file';

        if (!mid || !cid) {
            showFallback(container, name, opts.downloadUrl);
            return;
        }

        showLoading(container, 'Loading content\u2026');

        var a = document.createElement('a');
        a.href = opts.downloadUrl || opts.url || '';
        var parts = a.pathname.split('/');
        var idx = parts.indexOf('local');
        var root = idx > 0 ? parts.slice(0, idx).join('/') : '';
        var apiUrl = a.origin + root + '/local/umat_ai/material_text.php?material_id=' + mid + '&course_id=' + cid;

        fetch(apiUrl)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (!data.success || !data.text) {
                    showFallback(container, name, opts.downloadUrl);
                    return;
                }
                var text = data.text;
                var lines = text.split('\n').filter(function (l) { return l.trim(); });
                container.innerHTML =
                    '<div class="umat-vw-docx-body" style="padding:16px;font-size:14px;line-height:1.7;">' +
                        lines.map(function (l) {
                            return '<p style="margin:0 0 8px 0;">' + esc(l) + '</p>';
                        }).join('') +
                    '</div>';
                document.getElementById('umat-vw-meta').textContent = data.chunks + ' chunks' +
                    (opts.size ? ' \u00b7 ' + formatBytes(opts.size) : '');
            })
            .catch(function (err) {
                showFallback(container, name, opts.downloadUrl);
            });
    }

    function showFallback(container, name, downloadUrl) {
        var ext = name.lastIndexOf('.') > 0 ? name.substring(name.lastIndexOf('.') + 1).toUpperCase() : '';
        var label = ext || 'file';
        container.innerHTML =
            '<div class="umat-vw-empty">' +
                '<span class="material-symbols-outlined">description</span>' +
                '<p>Preview not available for this ' + esc(label) + ' format.</p>' +
                '<p style="font-size:13px;color:var(--u-txt-sec);margin-top:4px;">' + esc(name) + '</p>' +
                '<a href="' + esc(downloadUrl || '#') + '" download class="umat-vw-retry-btn" style="display:inline-flex;align-items:center;gap:6px;margin-top:12px;text-decoration:none;">' +
                    '<span class="material-symbols-outlined" style="font-size:18px;">download</span> Download to view' +
                '</a>' +
            '</div>';
    }

    var api = { open: openViewer, close: closeViewer };
    window.umatMaterialViewer = api;
    return api;
});
