define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {
    var courseId = null;
    var currentGroupId = null;
    var pollInterval = null;
    var aiMode = false;

    function getStudyGroups() {
        return Ajax.call([{
            methodname: 'local_umat_ai_get_study_groups',
            args: {courseid: courseId}
        }])[0];
    }

    function createStudyGroup(name, description, maxMembers) {
        return Ajax.call([{
            methodname: 'local_umat_ai_create_study_group',
            args: {courseid: courseId, name: name, description: description, max_members: maxMembers}
        }])[0];
    }

    function joinStudyGroup(groupid) {
        return Ajax.call([{
            methodname: 'local_umat_ai_join_study_group',
            args: {groupid: groupid}
        }])[0];
    }

    function leaveStudyGroup(groupid) {
        return Ajax.call([{
            methodname: 'local_umat_ai_leave_study_group',
            args: {groupid: groupid}
        }])[0];
    }

    function getGroupMembers(groupid) {
        return Ajax.call([{
            methodname: 'local_umat_ai_get_group_members',
            args: {groupid: groupid}
        }])[0];
    }

    function getGroupMessages(groupid, limit, offset) {
        return Ajax.call([{
            methodname: 'local_umat_ai_get_group_messages',
            args: {groupid: groupid, limit: limit || 50, offset: offset || 0}
        }])[0];
    }

    function sendGroupMessage(groupid, question, answer, sources, message) {
        return Ajax.call([{
            methodname: 'local_umat_ai_send_group_message',
            args: {groupid: groupid, question: question || '', answer: answer || '', sources: sources || '', message: message || ''}
        }])[0];
    }

    function deleteStudyGroup(groupid) {
        return Ajax.call([{
            methodname: 'local_umat_ai_delete_study_group',
            args: {groupid: groupid}
        }])[0];
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function formatTime(timestamp) {
        if (!timestamp) return '';
        var d = new Date(timestamp * 1000);
        return d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    }

    // ================================================================
    // GROUP LIST
    // ================================================================

    function renderGroupList(groups) {
        var container = document.getElementById('umat-group-list-container');
        if (!container) return;

        if (!groups || groups.length === 0) {
            Str.get_string('group_empty', 'local_umat_ai').done(function(s) {
                container.innerHTML = '<div class="umat-group-empty">' + s + '</div>';
            });
            return;
        }

        var html = '';
        groups.forEach(function(g) {
            html += '<div class="umat-group-card" data-groupid="' + g.id + '">';
            html += '<div class="umat-group-card-header">';
            html += '<h4 class="umat-group-name">' + escapeHtml(g.name) + '</h4>';
            html += '<span class="umat-group-badge badge badge-' + (g.status === 'open' ? 'success' : 'secondary') + '">' + g.status + '</span>';
            html += '</div>';
            if (g.description) {
                html += '<p class="umat-group-desc">' + escapeHtml(g.description) + '</p>';
            }
            html += '<div class="umat-group-meta">';
            html += '<span>' + g.member_count + '/' + g.max_members + ' members</span>';
            html += '</div>';
            html += '<div class="umat-group-actions">';
            if (g.is_member) {
                html += '<button class="btn btn-primary btn-sm umat-group-open" data-groupid="' + g.id + '">Open</button>';
                html += '<button class="btn btn-outline-secondary btn-sm umat-group-leave" data-groupid="' + g.id + '">Leave</button>';
            } else if (g.status === 'open' && g.member_count < g.max_members) {
                html += '<button class="btn btn-success btn-sm umat-group-join" data-groupid="' + g.id + '">Join</button>';
            } else {
                html += '<span class="text-muted small">Full</span>';
            }
            html += '</div></div>';
        });

        container.innerHTML = html;
        attachGroupListeners();
    }

    // ================================================================
    // GROUP CHAT
    // ================================================================

    function renderGroupChat(group) {
        var container = document.getElementById('umat-group-chat-container');
        if (!container) return;

        container.innerHTML =
            '<div class="umat-group-chat-header">' +
            '<button class="btn btn-sm btn-outline-secondary umat-group-back">&larr; Back to Groups</button>' +
            '<h4>' + escapeHtml(group.name) + '</h4>' +
            '</div>' +
            '<div class="umat-group-chat-members">' +
            '<span class="badge badge-info">Members:</span> ' +
            '<span id="umat-group-member-list">Loading...</span>' +
            '</div>' +
            '<div class="umat-group-chat-messages" id="umat-group-messages"></div>' +
            '<div class="umat-group-chat-input">' +
            '<div class="umat-group-input-mode" id="umat-group-mode-bar">' +
            '<button class="umat-group-mode-btn active" id="umat-group-chat-mode" type="button">💬 Chat</button>' +
            '<button class="umat-group-mode-btn" id="umat-group-ai-mode" type="button">🤖 Ask AI &amp; Share</button>' +
            '</div>' +
            '<div style="display:flex;gap:8px;align-items:flex-end;">' +
            '<textarea class="form-control" id="umat-group-input" rows="2" placeholder="Type a message..." maxlength="1000" style="flex:1;"></textarea>' +
            '<button class="btn btn-primary umat-group-send-btn" id="umat-group-send-btn" style="white-space:nowrap;">Send</button>' +
            '</div>' +
            '</div>';

        document.querySelector('.umat-group-back').addEventListener('click', showGroupList);

        document.getElementById('umat-group-chat-mode').addEventListener('click', function() {
            aiMode = false;
            document.getElementById('umat-group-chat-mode').classList.add('active');
            document.getElementById('umat-group-ai-mode').classList.remove('active');
            document.getElementById('umat-group-input').placeholder = 'Type a message...';
            document.getElementById('umat-group-send-btn').textContent = 'Send';
        });

        document.getElementById('umat-group-ai-mode').addEventListener('click', function() {
            aiMode = true;
            document.getElementById('umat-group-ai-mode').classList.add('active');
            document.getElementById('umat-group-chat-mode').classList.remove('active');
            document.getElementById('umat-group-input').placeholder = 'Ask AI a question to share with the group...';
            document.getElementById('umat-group-send-btn').textContent = 'Ask AI & Share';
        });

        document.getElementById('umat-group-send-btn').addEventListener('click', function() {
            sendGroupMessage_(group.id);
        });
        document.getElementById('umat-group-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendGroupMessage_(group.id);
            }
        });

        loadGroupMembers(group.id);
        loadGroupMessages(group.id);
        startPolling(group.id);
    }

    function loadGroupMembers(groupid) {
        getGroupMembers(groupid).done(function(data) {
            var el = document.getElementById('umat-group-member-list');
            if (el && data.members) {
                el.textContent = data.members.map(function(m) { return m.fullname; }).join(', ');
            }
        }).fail(Notification.exception);
    }

    function loadGroupMessages(groupid) {
        getGroupMessages(groupid).done(function(data) {
            var container = document.getElementById('umat-group-messages');
            if (!container) return;

            if (!data.messages || data.messages.length === 0) {
                Str.get_string('group_empty_messages', 'local_umat_ai').done(function(s) {
                    container.innerHTML = '<div class="umat-group-empty-msg">' + s + '</div>';
                });
                return;
            }

            var html = '';
            data.messages.forEach(function(m) {
                if (m.question) {
                    html += '<div class="umat-group-msg umat-group-msg-ai">';
                    html += '<div class="umat-group-msg-hdr"><span class="umat-group-msg-author">' + escapeHtml(m.fullname) + '</span><span class="umat-group-msg-badge">AI Q&A</span><span class="text-muted small">' + formatTime(m.timecreated) + '</span></div>';
                    html += '<div class="umat-group-msg-q"><strong>Q:</strong> ' + escapeHtml(m.question) + '</div>';
                    if (m.answer) {
                        html += '<div class="umat-group-msg-a"><strong>A:</strong> ' + escapeHtml(m.answer) + '</div>';
                    }
                    if (m.sources && m.sources !== '[]' && m.sources !== '') {
                        html += '<div class="umat-group-msg-src"><strong>Sources:</strong> ' + escapeHtml(m.sources) + '</div>';
                    }
                    html += '</div>';
                } else if (m.message) {
                    html += '<div class="umat-group-msg umat-group-msg-chat">';
                    html += '<div class="umat-group-msg-hdr"><span class="umat-group-msg-author">' + escapeHtml(m.fullname) + '</span><span class="text-muted small">' + formatTime(m.timecreated) + '</span></div>';
                    html += '<div class="umat-group-msg-text">' + escapeHtml(m.message) + '</div>';
                    html += '</div>';
                }
            });
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        }).fail(Notification.exception);
    }

    function sendGroupMessage_(groupid) {
        var input = document.getElementById('umat-group-input');
        var btn = document.getElementById('umat-group-send-btn');
        if (!input || !btn) return;

        var text = input.value.trim();
        if (!text) return;

        btn.disabled = true;

        if (aiMode) {
            btn.textContent = 'Asking AI...';
            Ajax.call([{
                methodname: 'local_umat_ai_ask_question',
                args: {courseid: courseId, question: text}
            }])[0].done(function(response) {
                var answer = response.answer || '';
                var sources = JSON.stringify(response.sources || []);
                sendGroupMessage(groupid, text, answer, sources, '').done(function() {
                    input.value = '';
                    loadGroupMessages(groupid);
                }).fail(Notification.exception);
            }).fail(function() {
                sendGroupMessage(groupid, text, '', '[]', '').done(function() {
                    input.value = '';
                    loadGroupMessages(groupid);
                }).fail(Notification.exception);
            }).always(function() {
                btn.disabled = false;
                btn.textContent = 'Ask AI & Share';
            });
        } else {
            btn.textContent = 'Sending...';
            sendGroupMessage(groupid, '', '', '', text).done(function() {
                input.value = '';
                loadGroupMessages(groupid);
            }).fail(Notification.exception).always(function() {
                btn.disabled = false;
                btn.textContent = 'Send';
            });
        }
    }

    function startPolling(groupid) {
        if (pollInterval) clearInterval(pollInterval);
        currentGroupId = groupid;
        pollInterval = setInterval(function() {
            if (document.getElementById('umat-group-chat-container')) {
                loadGroupMessages(groupid);
            } else {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }, 3000);
    }

    // ================================================================
    // NAVIGATION
    // ================================================================

    function showGroupList() {
        var chatContainer = document.getElementById('umat-group-chat-container');
        var listContainer = document.getElementById('umat-group-list-container');
        if (chatContainer) chatContainer.innerHTML = '';
        if (listContainer) listContainer.style.display = 'block';
        document.getElementById('umat-create-group-form').style.display = 'block';
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
        currentGroupId = null;
        aiMode = false;
        refreshGroupList();
    }

    function showGroupChat(groupid) {
        var listContainer = document.getElementById('umat-group-list-container');
        if (listContainer) listContainer.style.display = 'none';
        document.getElementById('umat-create-group-form').style.display = 'none';

        getStudyGroups().done(function(data) {
            if (data.groups) {
                var group = data.groups.find(function(g) { return g.id === groupid; });
                if (group) renderGroupChat(group);
            }
        }).fail(Notification.exception);
    }

    function refreshGroupList() {
        getStudyGroups().done(function(data) {
            renderGroupList(data.groups || []);
        }).fail(Notification.exception);
    }

    function attachGroupListeners() {
        document.querySelectorAll('.umat-group-join').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var gid = parseInt(this.getAttribute('data-groupid'));
                if (!gid) return;
                joinStudyGroup(gid).done(function() { refreshGroupList(); }).fail(Notification.exception);
            });
        });

        document.querySelectorAll('.umat-group-leave').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var gid = parseInt(this.getAttribute('data-groupid'));
                if (!gid) return;
                leaveStudyGroup(gid).done(function() { refreshGroupList(); }).fail(Notification.exception);
            });
        });

        document.querySelectorAll('.umat-group-open').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var gid = parseInt(this.getAttribute('data-groupid'));
                if (!gid) return;
                showGroupChat(gid);
            });
        });
    }

    // ================================================================
    // CREATE FORM
    // ================================================================

    function setupCreateGroupForm() {
        var form = document.getElementById('umat-create-group-form');
        if (!form) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var nameInput = document.getElementById('umat-group-name-input');
            var descInput = document.getElementById('umat-group-desc-input');
            var maxInput = document.getElementById('umat-group-max-input');

            var name = nameInput ? nameInput.value.trim() : '';
            if (!name) return;

            var desc = descInput ? descInput.value.trim() : '';
            var max = maxInput ? parseInt(maxInput.value) || 5 : 5;

            createStudyGroup(name, desc, max).done(function() {
                if (nameInput) nameInput.value = '';
                if (descInput) descInput.value = '';
                refreshGroupList();
            }).fail(Notification.exception);
        });
    }

    // ================================================================
    // INIT
    // ================================================================

    function init(courseIdParam) {
        courseId = courseIdParam;
        setupCreateGroupForm();
        refreshGroupList();
    }

    return {init: init};
});
