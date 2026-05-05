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
$string['openai_api_key']        = 'OpenAI API Key';
$string['openai_api_key_desc']   = 'Your OpenAI API key for LLM processing';
$string['llm_model']             = 'LLM Model';
$string['llm_model_desc']        = 'The OpenAI model to use for content generation';
$string['require_approval']      = 'Require Lecturer Approval';
$string['require_approval_desc'] = 'AI-generated content must be approved by the lecturer before students can view it';

// --- Chat UI ---
$string['chatpanel_title']  = 'Ask Your AI Academic Assistant';
$string['ai_greeting']      = 'Hello! I can answer questions about your course materials and lecture content. How can I help you today?';
$string['ask_placeholder']  = 'Ask a question about this course...';
$string['send']             = 'Ask';
$string['ai_disclaimer']    = 'Answers are based on your course materials only. Always verify with your lecturer.';
$string['materials_indexed']= 'Course materials loaded';
$string['no_materials']     = 'No materials indexed yet';
$string['no_access']        = 'You do not have permission to use the AI assistant in this course.';

// --- AI Output ---
$string['session_summary']   = 'Lecture Summary';
$string['session_notes']     = 'Study Notes';
$string['session_quiz']      = 'Practice Questions';
$string['pending_approval']  = 'Awaiting lecturer approval';
$string['approve_btn']       = 'Approve and Publish';
$string['what_you_missed']   = 'What You Missed';

// --- Scheduled tasks ---
$string['task_process_recording'] = 'Process BBB lecture recordings';
$string['task_sync_transcripts']  = 'Sync AI-generated transcripts and summaries';
$string['task_index_materials']   = 'Index new course materials for AI';

// --- Privacy ---
$string['privacy:metadata:umat_ai_chat_logs']             = 'Stores your AI chat interactions within course contexts';
$string['privacy:metadata:umat_ai_chat_logs:userid']      = 'Your Moodle user ID';
$string['privacy:metadata:umat_ai_chat_logs:question']    = 'Questions you ask the AI assistant';
$string['privacy:metadata:umat_ai_chat_logs:answer']      = 'Answers provided by the AI';
$string['privacy:metadata:umat_ai_chat_logs:timecreated'] = 'When you asked the question';
$string['privacy:metadata:openai_api']                    = 'Questions you ask are sent to OpenAI for processing. No personally identifiable information beyond the text is sent.';
