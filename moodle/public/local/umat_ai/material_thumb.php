<?php
/**
 * First-page thumbnail renderer for PDF / Office materials.
 *
 * Pipeline (locally installed tools):
 *   PDF            -> ImageMagick page [0] -> PNG
 *   DOCX/PPTX/XLSX -> LibreOffice -> PDF -> ImageMagick page [0] -> PNG
 *
 * Output is cached in dataroot/.umat-thumbs keyed on contenthash+mtime,
 * so each file is converted only once.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$url = required_param('url', PARAM_RAW_TRIMMED);
if (!preg_match('#^https?://#', $url)) {
    http_response_code(400);
    die('Invalid URL');
}

// ─────────────────────────────────────────────────
// 1. Resolve file from URL (same resolver as pptx_render.php)
// ─────────────────────────────────────────────────
$fileinfo = resolve_pluginfile_url($url);
if (!$fileinfo) {
    http_response_code(404);
    die('Could not resolve file');
}

$courseid = (int)$fileinfo['courseid'];
$path     = $fileinfo['path'];
$filename = $fileinfo['filename'];
$hash     = $fileinfo['hash'];
$mtime    = $fileinfo['mtime'];

// ─────────────────────────────────────────────────
// 2. Auth — logged in and enrolled in the course
// ─────────────────────────────────────────────────
if ($courseid <= 0) {
    http_response_code(400);
    die('Invalid course');
}
$course = get_course($courseid);
require_login($course, true);

// ─────────────────────────────────────────────────
// 3. Type gate
// ─────────────────────────────────────────────────
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$supported = ['pdf' => 1, 'doc' => 1, 'docx' => 1, 'ppt' => 1, 'pptx' => 1, 'xls' => 1, 'xlsx' => 1];
if (!isset($supported[$ext]) || !file_exists($path)) {
    http_response_code(404);
    die('Unsupported or missing file');
}

// ─────────────────────────────────────────────────
// 4. Cache + render
// ─────────────────────────────────────────────────
$thumbdir = $CFG->dataroot . '/.umat-thumbs';
if (!is_dir($thumbdir)) {
    mkdir($thumbdir, 0777, true);
}
$thumb = $thumbdir . '/' . md5($hash . '_' . $mtime . '_v1') . '.png';

if (!file_exists($thumb)) {
    $ok = ($ext === 'pdf') ? render_pdf_thumb($path, $thumb) : render_office_thumb($path, $ext, $thumb);
    if (!$ok) {
        http_response_code(404);
        die('Render failed');
    }
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($thumb));
header('Cache-Control: public, max-age=86400');
readfile($thumb);
exit;

// ═══════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════

/**
 * First page of a PDF -> PNG thumbnail via ImageMagick.
 * `[0]` selects page 1; `-resize 400x>` only ever shrinks.
 *
 * NOTE: `-alpha remove` / `-background` are OPERATORS — they must appear
 * AFTER the input file, or ImageMagick errors with "no images found".
 */
function render_pdf_thumb(string $pdf, string $out): bool {
    $magick = find_magick();
    if (!$magick) return false;
    $cmd = '"' . $magick . '" -density 110 ' .
        escapeshellarg($pdf . '[0]') .
        ' -background white -alpha remove -resize "400x>" -quality 85 ' . escapeshellarg($out) . ' 2>&1';
    shell_exec($cmd);
    return file_exists($out) && filesize($out) > 0;
}

/**
 * Office file (docx/pptx/xlsx/...) -> PDF via LibreOffice, then first page PNG.
 */
function render_office_thumb(string $src, string $ext, string $out): bool {
    $lo = find_soffice();
    $magick = find_magick();
    if (!$lo || !$magick) return false;

    // LO infers the input filter from the file extension, so the copy must
    // keep the original extension (filedir names are extension-less hashes).
    $tmp = sys_get_temp_dir() . '/umat_thumb_' . uniqid();
    if (!@mkdir($tmp, 0777, true)) return false;
    $converted = $tmp . '/convert.' . $ext;
    if (!@copy($src, $converted)) {
        @rmdir($tmp);
        return false;
    }

    $cmd = '"' . $lo . '" --headless --norestore --convert-to pdf --outdir ' .
        escapeshellarg($tmp) . ' ' . escapeshellarg($converted) . ' 2>&1';
    shell_exec($cmd);

    $ok = false;
    $pdfs = glob($tmp . '/*.pdf');
    if (!empty($pdfs)) {
        $ok = render_pdf_thumb($pdfs[0], $out);
    }
    foreach (glob($tmp . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
    return $ok;
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
        foreach (['/usr/bin/libreoffice', '/usr/local/bin/libreoffice', '/snap/bin/libreoffice'] as $p) {
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

/**
 * Parse a pluginfile.php URL to get course, path, hash.
 * URL format: /pluginfile.php/{contextid}/{component}/{filearea}/{itemid}/{path}{filename}
 */
function resolve_pluginfile_url(string $url): ?array {
    global $DB, $CFG;

    $parts = parse_url($url);
    $path = $parts['path'] ?? '';

    if (!preg_match('#/pluginfile\.php/(\d+)/([^/]+)/([^/]+)/([^/]+)/(.*)$#', $path, $m)) {
        return resolve_direct_url($url);
    }

    $contextid = (int)$m[1];
    $component = $m[2];
    $filearea  = $m[3];
    $itemid    = $m[4];
    $rest      = $m[5];

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
        $mat = $DB->get_record('umat_ai_materials', ['filename' => $filename], 'courseid');
        if ($mat) {
            return build_result(null, (int)$mat->courseid, $filename, null, 0);
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

    // Fallback 1: common variations (different itemids, different filepath)
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
                'courseid' => (int)$mat->courseid,
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
        $filedir = $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        if (file_exists($filedir)) {
            $path = $filedir;
        }
    } else {
        $path = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $filename;
        if (!file_exists($path)) {
            $decoded = urldecode($filename);
            $altPath = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $decoded;
            if (file_exists($altPath)) {
                $path = $altPath;
                $filename = $decoded;
            } else {
                $basename = basename($decoded);
                $pattern = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $basename;
                if (file_exists($pattern)) {
                    $path = $pattern;
                    $filename = $basename;
                } else {
                    $path = $decoded;
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
