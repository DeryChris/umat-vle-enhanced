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
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_course_recordings' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_course_recordings',
        'description' => 'Get BBB recordings with AI metadata', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_ai_sessions' => [
        'classname'   => '\local_umat_ai\external\course_data', 'methodname' => 'get_ai_sessions',
        'description' => 'Get AI chat sessions for a user', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => '',
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
    /* ---- Group Study ---- */
    'local_umat_ai_get_study_groups' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'get_study_groups',
        'description' => 'List study groups for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_create_study_group' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'create_study_group',
        'description' => 'Create a new study group', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:creategroup',
    ],
    'local_umat_ai_join_study_group' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'join_study_group',
        'description' => 'Join a study group', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_leave_study_group' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'leave_study_group',
        'description' => 'Leave a study group', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_group_members' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'get_group_members',
        'description' => 'Get members of a study group', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_group_messages' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'get_group_messages',
        'description' => 'Get shared messages in a study group', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_send_group_message' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'send_group_message',
        'description' => 'Post a shared AI Q&A to the group', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_delete_study_group' => [
        'classname'   => '\local_umat_ai\external\group_study', 'methodname' => 'delete_study_group',
        'description' => 'Delete a study group (owner only)', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:creategroup',
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
        'description' => 'Student submits an issue/complaint', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_student_issues' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_student_issues',
        'description' => 'Student views their own issue reports', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_course_issues' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_course_issues',
        'description' => 'Lecturer views all issues for a course', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_update_issue_status' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'update_issue_status',
        'description' => 'Lecturer updates issue status and notes', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_update_issue_response' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'update_issue_response',
        'description' => 'Lecturer posts a public response to a student issue', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],
    'local_umat_ai_get_unread_response_count' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_unread_response_count',
        'description' => 'Student checks how many lecturer responses are unread', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_mark_responses_read' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'mark_responses_read',
        'description' => 'Student marks all lecturer responses as read', 'type' => 'write', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:chatwithai',
    ],
    'local_umat_ai_get_unresponded_issues_count' => [
        'classname'   => '\local_umat_ai\external\issue_report', 'methodname' => 'get_unresponded_issues_count',
        'description' => 'Lecturer counts total issues for notification badge', 'type' => 'read', 'ajax' => true,
        'loginrequired' => true, 'capabilities' => 'local/umat_ai:viewanalytics',
    ],

    /* ---- Student Progress ---- */
    'local_umat_ai_get_my_progress' => [
        'classname'   => '\local_umat_ai\external\student_progress', 'methodname' => 'get_my_progress',
        'description' => 'Student views their personal progress/struggle dashboard', 'type' => 'read', 'ajax' => true,
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
