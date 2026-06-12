<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']            = 'UMaT AI Learning Assistant';

/* ---- Capabilities ---- */
$string['umat_ai:viewsummary']   = 'View AI-generated summaries and notes';
$string['umat_ai:approveoutput'] = 'Approve AI-generated content';
$string['umat_ai:chatwithai']    = 'Chat with the AI assistant';
$string['umat_ai:viewanalytics'] = 'View lecturer analytics dashboard';

/* ---- General ---- */
$string['ai_assistant']          = 'UMaT AI Assistant';
$string['ai_hub_title']          = 'AI Learning Hub';
$string['send']                  = 'Send';
$string['na']                    = 'N/A';
$string['error_message']         = 'An error occurred. Please try again.';
$string['connection_error']      = 'Cannot connect to the AI service. Please try again later.';
$string['ai_unavailable']        = 'The AI service is currently unavailable. Please try again shortly.';
$string['rate_limit_hit']        = 'You\'ve reached the question limit for this minute. Please wait a moment and try again.';

/* ---- Settings ---- */
$string['settings_heading']          = 'UMaT AI Configuration';
$string['ai_service_url']            = 'AI Service Base URL';
$string['ai_service_url_desc']       = 'Base URL of the FastAPI AI backend (e.g. http://localhost:8000). No trailing slash.';
$string['ai_service_token']          = 'API Bearer Token';
$string['ai_service_token_desc']     = 'Bearer token sent in the Authorization header to authenticate AI service requests.';
$string['rate_limit']                = 'Rate Limit (questions per minute)';
$string['rate_limit_desc']           = 'Max AI questions a student can ask per minute. Default: 10.';
$string['enable_student_fab']        = 'Enable Student AI FAB';
$string['enable_student_fab_desc']   = 'Show the floating AI assistant button + panel on all student course pages.';
$string['enable_lecturer_fab']       = 'Enable Lecturer Analytics FAB';
$string['enable_lecturer_fab_desc']  = 'Show the floating analytics button + panel on all lecturer course pages.';
$string['enable_hub_fab']            = 'Enable Hub FAB (non-course pages)';
$string['enable_hub_fab_desc']       = 'Show a compact hub FAB on non-course pages for enrolled students.';

/* ---- Approval page ---- */
$string['approval_title']  = 'Review AI-Generated Content';
$string['str_approve']     = 'Approve & Publish';
$string['str_reject']      = 'Reject';
$string['str_summary']     = 'Summary';
$string['str_notes']       = 'Notes';
$string['str_quiz']        = 'Practice Quiz';
$string['str_no_pending']  = 'There are no AI-generated outputs awaiting review for this course.';
$string['approved_message']= 'Content approved and published to students.';
$string['rejected_message']= 'Content rejected and removed from the queue.';

/* ---- Hub page ---- */
$string['hub_greeting']      = 'Welcome! How can I assist with your engineering studies or UMaT campus inquiries today?';
$string['general_chat']      = 'General Assistant';
$string['all_courses']       = 'All Courses';
$string['recent_sessions']   = 'Recent Session Logs';
$string['new_session']       = 'New AI Session';
$string['sessions_this_week']= 'Sessions this week';
$string['questions_asked']   = 'Questions asked';
$string['learning_pulse']    = 'Learning Pulse';
$string['study_goal']        = 'Study Goal Progress';

/* ---- Workspace ---- */
$string['workspace_greeting']= 'Welcome to the AI Workspace! Ask me anything about the lecture content, or click a suggestion above.';
$string['transcript']        = 'Synchronized Transcript';
$string['search_transcript'] = 'Search transcript…';
$string['generate_summary']  = 'Generate Notes';
$string['attach_material']   = 'Reference Course Material';
$string['video_error']       = 'Your browser does not support this video format.';
$string['no_notes_yet']      = 'AI-generated notes will appear here once your lecturer approves AI content for this session.';
$string['no_resources']      = 'Indexed course materials will appear here once uploaded by your lecturer.';

/* ---- Analytics ---- */
$string['analytics_dashboard_title'] = 'Lecturer Analytics Dashboard';

/* ---- Notifications ---- */
$string['messageprovider:pendingapproval'] = 'AI-generated content pending approval';
$string['pendingapproval_subject'] = 'New AI content awaits your review: {$a}';
$string['pendingapproval_body']    = 'The AI assistant has generated new {$a->types} for a lecture in "{$a->course}". Please review and approve the content so students can see it: {$a->url}';
$string['pendingapproval_short']   = 'New AI outputs ({$a->types}) await approval in {$a->course}.';
