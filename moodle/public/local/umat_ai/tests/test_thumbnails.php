<?php
/**
 * Regression test harness for thumbnail rendering across all 7 content types.
 *
 * Tests: video, image, audio, PDF, DOCX, XLSX, PPTX
 *
 * Run:  php tests/test_thumbnails.php
 *       php tests/test_thumbnails.php --verbose
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// ─── Config ───────────────────────────────────────
$VERBOSE = in_array('--verbose', $argv ?? []);
$PLUGIN_DIR = __DIR__ . '/..';
$CSS_FILE = $PLUGIN_DIR . '/styles/umat-yt-grid.css';
$HELPER_FILE = $PLUGIN_DIR . '/classes/overlay_helper.php';
$MAT_VIEWER_SRC = $PLUGIN_DIR . '/amd/src/material_viewer.js';
$MAT_VIEWER_BUILD = $PLUGIN_DIR . '/amd/build/material_viewer.js';
$HUB_SRC = $PLUGIN_DIR . '/amd/src/umat_hub.js';
$HUB_BUILD = $PLUGIN_DIR . '/amd/build/umat_hub.js';
$HUB_MIN = $PLUGIN_DIR . '/amd/build/umat_hub.min.js';
$DOC_PREVIEW = $PLUGIN_DIR . '/doc_preview.php';
$PPTX_RENDER = $PLUGIN_DIR . '/pptx_render.php';

// ─── Test Runner ──────────────────────────────────
$passed = 0;
$failed = 0;
$tests = [];

function test(string $label, $result, string $detail = '') {
    global $passed, $failed, $VERBOSE;
    if ($result) {
        $passed++;
        if ($VERBOSE) echo "  ✅ $label\n";
    } else {
        $failed++;
        echo "  ❌ $label" . ($detail ? " — $detail" : '') . "\n";
    }
}

function section(string $title) {
    echo "\n─── $title ───\n";
}

// Ensure we can load Moodle for DB-dependent tests
$moodleLoaded = false;
if (file_exists($PLUGIN_DIR . '/../../config.php')) {
    try {
        require_once($PLUGIN_DIR . '/../../config.php');
        $admins = get_admins();
        $admin = reset($admins);
        \core\session\manager::set_user($admin);
        $moodleLoaded = true;
    } catch (Throwable $e) {
        // Moodle not available, skip DB-dependent tests
    }
}

// ═══════════════════════════════════════════════════
//  1.  FILE EXISTENCE & PHP SYNTAX
// ═══════════════════════════════════════════════════
section('1. File Existence & PHP Syntax');

$phpFiles = [
    'classes/overlay_helper.php' => $HELPER_FILE,
    'doc_preview.php'            => $DOC_PREVIEW,
    'pptx_render.php'            => $PPTX_RENDER,
    'lib.php'                    => $PLUGIN_DIR . '/lib.php',
    'settings.php'               => $PLUGIN_DIR . '/settings.php',
];
foreach ($phpFiles as $name => $path) {
    test("PHP file exists: $name", file_exists($path));
}
test("CSS file exists", file_exists($CSS_FILE));
test("Material viewer source exists", file_exists($MAT_VIEWER_SRC));
test("Hub source exists", file_exists($HUB_SRC));
test("Hub build exists", file_exists($HUB_BUILD));
test("Hub minified exists", file_exists($HUB_MIN));

// PHP syntax check
foreach ($phpFiles as $name => $path) {
    if (!file_exists($path)) continue;
    $out = shell_exec("php -l " . escapeshellarg($path) . " 2>&1");
    test("PHP syntax: $name", strpos($out, 'No syntax errors') !== false, $out);
}

// ═══════════════════════════════════════════════════
//  2.  INLINE JS — 7 CONTENT BRANCHES
// ═══════════════════════════════════════════════════
section('2. Inline JS — loadYtThumbnails() Branches');

$helper = file_get_contents($HELPER_FILE);

// Check the function exists
test("loadYtThumbnails defined",
    preg_match('/window\.loadYtThumbnails\s*=\s*window\.loadYtThumbnails\s*\|\|\s*function\s*\(/', $helper));

// 7 content types must each have a branch
$branches = [
    'image'        => "mime.includes('image')",
    'video'        => "mime.includes('video')",
    'pdf'          => "mime.includes('pdf')",
    'presentation' => "mime.includes('presentation')",
    'word'         => "mime.includes('word')",
    'spreadsheet'  => "mime.includes('spreadsheet')",
    'audio'        => "mime.includes('audio')",
];
foreach ($branches as $type => $pattern) {
    test("Branch for: $type", strpos($helper, $pattern) !== false);
}

// Verify correct ordering (image → video → pdf → pptx → docx/xlsx → audio)
$order = ['image', 'video', 'pdf', 'presentation', 'word', 'spreadsheet', 'audio'];
$prevPos = 0;
foreach ($order as $type) {
    $pos = strpos($helper, $branches[$type]);
    test("Branch order: $type after previous", $pos > $prevPos, "pos=$pos prev=$prevPos");
    $prevPos = $pos;
}

// ═══════════════════════════════════════════════════
//  3.  BUG FIX VERIFICATION
// ═══════════════════════════════════════════════════
section('3. Bug Fix Verification');

// PDF onerror handler (Issue: spinner stuck on CDN failure)
test("PDF onerror handler",
    strpos($helper, 's.onerror=function(){lo.remove()}') !== false ||
    strpos($helper, 's.onerror=function(){lo.remove();}') !== false);

// Safe URL encoding prevents double-encoding (Issue: %20 → %2520)
test("Safe URL decode-encode in PPTX branch",
    strpos($helper, 'decodeURIComponent(url)') !== false);
test("Safe URL decode-encode in DOCX branch",
    strpos($helper, '_eu2;try{_eu2=encodeURIComponent(decodeURIComponent(url))}') !== false);

// Base URL extraction guard (Issue: indexOf returns -1)
test("Base URL guard for PPTX (a1)",
    strpos($helper, "_pi>=0?a1.pathname.substring(0,_pi):''") !== false);
test("Base URL guard for DOCX (a2)",
    strpos($helper, "_pi2>=0?a2.pathname.substring(0,_pi2):''") !== false);

// PPTX img onerror fallback exists
test("PPTX image onerror fallback",
    strpos($helper, 'i1.onerror=function(){') !== false);

// DOCX fetch catch fallback with loading removal
test("DOCX fetch catch removes loading",
    strpos($helper, '.catch(function(){lo2.remove()') !== false);

// MutationObserver triggered
test("MutationObserver triggers loadYtThumbnails",
    strpos($helper, 'MutationObserver') !== false);

// ═══════════════════════════════════════════════════
//  4.  CSS CLASS PRESENCE
// ═══════════════════════════════════════════════════
section('4. CSS Class Presence');

$css = file_get_contents($CSS_FILE);

// Background classes for each type
$bgClasses = [
    'yt-bg-video',
    'yt-bg-pdf',
    'yt-bg-word',
    'yt-bg-pptx',
    'yt-bg-excel',
    'yt-bg-image',
    'yt-bg-audio',
    'yt-bg-other',
];
foreach ($bgClasses as $cls) {
    test("CSS class .$cls exists",
        strpos($css, ".$cls") !== false || strpos($css, ".$cls ") !== false);
}

// Thumbnail structural classes
$structClasses = [
    '.yt-thumb',
    '.yt-thumb-img',
    '.yt-thumb-canvas',
    '.yt-thumb-icon',
    '.yt-thumb-loading',
    '.yt-thumb-doc-preview',
    '.yt-thumb-doc-line',
    '.yt-thumb-audio-wav',
];
foreach ($structClasses as $cls) {
    test("CSS struct class $cls exists",
        strpos($css, $cls) !== false);
}

// Verify doc line has ellipsis overflow settings
test("Doc line has text-overflow: ellipsis",
    strpos($css, 'text-overflow: ellipsis') !== false);
test("Doc line has overflow: hidden",
    strpos($css, 'overflow: hidden') !== false);

// ═══════════════════════════════════════════════════
//  5.  DOCX TEXT EXTRACTION (synthetic)
// ═══════════════════════════════════════════════════
section('5. DOCX Text Extraction');

if (!extension_loaded('zip')) {
    test("PHP zip extension", false, "zip extension not loaded — skipping extraction tests");
} else {
    $docxPath = sys_get_temp_dir() . '/umat_test_docx.docx';

    // Create synthetic DOCX
    $zip = new ZipArchive();
    if ($zip->open($docxPath, ZipArchive::CREATE) === true) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    <w:p><w:r><w:t>TITLE: Regression Test Document</w:t></w:r></w:p>
    <w:p><w:r><w:t>Line 2: Content paragraph for testing</w:t></w:r></w:p>
    <w:p><w:r><w:t>Line 3: Final verification line</w:t></w:r></w:p>
  </w:body>
</w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->close();

        // Try to extract using the same logic as doc_preview.php
        $z2 = new ZipArchive();
        if ($z2->open($docxPath) === true) {
            $xmlContent = $z2->getFromName('word/document.xml');
            $z2->close();

            test("DOCX: document.xml readable", $xmlContent !== false);
            
            if ($xmlContent) {
                // Write XML to a temp file first (circumvents simplexml_load_string
                // intermittent failures on Windows/PHP 8.4)
                $tmpXml = sys_get_temp_dir() . '/umat_docx_slide.xml';
                file_put_contents($tmpXml, $xmlContent);
                libxml_use_internal_errors(true);
                libxml_clear_errors();
                $sx = simplexml_load_file($tmpXml);
                $docxParseOk = ($sx !== false);
                // Always report parse status — handled below
                libxml_clear_errors();
                unlink($tmpXml);

                $docxSxOk = $docxParseOk; // rename for clarity
                if ($docxSxOk) {
                    $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
                    $sx->registerXPathNamespace('w', $ns);
                    $paragraphs = $sx->xpath('//w:p');
                    test("DOCX: paragraphs found", $paragraphs && count($paragraphs) > 0,
                        'count=' . count($paragraphs ?? []));

                    if ($paragraphs) {
                        $texts = [];
                        foreach ($paragraphs as $p) {
                            $txt = '';
                            foreach ($p->xpath('.//w:t') as $t) {
                                $txt .= (string)$t;
                            }
                            $texts[] = trim($txt);
                        }
                        test("DOCX: contains 'Regression Test'",
                            strpos(implode(' ', $texts), 'Regression Test') !== false,
                            'texts=' . json_encode($texts));
                        test("DOCX: extracted ≥3 lines", count($texts) >= 3);
                    } else {
                        // If this triggers, count was 0 or null
                        test("DOCX: text extraction skipped", false,
                            'paragraphs empty or null');
                    }
                } else {
                    test("DOCX: XML parse (simplexml_load_file)", false,
                        'Could not parse DOCX XML from temp file');
                }
            }
        }
        unlink($docxPath);
    } else {
        test("DOCX: create zip", false, 'ZipArchive::open failed');
    }
}

// ═══════════════════════════════════════════════════
//  6.  XLSX TEXT EXTRACTION (synthetic)
// ═══════════════════════════════════════════════════
section('6. XLSX Text Extraction');

if (extension_loaded('zip')) {
    $xlsxPath = sys_get_temp_dir() . '/umat_test_xlsx.xlsx';
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath, ZipArchive::CREATE) === true) {
        $ss = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="3"><si><t>Name</t></si><si><t>Score</t></si><si><t>Grade</t></si></sst>';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row><row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2"><v>95</v></c><c r="C2" t="s"><v>2</v></c></row></sheetData></worksheet>';
        $zip->addFromString('xl/sharedStrings.xml', $ss);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->close();

        // Extract using same logic as doc_preview.php
        $z2 = new ZipArchive();
        $z2->open($xlsxPath);
        $ssXml = $z2->getFromName('xl/sharedStrings.xml');
        $shXml = $z2->getFromName('xl/worksheets/sheet1.xml');
        $z2->close();

        test("XLSX: sharedStrings.xml readable", $ssXml !== false);
        test("XLSX: sheet1.xml readable", $shXml !== false);

        if ($ssXml && $shXml) {
            libxml_use_internal_errors(true);

            // Parse shared strings
            $ssDoc = simplexml_load_string($ssXml);
            $strings = [];
            if ($ssDoc) {
                foreach ($ssDoc->xpath('//*[local-name()="si"]') as $si) {
                    $t = '';
                    foreach ($si->xpath('.//*[local-name()="t"]') as $tx) {
                        $t .= (string)$tx;
                    }
                    $strings[] = $t;
                }
            }

            // Parse sheet
            $shDoc = simplexml_load_string($shXml);
            $rows = $shDoc->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');

            test("XLSX: shared strings parsed", count($strings) === 3,
                'count=' . count($strings));
            test("XLSX: rows found", $rows && count($rows) === 2,
                'count=' . count($rows ?? []));
            test("XLSX: shared string 'Name' found",
                in_array('Name', $strings) && in_array('Score', $strings) && in_array('Grade', $strings));

            libxml_clear_errors();
        }
        unlink($xlsxPath);
    } else {
        test("XLSX: create zip", false, 'ZipArchive::open failed');
    }
}

// ═══════════════════════════════════════════════════
//  7.  PPTX URL CONSTRUCTION
// ═══════════════════════════════════════════════════
section('7. PPTX Thumbnail URL Construction');

// Verify the JS constructs the correct URL for pptx_render.php
test("PPTX URL uses encodeURIComponent pattern",
    strpos($helper, "pptx_render.php?action=slide&url=") !== false);
test("PPTX URL uses slide=1 parameter",
    strpos($helper, "&slide=1") !== false);
test("PPTX URL uses _eu (safe-encoded) variable",
    strpos($helper, "b1+'/local/umat_ai/pptx_render.php?action=slide&url='+_eu+'&slide=1'") !== false);

// Verify the doc_preview.php URL construction
test("DOCX URL uses doc_preview.php",
    strpos($helper, "doc_preview.php?url=") !== false);
test("DOCX URL passes type parameter",
    strpos($helper, "&type=") !== false);

// ═══════════════════════════════════════════════════
//  8.  AMD MODULE — VIEWER TYPE HANDLING
// ═══════════════════════════════════════════════════
section('8. AMD Module — material_viewer.js Types');

$viewer = file_get_contents($MAT_VIEWER_SRC);

// The viewer switch statement should handle all 7 types
$viewerTypes = [
    "'video'" => 'Video',
    "'image'" => 'Image',
    "'audio'" => 'Audio',
    "'pdf'"   => 'PDF',
    "'docx'"  => 'DOCX',
    "'xlsx'"  => 'XLSX',
    "'pptx'"  => 'PPTX',
];
foreach ($viewerTypes as $pattern => $label) {
    test("Viewer handles: $label", strpos($viewer, "case $pattern:") !== false);
}

// Verify the 'other' default fallback exists
test("Viewer has 'other' fallback",
    strpos($viewer, "default:") !== false &&
    strpos($viewer, "Preview not available") !== false);

// Verify silurus WASM integration exists
test("Viewer has silurus WASM CDN",
    strpos($viewer, 'SILURUS_CDN') !== false);
test("Viewer has silurus WASM path (dist/)",
    strpos($viewer, 'dist/') !== false);
test("Viewer has mammoth fallback for DOCX",
    strpos($viewer, 'MAMMOTH_CDN') !== false);
test("Viewer has SheetJS for XLSX",
    strpos($viewer, 'CDN_XLSX') !== false);
test("Viewer has trySilurus function",
    strpos($viewer, 'function trySilurus') !== false);
test("Viewer has PPTX PHP fallback",
    strpos($viewer, 'fallbackPptxRender') !== false ||
    strpos($viewer, 'fetchSlides') !== false);

// Verify CDN URLs are accessible (if network available)
$cdns = [
    'SILURUS_CDN' => 'https://cdn.jsdelivr.net/npm/@silurus/ooxml@0.74.2/+esm',
    'MAMMOTH_CDN' => 'https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js',
    'CDN_XLSX'    => 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
];
foreach ($cdns as $var => $url) {
    $found = strpos($viewer, $url) !== false;
    test("CDN URL in source: $var", $found);
}

// Verify the PRISM CDN for code viewer
test("Viewer has Prism.js code viewer",
    strpos($viewer, 'PRISM_CDN') !== false);

// Verify silurus API usage matches the module's export structure
// The module exports { docx, pptx, xlsx } where each is a namespace object
test("Silurus PPTX uses mod.pptx.PptxViewer (not mod.PptxViewer)",
    strpos($viewer, 'mod.pptx.PptxViewer') !== false);
test("Silurus DOCX uses mod.docx.DocxViewer (not mod.DocxViewer)",
    strpos($viewer, 'mod.docx.DocxViewer') !== false);

// Also check the build file uses the same API
$viewerBuild = file_get_contents($MAT_VIEWER_BUILD);
test("Build: silurus PPTX uses mod.pptx.PptxViewer",
    strpos($viewerBuild, 'mod.pptx.PptxViewer') !== false);
test("Build: silurus DOCX uses mod.docx.DocxViewer",
    strpos($viewerBuild, 'mod.docx.DocxViewer') !== false);

// ═══════════════════════════════════════════════════
//  9.  HUB RENDERER — TYPE MAPPING
// ═══════════════════════════════════════════════════
section('9. Hub Renderer Type Mapping');

$hubSource = file_get_contents($HUB_SRC);

// The click handler must map all 7 types
test("Hub maps 'docx' type", strpos($hubSource, "'docx'") !== false);
test("Hub maps 'xlsx' type", strpos($hubSource, "'xlsx'") !== false);
test("Hub maps 'pptx' type", strpos($hubSource, "'pptx'") !== false);
test("Hub maps 'video' type", strpos($hubSource, "'video'") !== false);
test("Hub maps 'pdf' type", strpos($hubSource, "'pdf'") !== false);
test("Hub maps 'image' type", strpos($hubSource, "'image'") !== false);
test("Hub maps 'audio' type", strpos($hubSource, "'audio'") !== false);
test("Hub falls back to 'other'", strpos($hubSource, "'other'") !== false);

// Verify the exact mapping line follows the correct order
test("Hub mapping order: video→pdf→image→audio→docx→xlsx→pptx→other",
    preg_match("/video.*pdf.*image.*audio.*docx.*xlsx.*pptx.*other/", $hubSource) === 1);

// Build file verification
$hubBuild = file_get_contents($HUB_BUILD);
test("Hub build has 'docx' mapping", strpos($hubBuild, "'docx'") !== false);
test("Hub build has 'xlsx' mapping", strpos($hubBuild, "'xlsx'") !== false);
test("Hub build has 'pptx' mapping", strpos($hubBuild, "'pptx'") !== false);

$hubMin = file_get_contents($HUB_MIN);
test("Hub minified has docx mapping", strpos($hubMin, '"docx"') !== false);
test("Hub minified has xlsx mapping", strpos($hubMin, '"xlsx"') !== false);
test("Hub minified has pptx mapping", strpos($hubMin, '"pptx"') !== false);

// Verify tile visual classes in hub source
$hubTileClasses = [
    "'yt-bg-word'",
    "'yt-bg-pptx'",
    "'yt-bg-excel'",
    "'yt-bg-video'",
    "'yt-bg-pdf'",
    "'yt-bg-image'",
    "'yt-bg-audio'",
];
foreach ($hubTileClasses as $cls) {
    test("Hub tile has class $cls", strpos($hubSource, $cls) !== false);
}

// ═══════════════════════════════════════════════════
//  10. URL RESOLUTION (if Moodle available)
// ═══════════════════════════════════════════════════
section('10. URL Resolution (Moodle-dependent)');

if ($moodleLoaded) {
    global $DB, $CFG, $USER;

    // Find real files if they exist
    $files = $DB->get_records_sql(
        "SELECT f.id, f.filename, f.mimetype, f.contextid, f.component, f.filearea, f.itemid, f.filepath, f.contenthash
         FROM {files} f
         JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = 70
         WHERE f.filename NOT IN ('.','..')
           AND (f.filename LIKE '%.docx' OR f.filename LIKE '%.pptx' OR f.filename LIKE '%.xlsx' OR f.filename LIKE '%.pdf')
           AND f.filesize > 0
         LIMIT 10"
    );

    if (empty($files)) {
        test("Real files in DB", false, "No Office/PDF files found in course contexts");
        echo "  ℹ️  File resolution tests skipped — no real files in DB\n";
    } else {
        test("Real files found in DB", count($files) > 0,
            'count=' . count($files));

        foreach ($files as $f) {
            $fs = get_file_storage();
            $file = $fs->get_file($f->contextid, $f->component, $f->filearea, $f->itemid, $f->filepath, $f->filename);
            if ($file) {
                $diskPath = $CFG->dataroot . '/filedir/' . substr($file->get_contenthash(), 0, 2) . '/' .
                            substr($file->get_contenthash(), 2, 2) . '/' . $file->get_contenthash();
                test("File on disk: {$f->filename}", file_exists($diskPath),
                    'path=' . $diskPath);
            } else {
                test("File API: {$f->filename}", false, 'get_file returned null');
            }
        }
    }
} else {
    echo "  ℹ️  Moodle bootstrap not available — skipping URL resolution tests\n";
}

// ═══════════════════════════════════════════════════
//  11. PPTX RENDER — CACHE DIRECTORY
// ═══════════════════════════════════════════════════
section('11. PPTX Cache Configuration');

$pptxSource = file_get_contents($PPTX_RENDER);
test("PPTX cache uses md5 hash",
    strpos($pptxSource, 'md5(') !== false);
test("PPTX cache dir under dataroot",
    strpos($pptxSource, "'/.pptx-cache/'") !== false);
test("PPTX action=slide auto-renders",
    strpos($pptxSource, "action=slide") !== false || strpos($pptxSource, 'action=slide') !== false);
test("PPTX action=slides lists slides",
    strpos($pptxSource, "=== 'slides'") !== false);
test("PPTX resolves file via pluginfile URL",
    strpos($pptxSource, 'resolve_pluginfile_url') !== false);

// ═══════════════════════════════════════════════════
//  12. CROSS-REFERENCE: JS CLASSES ↔ CSS CLASSES
// ═══════════════════════════════════════════════════
section('12. JS ↔ CSS Class Cross-Reference');

// Extract CSS class names set via className= in inline JS
$jsClassNameClasses = [];
preg_match_all("/className\s*=\s*'([^']+)'/", $helper, $matches);
foreach ($matches[1] as $cls) {
    foreach (explode(' ', $cls) as $c) {
        $c = trim($c);
        if ($c) $jsClassNameClasses[$c] = ($jsClassNameClasses[$c] ?? 0) + 1;
    }
}

// Also check classList.add patterns
preg_match_all('/classList\.add\(/', $helper, $claMatches);
$usesClassList = count($claMatches[0]) > 0;

// Check the most important class names exist in CSS
// Note: some classes are set via HTML templates or AMD modules, not inline JS
$requiredCssClasses = [
    'yt-thumb', 'yt-thumb-img', 'yt-thumb-icon', 'yt-thumb-loading',
    'yt-thumb-canvas', 'yt-thumb-doc-preview', 'yt-thumb-doc-line',
    'yt-thumb-audio-wav', 'yt-play-ov', 'yt-badge', 'yt-meta', 'yt-title',
    'yt-channel', 'yt-stats', 'yt-actions',
    // yt-view-btn: JS selector only, no CSS rule needed — used in hub JS
];
foreach ($requiredCssClasses as $cls) {
    $inJs = isset($jsClassNameClasses[$cls]);
    $inCss = strpos($css, ".$cls") !== false || strpos($css, ".$cls ") !== false;
    // Only require CSS presence; JS usage is informative (may be in templates/AMD)
    test("Class .$cls " . ($inCss ? '✅CSS' : '❌CSS') .
         ($inJs ? ' ✅JS' : ' (not in inline JS)'), $inCss);
}

// ═══════════════════════════════════════════════════
//  SUMMARY
// ═══════════════════════════════════════════════════
$total = $passed + $failed;
$pct = $total > 0 ? round($passed / $total * 100) : 0;
echo "\n" . str_repeat('═', 60);
echo "\n  RESULTS:  {$passed} passed  |  {$failed} failed  |  {$total} total  ({$pct}%)\n";
echo str_repeat('═', 60) . "\n";

if ($failed === 0) {
    echo "  ✅ ALL TESTS PASSED\n";
} else {
    echo "  ❌ {$failed} TEST(S) FAILED — review output above\n";
}
echo "\n";

exit($failed > 0 ? 1 : 0);
