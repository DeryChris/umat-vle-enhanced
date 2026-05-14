<?php
// ============================================================
// All English language strings for the plugin
// ============================================================

defined('MOODLE_INTERNAL') || die();

$string['pluginname']      = 'UMaT AI Academic Support';
$string['pluginname_desc'] = 'Generative AI-enhanced academic support for UMaT VLE';

// --- Settings ---
$string['ai_service_url']        = 'AI Service URL';
$string['ai_service_url_desc']   = 'The URL of the Python FastAPI AI processing service';
$string['ai_service_token']      = 'AI Service Token';
$string['ai_service_token_desc'] = 'Bearer token for authenticating with the AI service';

$string['google_api_key']        = 'Gemini API Key';
$string['google_api_key_desc']   = 'Your Google Gemini API key (Google AI Studio) for LLM processing';

$string['llm_model']             = 'LLM Model';
$string['llm_model_desc']        = 'The Gemini model to use for content generation';

$string['require_approval']      = 'Require Lecturer Approval';
$string['require_approval_desc'] = 'AI-generated content must be approved by the lecturer before students can view it';

// --- Chat UI ---
$string['chatpanel_title']   = 'UMaT AI Assistant';
$string['chatpanel_tooltip'] = 'Ask UMaT AI Assistant';
$string['ai_greeting']       = 'Hello! I can answer questions about your course materials and lecture content. How can I help you today?';
$string['ai_online']         = 'Online & Ready';
$string['ask_placeholder']   = 'Type your academic question...';
$string['send']              = 'Send';
$string['close']             = 'Close';
$string['ai_disclaimer']     = 'Answers are based on your course materials only. Always verify with your lecturer.';
$string['materials_indexed'] = 'Course materials loaded';
$string['no_materials']      = 'No materials indexed yet';
$string['no_access']         = 'You do not have permission to use the AI assistant in this course.';

// --- Quick Actions ---
$string['action_summarize']  = 'Summarize';
$string['action_assignment'] = 'Assignment';
$string['action_explain']    = 'Explain Topic';
$string['action_deadlines']  = 'Deadlines';
$string['quick_summarize']   = 'Can you summarize the main points from this week\'s lecture?';
$string['quick_assignment'] = 'What are the requirements for the current assignment?';
$string['quick_explain']     = 'Can you explain the key concept from this week?';
$string['quick_deadlines']  = 'What are the upcoming deadlines in this course?';

// --- Status ---
$string['questions_remaining'] = 'questions remaining';
$string['error_ai']            = 'Sorry, something went wrong. Please try again.';

// --- Approval ---
$string['reject_btn']    = 'Reject';
$string['approval_nav'] = 'Review AI Outputs';

// --- AI Output ---
$string['session_summary']  = 'Lecture Summary';
$string['session_notes']    = 'Study Notes';
$string['session_quiz']     = 'Practice Questions';
$string['pending_approval'] = 'Awaiting lecturer approval';
$string['approve_btn']      = 'Approve and Publish';
$string['what_you_missed']  = 'What You Missed';

// --- Scheduled tasks ---
$string['task_process_recording'] = 'Process BBB lecture recordings';
$string['task_sync_transcripts']  = 'Sync AI-generated transcripts and summaries';
$string['task_index_materials']   = 'Index new course materials for AI';

// --- Materials ---
$string['upload_materials'] = 'Upload Materials';
$string['upload_material_desc'] = 'Upload course materials for AI indexing';
$string['material_uploaded'] = 'File uploaded successfully';
$string['material_indexed'] = 'Material indexed and ready for AI Q&A';

// --- Privacy ---
$string['privacy:metadata:umat_ai_chat_logs']             = 'Stores your AI chat interactions within course contexts';
$string['privacy:metadata:umat_ai_chat_logs:userid']      = 'Your Moodle user ID';
$string['privacy:metadata:umat_ai_chat_logs:question']    = 'Questions you ask the AI assistant';
$string['privacy:metadata:umat_ai_chat_logs:answer']      = 'Answers provided by the AI';
$string['privacy:metadata:umat_ai_chat_logs:timecreated'] = 'When you asked the question';

// --- Workspace ---
$string['ai_assistant']         = 'AI Assistant';
$string['transcript']           = 'Transcript';
$string['search_transcript']     = 'Search transcript...';
$string['video_error']          = 'Your browser does not support the video tag.';
$string['generate_summary']     = 'Generate Summary';
$string['generate_summary_prompt'] = 'Can you summarize the key points from this lecture segment?';
$string['attach_material']      = 'Attach Reference Material';
$string['tab_chat']             = 'Chat';
$string['tab_notes']            = 'Notes';
$string['tab_resources']        = 'Resources';
$string['workspace_greeting']   = 'I can help you understand this lecture. Ask me about specific concepts, or use "Generate Summary" to get a quick overview.';
$string['ai_thinking']          = 'AI is thinking...';
$string['suggest_explain']      = 'Explain this';
$string['suggest_elaborate']    = 'Tell me more';
$string['suggest_compare']      = 'Compare to...';
$string['suggest_explain_text'] = 'Can you explain this concept in more detail?';
$string['suggest_elaborate_text'] = 'Can you elaborate on this with more examples?';
$string['suggest_compare_text'] = 'How does this compare to what we covered earlier?';
$string['ask_about_video']      = 'Ask about this lecture...';
$string['generated_notes']       = 'Generated Notes';
$string['download']             = 'Download';
$string['no_notes_yet']         = 'No notes generated yet. Click "Generate Summary" to create study notes.';
$string['course_resources']     = 'Course Resources';
$string['no_resources']         = 'No resources available for this session.';
$string['material_picker_coming'] = 'Material picker coming soon!';

// External processor disclosure:
$string['privacy:metadata:google_gemini_api'] = 'Questions you ask may be sent to the Gemini API for processing.';

// --- AI Hub ---
$string['ai_hub_title']      = 'AI Learning Hub';
$string['ai_hub_subtitle']   = 'Your cross-course AI assistant and session history';
$string['learning_pulse']    = 'Learning Pulse';
$string['pulse_description'] = 'Your most focused topics this semester based on AI interactions.';
$string['study_goal']        = 'Study Goal Progress';
$string['general_chat']     = 'General Chat';
$string['all_courses']       = 'All Courses';
$string['hub_greeting']      = 'Hello! I\'m your AI learning assistant. Ask me anything about your courses or select a previous session to continue.';
$string['recent_sessions']  = 'Recent Sessions';
$string['new_session']       = 'New Session';
$string['ai_thinking']       = 'AI is thinking...';
$string['error_message']     = 'Sorry, something went wrong. Please try again.';
$string['connection_error'] = 'Error connecting to AI service. Make sure it\'s running.';
$string['expand']           = 'Expand to full page';
$string['open_in_full']     = 'Open in full';
$string['close_fullscreen'] = 'Close full screen';
$string['open_hub']        = 'Open AI Hub';
$string['sessions_this_week'] = 'Sessions this week';
$string['questions_asked']   = 'Questions asked';
$string['search_sessions']   = 'Search sessions...';