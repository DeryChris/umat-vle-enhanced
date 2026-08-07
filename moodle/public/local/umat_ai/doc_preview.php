<?php
/**
 * Returns preview text content for Office documents (DOCX, XLSX).
 * Used by loadYtThumbnails() to render real text-based thumbnails.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

$url  = required_param('url', PARAM_RAW_TRIMMED);
$type = required_param('type', PARAM_ALPHA); // 'docx' or 'xlsx'

// Basic URL validation
if (!preg_match('#^https?://#', $url)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

// ─────────────────────────────────────────────────
// 1. Resolve file from URL  (simplified version of pptx_render.php logic)
// ─────────────────────────────────────────────────
$fileinfo = resolve_pluginfile_url_simple($url);
if (!$fileinfo) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Could not resolve file from URL']);
    exit;
}

$courseid = (int)$fileinfo['courseid'];
$filepath = $fileinfo['path'];
$tempFiles = $fileinfo['_temp'] ?? [];

// ─────────────────────────────────────────────────
// 2. Auth — must be logged in and enrolled
// ─────────────────────────────────────────────────
if ($courseid <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid course']);
    exit;
}
$course = get_course($courseid);
require_login($course, true);

// ─────────────────────────────────────────────────
// 3. Extract text content
// ─────────────────────────────────────────────────
header('Content-Type: application/json');

try {
    if ($type === 'docx') {
        $result = extract_docx_preview($filepath);
    } elseif ($type === 'xlsx') {
        $result = extract_xlsx_preview($filepath);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported type: ' . $type]);
        exit;
    }

    if ($result === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to extract preview']);
        exit;
    }

    echo json_encode($result);
} finally {
    // Clean up any temp files
    foreach ($tempFiles as $tf) {
        if (file_exists($tf)) @unlink($tf);
    }
}
exit;

// ═══════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═══════════════════════════════════════════════════

/**
 * Simplified URL resolver — extracts file path and course from pluginfile URL.
 */
function resolve_pluginfile_url_simple(string $url): ?array {
    global $DB;

    $parts = parse_url($url);
    $path = $parts['path'] ?? '';

    // Match: /pluginfile.php/CONTEXTID/COMPONENT/FILEAREA/ITEMID/REST
    if (!preg_match('#/pluginfile\.php/(\d+)/([^/]+)/([^/]+)/([^/]+)/(.*)$#', $path, $m)) {
        return null;
    }

    $contextid = (int)$m[1];
    $component = $m[2];
    $filearea  = $m[3];
    $itemid    = $m[4];
    $rest      = $m[5];

    // Derive filepath + filename
    $lastslash = strrpos($rest, '/');
    if ($lastslash === false) {
        $filepath = '/';
        $filename = urldecode($rest);
    } else {
        $filepath = '/' . urldecode(substr($rest, 0, $lastslash + 1));
        $filename = urldecode(substr($rest, $lastslash + 1));
    }

    // Get courseid from context
    $courseid = 0;
    $context = context::instance_by_id($contextid, IGNORE_MISSING);
    if ($context) {
        $ctx = $context->get_course_context(false);
        if ($ctx) {
            $courseid = $ctx->instanceid;
        }
    }
    if (!$courseid) {
        // Fallback: try umat_ai_materials table
        $mat = $DB->get_record('umat_ai_materials', ['filename' => $filename], 'courseid');
        if ($mat) {
            $courseid = (int)$mat->courseid;
        }
    }

    // Try Moodle file API first — strict match on the URL's itemid.
    $fs = get_file_storage();
    $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        // Fallback 1: common variations — the URL itemid may not match the
        // stored itemid (files are stored with itemid = courseid on push).
        $variations = [
            [$itemid, '/'],
            [0, '/'],
            [$itemid, $filepath],
        ];
        foreach ($variations as [$tryItem, $tryPath]) {
            $file = $fs->get_file($contextid, $component, $filearea, $tryItem, $tryPath, $filename);
            if ($file) {
                break;
            }
        }
    }
    if (!$file) {
        // Fallback 2: search every file in this context's filearea for a
        // matching filename, whatever itemid/filepath it lives at.
        $allfiles = $fs->get_area_files($contextid, $component, $filearea, false, 'id, filename, contenthash, timecreated', 'filename');
        foreach ($allfiles as $f) {
            if (isset($f['filename']) && $f['filename'] === $filename) {
                $file = $fs->get_file_by_hashname($f['contenthash']);
                if ($file) {
                    break;
                }
            }
        }
    }

    if ($file) {
        $hash = $file->get_contenthash();
        $filedir = dirname($file->get_content()) . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        // Moodle stores files in: dataroot/filedir/XX/XX/hash
        global $CFG;
        $diskpath = $CFG->dataroot . '/filedir/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
        if (file_exists($diskpath)) {
            return ['path' => $diskpath, 'filename' => $filename, 'courseid' => $courseid];
        }
        // Fallback: copy to temp (track for cleanup)
        $temp = tempnam(sys_get_temp_dir(), 'umat_');
        $file->copy_content_to($temp);
        return ['path' => $temp, 'filename' => $filename, 'courseid' => $courseid, '_temp' => [$temp]];
    }

    // Fallback: check ai_materials directory
    global $CFG;
    $matpath = $CFG->dataroot . '/ai_materials/' . $courseid . '/' . $filename;
    if (file_exists($matpath)) {
        return ['path' => $matpath, 'filename' => $filename, 'courseid' => $courseid];
    }

    return ['path' => $filename, 'filename' => $filename, 'courseid' => $courseid];
}

/**
 * Extract first ~10 non-empty paragraphs from a DOCX file.
 */
function extract_docx_preview(string $filepath): ?array {
    if (!extension_loaded('zip')) {
        return ['lines' => [], 'type' => 'docx', 'note' => 'zip extension not available'];
    }
    if (!file_exists($filepath)) {
        return ['lines' => [], 'type' => 'docx', 'note' => 'file not found'];
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return ['lines' => [], 'type' => 'docx', 'note' => 'could not open zip'];
    }

    $xmlContent = $zip->getFromName('word/document.xml');
    $zip->close();

    if (!$xmlContent) {
        return ['lines' => [], 'type' => 'docx', 'note' => 'no document.xml'];
    }

    // Suppress XML errors for malformed documents
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if (!$xml) {
        libxml_clear_errors();
        return ['lines' => [], 'type' => 'docx', 'note' => 'could not parse XML'];
    }

    // Register the main Word namespace
    $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xml->registerXPathNamespace('w', $ns);

    $paragraphs = $xml->xpath('//w:p');
    if (!$paragraphs) {
        libxml_clear_errors();
        return ['lines' => [], 'type' => 'docx'];
    }
    libxml_clear_errors();

    $lines = [];
    foreach ($paragraphs as $p) {
        $text = '';
        // Collect all w:t text runs within this paragraph
        foreach ($p->xpath('.//w:t') as $t) {
            $text .= (string)$t;
        }
        $text = trim($text);
        if ($text !== '') {
            $lines[] = $text;
            if (count($lines) >= 10) {
                break; // First 10 non-empty lines is enough for a thumbnail
            }
        }
    }

    return ['lines' => $lines, 'type' => 'docx'];
}

/**
 * Extract first 8 rows from the first sheet of an XLSX file.
 */
function extract_xlsx_preview(string $filepath): ?array {
    if (!extension_loaded('zip')) {
        return ['lines' => [], 'type' => 'xlsx', 'note' => 'zip extension not available'];
    }
    if (!file_exists($filepath)) {
        return ['lines' => [], 'type' => 'xlsx', 'note' => 'file not found'];
    }

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return ['lines' => [], 'type' => 'xlsx', 'note' => 'could not open zip'];
    }

    // 1. Get shared strings table (using local-name() to avoid namespace prefix issues)
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        libxml_use_internal_errors(true);
        $ssDoc = simplexml_load_string($ssXml);
        if ($ssDoc) {
            foreach ($ssDoc->xpath('//*[local-name()="si"]') as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                    $text .= (string)$t;
                }
                $sharedStrings[] = $text;
            }
        }
        libxml_clear_errors();
    }

    // 2. Get first sheet (always default to sheet1.xml for simplicity)
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if (!$sheetXml) {
        return ['lines' => [], 'type' => 'xlsx'];
    }

    libxml_use_internal_errors(true);
    $sheetDoc = simplexml_load_string($sheetXml);
    if (!$sheetDoc) {
        libxml_clear_errors();
        return ['lines' => [], 'type' => 'xlsx'];
    }

    $rows = $sheetDoc->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
    if (!$rows) {
        libxml_clear_errors();
        return ['lines' => [], 'type' => 'xlsx'];
    }
    libxml_clear_errors();

    $lines = [];
    foreach ($rows as $row) {
        $cells = $row->xpath('*[local-name()="c"]');
        if (!$cells) continue;

        $rowText = [];
        $colCount = 0;
        foreach ($cells as $cell) {
            if ($colCount >= 6) break; // Max 6 cols for preview
            $colCount++;

            $type = (string)$cell['t'];
            $value = '';

            if ($type === 's' && isset($cell->v)) {
                // Shared string reference
                $idx = (int)(string)$cell->v;
                $value = $sharedStrings[$idx] ?? '';
            } elseif (isset($cell->v)) {
                $value = (string)$cell->v;
            }

            $rowText[] = $value;
        }

        $line = implode('', $rowText);
        if (trim($line) !== '') {
            $lines[] = $rowText;
            if (count($lines) >= 8) break; // First 8 rows
        }
    }

    return ['lines' => $lines, 'type' => 'xlsx'];
}
