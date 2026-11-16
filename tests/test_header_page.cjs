// Synthetic tests for the offline page's actual script. No browser/network/real keys.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const html = fs.readFileSync('tools/application-password-header.html', 'utf8');
const elements = {};
const context = {
  document: {getElementById: id => elements[id] ||= {value:'', type:'password', textContent:'',select(){this.selected=true;}}},
  window: {addEventListener(name, fn){assert.equal(name,'pagehide');this.hide=fn;}},
  btoa: s => Buffer.from(s,'binary').toString('base64'),
};
vm.createContext(context);
vm.runInContext(html.match(/<script>([\s\S]*?)<\/script>/)[1], context);
assert.equal(context.buildAuthorization('tkd-cloud','abcd efgh ijkl mnop qrst uvwx'), 'Basic '+Buffer.from('tkd-cloud:abcdefghijklmnopqrstuvwx').toString('base64'));
for (const [u,p] of [['bad:user','abcdefghijklmnopqrstuvwx'],['tkd-cloud','main-password'],['tkd-cloud','<script>']]) assert.throws(()=>context.buildAuthorization(u,p));
context.document.getElementById('username').value='tkd-cloud'; context.document.getElementById('password').value='abcdefghijklmnopqrstuvwx';
elements.generate.onclick(); assert.ok(elements.output.value.startsWith('Basic ')); assert.equal(elements.password.value,'');
elements.select.onclick(); assert.equal(elements.output.type,'text'); assert.equal(elements.output.selected,true);
context.window.hide(); assert.equal(elements.output.value,''); assert.equal(elements.output.type,'password');
assert.ok(html.includes("connect-src 'none'")); assert.ok(html.includes("form-action 'none'"));
assert.ok(!/fetch\(|XMLHttpRequest|localStorage|sessionStorage|navigator\.clipboard|https?:\/\//.test(html));
console.log('PASS offline page synthetic encoding, validation, clear, no network/storage checks');
