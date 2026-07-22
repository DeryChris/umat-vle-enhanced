/**
 * Authenticated browser check for the AI Hub chat.
 *
 * Required environment variables:
 * MOODLE_TEST_USER, MOODLE_TEST_PASSWORD
 *
 * Optional environment variable:
 * MOODLE_URL (defaults to http://localhost)
 */
'use strict';

const assert = require('node:assert/strict');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const baseUrl = (process.env.MOODLE_URL || 'http://localhost').replace(/\/$/, '');
const username = process.env.MOODLE_TEST_USER;
const password = process.env.MOODLE_TEST_PASSWORD;

if (!username || !password) {
    throw new Error('MOODLE_TEST_USER and MOODLE_TEST_PASSWORD are required.');
}

function multipartField(body, name) {
    const match = (body || '').match(new RegExp('name="' + name + '"\\r?\\n\\r?\\n([^\\r\\n]*)'));
    return match ? match[1] : null;
}

async function waitForCompletedAnswer(page, previousCount) {
    const handle = await page.waitForFunction(expected => {
        const answers = Array.from(document.querySelectorAll('#hub-msgs [data-msg-role="ai"]'));
        if (answers.length <= expected) {
            return false;
        }
        const latest = answers[answers.length - 1];
        const content = latest.querySelector('.umat-ai-stream-content');
        if (!content || !content.textContent.trim() || latest.classList.contains('umat-msg-streaming')) {
            return false;
        }
        return {
            error: Boolean(latest.querySelector('.umat-ai-error-text')),
            text: content.textContent.trim(),
        };
    }, previousCount, {timeout: 180000});
    const result = await handle.jsonValue();
    assert.equal(result.error, false, 'Expected an assistant answer but received: ' + result.text);
    return result.text;
}

async function main() {
    const browser = await chromium.launch({headless: true});
    const context = await browser.newContext({viewport: {width: 1440, height: 900}});
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(120000);
    const chatRequests = [];
    const chatResponses = [];
    const consoleErrors = [];
    const checkpoints = [];

    page.on('request', request => {
        if (request.url().includes('/local/umat_ai/chat_stream.php')) {
            chatRequests.push({
                method: request.method(),
                postData: request.postData() || '',
            });
        }
    });
    page.on('response', response => {
        if (response.url().includes('/local/umat_ai/chat_stream.php')) {
            const record = {status: response.status(), body: ''};
            chatResponses.push(record);
            response.text().then(body => {
                record.body = body;
            }).catch(error => {
                record.body = '[unavailable: ' + error.message + ']';
            });
        }
    });
    page.on('console', message => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', error => consoleErrors.push(error.message));

    try {
        await page.goto(baseUrl + '/login/index.php', {waitUntil: 'commit'});
        await page.locator('#username').fill(username);
        await page.locator('#password').fill(password);
        try {
            await Promise.all([
                page.waitForURL(url => !url.pathname.includes('/login/'), {
                    waitUntil: 'domcontentloaded',
                    timeout: 60000,
                }),
                page.locator('#loginbtn').click(),
            ]);
        } catch (error) {
            await page.waitForLoadState('domcontentloaded', {timeout: 30000}).catch(() => {});
            if (new URL(page.url()).pathname.includes('/login/')) {
                const loginError = await page.locator('.loginerrors, .alert-danger').allTextContents();
                throw new Error('Moodle login failed: ' + (loginError.join(' ').trim() || error.message));
            }
        }
        checkpoints.push('Authenticated as disposable student');

        await page.goto(baseUrl + '/my/', {waitUntil: 'commit'});
        await page.locator('#hub-fab').waitFor({state: 'visible', timeout: 30000});
        await page.waitForFunction(() => Boolean(document.getElementById('hub-send')?._umatChatControl), null, {
            timeout: 30000,
        });
        await page.locator('#hub-fab').click();
        await page.locator('#hub-ov').waitFor({state: 'visible'});
        await page.locator('#hub-sb [data-hp="hub-tutor"]').click();
        await page.locator('#hub-tutor').waitFor({state: 'visible'});
        checkpoints.push('Opened AI Hub chat');

        const input = page.locator('#hub-input');
        const send = page.locator('#hub-send');
        assert.equal(await send.isDisabled(), true, 'Send must start disabled for empty input');
        await input.fill('Explain e-commerce briefly.');
        assert.equal(await send.isEnabled(), true, 'Typing non-empty text must enable Send');
        checkpoints.push('Non-empty input enabled Send');

        const firstUserCount = await page.locator('#hub-msgs [data-msg-role="user"]').count();
        const firstAiCount = await page.locator('#hub-msgs [data-msg-role="ai"]').count();
        const firstRequestCount = chatRequests.length;
        await send.click();
        await page.waitForFunction(expected =>
            document.querySelectorAll('#hub-msgs [data-msg-role="user"]').length === expected + 1,
        firstUserCount);
        assert.match(
            await page.locator('#hub-msgs [data-msg-role="user"] .umat-bubble-user').last().innerText(),
            /Explain e-commerce briefly\./
        );
        await page.locator('#hub-msgs .umat-typing-wrap').waitFor({state: 'visible', timeout: 3000});
        assert.equal(chatRequests.length, firstRequestCount + 1, 'Click must send one request');
        checkpoints.push('Click appended user message, typing indicator, and one request');

        const firstRequest = chatRequests[firstRequestCount];
        assert.equal(firstRequest.method, 'POST');
        assert.equal(multipartField(firstRequest.postData, 'question'), 'Explain e-commerce briefly.');
        assert.ok(Number(multipartField(firstRequest.postData, 'courseid')) > 0, 'Course ID must be present');
        assert.ok(multipartField(firstRequest.postData, 'sesskey'), 'Sesskey must be present');
        assert.ok(multipartField(firstRequest.postData, 'session_key'), 'Conversation ID must be present');
        await waitForCompletedAnswer(page, firstAiCount);
        assert.equal(chatRequests.length, firstRequestCount + 1, 'First message must send exactly one request');
        assert.equal(await page.locator('#hub-msgs .umat-typing-wrap').count(), 0);
        checkpoints.push('Backend response rendered and typing indicator was removed');

        const secondUserCount = await page.locator('#hub-msgs [data-msg-role="user"]').count();
        const secondAiCount = await page.locator('#hub-msgs [data-msg-role="ai"]').count();
        const secondRequestCount = chatRequests.length;
        await input.fill('Give one practical example.');
        await input.press('Enter');
        await page.waitForFunction(expected =>
            document.querySelectorAll('#hub-msgs [data-msg-role="user"]').length === expected + 1,
        secondUserCount);
        assert.equal(chatRequests.length, secondRequestCount + 1, 'Enter must send one request');
        await waitForCompletedAnswer(page, secondAiCount);
        assert.equal(chatRequests.length, secondRequestCount + 1, 'Second message must send exactly one request');
        await page.waitForFunction(() => !document.querySelector('#hub-msgs .umat-typing-wrap'));
        checkpoints.push('Enter sent the second message once');

        const shiftRequestCount = chatRequests.length;
        await input.fill('First line');
        await input.press('Shift+Enter');
        assert.equal(await input.inputValue(), 'First line\n');
        assert.equal(chatRequests.length, shiftRequestCount, 'Shift+Enter must not send');
        checkpoints.push('Shift+Enter inserted a newline without sending');

        const failureBody = 'event: error\n'
            + 'data: {"message":"Simulated failure","error":"service_error"}\n\n';
        const failureHandler = route => route.fulfill({
            status: 200,
            contentType: 'text/event-stream',
            body: failureBody,
        });
        await page.route('**/local/umat_ai/chat_stream.php', failureHandler);
        const failureUserCount = await page.locator('#hub-msgs [data-msg-role="user"]').count();
        const failureRequestCount = chatRequests.length;
        await input.fill('Trigger a simulated failure.');
        await send.click();
        await page.locator('#hub-msgs .umat-ai-error-text').last().waitFor({state: 'visible'});
        assert.equal(
            await page.locator('#hub-msgs .umat-ai-error-text').last().innerText(),
            'The AI could not respond. Please try again.'
        );
        assert.equal(chatRequests.length, failureRequestCount + 1);
        assert.equal(await page.locator('#hub-msgs [data-msg-role="user"]').count(), failureUserCount + 1);
        await page.waitForFunction(() => !document.querySelector('#hub-msgs .umat-typing-wrap'), null, {timeout: 5000});
        const failureTypingIds = await page.locator('#hub-msgs .umat-typing-wrap').evaluateAll(
            elements => elements.map(element => element.id)
        );
        assert.deepEqual(failureTypingIds, [], 'Typing indicators after failure: ' + failureTypingIds.join(', '));

        const oldRetry = await page.locator('#hub-msgs .umat-retry-btn').last().elementHandle();
        await page.locator('#hub-msgs .umat-retry-btn').last().click();
        await page.waitForFunction(element => !element.isConnected, oldRetry);
        await page.locator('#hub-msgs .umat-retry-btn').last().waitFor({state: 'visible'});
        assert.equal(chatRequests.length, failureRequestCount + 2, 'Retry must send one new request');
        assert.equal(
            await page.locator('#hub-msgs [data-msg-role="user"]').count(),
            failureUserCount + 1,
            'Retry must not duplicate the user message'
        );
        await page.unroute('**/local/umat_ai/chat_stream.php', failureHandler);
        await input.fill('Send is usable again.');
        assert.equal(await send.isEnabled(), true);
        checkpoints.push('Failure UI, Retry, preserved message, and Send recovery passed');

        await page.keyboard.press('Escape');
        await page.setViewportSize({width: 1024, height: 720});
        await page.locator('#hub-fab').click();
        await page.locator('#hub-sb [data-hp="hub-tutor"]').click();
        const reopenBody = 'event: meta\n'
            + 'data: {"remaining":9}\n\n'
            + 'event: token\n'
            + 'data: {"text":"Reopen works."}\n\n'
            + 'event: done\n'
            + 'data: {"answer":"Reopen works.","sources":[]}\n\n';
        const reopenHandler = route => route.fulfill({
            status: 200,
            contentType: 'text/event-stream',
            body: reopenBody,
        });
        await page.route('**/local/umat_ai/chat_stream.php', reopenHandler);
        const reopenUserCount = await page.locator('#hub-msgs [data-msg-role="user"]').count();
        const reopenAiCount = await page.locator('#hub-msgs [data-msg-role="ai"]').count();
        const reopenRequestCount = chatRequests.length;
        await input.fill('After resize and reopen.');
        assert.equal(await send.isEnabled(), true);
        await send.click();
        await page.waitForFunction(expected =>
            document.querySelectorAll('#hub-msgs [data-msg-role="user"]').length === expected + 1,
        reopenUserCount);
        await waitForCompletedAnswer(page, reopenAiCount);
        assert.equal(chatRequests.length, reopenRequestCount + 1, 'Reopened chat must send exactly one request');
        await page.unroute('**/local/umat_ai/chat_stream.php', reopenHandler);
        checkpoints.push('Resize and reopen preserved Send behavior');

        const relatedErrors = consoleErrors.filter(message =>
            /\[umat\]|umat_hub|hub chat|chat_stream|_umatChatControl|TypeError/i.test(message)
        );
        assert.deepEqual(relatedErrors, [], 'Related browser console errors: ' + relatedErrors.join(' | '));
        checkpoints.push('No related browser console errors');
        assert.equal(chatRequests.length, 5, 'Expected two real sends, failure, Retry, and reopen request');

        console.log(JSON.stringify({
            status: 'PASS',
            chatRequestCount: chatRequests.length,
            requestQuestions: chatRequests.map(request => multipartField(request.postData, 'question')),
            checkpoints,
            consoleErrors,
        }, null, 2));
    } catch (error) {
        console.error(JSON.stringify({
            status: 'FAIL',
            checkpoints,
            chatRequestCount: chatRequests.length,
            requestQuestions: chatRequests.map(request => multipartField(request.postData, 'question')),
            chatResponses,
            consoleErrors,
        }, null, 2));
        throw error;
    } finally {
        await browser.close();
    }
}

main().catch(error => {
    console.error(error.stack || error.message);
    process.exitCode = 1;
});
