/**
 * Focused tests for the AI Hub send-control regression.
 *
 * Run from the repository root:
 * node --test moodle/public/local/umat_ai/tests/hub_chat_test.js
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const pluginRoot = path.resolve(__dirname, '..');

class FakeElement {
    constructor() {
        this.value = '';
        this.disabled = false;
        this.scrollHeight = 40;
        this.style = {};
        this.attributes = {};
        this.listeners = {};
    }

    addEventListener(type, listener) {
        if (!this.listeners[type]) {
            this.listeners[type] = [];
        }
        this.listeners[type].push(listener);
    }

    getAttribute(name) {
        return this.attributes[name] || null;
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }

    dispatch(type, event = {}) {
        event.preventDefault = event.preventDefault || function() {
            event.defaultPrevented = true;
        };
        (this.listeners[type] || []).forEach(listener => listener.call(this, event));
        return event;
    }
}

function loadHubModule(sourcePath) {
    let hubModule;
    const source = fs.readFileSync(sourcePath, 'utf8');
    vm.runInNewContext(source, {
        console,
        define: function(dependencies, factory) {
            hubModule = factory({}, {});
        },
    }, {filename: sourcePath});
    return hubModule;
}

function setup() {
    const module = loadHubModule(path.join(pluginRoot, 'amd/src/umat_hub.js'));
    const input = new FakeElement();
    const send = new FakeElement();
    const messages = new FakeElement();
    const submissions = [];
    const control = module.bindChat(input, send, messages, question => {
        submissions.push(question);
        send.setAttribute('aria-busy', 'true');
    });
    return {module, input, send, messages, submissions, control};
}

test('non-empty trimmed input enables Send and empty input is rejected', () => {
    const {input, send, submissions} = setup();
    assert.equal(send.disabled, true);
    input.value = '   ';
    input.dispatch('input');
    send.dispatch('click');
    assert.equal(send.disabled, true);
    assert.deepEqual(submissions, []);

    input.value = '  Explain e-commerce briefly.  ';
    input.dispatch('input');
    assert.equal(send.disabled, false);
    send.dispatch('click');
    assert.deepEqual(submissions, ['Explain e-commerce briefly.']);
});

test('Enter sends once while Shift+Enter preserves the newline behavior', () => {
    const {input, send, submissions} = setup();
    input.value = 'Second message';
    input.dispatch('input');
    const enter = input.dispatch('keydown', {key: 'Enter', shiftKey: false});
    assert.equal(enter.defaultPrevented, true);
    assert.deepEqual(submissions, ['Second message']);

    send.attributes = {};
    input.value = 'First line';
    const shiftEnter = input.dispatch('keydown', {key: 'Enter', shiftKey: true});
    assert.equal(shiftEnter.defaultPrevented, undefined);
    assert.deepEqual(submissions, ['Second message']);
});

test('busy state prevents duplicate requests and completion resynchronizes Send', () => {
    const {input, send, submissions, control} = setup();
    input.value = 'Only once';
    input.dispatch('input');
    send.dispatch('click');
    send.dispatch('click');
    assert.deepEqual(submissions, ['Only once']);

    input.value = '';
    send.attributes = {};
    control.sync();
    assert.equal(send.disabled, true);
    input.value = 'Retry question';
    input.dispatch('input');
    assert.equal(send.disabled, false);
});

test('binding twice does not register competing listeners', () => {
    const {module, input, send, messages, submissions, control} = setup();
    const duplicate = module.bindChat(input, send, messages, question => submissions.push('duplicate:' + question));
    assert.equal(duplicate, control);
    assert.equal(send.listeners.click.length, 1);
    assert.equal(input.listeners.keydown.length, 1);

    input.scrollHeight = 260;
    input.dispatch('input');
    assert.equal(input.style.height, '200px');
});

test('submission appends the user message and typing indicator before constructing the request', () => {
    const source = fs.readFileSync(path.join(pluginRoot, 'amd/src/umat_hub.js'), 'utf8');
    const sendFlow = source.slice(source.indexOf('function sendQ(q)'), source.indexOf('var hubIn='));
    const appendPosition = sendFlow.indexOf('appendMsg(q,true,msgs');
    const typingPosition = sendFlow.indexOf("_umatShowTyping('hub-msgs', tid)");
    const requestPosition = sendFlow.indexOf('_umatStreamChat({');
    assert.ok(appendPosition >= 0 && appendPosition < typingPosition);
    assert.ok(typingPosition < requestPosition);
    assert.match(sendFlow, /courseid:cid,question:ctx/);
    assert.match(sendFlow, /session_key:sessKey/);
    assert.match(sendFlow, /material_ids:selMat\.map/);
});

test('source and compiled Hub assets use the rendered control IDs', () => {
    const source = fs.readFileSync(path.join(pluginRoot, 'amd/src/umat_hub.js'), 'utf8');
    const markup = fs.readFileSync(path.join(pluginRoot, 'classes/overlay_helper.php'), 'utf8');
    assert.match(markup, /id="hub-input"/);
    assert.match(markup, /id="hub-send"[^>]*type="button"/);
    assert.doesNotMatch(source, /hub-chat-(?:input|send)/);

    const buildPath = path.join(pluginRoot, 'amd/build/umat_hub.min.js');
    assert.equal(fs.existsSync(buildPath), true);
    const build = fs.readFileSync(buildPath, 'utf8');
    assert.match(build, /hub-input/);
    assert.match(build, /hub-send/);
    assert.doesNotMatch(build, /hub-chat-(?:input|send)/);
});

test('compiled shared asset contains retry cleanup and premature EOF handling', () => {
    const buildPath = path.join(pluginRoot, 'amd/build/umatshared.min.js');
    assert.equal(fs.existsSync(buildPath), true);
    const build = fs.readFileSync(buildPath, 'utf8');
    assert.match(build, /removeEventListener\("click"/);
    assert.match(build, /response stream ended before completion/i);
});

test('generated source maps match the current AMD sources', () => {
    ['umat_hub', 'umatshared'].forEach(moduleName => {
        const source = fs.readFileSync(path.join(pluginRoot, 'amd/src/' + moduleName + '.js'), 'utf8');
        const map = JSON.parse(fs.readFileSync(
            path.join(pluginRoot, 'amd/build/' + moduleName + '.min.js.map'),
            'utf8'
        ));
        assert.equal(map.sourcesContent[0], source);
    });
});

test('shared failure UI is visible, retryable, and does not append another user message', () => {
    const source = fs.readFileSync(path.join(pluginRoot, 'amd/src/umatshared.js'), 'utf8');
    const failureStart = source.indexOf('function showFailureUI');
    const failureEnd = source.indexOf('var body = new FormData()', failureStart);
    const failureFlow = source.slice(failureStart, failureEnd);
    assert.match(failureFlow, /The AI could not respond\. Please try again\./);
    assert.match(failureFlow, /umat-retry-btn/);
    assert.match(failureFlow, /_umatStreamChat\(opts\)/);
    assert.doesNotMatch(failureFlow, /AppendUser|appendMsg\([^,]+,\s*true/);
});
