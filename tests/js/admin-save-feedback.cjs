const fs = require('fs');
const vm = require('vm');
const path = require('path');
const assert = require('assert/strict');
(async () => {
 const controls=[]; const rows=[]; const ids={'wmcp-save-status':{textContent:'',className:''},'wmcp-count-shown':{},'wmcp-count-anonymous':{}};
 for(let n=0;n<2;n++) {
  const eye={disabled:false,dataset:{visible:'1',ability:'tool'+n},setAttribute(){},firstElementChild:{style:{}},addEventListener(k,f){this.click=f;}};
  const anon={disabled:false,checked:true,dataset:{ability:'tool'+n},addEventListener(k,f){this.change=f;}};
  const reason={classList:{toggle(){}}};
  const row={querySelector(s){return {'.wmcp-eye':eye,'.wmcp-anon':anon,'.wmcp-reason':reason}[s]},classList:{toggle(){}},setAttribute(){},removeAttribute(){}};
  eye.closest=anon.closest=()=>row;rows.push({eye,anon});controls.push(eye,anon);
 }
 let resolve,reject,calls=0;
 const document={querySelectorAll(s){return s==='.wmcp-eye'?rows.map(r=>r.eye):s==='.wmcp-anon'?rows.map(r=>r.anon):controls;},getElementById(id){return ids[id]}};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../includes/class-admin-page.php'), 'utf8').split('<script>')[1].split('</script>')[0].replace(/<\?php[\s\S]*?\?>/g, '{}'),{document,window:{fetch(){calls++;return new Promise((a,b)=>{resolve=a;reject=b;});}},Array,Promise});
 rows[0].eye.click();assert(controls.every(c=>c.disabled));rows[1].eye.click();assert.equal(calls,1);
 resolve({ok:true,json:async()=>({visible:false,anonymous:false,reason:'hidden',count_shown:1,count_anonymous:1})});await new Promise(setImmediate);
 assert.equal(rows[0].eye.disabled,false);assert.equal(rows[0].anon.disabled,true);assert.equal(rows[1].anon.disabled,false);assert.equal(ids['wmcp-count-shown'].textContent,1);
 rows[1].anon.checked=false;rows[1].anon.change();reject(new Error('offline'));await new Promise(setImmediate);
 assert.equal(rows[1].anon.checked,true);assert.equal(rows[0].anon.disabled,true);assert.equal(rows[1].anon.disabled,false);assert(ids['wmcp-save-status'].className.includes('notice-error'));
 console.log('PASS: serialization, success counts, hidden controls, failure rollback and recovery');
})().catch(e=>{console.error(e);process.exit(1)});
