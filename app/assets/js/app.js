(function(){
  var b=document.body;
  var mb=document.getElementById('menu-btn'); if(mb) mb.addEventListener('click',function(){b.classList.toggle('drawer'); mb.setAttribute('aria-expanded',b.classList.contains('drawer'));});
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') b.classList.remove('drawer'); });
  // live countdowns for cancellable transfers
  var cds=[].slice.call(document.querySelectorAll('[data-countdown]'));
  function tick(){ var now=Date.now(); cds.forEach(function(el){ var left=Math.max(0,Math.floor((parseInt(el.getAttribute('data-countdown'),10)*1000-now)/1000)); var m=Math.floor(left/60), s=left%60; el.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s; if(left===0 && !el.dataset.done){ el.dataset.done=1; el.textContent=el.getAttribute('data-done-text')||'00:00'; setTimeout(function(){location.reload();},1500);} }); }
  if(cds.length){ tick(); setInterval(tick,1000); }
  // QR codes (offline, bundled library)
  [].slice.call(document.querySelectorAll('[data-qr]')).forEach(function(el){ try{ var qr=qrcode(0,'M'); qr.addData(el.getAttribute('data-qr')); qr.make(); el.innerHTML=qr.createSvgTag({cellSize:4,margin:0,scalable:true}); el.setAttribute('role','img'); el.setAttribute('aria-label','QR code'); }catch(e){ el.textContent='QR unavailable'; } });
  // prevent double submits: disable submit button after first click (server also enforces idempotency)
  [].slice.call(document.querySelectorAll('form[data-once]')).forEach(function(f){ f.addEventListener('submit',function(){ var s=f.querySelector('button[type=submit]'); if(s){ setTimeout(function(){s.disabled=true;},0);} }); });
  // split-type toggle in group expense form
  var st=document.getElementById('split_type'); if(st){ var upd=function(){ [].slice.call(document.querySelectorAll('[data-split]')).forEach(function(x){ x.style.display=(x.getAttribute('data-split')===st.value||x.getAttribute('data-split')==='all')?'':'none'; }); }; st.addEventListener('change',upd); upd(); }
  // live line-item total for invoices
  [].slice.call(document.querySelectorAll('[data-copy]')).forEach(function(btn){ btn.addEventListener('click',function(){ var t=document.getElementById(btn.getAttribute('data-copy')); if(navigator.clipboard){ navigator.clipboard.writeText(t.value||t.textContent); btn.textContent='Copied'; } }); });
})();
