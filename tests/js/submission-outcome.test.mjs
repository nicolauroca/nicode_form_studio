import test from 'node:test';
import assert from 'node:assert/strict';
import {FormInstance} from '../../src/com_nicode_form_studio/media/js/form-instance.js';

// Exercise the real submit method while keeping DOM rendering outside this test.
function fixture(t, fetchResponse) {
  const NativeFormData = globalThis.FormData;
  t.mock.method(globalThis, 'fetch', fetchResponse);
  t.mock.method(globalThis, 'FormData', class extends NativeFormData {
    constructor() { super(); this.set('attempt', 'original-attempt'); }
  });
  const attributes = new Map(), button = {disabled:false};
  let focused = 0;
  const instance = {
    pending:false, validate:()=>true, remoteOptions:{pending:false,failed:false},
    message:(key, fallback)=>fallback,
    result:{textContent:'', focus:()=>{ focused++; }},
    form:{dataset:{}, action:'/submit', querySelector:()=>button,
      setAttribute:(key,value)=>attributes.set(key,value), removeAttribute:key=>attributes.delete(key),
      reset:()=>assert.fail('Rejected response reset entered values'),
      elements:{namedItem:()=>assert.fail('Rejected response replaced the attempt')},
    },
    showErrors:()=>assert.fail('General refusal was treated as field errors'),
  };
  return {instance, button, attributes, focused:()=>focused};
}

test('server refusals preserve localized messages, input and attempt while releasing submit state', async t => {
  for (const [status, category, message] of [[403,'session_error','Su sesión ha caducado.'], [413,'request_too_large','Tu respuesta supera el límite y no se ha guardado.'], [429,'rate_limited','Espere antes de enviar otra respuesta.'], [422,'anti_spam_rejected','No se pudo validar este envío.']]) {
    await t.test(category, async t => {
      const f = fixture(t, async (_url, options) => {
        assert.equal(options.body.get('attempt'),'original-attempt');
        return {ok:false, status, json:async()=>({accepted:false,category,message,errors:[],behavior:'reset',next_attempt:'must-not-apply'})};
      });
      await FormInstance.prototype.submit.call(f.instance);
      assert.equal(f.instance.result.textContent,message);
      assert.equal(f.focused(),1);
      assert.equal(f.instance.pending,false); assert.equal(f.button.disabled,false);
      assert.equal(f.attributes.has('aria-busy'),false);
    });
  }
});

test('pending AJAX announces progress, prevents duplicate submission and focuses confirmed success', async t => {
  let release, calls=0;
  const f=fixture(t,()=>{ calls++; return new Promise(resolve=>{release=resolve;}); });
  const pending=FormInstance.prototype.submit.call(f.instance);
  assert.equal(calls,1); assert.equal(f.instance.pending,true); assert.equal(f.button.disabled,true);
  assert.equal(f.attributes.get('aria-busy'),'true'); assert.equal(f.instance.result.textContent,'Submitting…');
  await FormInstance.prototype.submit.call(f.instance);
  assert.equal(calls,1); assert.equal(f.focused(),0);
  release({ok:true,json:async()=>({accepted:true,message:'Recibido',reference:'safe-reference',behavior:'keep',errors:[]})});
  await pending;
  assert.equal(f.instance.result.textContent,'Recibido safe-reference'); assert.equal(f.focused(),1);
  assert.equal(f.instance.pending,false); assert.equal(f.button.disabled,false); assert.equal(f.attributes.has('aria-busy'),false);
});

test('timeout preserves the attempt and values, releases busy state and keeps provider failure blocking submit', async t => {
  let expire, cleared=false, observedSignal;
  t.mock.method(globalThis,'setTimeout',(callback,delay)=>{ assert.equal(delay,30000); expire=callback; return 123; });
  t.mock.method(globalThis,'clearTimeout',id=>{ assert.equal(id,123); cleared=true; });
  const f=fixture(t,(_url,options)=>{
    observedSignal=options.signal;
    return new Promise((_resolve,reject)=>options.signal.addEventListener('abort',()=>reject(new DOMException('Timed out','AbortError')),{once:true}));
  });
  f.instance.remoteOptions.failed=true;
  const pending=FormInstance.prototype.submit.call(f.instance); expire(); await pending;
  assert.equal(observedSignal.aborted,true); assert.equal(cleared,true);
  assert.equal(f.instance.result.textContent,'The response is delayed. Retrying will use the same submission reference.');
  assert.equal(f.focused(),1); assert.equal(f.instance.pending,false); assert.equal(f.attributes.has('aria-busy'),false);
  assert.equal(f.button.disabled,true);
});

test('server field errors delegate to the error summary while preserving input and clearing busy state', async t => {
  const f=fixture(t,async()=>({ok:false,json:async()=>({accepted:false,message:'Revisa el campo',errors:{answer:['Obligatorio']},behavior:'reset'})}));
  let errors;
  f.instance.showErrors=value=>{errors=value;};
  await FormInstance.prototype.submit.call(f.instance);
  assert.deepEqual(errors,{answer:['Obligatorio']}); assert.equal(f.focused(),0);
  assert.equal(f.instance.result.textContent,'Revisa el campo'); assert.equal(f.instance.pending,false);
  assert.equal(f.attributes.has('aria-busy'),false); assert.equal(f.button.disabled,false);
});

test('unreadable transport response retains the unconfirmed outcome instead of inventing success', async t => {
  const f = fixture(t, async()=>({ok:false,json:async()=>{ throw new SyntaxError('HTML proxy response'); }}));
  await FormInstance.prototype.submit.call(f.instance);
  assert.equal(f.instance.result.textContent,'The response could not be confirmed. Please retry.');
  assert.equal(f.focused(),1); assert.equal(f.button.disabled,false); assert.equal(f.instance.pending,false);
});

test('a server rejection message is assigned as literal text and never as HTML', async t => {
  const message = '<img src=x onerror=alert(1)> refused';
  const f = fixture(t, async()=>({ok:false,json:async()=>({accepted:false,message,errors:[]})}));
  Object.defineProperty(f.instance.result,'innerHTML',{set:()=>assert.fail('Unsafe HTML interpretation')});
  await FormInstance.prototype.submit.call(f.instance);
  assert.equal(f.instance.result.textContent,message);
});

test('success reset preserves configured definition fields independently in repeated rows and replaces attempt', async t => {
  const ids = ['11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222','33333333-3333-4333-8333-333333333333','44444444-4444-4444-8444-444444444444','55555555-5555-4555-8555-555555555555'];
  const [group,one,two,kept,cleared] = ids;
  const a = `${group}/${one}/${kept}`, b = `${group}/${two}/${kept}`, c = `${group}/${one}/${cleared}`;
  const f = fixture(t, async()=>({ok:true,json:async()=>({accepted:true,behavior:'reset',preserve:[kept],next_attempt:'next-attempt',errors:[]})}));
  const values = new Map([[a,'first'],[b,''],[c,'discard']]);
  f.instance.spec = {fields:[a,b,c].map(uuid=>({uuid}))};
  f.instance.controls = new Map([...values].map(([key,value])=>[key,[{type:'text',value}]]));
  f.instance.read = field=>f.instance.controls.get(field.uuid)[0].value;
  f.instance.form.reset = ()=>{ for (const controls of f.instance.controls.values()) controls[0].value = 'initial'; };
  const attempt = {value:'old'}; f.instance.form.elements.namedItem = name=>{ assert.equal(name,'attempt'); return attempt; };
  let refreshed = false; f.instance.refresh = ()=>{ refreshed=true; };
  await FormInstance.prototype.submit.call(f.instance);
  assert.equal(f.instance.controls.get(a)[0].value,'first');
  assert.equal(f.instance.controls.get(b)[0].value,'');
  assert.equal(f.instance.controls.get(c)[0].value,'initial');
  assert.equal(attempt.value,'next-attempt'); assert.equal(refreshed,true);
  assert.equal(f.instance.currentStep,0); assert.deepEqual(f.instance.steps,[]);
});
