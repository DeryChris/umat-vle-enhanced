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
        // Save to moodledata/ai_materials
        $uploadDir = $CFG->dataroot . '/ai_materials/' . $courseid;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = basename($file['name']);
        $filepath = $uploadDir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Record in database
            global $DB;

            $record = new stdClass();
            $record->courseid = $courseid;
            $record->fileid = 0;
            $record->filename = $filename;
            $record->is_indexed = 0;
            $record->timecreated = time();

            $record->id = $DB->insert_record('umat_ai_materials', $record);

            // Call AI service to index the file
            $config = local_umat_ai_get_service_config();

            // Build multipart request
            $boundary = '----WebKitFormBoundary' . uniqid();
            $body = '';

            // Add form fields
            $body .= "--$boundary\r\n";
            $body .= 'Content-Disposition: form-data; name="course_id"' . "\r\n\r\n";
            $body .= $courseid . "\r\n";

            $body .= "--$boundary\r\n";
            $body .= 'Content-Disposition: form-data; name="material_id"' . "\r\n\r\n";
            $body .= $record->id . "\r\n";

            $body .= "--$boundary\r\n";
            $body .= 'Content-Disposition: form-data; name="filename"' . "\r\n\r\n";
            $body .= $filename . "\r\n";

            // Add file
            $fileContent = file_get_contents($filepath);
            $mimeType = mime_content_type($filepath);
            $body .= "--$boundary\r\n";
            $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
            $body .= 'Content-Type: ' . $mimeType . "\r\n\r\n";
            $body .= $fileContent . "\r\n";

            $body .= "--$boundary--\r\n";

            $client = new \curl(['ignoresecurity' => true]);
            $client->setHeader([
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Authorization: Bearer ' . $config['token'],
            ]);

            $response = $client->post($config['url'] . '/api/v1/materials/index', $body);
            $result = json_decode($response, true);

            // Debug: log the response and show details
            error_log("Material indexing response: " . $response);
            $debugInfo = "Response: " . substr($response, 0, 500);

            if (!empty($result['success'])) {
                // Update indexed status
                $record->is_indexed = 1;
                $DB->update_record('umat_ai_materials', $record);
                $message = "File uploaded and indexed successfully! " . ($result['message'] ?? '');
            } else {
                $message = "File uploaded but indexing failed. Debug: " . substr($response, 0, 200);
            }
        } else {
            $error = "Failed to move uploaded file.";
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
<p>Upload PDFs, documents, or text files to make them available for AI Q&A.</p>

<?php if ($message): ?>
<div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="mb-4">
    <div class="mb-3">
        <label for="material" class="form-label">Select File (PDF, DOCX, TXT)</label>
        <input type="file" name="material" id="material" class="form-control" accept=".pdf,.docx,.doc,.txt" required>
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