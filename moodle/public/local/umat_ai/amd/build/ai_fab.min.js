// AMD module for UMaT AI FAB and workspace
define(['core/ajax'], function(Ajax) {
    'use strict';

    var courseId = null;
    var courseName = '';
    var workspaceEl = null;
    var floatingPanelEl = null;
    var expandedWorkspaceEl = null;

    // Animation keyframes
    function addStyles() {
        if (document.getElementById('umat-ai-styles')) return;

        var style = document.createElement('style');
        style.id = 'umat-ai-styles';
        style.textContent = `
            @keyframes fabPulse {
                0% { box-shadow: 0 0 0 0 rgba(0,107,47,0.5); }
                70% { box-shadow: 0 0 0 12px rgba(0,107,47,0); }
                100% { box-shadow: 0 0 0 0 rgba(0,107,47,0); }
            }
            @keyframes statusPulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            @keyframes typingBounce {
                0%, 60%, 100% { transform: translateY(0); }
                30% { transform: translateY(-5px); }
            }
            @keyframes expSlideIn {
                from { opacity: 0; transform: scale(0.98); }
                to { opacity: 1; transform: scale(1); }
            }
            .fabTooltip {
                position: absolute;
                right: 70px;
                background: #333;
                color: #fff;
                padding: 8px 12px;
                border-radius: 8px;
                font-size: 12px;
                white-space: nowrap;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s;
            }
            .fabTooltip::after {
                content: "";
                position: absolute;
                right: -6px;
                top: 50%;
                transform: translateY(-50%);
                border: 6px solid transparent;
                border-left-color: #333;
            }
            #umatFabBtn:hover .fabTooltip {
                opacity: 1;
            }
            .umat-group-card {
                background: white;
                border-radius: 12px;
                padding: 14px;
                margin-bottom: 10px;
                border: 1px solid #dee5da;
                transition: box-shadow 0.2s;
            }
            .umat-group-card:hover {
                box-shadow: 0 2px 8px rgba(0,107,47,0.1);
            }
            .umat-group-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 6px;
            }
            .umat-group-name {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #333;
            }
            .umat-group-desc {
                font-size: 12px;
                color: #666;
                margin: 4px 0 8px;
                line-height: 1.4;
            }
            .umat-group-meta {
                font-size: 11px;
                color: #999;
                margin-bottom: 10px;
            }
            .umat-group-actions {
                display: flex;
                gap: 6px;
            }
            .umat-group-actions .btn {
                font-size: 12px;
                padding: 4px 12px;
            }
            .umat-group-chat-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 12px;
                padding-bottom: 10px;
                border-bottom: 1px solid #dee5da;
            }
            .umat-group-chat-header h4 {
                margin: 0;
                font-size: 14px;
                font-weight: 600;
                color: #006b2f;
                flex: 1;
            }
            .umat-group-chat-members {
                font-size: 11px;
                color: #666;
                margin-bottom: 12px;
            }
            .umat-group-chat-messages {
                flex: 1;
                overflow-y: auto;
                max-height: 300px;
                margin-bottom: 12px;
            }
            .umat-group-msg {
                background: white;
                border-radius: 10px;
                padding: 10px 12px;
                margin-bottom: 8px;
                border: 1px solid #dee5da;
            }
            .umat-group-msg-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 4px;
                font-size: 12px;
            }
            .umat-group-msg-q {
                font-size: 13px;
                color: #333;
                margin-bottom: 4px;
            }
            .umat-group-msg-a {
                font-size: 12px;
                color: #555;
                padding-left: 8px;
                border-left: 2px solid #006b2f;
            }
            .umat-group-chat-input {
                display: flex;
                gap: 8px;
                align-items: flex-end;
            }
            .umat-group-chat-input textarea {
                flex: 1;
                padding: 8px;
                border: 1px solid #dee5da;
                border-radius: 8px;
                font-size: 13px;
                resize: none;
                outline: none;
            }
            .umat-group-chat-input textarea:focus {
                border-color: #006b2f;
            }
            .umat-group-chat-input button {
                padding: 8px 16px;
                background: #006b2f;
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-size: 13px;
                white-space: nowrap;
            }
            .umat-group-empty, .umat-group-empty-msg {
                text-align: center;
                padding: 24px 16px;
                color: #999;
                font-size: 13px;
            }
        `;
        document.head.appendChild(style);
    }

    // Create the FAB button
    function createFab() {
        if (document.getElementById('umatFabBtn')) return;

        addStyles();

        var fab = document.createElement('button');
        fab.id = 'umatFabBtn';
        fab.innerHTML = '<span class="material-symbols-outlined" style="font-size:28px">smart_toy</span><span class="fabTooltip">Ask UMaT AI Assistant</span>';
        fab.style.cssText = 'position:fixed;bottom:80px;right:24px;z-index:9999;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#006b2f,#00873d);color:white;border:none;box-shadow:0 6px 20px rgba(0,107,47,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:transform 0.2s,box-shadow 0.2s;animation:fabPulse 2.5s infinite;';
        fab.onmouseover = function() { this.style.transform = 'scale(1.1)'; this.style.boxShadow = '0 8px 25px rgba(0,107,47,0.5)'; };
        fab.onmouseout = function() { this.style.transform = 'scale(1)'; this.style.boxShadow = '0 6px 20px rgba(0,107,47,0.4)'; };

        document.body.appendChild(fab);
        fab.addEventListener('click', showFloatingPanel);

        return fab;
    }

    // Show floating panel
    function showFloatingPanel() {
        if (!workspaceEl) {
            createWorkspace();
        }
        workspaceEl.style.display = 'block';
        setTimeout(function() {
            var input = document.getElementById('umatInput');
            if (input) input.focus();
        }, 100);
    }

    // Hide floating panel
    function hideFloatingPanel() {
        if (workspaceEl) {
            workspaceEl.style.display = 'none';
        }
    }

    // Create floating panel (the compact chat view)
    function createWorkspace() {
        workspaceEl = document.createElement('div');
        workspaceEl.id = 'umatWorkspace';
        workspaceEl.style.cssText = 'display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.3);backdrop-filter:blur(4px);';

        var panel = document.createElement('div');
        panel.id = 'umatFloatingPanel';
        panel.style.cssText = 'position:fixed;bottom:24px;right:24px;width:400px;max-width:calc(100vw - 48px);max-height:75vh;background:#f8faf7;border-radius:20px;box-shadow:0 10px 40px rgba(0,0,0,0.2);display:flex;flex-direction:column;overflow:hidden;';

        // Header
        panel.innerHTML =
            '<div style="background:linear-gradient(135deg,#006b2f,#00873d);padding:16px 20px;color:white;">' +
            '<div style="display:flex;align-items:center;gap:10px;">' +
            '<div style="width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;position:relative;">' +
            '<span class="material-symbols-outlined" style="font-size:22px;">smart_toy</span>' +
            '<span style="position:absolute;bottom:0;right:0;width:10px;height:10px;border-radius:50%;background:#4ade80;border:2px solid #00873d;animation:statusPulse 1.5s infinite;"></span></div>' +
            '<div style="flex:1;"><h3 style="margin:0;font-size:15px;font-weight:600;">UMaT AI Assistant</h3>' +
            '<div style="display:flex;align-items:center;gap:4px;font-size:11px;opacity:0.9;">' +
            '<span style="width:6px;height:6px;border-radius:50%;background:#4ade80;animation:statusPulse 1.5s infinite;"></span>Online & Ready</div></div>' +
            '<button id="umatExpandBtn" title="Expand to full workspace" style="background:rgba(255,255,255,0.2);border:none;color:white;width:36px;height:36px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;margin-right:8px;">' +
            '<span class="material-symbols-outlined" style="font-size:20px;">open_in_full</span></button>' +
            '<button id="umatCloseBtn" style="background:rgba(255,255,255,0.2);border:none;color:white;width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;">' +
            '<span class="material-symbols-outlined" style="font-size:16px;">close</span></button></div>' +
            '<div style="font-size:10px;opacity:0.7;padding:0 20px 12px 70px;">' + courseName + '</div></div>' +

            // Tabs
            '<div style="display:flex;border-bottom:1px solid #dee5da;background:white;">' +
            '<button class="umatTab active" data-tab="chat" style="flex:1;padding:10px 8px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:#006b2f;border-bottom:2px solid #006b2f;">Chat</button>' +
            '<button class="umatTab" data-tab="notes" style="flex:1;padding:10px 8px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:500;color:#666;">Notes</button>' +
            '<button class="umatTab" data-tab="resources" style="flex:1;padding:10px 8px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:500;color:#666;">Resources</button>' +
            '<button class="umatTab" data-tab="group" style="flex:1;padding:10px 8px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:500;color:#666;">Group</button></div>' +

            // Chat content
            '<div id="umatChatContent" style="flex:1;overflow-y:auto;padding:16px;background:#f8faf7;display:flex;flex-direction:column;gap:12px;">' +
            '<div style="display:flex;gap:10px;align-items:flex-start;">' +
            '<div style="min-width:32px;height:32px;border-radius:50%;background:rgba(0,107,47,0.15);display:flex;align-items:center;justify-content:center;color:#006b2f;flex-shrink:0;">' +
            '<span class="material-symbols-outlined" style="font-size:16px;">smart_toy</span></div>' +
            '<div style="background:white;border-left:3px solid #006b2f;padding:12px;border-radius:0 12px 12px 12px;font-size:13px;line-height:1.5;max-width:88%;">' +
            '<p style="margin:0;">Hello! I\'m your AI course tutor for <strong>' + courseName + '</strong>. How can I help you today?</p></div></div>' +

            // Quick actions
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:8px 0;">' +
            '<button class="quickAction" data-action="summarize" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;">' +
            '<span class="material-symbols-outlined" style="font-size:22px;color:#006b2f;margin-bottom:4px;">summarize</span>' +
            '<span style="font-size:11px;color:#333;text-align:center;line-height:1.2;">Summarize recent lecture</span></button>' +
            '<button class="quickAction" data-action="assignment" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;">' +
            '<span class="material-symbols-outlined" style="font-size:22px;color:#006b2f;margin-bottom:4px;">quiz</span>' +
            '<span style="font-size:11px;color:#333;text-align:center;line-height:1.2;">Ask about Assignment</span></button>' +
            '<button class="quickAction" data-action="explain" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;">' +
            '<span class="material-symbols-outlined" style="font-size:22px;color:#006b2f;margin-bottom:4px;">search_spark</span>' +
            '<span style="font-size:11px;color:#333;text-align:center;line-height:1.2;">Explain Topic</span></button>' +
            '<button class="quickAction" data-action="deadlines" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px 8px;border:1px solid #dee5da;background:white;border-radius:12px;cursor:pointer;transition:all 0.2s;">' +
            '<span class="material-symbols-outlined" style="font-size:22px;color:#006b2f;margin-bottom:4px;">schedule</span>' +
            '<span style="font-size:11px;color:#333;text-align:center;line-height:1.2;">Upcoming Deadlines</span></button></div>' +

            '<div id="umatMessages" style="display:flex;flex-direction:column;gap:10px;"></div></div>' +

            // Notes content
            '<div id="umatNotesContent" style="display:none;flex:1;overflow-y:auto;padding:24px;text-align:center;color:#666;font-size:14px;">' +
            '<span class="material-symbols-outlined" style="font-size:48px;color:#dee5da;">description</span>' +
            '<p style="margin-top:12px;">Your generated notes will appear here after watching lectures.</p></div>' +

            // Resources content
            '<div id="umatResourcesContent" style="display:none;flex:1;overflow-y:auto;padding:24px;text-align:center;color:#666;font-size:14px;">' +
            '<span class="material-symbols-outlined" style="font-size:48px;color:#dee5da;">folder_open</span>' +
            '<p style="margin-top:12px;">Course resources will appear here.</p></div>' +

            // Group study content
            '<div id="umatGroupContent" style="display:none;flex:1;overflow-y:auto;padding:16px;background:#f8faf7;"></div>' +

            // Input area
            '<div id="umatChatInput" style="padding:12px 16px;background:white;border-top:1px solid #dee5da;">' +
            '<div style="display:flex;gap:8px;align-items:flex-end;">' +
            '<textarea id="umatInput" placeholder="Type your academic question..." rows="2" style="flex:1;padding:12px;border:1px solid #dee5da;border-radius:12px;font-size:13px;resize:none;outline:none;line-height:1.4;"></textarea>' +
            '<button id="umatSendBtn" style="width:44px;height:44px;border-radius:12px;background:#006b2f;color:white;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
            '<span class="material-symbols-outlined" style="font-size:20px;">send</span></button></div>' +
            '<div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;">' +
            '<span style="font-size:10px;color:#999;">UMaT AI Model v2.4</span>' +
            '<button style="background:none;border:none;color:#006b2f;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:4px;">' +
            '<span class="material-symbols-outlined" style="font-size:14px;">history</span>Past Logs</button></div></div>';

        workspaceEl.appendChild(panel);
        document.body.appendChild(workspaceEl);
        floatingPanelEl = panel;

        setupEventListeners();
    }

    // Setup event listeners for floating panel
    function setupEventListeners() {
        var closeBtn = document.getElementById('umatCloseBtn');
        var expandBtn = document.getElementById('umatExpandBtn');
        var input = document.getElementById('umatInput');
        var sendBtn = document.getElementById('umatSendBtn');
        var messages = document.getElementById('umatMessages');
        var tabs = document.querySelectorAll('.umatTab');
        var quickActions = document.querySelectorAll('.quickAction');

        // Close buttons
        if (closeBtn) {
            closeBtn.addEventListener('click', hideFloatingPanel);
        }

        // Expand button - show full workspace
        if (expandBtn) {
            expandBtn.addEventListener('click', function() {
                hideFloatingPanel();
                showExpandedWorkspace();
            });
        }

        // Click outside to close
        if (workspaceEl) {
            workspaceEl.addEventListener('click', function(e) {
                if (e.target === workspaceEl) {
                    hideFloatingPanel();
                }
            });
        }

        // Send message
        if (sendBtn && input) {
            sendBtn.addEventListener('click', function() {
                sendQuestion(input.value, messages);
            });

            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendQuestion(input.value, messages);
                }
            });
        }

        // Tab switching
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
                var notesContent = document.getElementById('umatNotesContent');
                var resourcesContent = document.getElementById('umatResourcesContent');
                var groupContent = document.getElementById('umatGroupContent');

                chatContent.style.display = 'none';
                chatInput.style.display = 'none';
                if (notesContent) notesContent.style.display = 'none';
                if (resourcesContent) resourcesContent.style.display = 'none';
                if (groupContent) groupContent.style.display = 'none';

                if (tabName === 'chat') {
                    chatContent.style.display = 'flex';
                    chatInput.style.display = 'block';
                } else if (tabName === 'notes') {
                    if (notesContent) notesContent.style.display = 'block';
                } else if (tabName === 'resources') {
                    if (resourcesContent) resourcesContent.style.display = 'block';
                } else if (tabName === 'group') {
                    if (groupContent) {
                        groupContent.style.display = 'block';
                        // Lazily initialize group study
                        if (!groupContent.querySelector('.umat-group-study')) {
                            require(['local_umat_ai/group_study'], function(GroupStudy) {
                                var template = '' +
                                    '<div class="umat-group-study" id="umat-group-study">' +
                                    '<div class="umat-group-study-header"><h3 style="font-size:15px;font-weight:600;color:#006b2f;margin:0 0 12px;">Study Groups</h3></div>' +
                                    '<form id="umat-create-group-form" class="umat-group-create-form" style="margin-bottom:16px;">' +
                                    '<input type="text" id="umat-group-name-input" class="form-control form-control-sm mb-1" placeholder="Group name..." maxlength="255" required style="width:100%;padding:8px;border:1px solid #dee5da;border-radius:8px;font-size:13px;margin-bottom:8px;box-sizing:border-box;">' +
                                    '<textarea id="umat-group-desc-input" class="form-control form-control-sm mb-1" rows="2" placeholder="Description..." maxlength="500" style="width:100%;padding:8px;border:1px solid #dee5da;border-radius:8px;font-size:13px;margin-bottom:8px;resize:none;box-sizing:border-box;"></textarea>' +
                                    '<div style="display:flex;gap:8px;">' +
                                    '<input type="number" id="umat-group-max-input" class="form-control form-control-sm" value="5" min="2" max="20" style="width:70px;padding:6px;border:1px solid #dee5da;border-radius:8px;font-size:13px;">' +
                                    '<button type="submit" class="btn btn-success btn-sm" style="flex:1;padding:8px;background:#006b2f;color:white;border:none;border-radius:8px;cursor:pointer;font-size:13px;">Create Group</button></div></form>' +
                                    '<div id="umat-group-list-container" class="umat-group-list-container"></div>' +
                                    '<div id="umat-group-chat-container" class="umat-group-chat-container"></div></div>';
                                groupContent.innerHTML = template;
                                GroupStudy.init(courseId);
                            });
                        }
                    }
                }
            });
        });

        // Quick actions
        quickActions.forEach(function(btn) {
            btn.onmouseover = function() { this.style.borderColor = '#006b2f'; this.style.background = 'rgba(129, 251, 156, 0.1)'; };
            btn.onmouseout = function() { this.style.borderColor = '#dee5da'; this.style.background = 'white'; };

            btn.addEventListener('click', function() {
                var action = this.dataset.action;
                var question = '';
                switch(action) {
                    case 'summarize': question = 'Summarize the recent lecture material'; break;
                    case 'assignment': question = 'What are the current assignment requirements?'; break;
                    case 'explain': question = 'Explain the main concept from this week'; break;
                    case 'deadlines': question = 'What are the upcoming deadlines?'; break;
                }
                if (question) {
                    var msgs = document.getElementById('umatMessages') || messages;
                    sendQuestion(question, msgs);
                }
            });
        });
    }

    // Send question to AI
    function sendQuestion(question, messagesContainer) {
        if (!question.trim()) return;

        var input = document.getElementById('umatInput');
        var msgs = messagesContainer || document.getElementById('umatMessages');

        // Add user message
        var userMsg = document.createElement('div');
        userMsg.style.cssText = 'display:flex;justify-content:flex-end;';
        userMsg.innerHTML = '<div style="background:#EBF0FF;padding:10px 14px;border-radius:14px 0 14px 14px;font-size:13px;max-width:90%;"><p style="margin:0;color:#333;">' + question + '</p></div>';
        msgs.appendChild(userMsg);

        if (input) input.value = '';
        msgs.scrollTop = msgs.scrollHeight;

        // Show typing indicator
        var typing = document.createElement('div');
        typing.id = 'umat-typing-indicator';
        typing.style.cssText = 'display:flex;gap:10px;align-items:flex-start;';
        typing.innerHTML = '<div style="min-width:32px;height:32px;border-radius:50%;background:rgba(0,107,47,0.15);display:flex;align-items:center;justify-content:center;color:#006b2f;">' +
            '<span class="material-symbols-outlined" style="font-size:16px;">smart_toy</span></div>' +
            '<div style="background:white;border-left:3px solid #006b2f;padding:12px;border-radius:0 12px 12px 12px;display:flex;gap:6px;align-items:center;">' +
            '<span style="width:8px;height:8px;border-radius:50%;background:#006b2f;animation:typingBounce 1.2s infinite;"></span>' +
            '<span style="width:8px;height:8px;border-radius:50%;background:#006b2f;animation:typingBounce 1.2s infinite 0.2s;"></span>' +
            '<span style="width:8px;height:8px;border-radius:50%;background:#006b2f;animation:typingBounce 1.2s infinite 0.4s;"></span></div>';
        msgs.appendChild(typing);
        msgs.scrollTop = msgs.scrollHeight;

        // Call API
        Ajax.call([{
            methodname: 'local_umat_ai_ask_question',
            args: { courseid: courseId, question: question }
        }])[0].done(function(response) {
            typing.remove();
            var aiMsg = document.createElement('div');
            aiMsg.style.cssText = 'display:flex;gap:10px;align-items:flex-start;';
            aiMsg.innerHTML = '<div style="min-width:32px;height:32px;border-radius:50%;background:rgba(0,107,47,0.15);display:flex;align-items:center;justify-content:center;color:#006b2f;flex-shrink:0;">' +
                '<span class="material-symbols-outlined" style="font-size:16px;">smart_toy</span></div>' +
                '<div style="background:white;border-left:3px solid #006b2f;padding:12px;border-radius:0 12px 12px 12px;font-size:13px;line-height:1.5;max-width:88%;">' +
                '<p style="margin:0;">' + (response.success ? (response.answer || 'Got your response!') : 'Error: ' + (response.error || 'Something went wrong')) + '</p></div>';
            msgs.appendChild(aiMsg);
            msgs.scrollTop = msgs.scrollHeight;
        }).fail(function() {
            typing.remove();
        });
    }

    // Show expanded workspace (full-screen with video + transcript + AI sidebar)
    function showExpandedWorkspace() {
        // Check if already exists
        var existing = document.getElementById('umatExpandedWorkspace');
        if (existing) {
            existing.style.display = 'flex';
            return;
        }

        // Create expanded workspace
        expandedWorkspaceEl = document.createElement('div');
        expandedWorkspaceEl.id = 'umatExpandedWorkspace';
        expandedWorkspaceEl.style.cssText = 'display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:10001;background:#f8faf7;animation:expSlideIn 0.3s ease;';

        // Left side: Video + Transcript
        var leftSide = document.createElement('div');
        leftSide.style.cssText = 'flex:1;display:flex;flex-direction:column;padding:24px;overflow:hidden;';

        // Header with minimize button
        leftSide.innerHTML =
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">' +
            '<button id="umatMinimizeBtn" style="display:flex;align-items:center;gap:8px;padding:10px 16px;background:white;border:1px solid #dee5da;border-radius:10px;cursor:pointer;color:#006b2f;font-size:14px;">' +
            '<span class="material-symbols-outlined" style="font-size:20px;">open_in_full</span>Minimize</button>' +
            '<h2 style="color:#006b2f;font-size:20px;font-weight:600;margin:0;">' + courseName + '</h2>' +
            '<div style="width:100px;"></div></div>' +

            '<div style="flex:1;display:flex;flex-direction:column;gap:20px;overflow:hidden;">' +
            // Video player placeholder
            '<div style="position:relative;aspect-ratio:16/9;background:#000;border-radius:16px;overflow:hidden;">' +
            '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">' +
            '<button style="width:80px;height:80px;border-radius:50%;background:rgba(0,107,47,0.9);color:white;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;">' +
            '<span class="material-symbols-outlined" style="font-size:40px;">play_arrow</span></button></div>' +
            '<div style="position:absolute;bottom:0;left:0;right:0;padding:16px;background:linear-gradient(transparent,rgba(0,0,0,0.8));display:flex;align-items:center;justify-content:space-between;color:white;">' +
            '<div style="display:flex;align-items:center;gap:16px;">' +
            '<span class="material-symbols-outlined">play_arrow</span><span class="material-symbols-outlined">volume_up</span>' +
            '<span style="font-size:14px;">12:45 / 45:00</span></div>' +
            '<div style="display:flex;align-items:center;gap:12px;">' +
            '<span class="material-symbols-outlined">settings</span><span class="material-symbols-outlined">fullscreen</span></div></div></div>' +

            // Transcript
            '<div style="background:white;border-radius:16px;padding:20px;flex:1;overflow:hidden;display:flex;flex-direction:column;">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #dee5da;">' +
            '<h3 style="color:#006b2f;font-size:16px;font-weight:600;margin:0;">Synchronized Transcript</h3>' +
            '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f8faf7;border-radius:8px;">' +
            '<span class="material-symbols-outlined" style="font-size:18px;color:#666;">search</span>' +
            '<input placeholder="Search transcript..." style="border:none;background:none;outline:none;font-size:13px;width:150px;"></div></div>' +
            '<div style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:12px;">' +
            '<div style="padding:10px;cursor:pointer;border-radius:8px;"><span style="color:#006b2f;font-weight:600;font-size:12px;">12:15</span><p style="margin:4px 0 0;font-size:13px;color:#333;">The mechanical properties of rock masses are influenced heavily by the discontinuities and structural features.</p></div>' +
            '<div style="padding:10px;cursor:pointer;border-radius:8px;background:rgba(0,107,47,0.1);border-left:3px solid #006b2f;"><span style="color:#006b2f;font-weight:600;font-size:12px;">12:45</span><p style="margin:4px 0 0;font-size:13px;color:#333;font-weight:500;">When considering the stress-strain relationship, we must account for the anisotropy of the schist layers.</p></div>' +
            '<div style="padding:10px;cursor:pointer;border-radius:8px;"><span style="color:#006b2f;font-weight:600;font-size:12px;">13:10</span><p style="margin:4px 0 0;font-size:13px;color:#333;">This is why laboratory testing of core samples alone is often insufficient for predicting field behavior.</p></div>' +
            '<div style="padding:10px;cursor:pointer;border-radius:8px;"><span style="color:#006b2f;font-weight:600;font-size:12px;">13:45</span><p style="margin:4px 0 0;font-size:13px;color:#333;">Moving on to the Hoek-Brown criterion, let\'s analyze how the mi parameter changes with composition.</p></div></div></div></div></div>';

        // Right side: AI Sidebar
        var rightSide = document.createElement('div');
        rightSide.style.cssText = 'width:420px;background:white;border-left:1px solid #dee5da;display:flex;flex-direction:column;box-shadow:-10px 0 30px rgba(0,0,0,0.1);';

        // Header
        rightSide.innerHTML =
            '<div style="padding:20px;background:linear-gradient(135deg,#006b2f,#00873d);color:white;display:flex;align-items:center;justify-content:space-between;">' +
            '<div style="display:flex;align-items:center;gap:12px;">' +
            '<div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;position:relative;">' +
            '<span class="material-symbols-outlined" style="font-size:24px;">smart_toy</span>' +
            '<span style="position:absolute;bottom:0;right:0;width:12px;height:12px;border-radius:50%;background:#4ade80;border:2px solid #00873d;"></span></div>' +
            '<div><h3 style="margin:0;font-size:16px;font-weight:600;">AI Learning Assistant</h3>' +
            '<div style="display:flex;align-items:center;gap:4px;font-size:11px;"><span style="width:6px;height:6px;border-radius:50%;background:#4ade80;"></span>Online & Ready</div></div></div>' +
            '<button id="umatCloseExpanded" style="background:rgba(255,255,255,0.2);border:none;color:white;width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;">' +
            '<span class="material-symbols-outlined" style="font-size:20px;">close</span></button></div>' +

            // Tabs
            '<div style="display:flex;border-bottom:1px solid #dee5da;">' +
            '<button class="expTab active" data-tab="chat" style="flex:1;padding:14px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:600;color:#006b2f;border-bottom:2px solid #006b2f;">Chat</button>' +
            '<button class="expTab" data-tab="notes" style="flex:1;padding:14px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:#666;">Notes</button>' +
            '<button class="expTab" data-tab="resources" style="flex:1;padding:14px;border:none;background:none;cursor:pointer;font-size:14px;font-weight:500;color:#666;">Resources</button></div>' +

            // Chat content
            '<div id="expChatContent" style="flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:16px;background:#f8faf7;">' +
            '<div style="display:flex;gap:10px;align-items:flex-start;">' +
            '<div style="min-width:36px;height:36px;border-radius:50%;background:rgba(0,107,47,0.15);display:flex;align-items:center;justify-content:center;color:#006b2f;">' +
            '<span class="material-symbols-outlined" style="font-size:18px;">smart_toy</span></div>' +
            '<div style="background:white;border-left:3px solid #006b2f;padding:14px;border-radius:0 14px 14px 14px;font-size:14px;line-height:1.5;max-width:90%;">' +
            '<p style="margin:0;">I noticed the professor mentioned "anisotropy" at 12:45. Would you like me to explain how this applies to the Tarkwaian schist?</p>' +
            '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;">' +
            '<button style="padding:8px 14px;background:white;border:1px solid #dee5da;border-radius:20px;font-size:12px;color:#006b2f;cursor:pointer;">Explain Anisotropy</button>' +
            '<button style="padding:8px 14px;background:white;border:1px solid #dee5da;border-radius:20px;font-size:12px;color:#006b2f;cursor:pointer;">Compare to Granite</button></div></div></div></div>' +

            // Notes content
            '<div id="expNotesContent" style="display:none;flex:1;overflow-y:auto;padding:24px;text-align:center;color:#666;">' +
            '<span class="material-symbols-outlined" style="font-size:48px;color:#dee5da;">description</span>' +
            '<p style="margin-top:12px;">Your generated notes will appear here.</p></div>' +

            // Resources content
            '<div id="expResourcesContent" style="display:none;flex:1;overflow-y:auto;padding:24px;text-align:center;color:#666;">' +
            '<span class="material-symbols-outlined" style="font-size:48px;color:#dee5da;">folder_open</span>' +
            '<p style="margin-top:12px;">Course resources will appear here.</p></div>' +

            // Input
            '<div style="padding:16px;background:white;border-top:1px solid #dee5da;">' +
            '<div style="display:flex;gap:10px;align-items:flex-end;">' +
            '<textarea id="expInput" placeholder="Ask AI about this lecture..." rows="2" style="flex:1;padding:14px;border:1px solid #dee5da;border-radius:14px;font-size:14px;resize:none;outline:none;"></textarea>' +
            '<button id="expSendBtn" style="width:48px;height:48px;border-radius:14px;background:#006b2f;color:white;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;">' +
            '<span class="material-symbols-outlined" style="font-size:22px;">send</span></button></div>' +
            '<div style="margin-top:10px;display:flex;justify-content:space-between;">' +
            '<button style="display:flex;align-items:center;gap:6px;background:none;border:none;color:#006b2f;font-size:12px;cursor:pointer;">' +
            '<span class="material-symbols-outlined" style="font-size:16px;">attachment</span>Reference Course Material</button>' +
            '<span class="material-symbols-outlined" style="color:#999;cursor:pointer;">mic</span></div></div>';

        // Assemble the layout
        var container = document.createElement('div');
        container.style.cssText = 'display:flex;height:100vh;width:100vw;';
        container.appendChild(leftSide);
        container.appendChild(rightSide);
        expandedWorkspaceEl.appendChild(container);
        document.body.appendChild(expandedWorkspaceEl);

        // Event listeners for expanded workspace
        setupExpandedEventListeners();
        expandedWorkspaceEl.style.display = 'flex';
    }

    function setupExpandedEventListeners() {
        var minimizeBtn = document.getElementById('umatMinimizeBtn');
        var closeExpBtn = document.getElementById('umatCloseExpanded');
        var expTabs = document.querySelectorAll('.expTab');
        var expSendBtn = document.getElementById('expSendBtn');
        var expInput = document.getElementById('expInput');
        var expChatContent = document.getElementById('expChatContent');

        // Minimize - return to floating panel
        if (minimizeBtn) {
            minimizeBtn.addEventListener('click', function() {
                expandedWorkspaceEl.style.display = 'none';
                showFloatingPanel();
            });
        }

        // Close - hide expanded workspace completely
        if (closeExpBtn) {
            closeExpBtn.addEventListener('click', function() {
                expandedWorkspaceEl.style.display = 'none';
            });
        }

        // Tab switching
        expTabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var tabName = this.dataset.tab;
                expTabs.forEach(function(t) {
                    t.style.color = '#666';
                    t.style.fontWeight = '500';
                    t.style.borderBottom = 'none';
                });
                this.style.color = '#006b2f';
                this.style.fontWeight = '600';
                this.style.borderBottom = '2px solid #006b2f';

                if (tabName === 'chat') {
                    document.getElementById('expChatContent').style.display = 'flex';
                    document.getElementById('expNotesContent').style.display = 'none';
                    document.getElementById('expResourcesContent').style.display = 'none';
                } else if (tabName === 'notes') {
                    document.getElementById('expChatContent').style.display = 'none';
                    document.getElementById('expNotesContent').style.display = 'block';
                    document.getElementById('expResourcesContent').style.display = 'none';
                } else {
                    document.getElementById('expChatContent').style.display = 'none';
                    document.getElementById('expNotesContent').style.display = 'none';
                    document.getElementById('expResourcesContent').style.display = 'block';
                }
            });
        });

        // Send in expanded view
        if (expSendBtn && expInput) {
            expSendBtn.addEventListener('click', function() {
                sendQuestion(expInput.value, expChatContent);
            });

            expInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendQuestion(expInput.value, expChatContent);
                }
            });
        }
    }

    // Initialize
    function init(courseIdParam, courseNameParam) {
        courseId = courseIdParam;
        courseName = courseNameParam || 'Course';
        createFab();
    }

    return { init: init };
});