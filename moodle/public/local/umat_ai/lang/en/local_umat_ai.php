<?php
/**
 * Language strings for local_umat_ai.
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/* ---- Plugin metadata ---- */
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

/* ---- FAB tooltips ---- */
$string['open_ai_assistant']     = 'Open AI Assistant';
$string['open_analytics']        = 'Open Analytics Dashboard';
$string['open_hub']              = 'Open AI Learning Hub';

/* ---- Student FAB / panel ---- */
$string['ask_placeholder']       = 'Type your academic question…';
$string['quick_summarize']       = 'Summarize Lecture';
$string['quick_assignment']      = 'About Assignment';
$string['quick_explain']         = 'Explain Concept';
$string['quick_deadlines']       = 'Deadlines';
$string['rate_limit_hit']        = 'You\'ve reached the question limit for this minute. Please wait a moment and try again.';
$string['rate_remaining']        = '{$a} questions remaining this minute';
$string['online_ready']          = 'Online & Ready';
$string['expand']                = 'Expand';
$string['past_sessions']         = 'Past Sessions';
$string['tab_chat']              = 'Chat';
$string['tab_notes']             = 'Notes';
$string['tab_resources']         = 'Resources';
$string['no_notes_yet']          = 'AI-generated notes will appear here after the lecturer processes a lecture recording and approves the content.';
$string['no_resources']          = 'Course materials indexed for AI will appear here once they are uploaded by your lecturer.';

/* ---- Workspace ---- */
$string['workspace_greeting']    = 'Welcome to the AI Workspace! I can see your current lecture. Ask me anything about the content, or click a suggestion above.';
$string['ask_about_video']       = 'Ask about this lecture…';
$string['transcript']            = 'Lecture Transcript';
$string['search_transcript']     = 'Search transcript…';
$string['generate_summary']      = 'Generate Notes';
$string['attach_material']       = 'Attach Material';
$string['video_error']           = 'Your browser does not support the video element.';

/* ---- Lecturer panel ---- */
$string['lecturer_analytics']    = 'Lecturer Analytics';
$string['open_full_dashboard']   = 'Open Full Analytics Dashboard';
$string['review_ai_outputs']     = 'Review AI Outputs';
$string['common_questions']      = 'Common Student Questions';
$string['ai_insights']           = 'AI Insights';
$string['loading_analytics']     = 'Loading analytics data…';
$string['ask_ai_placeholder']    = 'Ask AI about your course…';
$string['struggle_areas']        = 'Struggle areas';
$string['weekly_summary']        = 'Weekly summary';
$string['at_risk_students']      = 'At-risk students';

/* ---- Analytics dashboard ---- */
$string['analytics_dashboard_title'] = 'Course Analytics Dashboard';
$string['active_students']       = 'Active Students';
$string['enrolled']              = 'enrolled';
$string['ai_interactions']       = 'AI Interactions';
$string['thirty_days']           = '30 days';
$string['struggle_index']        = 'Struggle Index';
$string['most_questioned']       = 'Most-questioned session';
$string['pending_approvals']     = 'Pending Approvals';
$string['needs_review']          = 'Needs review';
$string['all_clear']             = 'All clear';
$string['engagement_trends']     = 'Student Engagement Trends';
$string['fourteen_days']         = '14 days';
$string['performance_breakdown'] = 'Student Performance Breakdown';
$string['high_engagement']       = 'High Engagement';
$string['on_track']              = 'On Track';
$string['at_risk']               = 'At Risk (inactive)';
$string['export_report']         = 'Export Report';

/* ---- Hub ---- */
$string['general_chat']          = 'General AI Chat';
$string['all_courses']           = 'All Courses';
$string['recent_sessions']       = 'Recent Sessions';
$string['new_session']           = 'New Conversation';
$string['sessions_this_week']    = 'Sessions this week';
$string['questions_asked']       = 'Questions asked';
$string['learning_pulse']        = 'Learning Pulse';
$string['study_goal']            = 'Weekly study goal';
$string['hub_greeting']          = 'Hello! I\'m your AI learning companion. I can help with any subject from your enrolled courses. What would you like to explore today?';

/* ---- Approval page ---- */
$string['approval_title']        = 'Review AI-Generated Content';
$string['str_approve']           = 'Approve & Publish';
$string['str_reject']            = 'Reject';
$string['str_summary']           = 'Summary';
$string['str_notes']             = 'Notes';
$string['str_quiz']              = 'Practice Quiz';
$string['str_no_pending']        = 'There are no AI-generated outputs awaiting your review for this course. Check back after the next lecture session.';
$string['approved_message']      = 'Content approved and published to students.';
$string['rejected_message']      = 'Content rejected and removed from the queue.';

/* ---- Settings ---- */
$string['settings_heading']      = 'UMaT AI Configuration';
$string['ai_service_url']        = 'AI Service Base URL';
$string['ai_service_url_desc']   = 'The base URL for the AI backend service (e.g. http://localhost:8000). No trailing slash.';
$string['ai_service_token']      = 'API Token';
$string['ai_service_token_desc'] = 'Bearer token used to authenticate requests to the AI backend.';
$string['rate_limit']            = 'Rate Limit (questions per minute)';
$string['rate_limit_desc']       = 'Maximum number of AI questions a student can ask per minute. Default: 10.';
$string['enable_student_fab']    = 'Enable Student AI FAB';
$string['enable_student_fab_desc'] = 'Show the floating AI assistant button on all student course pages.';
$string['enable_lecturer_fab']   = 'Enable Lecturer Analytics FAB';
$string['enable_lecturer_fab_desc'] = 'Show the floating analytics button on all lecturer course pages.';
$string['enable_hub_fab']        = 'Enable Hub FAB (non-course pages)';
$string['enable_hub_fab_desc']   = 'Show a compact AI Hub button on non-course pages for enrolled students.';
