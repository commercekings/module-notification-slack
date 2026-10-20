'use strict';

/**
 * The "Send test message" button: one POST with the form key and the scope's website, the answer shown next to it,
 * the button disabled while it waits.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = path.join(__dirname, '../../view/adminhtml/web/js/test-button.js');

function element(id) {
    return {
        id, textValue: '', styles: {}, props: {}, handlers: {},
        text(value) { if (value === undefined) { return this.textValue; } this.textValue = value; return this; },
        css(name, value) { this.styles[name] = value; return this; },
        prop(name, value) { this.props[name] = value; return this; },
        on(event, handler) { this.handlers[event] = handler; return this; }
    };
}

function load() {
    const nodes = {button: element('button'), '#field_result': element('field_result')};
    const requests = [];
    const $ = (target) => (typeof target === 'string' ? nodes[target] : target);
    $.ajax = (options) => {
        const request = {options, callbacks: {}};
        const chain = {
            done(fn) { request.callbacks.done = fn; return chain; },
            fail(fn) { request.callbacks.fail = fn; return chain; },
            always(fn) { request.callbacks.always = fn; return chain; }
        };
        requests.push(request);
        return chain;
    };
    let factory;
    const context = {window: {FORM_KEY: 'fk1'}, define: (deps, fn) => { factory = fn; }};
    context.window.window = context.window;
    vm.runInNewContext(fs.readFileSync(source, 'utf8'), {...context, window: context.window}, {filename: source});
    factory($)({url: '/admin/ck_notification_slack/test/send/', website: '2', resultId: 'field_result',
        sending: 'Sending…', failed: 'The request failed'}, nodes.button);
    return {nodes, requests};
}

test('a click posts the form key and the website, and disables the button while it waits', () => {
    const {nodes, requests} = load();
    nodes.button.handlers.click();
    assert.strictEqual(requests.length, 1);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(requests[0].options)), {url: '/admin/ck_notification_slack/test/send/', type: 'POST',
        dataType: 'json', data: {form_key: 'fk1', website: '2'}});
    assert.strictEqual(nodes.button.props.disabled, true);
    assert.strictEqual(nodes['#field_result'].text(), 'Sending…');
});

test('the answer is shown, green when posted and red when not, and the button comes back', () => {
    const {nodes, requests} = load();
    nodes.button.handlers.click();
    requests[0].callbacks.done({success: true, message: 'Posted to C0123ABCD.'});
    requests[0].callbacks.always();
    assert.strictEqual(nodes['#field_result'].text(), 'Posted to C0123ABCD.');
    assert.strictEqual(nodes['#field_result'].styles.color, '#185b00');
    assert.strictEqual(nodes.button.props.disabled, false);
    nodes.button.handlers.click();
    requests[1].callbacks.done({success: false, message: 'Slack refused the test message: …'});
    assert.strictEqual(nodes['#field_result'].styles.color, '#e22626');
    requests[1].callbacks.done(null);
    assert.strictEqual(nodes['#field_result'].text(), 'The request failed');
});

test('a failed request says so', () => {
    const {nodes, requests} = load();
    nodes.button.handlers.click();
    requests[0].callbacks.fail();
    assert.strictEqual(nodes['#field_result'].text(), 'The request failed');
    assert.strictEqual(nodes['#field_result'].styles.color, '#e22626');
});
