define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {
    var courseId = null;
    var currentGroupId = null;
    var pollInterval = null;

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

    function sendGroupMessage(groupid, question, answer, sources) {
        return Ajax.call([{
            methodname: 'local_umat_ai_send_group_message',
            args: {groupid: groupid, question: question, answer: answer || '', sources: sources || ''}
        }])[0];
    }

    function deleteStudyGroup(groupid) {
        return Ajax.call([{
            methodname: 'local_umat_ai_delete_study_group',
            args: {groupid: groupid}
        }])[0];
    }

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

    function renderGroupChat(group) {
        var container = document.getElementById('umat-group-chat-container');
        if (!container) return;

        Str.get_string('group_chat', 'local_umat_ai').done(function(chatStr) {
            Str.get_string('group_back_to_list', 'local_umat_ai').done(function(backStr) {
                Str.get_string('group_ask_ai', 'local_umat_ai').done(function(askStr) {
                    Str.get_string('group_send', 'local_umat_ai').done(function(sendStr) {
                        Str.get_string('group_members', 'local_umat_ai').done(function(membersStr) {
                            container.innerHTML =
                                '<div class="umat-group-chat-header">' +
                                '<button class="btn btn-sm btn-outline-secondary umat-group-back">\u2190 ' + backStr + '</button>' +
                                '<h4>' + escapeHtml(group.name) + '</h4>' +
                                '</div>' +
                                '<div class="umat-group-chat-members">' +
                                '<span class="badge badge-info">' + membersStr + '</span> ' +
                                '<span id="umat-group-member-list">Loading...</span>' +
                                '</div>' +
                                '<div class="umat-group-chat-messages" id="umat-group-messages"></div>' +
                                '<div class="umat-group-chat-input">' +
                                '<textarea class="form-control" id="umat-group-question" rows="2" placeholder="' + askStr + '" maxlength="1000"></textarea>' +
                                '<button class="btn btn-primary umat-group-send-btn" id="umat-group-send-btn">' + sendStr + '</button>' +
                                '</div>';

                            document.querySelector('.umat-group-back').addEventListener('click', function() {
                                showGroupList();
                            });

                            loadGroupMembers(group.id);
                            loadGroupMessages(group.id);
                            startPolling(group.id);

                            document.getElementById('umat-group-send-btn').addEventListener('click', function() {
                                sendQuestionToAI(group.id);
                            });
                            document.getElementById('umat-group-question').addEventListener('keydown', function(e) {
                                if (e.key === 'Enter' && !e.shiftKey) {
                                    e.preventDefault();
                                    sendQuestionToAI(group.id);
                                }
                            });
                        });
                    });
                });
            });
        });
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
                html += '<div class="umat-group-msg">';
                html += '<div class="umat-group-msg-header"><strong>' + escapeHtml(m.fullname) + '</strong> <span class="text-muted small">' + formatTime(m.timecreated) + '</span></div>';
                html += '<div class="umat-group-msg-q"><strong>Q:</strong> ' + escapeHtml(m.question) + '</div>';
                if (m.answer) {
                    html += '<div class="umat-group-msg-a"><strong>A:</strong> ' + escapeHtml(m.answer) + '</div>';
                }
                html += '</div>';
            });
            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        }).fail(Notification.exception);
    }

    function sendQuestionToAI(groupid) {
        var input = document.getElementById('umat-group-question');
        var btn = document.getElementById('umat-group-send-btn');
        if (!input || !btn) return;

        var question = input.value.trim();
        if (!question) return;

        btn.disabled = true;
        btn.textContent = 'Sending...';

        // First call the existing AI question endpoint
        Ajax.call([{
            methodname: 'local_umat_ai_ask_question',
            args: {courseid: courseId, question: question}
        }])[0].done(function(response) {
            var answer = response.answer || '';
            var sources = JSON.stringify(response.sources || []);

            // Then share the Q&A to the group
            sendGroupMessage(groupid, question, answer, sources).done(function() {
                input.value = '';
                loadGroupMessages(groupid);
            }).fail(Notification.exception);
        }).fail(function(error) {
            // Even if AI fails, share the question with empty answer
            sendGroupMessage(groupid, question, '', '[]').done(function() {
                input.value = '';
                loadGroupMessages(groupid);
            }).fail(Notification.exception);
        }).always(function() {
            btn.disabled = false;
            btn.textContent = 'Send to Group';
        });
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
        }, 5000);
    }

    function showGroupList() {
        var chatContainer = document.getElementById('umat-group-chat-container');
        var listContainer = document.getElementById('umat-group-list-container');
        if (chatContainer) chatContainer.innerHTML = '';
        if (listContainer) listContainer.style.display = 'block';
        document.getElementById('umat-create-group-form').style.display = 'block';
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
        currentGroupId = null;
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

    function init(courseIdParam) {
        courseId = courseIdParam;
        setupCreateGroupForm();
        refreshGroupList();
    }

    return {init: init};
});
