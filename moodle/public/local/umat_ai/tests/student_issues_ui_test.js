/**
 * Lightweight source contract checks for Student Issues and the AI chat regression boundary.
 * Run with: node public/local/umat_ai/tests/student_issues_ui_test.js (from the Moodle directory).
 */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const plugin = path.resolve(__dirname, '..');
const overlay = fs.readFileSync(path.join(plugin, 'classes', 'overlay_helper.php'), 'utf8');
const student = fs.readFileSync(path.join(plugin, 'amd', 'src', 'umat_student.js'), 'utf8');
const lecturer = fs.readFileSync(path.join(plugin, 'amd', 'src', 'umat_lecturer.js'), 'utf8');
const studentBuild = fs.readFileSync(path.join(plugin, 'amd', 'build', 'umat_student.min.js'), 'utf8');
const lecturerBuild = fs.readFileSync(path.join(plugin, 'amd', 'build', 'umat_lecturer.min.js'), 'utf8');
const issueCss = fs.readFileSync(path.join(plugin, 'styles', 'umat-overlay.css'), 'utf8');
const renderedMarkup = overlay.split('<script>(function(){')[0];

assert(renderedMarkup.includes("'label' => 'AI Chat'"), 'Student overlay exposes AI Chat');
assert(renderedMarkup.includes("'label' => 'Student Issues'"), 'Student overlay exposes Student Issues');
assert(student.includes('No issues reported yet. If you need help with this course'), 'Student empty state is present');
assert(lecturer.includes('No student issues have been reported for this course.'), 'Lecturer empty state is present');
assert(!renderedMarkup.includes('lec-issues-filter'), 'No issue status filter is rendered');
assert(!renderedMarkup.includes('Issue Priority'), 'No issue priority control is rendered');
assert(!renderedMarkup.includes('Mark as resolved'), 'No resolution workflow is rendered');
assert(!student.includes('local_umat_ai_update_issue_status'), 'Student source has no ticket status workflow');
assert(!lecturer.includes('local_umat_ai_update_issue_status'), 'Lecturer source has no ticket status workflow');

assert(student.includes('local_umat_ai_create_issue_conversation'), 'Student can create a conversation');
assert(student.includes('local_umat_ai_send_issue_message'), 'Student can send follow-up messages');
assert(student.includes('local_umat_ai_mark_issue_messages_viewed'), 'Student uses per-message read receipts');
assert(student.includes('data-issue-retry'), 'Student failed messages expose Retry');
assert(student.includes("e.key==='Enter'&&!e.shiftKey"), 'Student composer supports Enter and Shift+Enter');
assert(student.includes('_umatStreamChat({'), 'Existing AI chat streaming remains wired');
assert(student.includes("receipt==='sent'?'&#10003;':'&#10003;&#10003;'"), 'Student receipts distinguish one and two ticks');

assert(lecturer.includes('local_umat_ai_list_issue_conversations'), 'Lecturer inbox loads conversations');
assert(lecturer.includes('local_umat_ai_send_issue_message'), 'Lecturer can reply');
assert(lecturer.includes('local_umat_ai_mark_issue_messages_viewed'), 'Lecturer uses per-message read receipts');
assert(lecturer.includes('lec-issues-search'), 'Lecturer search is wired');
assert(lecturer.includes('lec-issues-category'), 'Lecturer category filter is wired');
assert(lecturer.includes('_umatStreamChat({'), 'Existing lecturer AI chat streaming remains wired');
assert(lecturer.includes("receipt==='sent'?'&#10003;':'&#10003;&#10003;'"), 'Lecturer receipts distinguish one and two ticks');
assert(issueCss.includes('.umat-issue-receipt.viewed{color:#159447;}'), 'Viewed double ticks have the green state');

assert(studentBuild.includes('local_umat_ai_create_issue_conversation'), 'Compiled student AMD contains conversation creation');
assert(studentBuild.includes('local_umat_ai_mark_issue_messages_viewed'), 'Compiled student AMD contains read receipts');
assert(lecturerBuild.includes('local_umat_ai_list_issue_conversations'), 'Compiled lecturer AMD contains the inbox');
assert(lecturerBuild.includes('local_umat_ai_mark_issue_messages_viewed'), 'Compiled lecturer AMD contains read receipts');
assert(!studentBuild.includes('local_umat_ai_update_issue_status'), 'Compiled student AMD excludes ticket status controls');
assert(!lecturerBuild.includes('local_umat_ai_update_issue_status'), 'Compiled lecturer AMD excludes ticket status controls');
assert(studentBuild.includes('_umatStreamChat'), 'Compiled student AI Chat remains available');
assert(lecturerBuild.includes('_umatStreamChat'), 'Compiled lecturer AI Chat remains available');

console.log('Student Issues UI contract checks passed.');
