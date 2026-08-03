<?php
/**
 * Page for lecturers to upload course materials for AI indexing.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/umat_ai/lib.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course, true);
$context = context_course::instance($course->id);

// Check capability - only teachers can upload
require_capability('local/umat_ai:approveoutput', $context);

$PAGE->set_context($context);
$PAGE->set_title('Upload Course Materials');
$PAGE->set_heading($course->fullname);
$PAGE->set_url('/local/umat_ai/materials.php', ['courseid' => $courseid]);

// Handle file upload
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['material'])) {
    $file = $_FILES['material'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $filename = basename($file['name']);

        // Save to a temp location for staging
        $tempPath = make_temp_directory('ai_uploads') . '/' . uniqid() . '_' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            $error = "Failed to save uploaded file.";
        } else {
            global $DB, $CFG;
            $fs = get_file_storage();

            // Remove existing file with same name in the same area
            $existing = $fs->get_file($context->id, 'local_umat_ai', 'materials', 0, '/', $filename);
            if ($existing) {
                $existing->delete();
            }

            // Import into Moodle File API so it appears in get_course_materials()
            $filerecord = [
                'contextid' => $context->id,
                'component' => 'local_umat_ai',
                'filearea'  => 'materials',
                'itemid'    => 0,
                'filepath'  => '/',
                'filename'  => $filename,
            ];
            $stored = $fs->create_file_from_pathname($filerecord, $tempPath);

            // Record in umat_ai_materials with real Moodle file ID
            // Dedup by (courseid, filename) — update existing row instead of inserting duplicate
            $existing = $DB->get_record('umat_ai_materials', [
                'courseid' => $courseid,
                'filename' => $filename,
            ]);
            if ($existing) {
                $existing->fileid      = $stored->get_id();
                $existing->is_indexed  = 0;
                $existing->timecreated = time();
                $DB->update_record('umat_ai_materials', $existing);
                $record = $existing;
            } else {
                $record = new stdClass();
                $record->courseid    = $courseid;
                $record->fileid      = $stored->get_id();
                $record->filename    = $filename;
                $record->is_indexed  = 0;
                $record->timecreated = time();
                $record->id = $DB->insert_record('umat_ai_materials', $record);
            }

            // Send to AI service for indexing (CURLFile — same pattern as index_course_materials.php)
            $config = local_umat_ai_get_service_config();
            $client = new \curl(['ignoresecurity' => local_umat_ai_is_localhost($config['url'])]);
            $client->setHeader([
                'Authorization: Bearer ' . $config['token'],
                'X-Request-Id: ' . local_umat_ai_request_id(),
            ]);

            // Server-side extension validation before sending to AI service.
            $allowed = ['.pdf','.txt','.md','.markdown','.doc','.docx','.ppt','.pptx',
                        '.xlsx','.csv','.py','.js','.ts','.jsx','.tsx','.php','.rb',
                        '.go','.rs','.java','.kt','.swift','.c','.cpp','.h','.hpp',
                        '.cs','.sql','.sh','.bash','.ps1','.bat','.pl','.lua','.r',
                        '.html','.htm','.css','.scss','.less','.json','.xml',
                        '.yaml','.yml','.toml','.ini','.cfg',
                        '.mp3','.wav','.ogg','.flac','.m4a',
                        '.mp4','.webm','.mov','.avi','.mkv'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($ext !== '' && !in_array('.' . $ext, $allowed)) {
                $message = "File type .{$ext} is not supported for AI indexing. Supported: " . implode(', ', $allowed);
                unlink($tempPath);
            } else {
                $payload = [
                    'course_id'    => (string)$courseid,
                    'material_id'  => (string)$stored->get_id(),
                    'filename'     => $filename,
                    'file'         => new \CURLFile($tempPath, mime_content_type($tempPath), $filename),
                ];

                $response = $client->post($config['url'] . '/api/v1/materials/index', $payload);
                $result = json_decode($response, true);

                error_log("Material indexing response: " . $response);

                if (!empty($result['success'])) {
                    $record->is_indexed = 1;
                    $DB->update_record('umat_ai_materials', $record);
                    $message = "File uploaded and indexed successfully! " . ($result['message'] ?? '');
                } else {
                    $message = "File uploaded but indexing failed. Debug: " . substr($response, 0, 200);
                }

                unlink($tempPath);
            }
        }
    } else {
        $error = "Upload error: " . $file['error'];
    }
}

// Get existing materials
global $DB;
$materials = $DB->get_records('umat_ai_materials', ['courseid' => $courseid], 'timecreated DESC');

echo $OUTPUT->header();
?>

<h2>Upload Course Materials</h2>
<p>Upload PDFs, documents, slides, or text files to make them available for AI Q&A.</p>

<?php if ($message): ?>
<div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="mb-4">
    <div class="mb-3">
        <label for="material" class="form-label">Select File (PDF, DOCX, PPTX, TXT, CSV, XLSX, MP4, HTML, etc.)</label>
        <input type="file" name="material" id="material" class="form-control" accept=".pdf,.txt,.md,.doc,.docx,.ppt,.pptx,.xlsx,.csv,.mp3,.wav,.mp4,.webm,.mov,.avi,.mkv,.html,.htm,.css,.json,.xml,.yaml" required>
    </div>
    <button type="submit" class="btn btn-primary">Upload and Index</button>
</form>

<h3>Uploaded Materials</h3>
<?php if ($materials): ?>
<table class="table table-striped">
    <thead>
        <tr>
            <th>Filename</th>
            <th>Status</th>
            <th>Uploaded</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($materials as $mat): ?>
        <tr>
            <td><?php echo htmlspecialchars($mat->filename); ?></td>
            <td><?php echo $mat->is_indexed ? '<span class="badge bg-success">Indexed</span>' : '<span class="badge bg-warning">Pending</span>'; ?></td>
            <td><?php echo date('Y-m-d H:i', $mat->timecreated); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p class="text-muted">No materials uploaded yet.</p>
<?php endif; ?>

<?php
echo $OUTPUT->footer();