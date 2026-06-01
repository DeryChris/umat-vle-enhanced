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
$url    = required_param('url', PARAM_URL);
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
$cachekey  = md5($filehash . '_' . $filemtime);
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

    // Build slide URLs from $CFG->wwwroot (prevents Host header injection)
    $base = rtrim($CFG->wwwroot, '/') . '/local/umat_ai/pptx_render.php';
    $qurl = urlencode($url);
    foreach ($result['slides'] as &$s) {
        $s['src'] = $base . '?action=slide&url=' . $qurl . '&slide=' . $s['slide'];
    }
    unset($s);

    file_put_contents($cachejson, json_encode($result));
    echo json_encode($result);
    exit;
}

// ─────────────────────────────────────────────────
// 5. ACTION: slide — return PNG image
// ─────────────────────────────────────────────────
if ($action === 'slide') {
    $slidepath = $cachedir . '/slide_' . $slide . '.png';
    if (!file_exists($slidepath)) {
        http_response_code(404);
        die('Slide not found');
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($slidepath));
    header('Cache-Control: public, max-age=86400');
    readfile($slidepath);
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
        return null;
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

    // Fallback: look in ai_materials directory
    return build_result(null, $courseid, $filename, null, 0);
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
            $path = $filename; // raw filename as fallback
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
 * Main render dispatch — tries LibreOffice first, falls back to GD.
 * Returns array of [ ['slide' => N] ... ] or false on failure.
 */
function render_pptx(string $path, string $cachedir): array|false {
    if (!file_exists($path)) return false;

    // Try LibreOffice pipeline (produces full slide images)
    $slides = render_via_libreoffice($path, $cachedir);
    if ($slides !== false) {
        $GLOBALS['_pptx_mode'] = 'images';
        return $slides;
    }

    // Fallback to GD renderer (text-only extraction)
    $slides = render_via_gd($path, $cachedir);
    if ($slides !== false) {
        $GLOBALS['_pptx_mode'] = 'text';
        return $slides;
    }

    return false;
}

// ═══════════════════════════════════════════════════
//  LIBREOFFICE PIPELINE
// ═══════════════════════════════════════════════════

function render_via_libreoffice(string $path, string $cachedir): array|false {
    // Check if soffice is available
    $lo = '';
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $commonPaths = [
            getenv('PROGRAMFILES') . '\\LibreOffice\\program\\soffice.exe',
            getenv('PROGRAMFILES(X86)') . '\\LibreOffice\\program\\soffice.exe',
            getenv('LOCALAPPDATA') . '\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
        ];
        foreach ($commonPaths as $p) {
            if (file_exists($p)) { $lo = $p; break; }
        }
        if (!$lo) $lo = trim(shell_exec('where soffice 2>NUL'));
    } else {
        $lo = trim(shell_exec('which soffice 2>/dev/null'));
        if (!$lo) {
            $commonPaths = [
                '/usr/bin/libreoffice',
                '/usr/local/bin/libreoffice',
                '/snap/bin/libreoffice',
            ];
            foreach ($commonPaths as $p) {
                if (file_exists($p)) { $lo = $p; break; }
            }
        }
    }
    if (!$lo) return false;

    $pdfpath = $cachedir . '/output.pdf';
    $pdffile = $cachedir . '/presentation.pdf';

    // Step 1: Convert PPTX → PDF
    if (!file_exists($pdffile)) {
        $escPath = escapeshellarg($path);
        $escOut  = escapeshellarg($cachedir);
        $cmd = "\"$lo\" --headless --norestore --convert-to pdf --outdir $escOut $escPath 2>&1";
        shell_exec($cmd);

        // Find the generated PDF
        $pdfs = glob($cachedir . '/*.pdf');
        if (empty($pdfs)) return false;
        rename($pdfs[0], $pdffile);
    }

    // Step 2: Convert PDF → PNGs
    $existing = glob($cachedir . '/slide_*.png');
    if (count($existing) > 0) {
        // Count slides
        $slides = [];
        $i = 1;
        while (file_exists($cachedir . '/slide_' . $i . '.png')) {
            $slides[] = ['slide' => $i];
            $i++;
        }
        return $slides;
    }

    // Try ImageMagick
    $convert = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where magick 2>NUL' : 'which convert 2>/dev/null';
    $convBin = trim(shell_exec($convert));
    if ($convBin) {
        $escPdf = escapeshellarg($pdffile);
        $escOut = escapeshellarg($cachedir . '/slide_%d.png');
        $cmd = "\"$convBin\" -density 150 $escPdf -quality 95 -background white -alpha remove $escOut 2>&1";
        shell_exec($cmd);
        $pngs = glob($cachedir . '/slide_*.png');
        if (count($pngs) > 0) {
            $slides = [];
            $i = 1;
            while (file_exists($cachedir . '/slide_' . $i . '.png')) {
                $slides[] = ['slide' => $i];
                $i++;
            }
            return $slides;
        }
    }

    // Try Ghostscript
    $gs = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where gswin64c 2>NUL' : 'which gs 2>/dev/null';
    $gsBin = trim(shell_exec($gs));
    if ($gsBin) {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') $gsBin = 'gswin64c';
        $escPdf = escapeshellarg($pdffile);
        $escOut = escapeshellarg($cachedir . '/slide_%d.png');
        $cmd = "\"$gsBin\" -dNOPAUSE -dBATCH -sDEVICE=pngalpha -r150 -sOutputFile=$escOut $escPdf 2>&1";
        shell_exec($cmd);
        $pngs = glob($cachedir . '/slide_*.png');
        if (count($pngs) > 0) {
            $slides = [];
            $i = 1;
            while (file_exists($cachedir . '/slide_' . $i . '.png')) {
                $slides[] = ['slide' => $i];
                $i++;
            }
            return $slides;
        }
    }

    return false;
}

// ═══════════════════════════════════════════════════
//  GD FALLBACK RENDERER
// ═══════════════════════════════════════════════════

function render_via_gd(string $path, string $cachedir): array|false {
    if (!extension_loaded('zip') || !extension_loaded('gd')) return false;
    if (!extension_loaded('dom') || !extension_loaded('libxml')) return false;

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return false;

    // Get slide files sorted
    $slides = [];
    for ($i = 1; $i <= 100; $i++) {
        $name = "ppt/slides/slide{$i}.xml";
        $rel  = "ppt/slides/_rels/slide{$i}.xml.rels";
        if ($zip->locateName($name) === false) break;

        $xmlContent = $zip->getFromName($name);
        $relsContent = $zip->locateName($rel) !== false ? $zip->getFromName($rel) : '';

        // Slide dimensions from presentation.xml
        $presXml = $zip->getFromName('ppt/presentation.xml');
        $slideW = 960;
        $slideH = 540;
        if ($presXml) {
            $presDom = new DOMDocument();
            $presDom->loadXML($presXml);
            $xpath = new DOMXPath($presDom);
            $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $ns = $presDom->documentElement->getAttribute('xmlns:p');
            // Try to find slide size from presentation.xml <p:sldSz>
            $sldSz = $presDom->getElementsByTagNameNS('http://schemas.openxmlformats.org/presentationml/2006/main', 'sldSz')->item(0);
            if ($sldSz) {
                $cx = $sldSz->getAttribute('cx');
                $cy = $sldSz->getAttribute('cy');
                if ($cx && $cy) {
                    $slideW = (int)round($cx / 914400 * 25.4 * 4); // EMU → px at ~150 DPI → actually 96 DPI
                    $slideH = (int)round($cy / 914400 * 25.4 * 4);
                    // Better: EMU → inches → pixels at 96 DPI
                    $slideW = max(640, (int)round($cx / 914400 * 96));
                    $slideH = max(360, (int)round($cy / 914400 * 96));
                }
            }
        }

        $imgW = min($slideW, 1280);
        $imgH = (int)round($imgW * $slideH / $slideW);

        $im = imagecreatetruecolor($imgW, $imgH);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));

        // Parse slide XML
        $dom = new DOMDocument();
        @$dom->loadXML($xmlContent);
        $xpathSl = new DOMXPath($dom);
        $xpathSl->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xpathSl->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $xpathSl->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        // Get slide background color
        $bgColor = null;
        $bgEls = $xpathSl->query('//p:bg//a:srgbClr');
        if ($bgEls && $bgEls->length > 0) {
            $bgColor = '#' . $bgEls->item(0)->getAttribute('val');
        }

        if ($bgColor) {
            $bgRgb = hex2rgb($bgColor);
            if ($bgRgb) imagefill($im, 0, 0, imagecolorallocate($im, $bgRgb[0], $bgRgb[1], $bgRgb[2]));
        }

        // Find font
        $font = find_font();
        $noFont = empty($font);

        // Render text shapes
        $textEls = $xpathSl->query('//a:t');
        $scaleX = $imgW / $slideW;
        $scaleY = $imgH / $slideH;

        // Process text runs with position
        $txBodies = $xpathSl->query('//p:sp/p:txBody/a:p');
        foreach ($txBodies as $para) {
            $txSp = $para->parentNode->parentNode->parentNode;
            $spPr = $xpathSl->query('.//a:xfrm', $txSp)->item(0);

            $px = 0; $py = 0; $pw = $slideW; $ph = $slideH;
            if ($spPr) {
                $off = $spPr->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'off')->item(0);
                $ext = $spPr->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'ext')->item(0);
                if ($off) {
                    $px = (float)$off->getAttribute('x') * $scaleX / 914400 * 96;
                    $py = (float)$off->getAttribute('y') * $scaleY / 914400 * 96;
                }
                if ($ext) {
                    $pw = (float)$ext->getAttribute('x') * $scaleX / 914400 * 96;
                    $ph = (float)$ext->getAttribute('y') * $scaleY / 914400 * 96;
                }
            }

            $paraText = '';
            $runs = $xpathSl->query('.//a:r', $para);
            $fontSize = 14;
            $bold = false;
            $italic = false;
            $color = [0, 0, 0];

            foreach ($runs as $run) {
                $rPr = $xpathSl->query('a:rPr', $run)->item(0);
                if ($rPr) {
                    $sz = $rPr->getAttribute('sz');
                    if ($sz) $fontSize = (int)$sz / 100 * $scaleY;
                    if ($rPr->getAttribute('b') === '1') $bold = true;
                    if ($rPr->getAttribute('i') === '1') $italic = true;
                    $clr = $xpathSl->query('.//a:srgbClr', $rPr)->item(0);
                    if ($clr) {
                        $hex = $clr->getAttribute('val');
                        $rgb = hex2rgb('#' . $hex);
                        if ($rgb) $color = $rgb;
                    }
                }
                $tNode = $xpathSl->query('a:t', $run)->item(0);
                if ($tNode) $paraText .= $tNode->textContent;
            }

            $paraText = trim($paraText);
            if ($paraText === '') continue;

            $fontFile = $font;
            if (!$noFont && $bold) {
                // Try common bold variants: arialbd.ttf, arialb.ttf, arial-bold.ttf
                $base = substr($font, 0, -4);
                $boldVariants = [
                    $base . 'bd.ttf',
                    $base . 'b.ttf',
                    $base . '-bold.ttf',
                ];
                foreach ($boldVariants as $bf) {
                    if (file_exists($bf)) { $fontFile = $bf; break; }
                }
            }

            $fsize = max(8, min(48, $fontSize));
            $lines = explode("\n", wordwrap($paraText, max(20, (int)($pw / ($fsize * 0.5))), "\n", true));

            if ($noFont) {
                // No TrueType font available — skip text rendering (can't use imagestring for non-ASCII well)
                continue;
            }

            foreach ($lines as $li => $line) {
                $ty = (int)($py + $li * $fsize * 1.4);
                if ($ty > $imgH - 10) break;
                imagettftext($im, $fsize, 0, (int)$px, $ty, imagecolorallocate($im, $color[0], $color[1], $color[2]), $fontFile, $line);
            }
        }

        // Render embedded images
        $imgEls = $xpathSl->query('//p:pic');
        foreach ($imgEls as $picEl) {
            $blip = $xpathSl->query('.//a:blip', $picEl)->item(0);
            if (!$blip) continue;
            $embedId = $blip->getAttribute('r:embed');
            if (!$embedId) continue;

            // Resolve image from relationships
            $imgPath = resolve_image($zip, $relsContent, $embedId, $i);
            if (!$imgPath) continue;

            // Position
            $xfrm = $xpathSl->query('.//a:xfrm', $picEl)->item(0);
            $ix = 0; $iy = 0; $iw = 100; $ih = 100;
            if ($xfrm) {
                $off = $xfrm->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'off')->item(0);
                $ext = $xfrm->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'ext')->item(0);
                if ($off) {
                    $ix = (int)((float)$off->getAttribute('x') * $scaleX / 914400 * 96);
                    $iy = (int)((float)$off->getAttribute('y') * $scaleY / 914400 * 96);
                }
                if ($ext) {
                    $iw = (int)((float)$ext->getAttribute('x') * $scaleX / 914400 * 96);
                    $ih = (int)((float)$ext->getAttribute('y') * $scaleY / 914400 * 96);
                }
            }

            if ($iw > 0 && $ih > 0) {
                $srcIm = @imagecreatefromstring($imgPath);
                if ($srcIm) {
                    imagecopyresampled($im, $srcIm, $ix, $iy, 0, 0, $iw, $ih, imagesx($srcIm), imagesy($srcIm));
                    imagedestroy($srcIm);
                }
            }
        }

        $slidePath = $cachedir . '/slide_' . $i . '.png';
        imagepng($im, $slidePath, 6);
        imagedestroy($im);
        $slides[] = ['slide' => $i];
    }

    $zip->close();
    return count($slides) ? $slides : false;
}

function hex2rgb(string $hex): ?array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return null;
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

function find_font(): string {
    $candidates = [];
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $windir = getenv('WINDIR') ?: 'C:\\Windows';
        $candidates = [
            "$windir\\Fonts\\arial.ttf",
            "$windir\\Fonts\\calibri.ttf",
            "$windir\\Fonts\\segoeui.ttf",
            "$windir\\Fonts\\tahoma.ttf",
            "$windir\\Fonts\\verdana.ttf",
            "$windir\\Fonts\\times.ttf",
        ];
    } else {
        $candidates = [
            '/usr/share/fonts/truetype/msttcorefonts/Arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
        ];
    }
    foreach ($candidates as $f) {
        if (file_exists($f)) return $f;
    }
    // Final fallback: try any .ttf in system font dir
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $search = getenv('WINDIR') ?: 'C:\\Windows';
        $ttfs = glob("$search\\Fonts\\*.ttf");
        if (!empty($ttfs)) return $ttfs[0];
    }
    return '';
}

function resolve_image(ZipArchive $zip, string $relsXml, string $embedId, int $slideNum): ?string {
    if (empty($relsXml)) {
        // Try direct path
        $path = "ppt/media/image{$embedId}.png";
        if ($zip->locateName($path) !== false) return $zip->getFromName($path);
        $path = "ppt/media/image{$embedId}.jpeg";
        if ($zip->locateName($path) !== false) return $zip->getFromName($path);
        $path = "ppt/media/image{$embedId}.jpg";
        if ($zip->locateName($path) !== false) return $zip->getFromName($path);
        return null;
    }

    $relsDom = new DOMDocument();
    @$relsDom->loadXML($relsXml);
    $xpath = new DOMXPath($relsDom);
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $nodes = $xpath->query("//r:Relationship[@Id='$embedId']");
    if (!$nodes || $nodes->length === 0) return null;
    $target = $nodes->item(0)->getAttribute('Target');
    $fullPath = "ppt/slides/$target";
    if ($zip->locateName($fullPath) === false) {
        $fullPath = "ppt/$target";
    }
    if ($zip->locateName($fullPath) === false) return null;
    return $zip->getFromName($fullPath);
}
