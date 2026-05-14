<?php
/**
 * Library functions for the UMaT AI Academic Support local plugin.
 *
 * This file contains Moodle callback functions and shared helpers.
 *
 * Plugin component: local_umat_ai
 *
 * @package    local_umat_ai
 * @copyright  2026 UMaT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add plugin entry into the course navigation (left navigation drawer),
 * when the user is inside a course context.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course record.
 * @param \context_course $context Course context.
 */
function local_umat_ai_extend_navigation_course(navigation_node $navigation, stdClass $course, \context_course $context): void {
    global $PAGE, $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (!$context instanceof \context_course) {
        return;
    }

    if (!is_enrolled($context, $USER, '', false)) {
        return;
    }

    $courseid = $course->id;
    $coursename = addslashes(format_string($course->fullname, true, ['context' => $context]));

    $PAGE->requires->js_amd_inline("
(function() {
    if (document.getElementById('umatFabBtn')) return;
    var courseId = $courseid;
    var courseName = '$coursename';

    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0';
    document.head.appendChild(link);

    var styleEl = document.createElement('style');
    styleEl.textContent = '@keyframes fabPulse{0%{box-shadow:0 0 0 0 rgba(0,107,47,0.5);}70%{box-shadow:0 0 0 12px rgba(0,107,47,0);}100%{box-shadow:0 0 0 0 rgba(0,107,47,0);}}@keyframes statusPulse{0%,100%{opacity:1;}50%{opacity:0.5;}}@keyframes typingBounce{0%,60%,100%{transform:translateY(0);}30%{transform:translateY(-5px);}}.fabTooltip{position:absolute;right:70px;background:#333;color:#fff;padding:8px 12px;border-radius:8px;font-size:12px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity 0.2s;}.fabTooltip::after{content:\"\";position:absolute;right:-6px;top:50%;transform:translateY(-50%);border:6px solid transparent;border-left-color:#333;}#umatFabBtn:hover .fabTooltip{opacity:1;}';
    document.head.appendChild(styleEl);

    var fab = document.createElement('button');
    fab.id = 'umatFabBtn';
    fab.innerHTML = '<span class=\"material-symbols-outlined\" style=\"font-size:28px\">smart_toy</span><span class=\"fabTooltip\">Ask UMaT AI Assistant</span>';
    fab.style.cssText = 'position:fixed;bottom:80px;right:24px;z-index:9999;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#006b2f,#00873d);color:white;border:none;box-shadow:0 6px 20px rgba(0,107,47,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:transform 0.2s,box-shadow 0.2s;animation:fabPulse 2.5s infinite;';
    fab.onmouseover = function() { this.style.transform = 'scale(1.1)'; this.style.boxShadow = '0 8px 25px rgba(0,107,47,0.5)'; };
    fab.onmouseout = function() { this.style.transform = 'scale(1)'; this.style.boxShadow = '0 6px 20px rgba(0,107,47,0.4)'; };
    fab.title = 'Ask UMaT AI Assistant';

    var ws = document.createElement('div');
    ws.id = 'umatWorkspace';
    ws.style.cssText = 'display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.3);backdrop-filter:blur(4px);';

    var panel = document.createElement('div');
    panel.style.cssText = 'position:fixed;bottom:24px;right:24px;width:400px;max-width:calc(100vw - 48px);max-height:75vh;background:#f8faf7;border-radius:20px;box-shadow:0 10px 40px rgba(0,0,0,0.2);display:flex;flex-direction:column;overflow:hidden;';

    panel.innerHTML =
        '<div style=\"background:linear-gradient(135deg,#006b2f,#00873d);padding:16px 20px;color:white;\">' +
        '<div style=\"display:flex;align-items:center;gap:10px;\">' +
        '<div style=\"width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;position:relative;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:22px;\">smart_toy</span>' +
        '<span style=\"position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;background:#4ade80;border:2px solid #00873d;animation:statusPulse 1.5s infinite;\"></span></div>' +
        '<div style=\"flex:1;\"><h3 style=\"margin:0;font-size:15px;font-weight:600;\">UMaT AI Assistant</h3>' +
        '<div style=\"display:flex;align-items:center;gap:4px;font-size:11px;opacity:0.9;\">' +
        '<span style=\"width:6px;height:6px;border-radius:50%;background:#4ade80;animation:statusPulse 1.5s infinite;\"></span>' +
        'Online & Ready</div></div>' +
        '<button id=\"umatExpandBtn\" title=\"Expand to full workspace\" style=\"background:rgba(255,255,255,0.2);border:none;color:white;width:36px;height:36px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;margin-right:8px;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:20px;\">open_in_full</span></button>' +
        '<button id=\"umatCloseBtn\" style=\"background:rgba(255,255,255,0.2);border:none;color:white;width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:16px;\">close</span></button></div>' +
        '<div style=\"font-size:10px;opacity:0.7;padding:0 20px 12px 70px;\">' + courseName + '</div></div>' +

        '<div style=\"display:flex;border-bottom:1px solid #dee5da;background:white;\">' +
        '<button class=\"umatTab active\" data-tab=\"chat\" style=\"flex:1;padding:10px 8px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:#006b2f;border-bottom:2px solid #006b2f;\">Chat</button>' +
        '<button class=\"umatTab\" data-tab=\"notes\" style=\"flex:1;padding:10px 8px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:500;color:#666;\">Notes</button>' +
        '<button class=\"umatTab\" data-tab=\"resources\" style=\"flex:1;padding:10px 8px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:500;color:#666;\">Resources</button></div>' +

        '<div id=\"umatChatContent\" style=\"flex:1;overflow-y:auto;padding:16px;background:#f8faf7;display:flex;flex-direction:column;gap:12px;\">' +

        '<div style=\"display:flex;gap:10px;align-items:flex-start;\">' +
        '<div style=\"min-width:32px;height:32px;border-radius:50%;background:rgba(0,107,47,0.15);display:flex;align-items:center;justify-content:center;color:#006b2f;flex-shrink:0;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:16px;\">smart_toy</span></div>' +
        '<div style=\"background:white;border-left:3px solid #006b2f;padding:12px;border-radius:0 12px 12px 12px;font-size:13px;line-height:1.5;max-width:88%;\">' +
        '<p style=\"margin:0;\">Hello! I\'m your AI course tutor for <strong>' + courseName + '</strong>. How can I help you today?</p></div></div>' +

        '<div style=\"display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:8px 0;\">' +
        '<button class=\"quickAction\" data-action=\"summarize\" style=\"display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:22px;color:#006b2f;margin-bottom:4px;\">summarize</span>' +
        '<span style=\"font-size:11px;color:#333;text-align:center;line-height:1.2;\">Summarize recent lecture</span></button>' +
        '<button class=\"quickAction\" data-action=\"assignment\" style=\"display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:22px;color:#006b2f;margin-bottom:4px;\">quiz</span>' +
        '<span style=\"font-size:11px;color:#333;text-align:center;line-height:1.2;\">Ask about Assignment</span></button>' +
        '<button class=\"quickAction\" data-action=\"explain\" style=\"display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:22px;color:#006b2f;margin-bottom:4px;\">search_spark</span>' +
        '<span style=\"font-size:11px;color:#333;text-align:center;line-height:1.2;\">Explain Topic</span></button>' +
        '<button class=\"quickAction\" data-action=\"deadlines\" style=\"display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:22px;color:#006b2f;margin-bottom:4px;\">schedule</span>' +
        '<span style=\"font-size:11px;color:#333;text-align:center;line-height:1.2;\">Upcoming Deadlines</span></button></div>' +

        '<div id=\"umatMessages\" style=\"display:flex;flex-direction:column;gap:10px;\"></div></div>' +

        '<div id=\"umatNotesContent\" style=\"display:none;flex:1;overflow-y:auto;padding:24px;text-align:center;color:#666;font-size:14px;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:48px;color:#dee5da;\">description</span>' +
        '<p style=\"margin-top:12px;\">Your generated notes will appear here after watching lectures.</p></div>' +

        '<div id=\"umatResourcesContent\" style=\"display:none;flex:1;overflow-y:auto;padding:24px;text-align:center;color:#666;font-size:14px;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:48px;color:#dee5da;\">folder_open</span>' +
        '<p style=\"margin-top:12px;\">Course resources will appear here.</p></div>' +

        '<div id=\"umatChatInput\" style=\"padding:12px 16px;background:white;border-top:1px solid #dee5da;\">' +
        '<div style=\"display:flex;gap:8px;align-items:flex-end;\">' +
        '<textarea id=\"umatInput\" placeholder=\"Type your academic question...\" rows=\"2\" style=\"flex:1;padding:12px;border:1px solid #dee5da;border-radius:12px;font-size:13px;resize:none;outline:none;line-height:1.4;\"></textarea>' +
        '<button id=\"umatSendBtn\" style=\"width:44px;height:44px;border-radius:12px;background:#006b2f;color:white;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:20px;\">send</span></button></div>' +
        '<div style=\"margin-top:8px;display:flex;justify-content:space-between;align-items:center;\">' +
        '<span style=\"font-size:10px;color:#999;\">UMaT AI Model v2.4</span>' +
        '<button style=\"background:none;border:none;color:#006b2f;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:4px;\">' +
        '<span class=\"material-symbols-outlined\" style=\"font-size:14px;\">history</span>Past Logs</button></div></div>';

    ws.appendChild(panel);
    document.body.appendChild(fab);
    document.body.appendChild(ws);

    var closeBtn = document.getElementById('umatCloseBtn');
    var expandBtn = document.getElementById('umatExpandBtn');
    var input = document.getElementById('umatInput');
    var sendBtn = document.getElementById('umatSendBtn');
    var messages = document.getElementById('umatMessages');
    var tabs = document.querySelectorAll('.umatTab');
    var quickActions = document.querySelectorAll('.quickAction');

    expandBtn.addEventListener('click', function() {
        window.location.href = '/local/umat_ai/index.php?courseid=' + courseId;
    });

    quickActions.forEach(function(btn) {
        btn.onmouseover = function() { this.style.borderColor = '#006b2f'; this.style.background = 'rgba(129, 251, 156, 0.1)'; };
        btn.onmouseout = function() { this.style.borderColor = '#dee5da'; this.style.background = 'white'; };
    });

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var tabName = this.dataset.tab;
            tabs.forEach(function(t) {
                t.classList.remove('active');
                t.style.color = '#666';
                t.style.fontWeight = '500';
                t.style.borderBottom = 'none';
            });
            this.classList.add('active');
            this.style.color = '#006b2f';
            this.style.fontWeight = '600';
            this.style.borderBottom = '2px solid #006b2f';

            var chatContent = document.getElementById('umatChatContent');
            var chatInput = document.getElementById('umatChatInput');
            if (tabName === 'chat') {
                chatContent.style.display = 'flex';
                chatInput.style.display = 'block';
                document.getElementById('umatNotesContent').style.display = 'none';
                document.getElementById('umatResourcesContent').style.display = 'none';
            } else if (tabName === 'notes') {
                chatContent.style.display = 'none';
                chatInput.style.display = 'none';
                document.getElementById('umatNotesContent').style.display = 'block';
                document.getElementById('umatResourcesContent').style.display = 'none';
            } else {
                chatContent.style.display = 'none';
                chatInput.style.display = 'none';
                document.getElementById('umatNotesContent').style.display = 'none';
                document.getElementById('umatResourcesContent').style.display = 'block';
            }
        });
    });

    fab.addEventListener('click', function() {
        ws.style.display = 'block';
        setTimeout(function(){input.focus();}, 100);
    });
    closeBtn.addEventListener('click', function() {
        ws.style.display = 'none';
    });
    ws.addEventListener('click', function(e) {
        if(e.target === ws) {
            ws.style.display = 'none';
        }
    });

    function addAiMessage(text, suggestions) {
        var msgDiv = document.createElement('div');
        msgDiv.style.cssText = 'display:flex;gap:10px;align-items:flex-start;';
        msgDiv.innerHTML = '<div style=\"min-width:32px;height:32px;border-radius:50%;background:rgba(0,107,47,0.15);display:flex;align-items:center;justify-content:center;color:#006b2f;flex-shrink:0;\">' +
            '<span class=\"material-symbols-outlined\" style=\"font-size:16px;\">smart_toy</span></div>' +
            '<div style=\"background:white;border-left:3px solid #006b2f;padding:12px;border-radius:0 12px 12px 12px;font-size:13px;line-height:1.5;max-width:88%;\">' +
            '<p style=\"margin:0;\">' + text + '</p></div>';
        if (suggestions && suggestions.length > 0) {
            var chipsHtml = '<div style=\"display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;padding-left:42px;\">';
            suggestions.forEach(function(s) {
                chipsHtml += '<button class=\"suggestionChip\" style=\"padding:6px 12px;background:white;border:1px solid #dee5da;border-radius:16px;font-size:11px;color:#006b2f;cursor:pointer;\">' + s + '</button>';
            });
            chipsHtml += '</div>';
            msgDiv.innerHTML += chipsHtml;
        }
        messages.appendChild(msgDiv);
        messages.scrollTop = messages.scrollHeight;
        setTimeout(function() {
            document.querySelectorAll('.suggestionChip').forEach(function(chip) {
                chip.onclick = function() { input.value = this.textContent; sendQuestion(); };
            });
        }, 0);
    }

    function addUserMessage(text) {
        var msgDiv = document.createElement('div');
        msgDiv.style.cssText = 'display:flex;justify-content:flex-end;';
        msgDiv.innerHTML = '<div style=\"background:#EBF0FF;padding:10px 14px;border-radius:12px 0 12px 12px;font-size:13px;line-height:1.5;max-width:88%;\">' +
            '<p style=\"margin:0;color:#333;\">' + text + '</p></div>';
        messages.appendChild(msgDiv);
        messages.scrollTop = messages.scrollHeight;
    }

    function showTyping() {
        var typing = document.createElement('div');
        typing.id = 'umatTyping';
        typing.style.cssText = 'display:flex;gap:10px;align-items:flex-start;';
        typing.innerHTML = '<div style=\"min-width:32px;height:32px;border-radius:50%;background:rgba(0,107,47,0.15);display:flex;align-items:center;justify-content:center;color:#006b2f;flex-shrink:0;\">' +
            '<span class=\"material-symbols-outlined\" style=\"font-size:16px;\">smart_toy</span></div>' +
            '<div style=\"background:white;border-left:3px solid #006b2f;padding:12px;border-radius:0 12px 12px 12px;font-size:13px;display:flex;gap:4px;align-items:center;\">' +
            '<span style=\"width:8px;height:8px;border-radius:50%;background:#006b2f;animation:typingBounce 1.2s infinite;\"></span>' +
            '<span style=\"width:8px;height:8px;border-radius:50%;background:#006b2f;animation:typingBounce 1.2s infinite 0.2s;\"></span>' +
            '<span style=\"width:8px;height:8px;border-radius:50%;background:#006b2f;animation:typingBounce 1.2s infinite 0.4s;\"></span></div>';
        messages.appendChild(typing);
        messages.scrollTop = messages.scrollHeight;
    }

    function hideTyping() {
        var typing = document.getElementById('umatTyping');
        if (typing) typing.remove();
    }

    function sendQuestion() {
        var q = input.value.trim();
        if (!q) return;
        addUserMessage(q);
        input.value = '';
        showTyping();

        require(['core/ajax'], function(Ajax) {
            Ajax.call([{methodname:'local_umat_ai_ask_question', args:{courseid:courseId, question:q}}])[0].done(function(r) {
                hideTyping();
                if (r.success) {
                    var suggestions = [];
                    if (q.toLowerCase().includes('anisotropy')) {
                        suggestions.push('Explain Anisotropy');
                        suggestions.push('Compare to Granite');
                    }
                    addAiMessage(r.answer || 'Got your response!', suggestions);
                } else {
                    addAiMessage('Error: ' + (r.error || 'Something went wrong.'), []);
                }
            }).fail(function() {
                hideTyping();
                addAiMessage('Connection error. Please try again.', []);
            });
        });
    }

    function handleQuickAction(action) {
        var actions = {
            'summarize': 'Summarize the recent lecture on ' + courseName,
            'assignment': 'What are the upcoming assignments for ' + courseName + '?',
            'explain': 'Explain the main topics covered in the current module of ' + courseName,
            'deadlines': 'What are the upcoming deadlines for ' + courseName + '?'
        };
        input.value = actions[action] || action;
        sendQuestion();
    }

    quickActions.forEach(function(btn) {
        btn.addEventListener('click', function() {
            handleQuickAction(this.dataset.action);
        });
    });

    sendBtn.addEventListener('click', sendQuestion);
    input.addEventListener('keypress', function(e) { if(e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuestion(); }});

    console.log('UMaT AI Assistant loaded for course', courseId);
})();
");

    if (has_capability('local/umat_ai:chatwithai', $context)) {
        $url = new \moodle_url('/local/umat_ai/index.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('pluginname', 'local_umat_ai'),
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_umat_ai',
            new \pix_icon('i/info', '')
        );
    }

    if (has_capability('local/umat_ai:approveoutput', $context)) {
        $approveurl = new \moodle_url('/local/umat_ai/approve.php', ['courseid' => $course->id]);
        $navigation->add(
            get_string('approval_nav', 'local_umat_ai'),
            $approveurl,
            navigation_node::TYPE_CUSTOM,
            null,
            'umat_ai_approve',
            new \pix_icon('i/valid', '')
        );
    }
}

/**
 * Add plugin item under the course "More" / settings navigation.
 *
 * @param settings_navigation $settingsnav Settings navigation object.
 * @param \context $context Current context.
 */
function local_umat_ai_extend_settings_navigation(settings_navigation $settingsnav, \context $context): void {
    global $COURSE;

    if ($context->contextlevel !== CONTEXT_COURSE) {
        return;
    }

    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (!has_capability('local/umat_ai:chatwithai', $context)) {
        return;
    }

    $coursenode = $settingsnav->find('courseadmin', navigation_node::TYPE_COURSE);
    if (!$coursenode) {
        return;
    }

    $url = new \moodle_url('/local/umat_ai/index.php', ['courseid' => $COURSE->id]);

    $coursenode->add(
        get_string('pluginname', 'local_umat_ai'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_umat_ai_settingsnav'
    );
}

/**
 * Helper: Get AI service configuration from Moodle config settings.
 *
 * @return array{url:string, token:string} URL and bearer token.
 */
function local_umat_ai_get_service_config(): array {
    $url = (string) get_config('local_umat_ai', 'ai_service_url');
    $token = (string) get_config('local_umat_ai', 'ai_service_token');

    return [
        'url' => rtrim($url, "/"),
        'token' => $token,
    ];
}

/**
 * Helper: Returns true if the AI service is configured (URL and token exist).
 *
 * @return bool
 */
function local_umat_ai_is_service_configured(): bool {
    $cfg = local_umat_ai_get_service_config();
    return !empty($cfg['url']) && !empty($cfg['token']);
}

/**
 * Optional helper: Build standard headers for calling the AI service.
 *
 * @return string[] headers
 */
function local_umat_ai_get_ai_service_headers(): array {
    $cfg = local_umat_ai_get_service_config();
    return [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cfg['token'],
    ];
}