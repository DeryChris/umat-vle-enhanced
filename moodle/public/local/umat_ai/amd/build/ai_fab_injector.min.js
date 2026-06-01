// AMD module to inject FAB on all course pages
define(['core/ajax'], function(Ajax) {
    'use strict';

    function init(courseId, courseName) {
        // Only run once
        if (document.getElementById('umat-fab-btn')) {
            return;
        }

        // Add Material Symbols font
        var fontLink = document.createElement('link');
        fontLink.rel = 'stylesheet';
        fontLink.href = 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0';
        document.head.appendChild(fontLink);

        // FAB Button
        var fab = document.createElement('button');
        fab.id = 'umat-fab-btn';
        fab.innerHTML = '<span class="material-symbols-outlined" style="font-size:32px">smart_toy</span>';
        fab.title = 'AI Assistant';
        fab.style.cssText = 'position:fixed;bottom:80px;right:24px;z-index:9999;width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#006b2f,#00873d);color:white;border:none;box-shadow:0 6px 20px rgba(0,107,47,0.4);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:transform 0.2s;';
        fab.onmouseover = function() { this.style.transform = 'scale(1.1)'; };
        fab.onmouseout = function() { this.style.transform = 'scale(1)'; };

        // Workspace Overlay
        var ws = document.createElement('div');
        ws.id = 'umat-workspace';
        ws.style.cssText = 'display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);';

        // Panel
        var panel = document.createElement('div');
        panel.style.cssText = 'position:absolute;right:0;top:0;bottom:0;width:400px;max-width:95vw;background:#f8faf7;box-shadow:-10px 0 40px rgba(0,0,0,0.2);display:flex;flex-direction:column;transform:translateX(100%);transition:transform 0.3s ease;';

        // Header
        panel.innerHTML =
            '<div style="background:linear-gradient(135deg,#006b2f,#00873d);padding:20px;color:white;">' +
            '<div style="display:flex;align-items:center;gap:12px;">' +
            '<div style="width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;">' +
            '<span class="material-symbols-outlined" style="font-size:28px;">smart_toy</span></div>' +
            '<div style="flex:1;"><h3 style="margin:0;font-size:18px;">AI Learning Assistant</h3>' +
            '<div style="font-size:12px;opacity:0.85;">' + courseName + '</div></div>' +
            '<button id="umat-close-btn" style="background:rgba(255,255,255,0.2);border:none;color:white;width:36px;height:36px;border-radius:50%;cursor:pointer;">X</button></div></div>' +
            '<div id="umat-messages" style="flex:1;overflow-y:auto;padding:16px;background:#eff6eb;display:flex;flex-direction:column;gap:12px;min-height:200px;">' +
            '<div style="padding:12px;background:white;border-left:3px solid #006b2f;border-radius:12px;font-size:13px;">Hello! I\'m your AI assistant. Ask me anything!</div></div>' +
            '<div style="padding:16px;background:white;border-top:1px solid #dee5da;">' +
            '<div style="display:flex;gap:8px;">' +
            '<textarea id="umat-input" placeholder="Ask a question..." rows="2" style="flex:1;padding:12px;border:1px solid #dee5da;border-radius:8px;font-size:13px;resize:none;"></textarea>' +
            '<button id="umat-send-btn" style="width:46px;height:46px;border-radius:8px;background:#006b2f;color:white;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;">' +
            '<span class="material-symbols-outlined" style="font-size:20px;">send</span></button></div></div>';

        ws.appendChild(panel);
        document.body.appendChild(fab);
        document.body.appendChild(ws);

        var closeBtn = document.getElementById('umat-close-btn');
        var input = document.getElementById('umat-input');
        var sendBtn = document.getElementById('umat-send-btn');
        var messages = document.getElementById('umat-messages');

        // Open/close
        fab.addEventListener('click', function() {
            ws.style.display = 'block';
            panel.style.transform = 'translateX(0)';
            setTimeout(function(){input.focus();}, 100);
        });
        closeBtn.addEventListener('click', function() {
            panel.style.transform = 'translateX(100%)';
            setTimeout(function(){ws.style.display = 'none';}, 300);
        });
        ws.addEventListener('click', function(e) {
            if(e.target === ws) {
                panel.style.transform = 'translateX(100%)';
                setTimeout(function(){ws.style.display = 'none';}, 300);
            }
        });

        // Send function
        function sendQuestion() {
            var q = input.value.trim();
            if (!q) return;
            var userMsg = document.createElement('div');
            userMsg.style.cssText = 'align-self:flex-end;background:#d1fae5;padding:12px;border-radius:12px;max-width:85%;font-size:13px;';
            userMsg.textContent = q;
            messages.appendChild(userMsg);
            input.value = '';

            var typing = document.createElement('div');
            typing.id = 'umat-typing';
            typing.style.cssText = 'padding:12px;background:white;border-left:3px solid #006b2f;border-radius:12px;max-width:85%;font-size:13px;';
            typing.innerHTML = '<em>Thinking...</em>';
            messages.appendChild(typing);
            messages.scrollTop = messages.scrollHeight;

            Ajax.call([{
                methodname: 'local_umat_ai_ask_question',
                args: {courseid: courseId, question: q}
            }])[0].done(function(r) {
                typing.remove();
                var aiMsg = document.createElement('div');
                aiMsg.style.cssText = 'padding:12px;background:white;border-left:3px solid #006b2f;border-radius:12px;max-width:85%;font-size:13px;';
                aiMsg.textContent = r.success ? (r.answer || 'Got response') : 'Error: ' + (r.error || 'Something went wrong');
                messages.appendChild(aiMsg);
                messages.scrollTop = messages.scrollHeight;
            }).fail(function() {
                typing.remove();
                var errMsg = document.createElement('div');
                errMsg.style.cssText = 'padding:12px;background:white;border-left:3px solid #006b2f;border-radius:12px;max-width:85%;font-size:13px;';
                errMsg.textContent = 'Connection error';
                messages.appendChild(errMsg);
                messages.scrollTop = messages.scrollHeight;
            });
        }

        sendBtn.addEventListener('click', sendQuestion);
        input.addEventListener('keypress', function(e) {
            if(e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendQuestion();
            }
        });

        console.log('UMaT FAB loaded for course', courseId);
    }

    return {init: init};
});