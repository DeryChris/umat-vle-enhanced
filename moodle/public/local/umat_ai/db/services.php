<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_umat_ai_ask_question' => [
        'classname'   => '\local_umat_ai\external\ai_query', 'methodname' => 'ask_question',
        'description' => 'Student AI question', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_chat_history' => [
        'classname'   => '\local_umat_ai\external\ai_query', 'methodname' => 'get_chat_history',
        'description' => 'Get chat history for a session', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_session_transcript' => [
        'classname'   => '\local_umat_ai\external\ai_query', 'methodname' => 'get_session_transcript',
        'description' => 'Get recording URL + transcript', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_session_outputs' => [
        'classname'   => '\local_umat_ai\external\get_summary', 'methodname' => 'get_session_outputs',
        'description' => 'Get approved AI outputs', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewsummary',
    ],
    'local_umat_ai_approve_output' => [
        'classname'   => '\local_umat_ai\external\approve_output', 'methodname' => 'approve',
        'description' => 'Approve or reject AI content', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:approveoutput',
    ],
    'local_umat_ai_get_analytics' => [
        'classname'   => '\local_umat_ai\external\get_analytics', 'methodname' => 'get_course_analytics',
        'description' => 'Get course analytics (lecturer)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_lecturer_ask' => [
        'classname'   => '\local_umat_ai\external\lecturer_ai_query', 'methodname' => 'ask',
        'description' => 'Lecturer AI analytics query', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_my_courses' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_my_courses',
        'description' => 'Get enrolled/teaching courses', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => '',
    ],
    'local_umat_ai_get_course_materials' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_course_materials',
        'description' => 'Get course files for library', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => '',
    ],
    'local_umat_ai_reindex_material' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'reindex_material',
        'description' => 'Retry indexing a failed material', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:approveoutput',
    ],
    'local_umat_ai_get_course_recordings' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_course_recordings',
        'description' => 'Get BBB recordings with AI metadata', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_ai_sessions' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_ai_sessions',
        'description' => 'Get AI chat sessions', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_delete_session' => [
        'classname'   => '\local_umat_ai\external\delete_session', 'methodname' => 'delete_session',
        'description' => 'Delete a single chat session by session_key', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_lecturer_sessions' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_lecturer_sessions',
        'description' => 'Get lecturer AI chat sessions', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_lecturer_session_detail' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_lecturer_session_detail',
        'description' => 'Get messages in a lecturer AI session', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_delete_lecturer_session' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'delete_lecturer_session',
        'description' => 'Delete a lecturer AI session', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_pending_outputs' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_pending_outputs',
        'description' => 'Get unapproved AI outputs for lecturer review', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:approveoutput',
    ],
    'local_umat_ai_get_analysis_status' => [
        'classname'   => '\local_umat_ai\external\analysis', 'methodname' => 'get_analysis_status',
        'description' => 'Get analysis status for course materials', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_struggle_insights' => [
        'classname'   => '\local_umat_ai\external\get_struggle_insights', 'methodname' => 'get_struggle_insights',
        'description' => 'Get detailed struggle insights for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_request_analysis' => [
        'classname'   => '\local_umat_ai\external\analysis', 'methodname' => 'request_analysis',
        'description' => 'Trigger material analysis on AI service', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],

    'local_umat_ai_service_status' => [
        'classname'   => '\local_umat_ai\external\service_status', 'methodname' => 'ping',
        'description' => 'Check AI service availability for the connection indicator', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => '',
    ],

    /* ---- Student Notes ---- */
    'local_umat_ai_get_notes' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'get_notes',
        'description' => 'Get all notes for the current user', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_save_note' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'save_note',
        'description' => 'Create or update a note with tags', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_delete_note' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'delete_note',
        'description' => 'Delete a note and its tags', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_note_tag_sources' => [
        'classname'   => '\local_umat_ai\external\notes', 'methodname' => 'get_note_tag_sources',
        'description' => 'Get available tag sources (sessions, materials) for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],

    /* ---- Issue Reports ---- */
    'local_umat_ai_submit_issue' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'submit_issue',
        'description' => 'Compatibility endpoint for creating a private issue conversation', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:reportissue',
    ],
    'local_umat_ai_get_student_issues' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_student_issues',
        'description' => 'Student views their own issue reports', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_course_issues' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_course_issues',
        'description' => 'Lecturer views all issues for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:manageissues',
    ],
    'local_umat_ai_create_issue_conversation' => [
        'classname' => '\local_umat_ai\external\issue_conversation', 'methodname' => 'create_conversation',
        'description' => 'Create a private course issue conversation', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:reportissue',
    ],
    'local_umat_ai_list_issue_conversations' => [
        'classname' => '\local_umat_ai\external\issue_conversation', 'methodname' => 'list_conversations',
        'description' => 'List authorized private course issue conversations', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true,
    ],
    'local_umat_ai_get_issue_messages' => [
        'classname' => '\local_umat_ai\external\issue_conversation', 'methodname' => 'get_messages',
        'description' => 'Open one authorized private issue conversation', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true,
    ],
    'local_umat_ai_send_issue_message' => [
        'classname' => '\local_umat_ai\external\issue_conversation', 'methodname' => 'send_message',
        'description' => 'Send an idempotent private issue message', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true,
    ],
    'local_umat_ai_mark_issue_messages_viewed' => [
        'classname' => '\local_umat_ai\external\issue_conversation', 'methodname' => 'mark_viewed',
        'description' => 'Mark only displayed recipient messages as viewed', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true,
    ],
    'local_umat_ai_get_issue_unread_count' => [
        'classname' => '\local_umat_ai\external\issue_conversation', 'methodname' => 'get_unread_count',
        'description' => 'Count unread private issue messages', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true,
    ],


    /* ---- Quiz Generator ---- */
    'local_umat_ai_generate_quiz_draft' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'generate_quiz_draft',
        'description' => 'Lecturer creates an AI quiz generation job and queues it', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_quiz_job_status' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'get_quiz_job_status',
        'description' => 'Poll the status of a quiz generation job', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_finalize_quiz' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'finalize_quiz',
        'description' => 'Import generated questions into question bank + create quiz', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_quiz_job_history' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'get_quiz_job_history',
        'description' => 'List all quiz generation jobs for a course (history tracking)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_export_quiz_word' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'export_quiz_word',
        'description' => 'Generate a .docx assessment document from questions', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_save_quizgen_questions' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'save_quizgen_questions',
        'description' => 'Save edited questions back to a quizgen job and rebuild XML', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_regenerate_quizgen_question' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'regenerate_quizgen_question',
        'description' => 'Regenerate a single question via AI', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_course_quiz_config_data' => [
        'classname'   => '\local_umat_ai\external\quizgen', 'methodname' => 'get_course_quiz_config_data',
        'description' => 'Fetch course sections, grade categories, groups, groupings for quiz config', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ---- Lecturer Insights Dashboard ---- */
    'local_umat_ai_get_dashboard_summary' => [
        'classname'   => '\local_umat_ai\external\get_dashboard_summary', 'methodname' => 'get_dashboard_summary',
        'description' => 'Lecturer dashboard summary (engagement, at-risk count)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_query_student_insights' => [
        'classname'   => '\local_umat_ai\external\query_student_insights', 'methodname' => 'query_student_insights',
        'description' => 'NLQ-powered student insight query with risk filter', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_student_profile' => [
        'classname'   => '\local_umat_ai\external\get_student_profile', 'methodname' => 'get_student_profile',
        'description' => 'Deep-dive profile for a single student', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_execute_intervention' => [
        'classname'   => '\local_umat_ai\external\execute_intervention', 'methodname' => 'execute_intervention',
        'description' => 'Send intervention message to a student', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ---- Struggle Dashboard (Phase 2) ---- */
    'local_umat_ai_get_struggle_dashboard_data' => [
        'classname'   => '\local_umat_ai\external\get_struggle_dashboard_data', 'methodname' => 'get_struggle_dashboard_data',
        'description' => 'Single aggregated payload for the Struggle Areas Dashboard', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_report_material_progress' => [
        'classname'   => '\local_umat_ai\external\report_material_progress', 'methodname' => 'report_material_progress',
        'description' => 'Report material viewing progress via JS beacon', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_rate_answer' => [
        'classname'   => '\local_umat_ai\external\rate_answer', 'methodname' => 'rate_answer',
        'description' => 'Rate the helpfulness of an AI answer', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],

    /* ---- Video Generation ---- */
    'local_umat_ai_request_video_generation' => [
        'classname'   => '\local_umat_ai\external\video', 'methodname' => 'request_video_generation',
        'description' => 'Trigger AI video generation for a course material', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_video_status' => [
        'classname'   => '\local_umat_ai\external\video', 'methodname' => 'get_video_status',
        'description' => 'Get video generation status for course materials', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],

    /* ---- Student Quiz Persistence ---- */
    'local_umat_ai_save_quiz_attempt' => [
        'classname'   => '\local_umat_ai\external\quiz_attempt', 'methodname' => 'save_quiz_attempt',
        'description' => 'Save or update a student quiz attempt', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_quiz_attempts' => [
        'classname'   => '\local_umat_ai\external\quiz_attempt', 'methodname' => 'get_quiz_attempts',
        'description' => 'Get quiz attempts for the current user', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_delete_quiz_attempt' => [
        'classname'   => '\local_umat_ai\external\quiz_attempt', 'methodname' => 'delete_quiz_attempt',
        'description' => 'Delete a quiz attempt', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_grade_theory_answer' => [
        'classname'   => '\local_umat_ai\external\grade_theory', 'methodname' => 'grade_theory_answer',
        'description' => 'Grade a theoretical answer via AI service', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],

    /* ---- Instructor Quiz Review ---- */
    'local_umat_ai_get_course_quiz_attempts' => [
        'classname'   => '\local_umat_ai\external\quiz_attempt', 'methodname' => 'get_course_quiz_attempts',
        'description' => 'Get all quiz attempts for a course (lecturer review)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_quiz_analytics' => [
        'classname'   => '\local_umat_ai\external\quiz_attempt', 'methodname' => 'get_quiz_analytics',
        'description' => 'Get aggregate quiz analytics for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ---- Admin Control Panel ---- */
    'local_umat_ai_admin_get_config' => [
        'classname'   => '\local_umat_ai\external\admin_panel', 'methodname' => 'get_config',
        'description' => 'Get plugin configuration (masks secrets)', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_admin_save_config' => [
        'classname'   => '\local_umat_ai\external\admin_panel', 'methodname' => 'save_config',
        'description' => 'Save plugin configuration', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_admin_execute_action' => [
        'classname'   => '\local_umat_ai\external\admin_panel', 'methodname' => 'execute_action',
        'description' => 'Execute admin actions (clear cache, trigger cron)', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_admin_system_health' => [
        'classname'   => '\local_umat_ai\external\admin_panel', 'methodname' => 'system_health',
        'description' => 'Get AI service and system health', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],

    /* ---- Login Issue Report (no auth required) ---- */
    'local_umat_ai_login_lookup_courses' => [
        'classname'   => '\local_umat_ai\external\login_issue', 'methodname' => 'lookup_courses',
        'description' => 'Look up a student\'s courses by username/ID (login page, no auth)',
        'type' => 'read', 'ajax' => true,
        'loginrequired' => false, 'capabilities' => '',
    ],
    'local_umat_ai_login_submit_issue' => [
        'classname'   => '\local_umat_ai\external\login_issue', 'methodname' => 'submit_issue',
        'description' => 'Submit a login issue report (login page, no auth)',
        'type' => 'write', 'ajax' => true,
        'loginrequired' => false, 'capabilities' => '',
    ],

    /* ---- Lecture Transcription ---- */
    'local_umat_ai_upload_recording' => [
        'classname'   => '\local_umat_ai\external\transcription', 'methodname' => 'upload_recording',
        'description' => 'Initiate a transcription upload session', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_transcription' => [
        'classname'   => '\local_umat_ai\external\transcription', 'methodname' => 'get_transcription',
        'description' => 'Get transcription status and content', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_study_tools' => [
        'classname'   => '\local_umat_ai\external\transcription', 'methodname' => 'get_study_tools',
        'description' => 'Get AI-generated study tools from a transcript', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_list_transcriptions' => [
        'classname'   => '\local_umat_ai\external\transcription', 'methodname' => 'list_transcriptions',
        'description' => 'List transcription jobs for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_direct_upload' => [
        'classname'   => '\local_umat_ai\external\transcription', 'methodname' => 'direct_upload',
        'description' => 'Handle direct file upload for transcription', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ─── Resource Bank ─── */
    'local_umat_ai_resource_bank_list' => [
        'classname'   => '\local_umat_ai\external\resource_bank', 'methodname' => 'list_items',
        'description' => 'List items in a resource bank folder', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_resource_bank_create_folder' => [
        'classname'   => '\local_umat_ai\external\resource_bank', 'methodname' => 'create_folder',
        'description' => 'Create a folder in the resource bank', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_resource_bank_upload' => [
        'classname'   => '\local_umat_ai\external\resource_bank', 'methodname' => 'upload_file',
        'description' => 'Upload a file to the resource bank', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_resource_bank_delete' => [
        'classname'   => '\local_umat_ai\external\resource_bank', 'methodname' => 'delete_items',
        'description' => 'Delete items from the resource bank', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_resource_bank_push' => [
        'classname'   => '\local_umat_ai\external\resource_bank', 'methodname' => 'push_to_course',
        'description' => 'Push resource bank items to a course', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_resource_bank_teaching_courses' => [
        'classname'   => '\local_umat_ai\external\resource_bank', 'methodname' => 'list_teaching_courses',
        'description' => 'List courses the user can push resources to', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
    'local_umat_ai_resource_bank_rename' => [
        'classname'   => '\local_umat_ai\external\resource_bank', 'methodname' => 'rename_item',
        'description' => 'Rename a resource bank item', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:adminpanel',
    ],
];

$services = [
    'UMaT AI Service' => [
        'functions'       => array_keys($functions),
        'restrictedusers' => 0,
        'enabled'         => 1,
        'downloadfiles'   => 0,
        'uploadfiles'     => 0,
    ],
];
