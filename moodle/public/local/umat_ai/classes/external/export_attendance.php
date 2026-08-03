<?php

namespace local_umat_ai\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/pdflib.php');

use local_umat_ai\analytics\bbb_attendance_analyser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * External API: export course attendance data.
 *
 * Supports native CSV, XLSX (PhpSpreadsheet), and PDF (TCPDF) export.
 * Called by struggle_dashboard.js::exportAttendance().
 *
 * @package    local_umat_ai
 */
class export_attendance extends \external_api {

    public static function export_attendance_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'format'   => new \external_value(PARAM_ALPHA, 'Export format: csv, xlsx, pdf'),
        ]);
    }

    public static function export_attendance($courseid, $format) {
        global $DB;

        $params = self::validate_parameters(self::export_attendance_parameters(), [
            'courseid' => $courseid,
            'format'   => $format,
        ]);
        $cid    = (int) $params['courseid'];
        $format = (string) $params['format'];

        $context = \context_course::instance($cid);
        self::validate_context($context);
        require_capability('local/umat_ai:viewanalytics', $context);

        $course = $DB->get_record('course', ['id' => $cid], 'id, shortname, fullname');
        $slug   = $course ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $course->shortname) : 'course_' . $cid;
        $date   = date('Ymd');

        $sessionData = bbb_attendance_analyser::get_session_attendance($cid);

        switch ($format) {
            case 'csv':
                return self::export_csv($sessionData, $slug, $date);
            case 'xlsx':
                return self::export_xlsx($sessionData, $course, $slug, $date);
            case 'pdf':
                return self::export_pdf($sessionData, $course, $slug, $date);
            default:
                throw new \moodle_exception('Unsupported export format: ' . $format);
        }
    }

    public static function export_attendance_returns() {
        return new \external_single_structure([
            'success'   => new \external_value(PARAM_BOOL, 'Export succeeded'),
            'supported' => new \external_value(PARAM_BOOL, 'Format is natively supported'),
            'data'      => new \external_value(PARAM_RAW, 'CSV text or base64-encoded binary'),
            'filename'  => new \external_value(PARAM_TEXT, 'Suggested download filename'),
            'mimetype'  => new \external_value(PARAM_TEXT, 'MIME type of the exported data'),
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    //  CSV
    // ────────────────────────────────────────────────────────────────

    private static function export_csv(array $data, string $slug, string $date): array {
        $csv = self::build_csv($data);
        return [
            'success'   => true,
            'supported' => true,
            'data'      => $csv,
            'filename'  => "attendance_{$slug}_{$date}.csv",
            'mimetype'  => 'text/csv',
        ];
    }

    private static function build_csv(array $data): string {
        if (empty($data['sessions'])) {
            return "session_id,activity_name,start_date,student_name,email,attended,duration_min\n";
        }

        $csv = '';
        $header = ["session_id", "activity_name", "start_date", "student_name", "email", "attended", "duration_min"];
        $csv .= implode(',', $header) . "\n";

        foreach ($data['sessions'] as $sess) {
            $startDate = date('Y-m-d H:i', $sess['start_time']);
            $actName   = self::csv_escape($sess['activity_name']);

            foreach ($sess['present_students'] as $s) {
                $dur = $s['duration_min'] !== null ? $s['duration_min'] : '';
                $csv .= implode(',', [
                    $sess['session_id'],
                    $actName,
                    $startDate,
                    self::csv_escape($s['fullname']),
                    self::csv_escape($s['email']),
                    'Yes',
                    $dur,
                ]) . "\n";
            }

            foreach ($sess['absent_students'] as $s) {
                $csv .= implode(',', [
                    $sess['session_id'],
                    $actName,
                    $startDate,
                    self::csv_escape($s['fullname']),
                    self::csv_escape($s['email']),
                    'No',
                    '',
                ]) . "\n";
            }
        }

        return $csv;
    }

    private static function csv_escape(string $val): string {
        if (strpbrk($val, ",\"\n\r") !== false) {
            return '"' . str_replace('"', '""', $val) . '"';
        }
        return $val;
    }

    // ────────────────────────────────────────────────────────────────
    //  XLSX  (PhpSpreadsheet)
    // ────────────────────────────────────────────────────────────────

    private static function export_xlsx(array $data, ?\stdClass $course, string $slug, string $date): array {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendance');

        // Title row
        $courseName = $course ? $course->fullname : 'Course #' . $slug;
        $sheet->setCellValue('A1', $courseName);
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Session ID', 'Activity', 'Date', 'Student Name', 'Email', 'Attended', 'Duration (min)'];
        $headerRow = 3;
        foreach ($headers as $i => $h) {
            $cell = $sheet->getStyleByColumnAndRow($i + 1, $headerRow);
            $sheet->setCellValueByColumnAndRow($i + 1, $headerRow, $h);
            $cell->getFont()->setBold(true);
            $cell->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                 ->getStartColor()->setARGB('FFE0E0E0');
        }

        $row = 4;
        foreach ($data['sessions'] ?? [] as $sess) {
            $startDate = date('Y-m-d H:i', $sess['start_time']);

            foreach ($sess['present_students'] as $s) {
                $sheet->setCellValueByColumnAndRow(1, $row, $sess['session_id']);
                $sheet->setCellValueByColumnAndRow(2, $row, $sess['activity_name']);
                $sheet->setCellValueByColumnAndRow(3, $row, $startDate);
                $sheet->setCellValueByColumnAndRow(4, $row, $s['fullname']);
                $sheet->setCellValueByColumnAndRow(5, $row, $s['email']);
                $sheet->setCellValueByColumnAndRow(6, $row, 'Yes');
                $sheet->setCellByColumnAndRow(7, $row, $s['duration_min'] ?? '');
                $row++;
            }

            foreach ($sess['absent_students'] as $s) {
                $sheet->setCellValueByColumnAndRow(1, $row, $sess['session_id']);
                $sheet->setCellValueByColumnAndRow(2, $row, $sess['activity_name']);
                $sheet->setCellValueByColumnAndRow(3, $row, $startDate);
                $sheet->setCellValueByColumnAndRow(4, $row, $s['fullname']);
                $sheet->setCellValueByColumnAndRow(5, $row, $s['email']);
                $sheet->setCellValueByColumnAndRow(6, $row, 'No');
                $sheet->setCellByColumnAndRow(7, $row, '');
                $row++;
            }
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return [
            'success'   => true,
            'supported' => true,
            'data'      => base64_encode($content),
            'filename'  => "attendance_{$slug}_{$date}.xlsx",
            'mimetype'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    // ────────────────────────────────────────────────────────────────
    //  PDF  (TCPDF via Moodle wrapper)
    // ────────────────────────────────────────────────────────────────

    private static function export_pdf(array $data, ?\stdClass $course, string $slug, string $date): array {
        $courseName = $course ? $course->fullname : 'Course #' . $slug;
        $pdf = new \pdf();
        $pdf->SetTitle("Attendance Report - {$courseName}");
        $pdf->SetAuthor('UMaT VLE');
        $pdf->SetSubject('BBB Attendance Report');
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();

        $html = '<h2 style="margin-bottom:4px;">BBB Attendance Report</h2>';
        $html .= '<p style="font-size:11px;color:#666;">' . htmlspecialchars($courseName, ENT_QUOTES, 'UTF-8') . ' &mdash; Generated ' . date('Y-m-d H:i') . '</p>';

        if (empty($data['sessions'])) {
            $html .= '<p>No BBB attendance data for this course.</p>';
        } else {
            $html .= '<table border="1" cellpadding="4" cellspacing="0" style="font-size:9px;width:100%;border-collapse:collapse;">';
            $html .= '<tr style="background:#f0f0f0;font-weight:bold;">';
            $html .= '<th>Session</th><th>Activity</th><th>Date</th><th>Student</th><th>Email</th><th>Att.</th><th>Min</th>';
            $html .= '</tr>';

            foreach ($data['sessions'] as $sess) {
                $startDate = date('Y-m-d H:i', $sess['start_time']);
                $actEsc = htmlspecialchars($sess['activity_name'], ENT_QUOTES, 'UTF-8');

                foreach ($sess['present_students'] as $s) {
                    $dur = $s['duration_min'] !== null ? $s['duration_min'] : '';
                    $html .= '<tr>';
                    $html .= '<td>' . $sess['session_id'] . '</td>';
                    $html .= '<td>' . $actEsc . '</td>';
                    $html .= '<td>' . $startDate . '</td>';
                    $html .= '<td>' . htmlspecialchars($s['fullname'], ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td>' . htmlspecialchars($s['email'], ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="color:#22c55e;">Yes</td>';
                    $html .= '<td>' . $dur . '</td>';
                    $html .= '</tr>';
                }

                foreach ($sess['absent_students'] as $s) {
                    $html .= '<tr>';
                    $html .= '<td>' . $sess['session_id'] . '</td>';
                    $html .= '<td>' . $actEsc . '</td>';
                    $html .= '<td>' . $startDate . '</td>';
                    $html .= '<td>' . htmlspecialchars($s['fullname'], ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td>' . htmlspecialchars($s['email'], ENT_QUOTES, 'UTF-8') . '</td>';
                    $html .= '<td style="color:#dc2626;">No</td>';
                    $html .= '<td></td>';
                    $html .= '</tr>';
                }
            }

            $html .= '</table>';

            // Summary footer
            $totalSessions = $data['total_sessions'] ?? 0;
            $avgRate = $data['avg_attendance_rate'] ?? 0;
            $neverCount = $data['never_attended_count'] ?? 0;
            $html .= '<p style="font-size:10px;margin-top:12px;">';
            $html .= $totalSessions . ' sessions &middot; ' . round($avgRate * 100) . '% avg attendance &middot; ' . $neverCount . ' never attended';
            $html .= '</p>';
        }

        $pdf->writeHTML($html);
        $content = $pdf->Output('', 'S');

        return [
            'success'   => true,
            'supported' => true,
            'data'      => base64_encode($content),
            'filename'  => "attendance_{$slug}_{$date}.pdf",
            'mimetype'  => 'application/pdf',
        ];
    }
}
