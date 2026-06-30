<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']            = 'UMaT AI Learning Assistant';

/* ---- Capabilities ---- */
$string['umat_ai:viewsummary']   = 'View AI-generated summaries and notes';
$string['umat_ai:approveoutput'] = 'Approve AI-generated content';
$string['umat_ai:chatwithai']    = 'Chat with the AI assistant';
$string['umat_ai:viewanalytics'] = 'View lecturer analytics dashboard';
$string['umat_ai:creategroup']   = 'Create and join study groups';

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

/* ---- Student Notes ---- */
$string['my_notes']              = 'My Notes';
$string['new_note']              = 'New Note';
$string['edit_note']             = 'Edit Note';
$string['delete_note']           = 'Delete Note';
$string['delete_note_confirm']   = 'Are you sure you want to delete this note?';
$string['note_title']            = 'Title';
$string['note_content']          = 'Note content';
$string['note_saved']            = 'Note saved successfully';
$string['note_deleted']          = 'Note deleted';
$string['note_pinned']           = 'Pinned';
$string['note_unpin']            = 'Unpin';
$string['note_pin']              = 'Pin to top';
$string['no_notes_yet_student']  = 'No notes yet. Tap + to create your first note!';
$string['note_tags']             = 'Tags';
$string['tag_course']            = 'Course';
$string['tag_material']          = 'Material';
$string['tag_session']           = 'Chat Session';
$string['tag_custom']            = 'Custom Tag';
$string['note_save_and_tag']     = 'Save & Add Tags';
$string['note_open_in_chat']     = 'Open in Chat';
$string['note_resume_session']   = 'Resume Session';
$string['note_attach_to_chat']   = 'Attach to Chat';
$string['note_search']           = 'Search notes…';
$string['note_untitled']         = 'Untitled Note';

/* ---- Notifications ---- */
$string['messageprovider:pendingapproval'] = 'AI-generated content pending approval';
$string['pendingapproval_subject'] = 'New AI content awaits your review: {$a}';
$string['pendingapproval_body']    = 'The AI assistant has generated new {$a->types} for a lecture in "{$a->course}". Please review and approve the content so students can see it: {$a->url}';
$string['pendingapproval_short']   = 'New AI outputs ({$a->types}) await approval in {$a->course}.';

/* ---- Group Study ---- */
$string['group_study']               = 'Study Groups';
$string['group_study_title']         = 'AI Study Groups';
$string['group_create']              = 'Create Study Group';
$string['group_join']                = 'Join Group';
$string['group_leave']               = 'Leave Group';
$string['group_delete']              = 'Delete Group';
$string['group_name']                = 'Group Name';
$string['group_description']         = 'Description';
$string['group_max_members']         = 'Max Members';
$string['group_created']             = 'Study group created successfully';
$string['group_joined']              = 'You joined the study group';
$string['group_left']                = 'You left the study group';
$string['group_deleted']             = 'Study group deleted';
$string['group_empty']               = 'No study groups yet. Create one to collaborate!';
$string['group_empty_messages']      = 'No messages yet. Send a chat message or ask AI to start!';
$string['group_chat_mode']           = 'Chat';
$string['group_ai_mode']             = 'Ask AI & Share';
$string['group_chat_placeholder']    = 'Type a message...';
$string['group_ai_placeholder']      = 'Ask AI a question to share with the group...';
$string['group_send_chat']           = 'Send';
$string['group_send_ai']             = 'Ask AI & Share';
$string['group_full']                = 'This group is full';
$string['group_already_member']      = 'Already a member';
$string['group_not_member']          = 'You are not a member of this group';
$string['group_ask_ai']              = 'Ask the AI…';
$string['group_send']                = 'Send to Group';
$string['group_shared_question']     = 'Shared AI Question';
$string['group_members']             = 'Members';
$string['group_chat']                = 'Group AI Chat';
$string['group_back_to_list']        = 'Back to groups';
$string['group_member_count']        = '{$a} members';
$string['group_owner']               = 'Owner';
$string['group_invalid']             = 'Invalid study group';
$string['issue_report_title']      = 'Report Issue';
$string['issue_submit_btn']        = 'Submit Report';
$string['issue_category_label']    = 'Category';
$string['issue_topic_label']       = 'Topic (optional)';
$string['issue_topic_placeholder'] = 'Which topic is this about?';
$string['issue_desc_label']        = 'Describe the issue';
$string['issue_desc_placeholder']  = 'Explain what you don\'t understand or what the problem is…';
$string['issue_success']           = 'Your issue has been reported.';
$string['issue_empty']             = 'No issues reported yet.';
$string['issue_my_reports']        = 'My Reports';
$string['issue_new_report']        = 'New Report';
$string['issue_lecturer_title']    = 'Student Issues';
$string['issue_category_concept_confusion'] = 'Concept Confusion';
$string['issue_category_material_error']    = 'Material Error';
$string['issue_category_technical_issue']   = 'Technical Issue';
$string['issue_category_suggestion']        = 'Suggestion';
$string['issue_category_other']             = 'Other';
$string['issue_status_open']       = 'Open';
$string['issue_status_in_review']  = 'In Review';
$string['issue_status_resolved']   = 'Resolved';
$string['issue_status_closed']     = 'Closed';
$string['issue_no_issues']         = 'No student issues for this course.';
$string['issue_filter_all']        = 'All';
$string['issue_update_status']     = 'Update Status';
$string['issue_lecturer_notes']    = 'Lecturer Notes';

/* ---- Quiz Generator ---- */
$string['quizgen_auto_intro']              = 'This quiz was automatically generated by the UMaT AI assistant.';
$string['quizgen_ai_invalid']              = 'The AI service returned an invalid response. Please try again.';
$string['quizgen_import_preprocess_failed'] = 'Failed to preprocess the quiz XML for import.';
$string['quizgen_import_process_failed']   = 'Failed to import questions into the question bank.';
$string['quizgen_not_ready']               = 'Quiz generation is not yet complete.';
$string['quizgen_no_xml']                  = 'No XML content is available for import.';

/* ---- Tasks ---- */
$string['task_process_recording'] = 'Process BBB recordings through AI service';
$string['task_cleanup_old_logs']  = 'Clean up old chat logs';
$string['task_index_materials']   = 'Index course materials for RAG';
$string['task_aggregate_student_metrics']   = 'Aggregate student engagement and risk metrics hourly';
$string['task_compute_topic_friction']     = 'Compute per-topic friction scores from chat logs';
$string['task_compute_material_health']    = 'Compute material health metrics (complete, questions, correctness)';
$string['task_snapshot_metric_trends']     = 'Snapshot engagement and at-risk metric trends daily';

/* ---- Web Services ---- */
$string['notyourchatlog']      = 'This chat log does not belong to you.';

/* ---- Interventions ---- */
$string['intervention_subject'] = 'Message from your lecturer regarding {$a}';
$string['intervention_encouragement'] = 'Encouragement';
$string['intervention_meeting'] = 'Schedule 1:1';
$string['intervention_remedial_quiz'] = 'Assign Remedial Quiz';
$string['intervention_draft_placeholder'] = 'Draft your message…';
