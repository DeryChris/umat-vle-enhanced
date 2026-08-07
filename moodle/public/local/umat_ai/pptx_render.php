<?php
/**
 * PPTX slide renderer with caching.
 * Supports LibreOffice pipeline and pure-PHP GD fallback.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$action = required_param('action', PARAM_ALPHA);
$url    = required_param('url', PARAM_RAW_TRIMMED);
// Ensure the URL starts with http:// or https://
if (!preg_match('#^https?://#', $url)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid URL']));
}
$slide  = optional_param('slide', 1, PARAM_INT);

// ─────────────────────────────────────────────────
// 1. Resolve file from URL
// ─────────────────────────────────────────────────
$fileinfo = resolve_pluginfile_url($url);
if (!$fileinfo) {
    http_response_code(400);
    die(json_encode(['error' => 'Could not resolve file from URL']));
}

$courseid   = (int)$fileinfo['courseid'];
$actualpath = $fileinfo['path'];
$filename   = $fileinfo['filename'];
$filehash   = $fileinfo['hash'];
$filemtime  = $fileinfo['mtime'];

// ─────────────────────────────────────────────────
// 2. Auth — user must be logged in and enrolled in the course
// ─────────────────────────────────────────────────
if ($courseid <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid course']));
}
$course = get_course($courseid);
require_login($course, true);

// ─────────────────────────────────────────────────
// 3. Cache setup
// ─────────────────────────────────────────────────
$cachekey  = md5($filehash . '_' . $filemtime . '_v3');
$cachedir  = $CFG->dataroot . '/.pptx-cache/' . $cachekey;
$cachejson = $cachedir . '/slides.json';

// Ensure cache dir exists
if (!is_dir($cachedir)) {
    mkdir($cachedir, 0777, true);
}

// ─────────────────────────────────────────────────
// 4. ACTION: slides — return JSON with slide info
// ─────────────────────────────────────────────────
if ($action === 'slides') {
    header('Content-Type: application/json');

    // Check cache
    if (file_exists($cachejson)) {
        $data = json_decode(file_get_contents($cachejson), true);
        if ($data && isset($data['slides'])) {
            echo json_encode($data);
            exit;
        }
    }

    // Render slides
    $slides = render_pptx($actualpath, $cachedir);
    if ($slides === false) {
        http_response_code(500);
        die(json_encode(['error' => 'Failed to render presentation']));
    }

    $result = [
        'mode'   => $GLOBALS['_pptx_mode'] ?? 'images',
        'total'  => count($slides),
        'slides' => $slides,
    ];

    $base = rtrim($CFG->wwwroot, '/') . '/local/umat_ai/pptx_render.php';
    $qurl = urlencode($url);

    if ($result['mode'] === 'html') {
        $result['width'] = $GLOBALS['_pptx_width'] ?? 960;
        $result['height'] = $GLOBALS['_pptx_height'] ?? 540;
        $result['imgBase'] = $base . '?action=slideimg&url=' . $qurl . '&img=';
    } else {
        foreach ($result['slides'] as &$s) {
            $s['src'] = $base . '?action=slide&url=' . $qurl . '&slide=' . $s['slide'];
        }
        unset($s);
    }

    file_put_contents($cachejson, json_encode($result));
    echo json_encode($result);
    exit;
}

// ─────────────────────────────────────────────────
// 5. ACTION: slide — return PNG image (auto-renders if not cached)
// ─────────────────────────────────────────────────
if ($action === 'slide') {
    $slidepath = $cachedir . '/slide_' . $slide . '.png';
    // Auto-render if the slide doesn't exist yet
    if (!file_exists($slidepath)) {
        $slides = render_pptx($actualpath, $cachedir);
        if ($slides === false) {
            // Return a placeholder SVG for failed renders
            header('Content-Type: image/svg+xml');
            $placeholder = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90" viewBox="0 0 120 90"><rect fill="#f3f4f6" width="120" height="90" rx="8"/><text fill="#9ca3af" font-family="sans-serif" font-size="10" x="60" y="45" text-anchor="middle" dominant-baseline="middle">PPTX Preview</text></svg>';
            echo $placeholder;
            exit;
        }
        // Re-check after render
        if (!file_exists($slidepath)) {
            // HTML mode was used — return a placeholder
            header('Content-Type: image/svg+xml');
            $placeholder = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="90" viewBox="0 0 120 90"><rect fill="#f3f4f6" width="120" height="90" rx="8"/><text fill="#9ca3af" font-family="sans-serif" font-size="10" x="60" y="45" text-anchor="middle" dominant-baseline="middle">PPTX Slide</text></svg>';
            echo $placeholder;
            exit;
        }
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($slidepath));
    header('Cache-Control: public, max-age=86400');
    readfile($slidepath);
    exit;
}

// ═══════════════════════════════════════════════════
// 6. ACTION: slideimg — serve extracted image
// ═══════════════════════════════════════════════════
if ($action === 'slideimg') {
    $img = required_param('img', PARAM_INT);
    $ext = optional_param('ext', 'png', PARAM_ALPHA);
    $imgpath = $cachedir . '/img_' . $img . '.' . $ext;
    if (!file_exists($imgpath)) {
        http_response_code(404);
        die('Image not found');
    }
    $mime = ($ext === 'png') ? 'image/png' : (($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/' . $ext);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($imgpath));
    header('Cache-Control: public, max-age=86400');
    readfile($imgpath);
    exit;
}

http_response_code(400);
die('Invalid action');

// ═══════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═══════════════════════════════════════════════════

/**
 * Parse a pluginfile.php URL to get course, path, hash.
 * URL format: /pluginfile.php/{contextid}/{component}/{filearea}/{itemid}/{path}{filename}
 */
function resolve_pluginfile_url(string $url): ?array {
    global $DB, $CFG;

    $parts = parse_url($url);
    $path = $parts['path'] ?? '';

    // Match: /pluginfile.php/CONTEXTID/COMPONENT/FILEAREA/ITEMID/REST
    if (!preg_match('#/pluginfile\.php/(\d+)/([^/]+)/([^/]+)/([^/]+)/(.*)$#', $path, $m)) {
        // Not a pluginfile URL — try as a direct dataroot path
        return resolve_direct_url($url);
    }

    $contextid = (int)$m[1];
    $component = $m[2];
    $filearea  = $m[3];
    $itemid    = $m[4];
    $rest      = $m[5];

    // Derive filepath + filename (URL-decode to match Moodle file API)
    $lastslash = strrpos($rest, '/');
    if ($lastslash === false) {
        $filepath = '/';
        $filename = urldecode($rest);
    } else {
        $filepath = '/' . urldecode(substr($rest, 0, $lastslash + 1));
        $filename = urldecode(substr($rest, $lastslash + 1));
    }

    // Get courseid from context
    $context = context::instance_by_id($contextid, IGNORE_MISSING);
    if (!$context) {
        // Try finding via mdl_umat_ai_materials
        $mat = $DB->get_record('umat_ai_materials', ['filename' => $filename], 'courseid');
        if ($mat) {
            return build_result(null, $mat->courseid, $filename, null, 0);
        }
        return null;
    }
    $courseid = $context->get_course_context(false);
    $courseid = $courseid ? $courseid->instanceid : 0;

    // Try Moodle file API
    $fs = get_file_storage();
    $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
    if ($file) {
        return build_result($file, $courseid, $filename, $filepath, 0);
    }

    // Fallback 1: try common variations (different itemids, different filepath)
    $variations = [
        [$itemid, '/'],
        [0, '/'],
        [$itemid, $filepath],
    ];
    foreach ($variations as [$tryItem, $tryPath]) {
        $file = $fs->get_file($contextid, $component, $filearea, $tryItem, $tryPath, $filename);
        if ($file) {
            return build_result($file, $courseid, $filename, $tryPath, 0);
        }
    }

    // Fallback 2: search all files in this context for a matching filename
    $allfiles = $fs->get_area_files($contextid, $component, $filearea, false, 'id, filename, contenthash, timecreated', 'filename');
    foreach ($allfiles as $f) {
        if (isset($f['filename']) && $f['filename'] === $filename) {
            $file = $fs->get_file_by_hashname($f['contenthash']);
            if ($file) {
                return build_result($file, $courseid, $filename, '/', 0);
            }
        }
    }

    // Fallback 3: look in ai_materials directory
    return build_result(null, $courseid, $filename, null, 0);
}

/**
 * Handle direct URLs (non-pluginfile) — e.g., dataroot paths.
 */
function resolve_direct_url(string $url): ?array {
    global $CFG, $DB;

    $parts = parse_url($url);
    $path = $parts['path'] ?? '';

    // Check if it points to dataroot/ai_materials/...
    if (preg_match('#/ai_materials/(\d+)/(.+)$#', $path, $m)) {
        $courseid = (int)$m[1];
        $filename = urldecode($m[2]);
        $filepath = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $filename;
        if (file_exists($filepath)) {
            return [
                'path'     => $filepath,
                'filename' => $filename,
                'hash'     => md5_file($filepath),
                'mtime'    => filemtime($filepath),
                'courseid' => $courseid,
            ];
        }
    }

    // Check mdl_umat_ai_materials by filename
    $basename = basename(urldecode($path));
    $mat = $DB->get_record('umat_ai_materials', ['filename' => $basename], 'courseid');
    if ($mat) {
        $filepath = $CFG->dataroot . '/ai_materials/' . $mat->courseid . '/' . $basename;
        if (file_exists($filepath)) {
            return [
                'path'     => $filepath,
                'filename' => $basename,
                'hash'     => md5_file($filepath),
                'mtime'    => filemtime($filepath),
                'courseid' => $mat->courseid,
            ];
        }
    }

    return null;
}

function build_result(?stored_file $file, int $courseid, string $filename, ?string $filepath, int $mtime): array {
    global $CFG;

    if ($file) {
        $hash = $file->get_contenthash();
        $mtime = $file->get_timemodified();
        $path = $file->get_content();
        // Get actual filesystem path for processing
        $filedir = $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        if (file_exists($filedir)) {
            $path = $filedir;
        }
    } else {
        // Plugin's own uploads: dataroot/ai_materials/$courseid/$filename
        $path = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $filename;
        if (!file_exists($path)) {
            // Try URL-decoded filename variations
            $decoded = urldecode($filename);
            $altPath = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $decoded;
            if (file_exists($altPath)) {
                $path = $altPath;
                $filename = $decoded;
            } else {
                // Final fallback: search for any file matching the basename in ai_materials
                $basename = basename($decoded);
                $pattern = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $basename;
                if (file_exists($pattern)) {
                    $path = $pattern;
                    $filename = $basename;
                } else {
                    $path = $decoded; // raw filename as last resort
                }
            }
        }
        $hash = file_exists($path) ? md5_file($path) : md5($filename);
        $mtime = file_exists($path) ? filemtime($path) : time();
    }

    return [
        'path'     => $path,
        'filename' => $filename,
        'hash'     => $hash,
        'mtime'    => $mtime,
        'courseid' => $courseid,
    ];
}

/**
 * Main render dispatch — tries LibreOffice first, falls back to HTML extraction.
 * Returns array of slides or false on failure.
 */
function render_pptx(string $path, string $cachedir): array|false {
    // Try multiple path variations
    $candidates = [$path];
    if (!file_exists($path)) {
        // Try URL-decoded version
        $candidates[] = urldecode($path);
        // Try basename only in common locations
        global $CFG;
        $basename = basename($path);
        if ($CFG) {
            $candidates[] = $CFG->dataroot . '/ai_materials/' . dirname($path) . '/' . $basename;
        }
    }
    
    $actualPath = $path;
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            $actualPath = $c;
            break;
        }
    }
    
    if (!file_exists($actualPath)) return false;

    // Try LibreOffice pipeline (produces full slide images)
    $slides = render_via_libreoffice($actualPath, $cachedir);
    if ($slides !== false) {
        $GLOBALS['_pptx_mode'] = 'images';
        return $slides;
    }

    // Fallback to HTML extraction
    $slides = render_via_html($actualPath, $cachedir);
    if ($slides !== false) {
        $GLOBALS['_pptx_mode'] = 'html';
        return $slides;
    }

    return false;
}

// ═══════════════════════════════════════════════════
//  LIBREOFFICE PIPELINE
// ═══════════════════════════════════════════════════

function render_via_libreoffice(string $path, string $cachedir): array|false {
    $lo = find_soffice();
    if (!$lo) return false;

    // Strategy A: Convert PPTX directly to PNGs (no PDF intermediate)
    $escPath = escapeshellarg($path);
    $escOut  = escapeshellarg($cachedir);
    $base = pathinfo($path, PATHINFO_FILENAME);

    if (!glob($cachedir . '/' . $base . '_*.png')) {
        $cmd = "\"$lo\" --headless --norestore --convert-to png --outdir $escOut $escPath 2>&1";
        shell_exec($cmd);
    }

    $pngs = glob($cachedir . '/' . $base . '_*.png');
    if (count($pngs) > 0) {
        // Rename to slide_N.png (LibreOffice names them <filename>_N.png)
        $slides = [];
        $idx = 1;
        foreach ($pngs as $png) {
            $dest = $cachedir . '/slide_' . $idx . '.png';
            if ($png !== $dest) {
                @unlink($dest);
                rename($png, $dest);
            }
            $slides[] = ['slide' => $idx];
            $idx++;
        }
        return $slides;
    }

    // Strategy B: PPTX → PDF → PNG via ImageMagick
    $pdffile = $cachedir . '/presentation.pdf';
    if (!file_exists($pdffile)) {
        $cmd = "\"$lo\" --headless --norestore --convert-to pdf --outdir $escOut $escPath 2>&1";
        shell_exec($cmd);
        $pdfs = glob($cachedir . '/*.pdf');
        if (!empty($pdfs)) rename($pdfs[0], $pdffile);
    }

    if (file_exists($pdffile)) {
        // Only count properly named slide_N.png files (excludes malformed
        // leftovers such as 'slide_ d-1.png' from older buggy runs).
        $existing = glob($cachedir . '/slide_[0-9]*.png');
        if (count($existing) > 0) {
            $slides = []; $i = 1;
            while (file_exists($cachedir . '/slide_' . $i . '.png')) { $slides[] = ['slide' => $i]; $i++; }
            return $slides;
        }

        $magick = find_magick();
        if ($magick) {
            $escPdf = escapeshellarg($pdffile);
            // NOTE 1: -scene 1 is required — ImageMagick numbers %d sequences
            // from 0 by default, but the rest of the code expects slide_1.png
            // to be the FIRST slide (without it, slide 1 is dropped and every
            // slide in the viewer is off by one).
            // NOTE 2: Do NOT pass the %d output pattern through
            // escapeshellarg() — PHP's Windows escapeshellarg() mangles '%d'
            // (observed as 'slide_ d-N.png' files), so the slide_N.png lookup
            // below misses and every request falls back to the placeholder.
            // $cachedir is built from $CFG->dataroot + md5 (no quotes/$
            // characters), so plain double quotes are safe here.
            $outPattern = '"' . $cachedir . '/slide_%d.png"';
            $cmd = "\"$magick\" -density 200 $escPdf -scene 1 -quality 92 -background white -alpha remove $outPattern 2>&1";
            shell_exec($cmd);
            // Clean up any malformed leftovers (e.g. 'slide_ d-N.png' from the
            // older escapeshellarg bug) so they can never confuse the lookup.
            foreach (glob($cachedir . '/slide_*.png') as $stale) {
                if (!preg_match('#/slide_\d+\.png$#', $stale)) {
                    @unlink($stale);
                }
            }
            $pngs = glob($cachedir . '/slide_*.png');
            if (count($pngs) > 0) {
                $slides = []; $i = 1;
                while (file_exists($cachedir . '/slide_' . $i . '.png')) { $slides[] = ['slide' => $i]; $i++; }
                return $slides;
            }
        }
    }

    return false;
}

function find_soffice(): string {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $paths = [
            getenv('PROGRAMFILES') . '\\LibreOffice\\program\\soffice.exe',
            getenv('PROGRAMFILES(X86)') . '\\LibreOffice\\program\\soffice.exe',
            getenv('LOCALAPPDATA') . '\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ];
        foreach ($paths as $p) { if (file_exists($p)) return $p; }
        $t = trim(shell_exec('where soffice 2>NUL'));
        if ($t) return $t;
    } else {
        $t = trim(shell_exec('which soffice 2>/dev/null'));
        if ($t) return $t;
        foreach (['/usr/bin/libreoffice','/usr/local/bin/libreoffice','/snap/bin/libreoffice'] as $p) {
            if (file_exists($p)) return $p;
        }
    }
    return '';
}

function find_magick(): string {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $t = trim(shell_exec('where magick 2>NUL'));
        if ($t) return $t;
        $paths = [
            getenv('PROGRAMFILES') . '\\ImageMagick*\\magick.exe',
            getenv('PROGRAMFILES(X86)') . '\\ImageMagick*\\magick.exe',
            'C:\\Program Files\\ImageMagick*\\magick.exe',
            'C:\\Program Files (x86)\\ImageMagick*\\magick.exe',
        ];
        foreach ($paths as $pat) {
            $m = glob($pat);
            if (!empty($m)) return $m[0];
        }
    } else {
        $t = trim(shell_exec('which convert 2>/dev/null'));
        if ($t) return $t;
    }
    return '';
}

// ═══════════════════════════════════════════════════
//  HTML EXTRACTION RENDERER
//  Extracts text runs + images from PPTX XML for
//  client-side HTML/CSS rendering.
// ═══════════════════════════════════════════════════

function render_via_html(string $path, string $cachedir): array|false {
    if (!extension_loaded('zip') || !extension_loaded('dom') || !extension_loaded('libxml')) return false;

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    // Slide dimensions from presentation.xml
    $slideW = 960;
    $slideH = 540;
    $presXml = $zip->getFromName('ppt/presentation.xml');
    if ($presXml) {
        $presDom = new DOMDocument();
        $presDom->loadXML($presXml);
        $sldSz = $presDom->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldSz')->item(0);
        if ($sldSz) {
            $cx = (int)$sldSz->getAttribute('cx');
            $cy = (int)$sldSz->getAttribute('cy');
            if ($cx && $cy) {
                $slideW = max(640, (int)round($cx / 9525));
                $slideH = max(360, (int)round($cy / 9525));
            }
        }
    }

    // Build ordered slide file list
    $slideFiles = [];
    $relsXml = $zip->getFromName('ppt/_rels/presentation.xml.rels');
    if ($relsXml) {
        $rDom = new DOMDocument();
        $rDom->loadXML($relsXml);
        foreach ($rDom->getElementsByTagName('Relationship') as $rel) {
            $type = $rel->getAttribute('Type');
            if (strpos($type, '/slide') !== false) {
                $target = $rel->getAttribute('Target');
                if (preg_match('/slide(\d+)\.xml$/', $target, $m)) {
                    $slideFiles[(int)$m[1]] = 'ppt/' . ltrim(str_replace('\\', '/', $target), '/');
                }
            }
        }
    }
    ksort($slideFiles);
    if (empty($slideFiles)) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#i', $name, $m)) {
                $slideFiles[(int)$m[1]] = $name;
            }
        }
        ksort($slideFiles);
    }

    $aNs = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $pNs = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $result = [];
    $globalImgIdx = 0;

    foreach ($slideFiles as $slideNum => $slidePath) {
        $slideXml = $zip->getFromName($slidePath);
        if (!$slideXml) continue;

        $dom = new DOMDocument();
        $dom->loadXML($slideXml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('a', $aNs);
        $xpath->registerNamespace('p', $pNs);
        $xpath->registerNamespace('r', $rNs);

        $cSld = $dom->getElementsByTagNameNS($pNs, 'cSld')->item(0);
        if (!$cSld) continue;

        // Background color
        $bg = '#ffffff';
        $bgEl = $xpath->query('//p:cSld/p:bg//a:srgbClr')->item(0);
        if ($bgEl) {
            $v = $bgEl->getAttribute('val');
            if ($v) $bg = '#' . $v;
        }

        // Build image map from slide-level rels
        $slideImgMap = [];
        $slideRelsPath = dirname($slidePath) . '/_rels/' . basename($slidePath) . '.rels';
        $slideRelsXml = $zip->getFromName($slideRelsPath);
        if ($slideRelsXml) {
            $rDom = new DOMDocument();
            $rDom->loadXML($slideRelsXml);
            foreach ($rDom->getElementsByTagName('Relationship') as $rel) {
                if (strpos($rel->getAttribute('Type'), '/image') !== false) {
                    $t = str_replace('\\', '/', $rel->getAttribute('Target'));
                    $t = ltrim($t, '/');
                    if (strpos($t, 'ppt/') !== 0) $t = 'ppt/' . $t;
                    $slideImgMap[$rel->getAttribute('Id')] = $t;
                }
            }
        }

        $elements = [];
        $spTree = $cSld->getElementsByTagNameNS($pNs, 'spTree')->item(0);
        if ($spTree) {
            foreach ($spTree->childNodes as $shape) {
                if ($shape->nodeType !== XML_ELEMENT_NODE) continue;
                $tag = $shape->localName;
                if ($tag !== 'sp' && $tag !== 'pic') continue;

                $spPr = null;
                if ($tag === 'sp') {
                    $spPr = $shape->getElementsByTagNameNS($pNs, 'spPr')->item(0);
                } elseif ($tag === 'pic') {
                    $spPr = $shape->getElementsByTagNameNS($pNs, 'spPr')->item(0);
                }
                if (!$spPr) continue;

                $xfrm = $spPr->getElementsByTagNameNS($aNs, 'xfrm')->item(0);
                if (!$xfrm) continue;

                $off = $xfrm->getElementsByTagNameNS($aNs, 'off')->item(0);
                $extN = $xfrm->getElementsByTagNameNS($aNs, 'ext')->item(0);
                if (!$off || !$extN) continue;

                $x = (int)round((int)$off->getAttribute('x') / 9525);
                $y = (int)round((int)$off->getAttribute('y') / 9525);
                $w = (int)round((int)$extN->getAttribute('cx') / 9525);
                $h = (int)round((int)$extN->getAttribute('cy') / 9525);
                if ($w < 1 || $h < 1) continue;

                if ($tag === 'pic') {
                    $blipFill = $shape->getElementsByTagNameNS($pNs, 'blipFill')->item(0);
                    if ($blipFill) {
                        $blip = $blipFill->getElementsByTagNameNS($aNs, 'blip')->item(0);
                        if ($blip) {
                            $rId = $blip->getAttributeNS($rNs, 'embed');
                            if ($rId && isset($slideImgMap[$rId])) {
                                $imgData = $zip->getFromName($slideImgMap[$rId]);
                                if ($imgData) {
                                    $globalImgIdx++;
                                    $ext = strtolower(pathinfo($slideImgMap[$rId], PATHINFO_EXTENSION));
                                    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'])) $ext = 'png';
                                    file_put_contents($cachedir . '/img_' . $globalImgIdx . '.' . $ext, $imgData);

                                    $elements[] = [
                                        'type' => 'image',
                                        'x' => $x, 'y' => $y,
                                        'w' => $w, 'h' => $h,
                                        'img' => $globalImgIdx,
                                        'ext' => $ext,
                                    ];
                                }
                            }
                        }
                    }
                } elseif ($tag === 'sp') {
                    $txBody = $shape->getElementsByTagNameNS($pNs, 'txBody')->item(0);
                    if (!$txBody) continue;

                    $lines = [];
                    foreach ($txBody->getElementsByTagNameNS($aNs, 'p') as $para) {
                        // Default paragraph-level formatting
                        $defSize = 18;
                        $defColor = '#000000';
                        $defBold = false;
                        $defItalic = false;
                        $defFont = 'Calibri';

                        $pPr = $para->getElementsByTagNameNS($aNs, 'pPr')->item(0);
                        if ($pPr) {
                            $dRPr = $pPr->getElementsByTagNameNS($aNs, 'defRPr')->item(0);
                            if ($dRPr) {
                                $sz = $dRPr->getAttribute('sz');
                                if ($sz) $defSize = (int)$sz / 100;
                                $c = $dRPr->getElementsByTagNameNS($aNs, 'srgbClr')->item(0);
                                if ($c) $defColor = '#' . $c->getAttribute('val');
                                if ($dRPr->getAttribute('b') === '1') $defBold = true;
                                if ($dRPr->getAttribute('i') === '1') $defItalic = true;
                                $rf = $dRPr->getElementsByTagNameNS($aNs, 'rFont')->item(0);
                                if ($rf) {
                                    $l = $rf->getAttribute('latin') ?: $rf->getAttribute('ea') ?: '';
                                    if ($l) $defFont = $l;
                                }
                            }
                        }

                        $runTexts = [];
                        foreach ($para->getElementsByTagNameNS($aNs, 'r') as $run) {
                            $tNode = $run->getElementsByTagNameNS($aNs, 't')->item(0);
                            if (!$tNode) continue;

                            $text = $tNode->textContent;
                            $size = $defSize;
                            $color = $defColor;
                            $bold = $defBold;
                            $italic = $defItalic;
                            $font = $defFont;

                            $rPr = $run->getElementsByTagNameNS($aNs, 'rPr')->item(0);
                            if ($rPr) {
                                $sz = $rPr->getAttribute('sz');
                                if ($sz) $size = (int)$sz / 100;
                                $c = $rPr->getElementsByTagNameNS($aNs, 'srgbClr')->item(0);
                                if ($c) $color = '#' . $c->getAttribute('val');
                                if ($rPr->getAttribute('b') === '1') $bold = true;
                                if ($rPr->getAttribute('i') === '1') $italic = true;
                                $rf = $rPr->getElementsByTagNameNS($aNs, 'rFont')->item(0);
                                if ($rf) {
                                    $l = $rf->getAttribute('latin') ?: $rf->getAttribute('ea') ?: '';
                                    if ($l) $font = $l;
                                }
                            }

                            $runTexts[] = [
                                'text' => $text,
                                'size' => $size,
                                'color' => $color,
                                'bold' => $bold,
                                'italic' => $italic,
                                'font' => $font,
                            ];
                        }

                        if (!empty($runTexts)) {
                            $lines[] = $runTexts;
                        }
                    }

                    if (empty($lines)) continue;

                    $elements[] = [
                        'type' => 'text',
                        'x' => $x, 'y' => $y,
                        'w' => $w, 'h' => $h,
                        'lines' => $lines,
                    ];
                }
            }
        }

        $result[] = [
            'slide' => $slideNum,
            'bg' => $bg,
            'elements' => $elements,
        ];
    }

    $zip->close();

    $GLOBALS['_pptx_mode'] = 'html';
    $GLOBALS['_pptx_width'] = $slideW;
    $GLOBALS['_pptx_height'] = $slideH;

    return count($result) ? $result : false;
}
