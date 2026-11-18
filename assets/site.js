const menu=document.querySelector('.menu-btn');
const links=document.querySelector('.nav-links');
if(menu&&links){menu.addEventListener('click',()=>{const open=links.classList.toggle('open');menu.setAttribute('aria-expanded',String(open));});document.querySelectorAll('.nav-links a').forEach(a=>a.addEventListener('click',()=>{links.classList.remove('open');menu.setAttribute('aria-expanded','false');}));}
const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting)e.target.classList.add('in')}),{threshold:.12});
document.querySelectorAll('.reveal').forEach(x=>io.observe(x));

const quoteForm=document.querySelector('#quote-form');
if(quoteForm){
  const formLoadedAt=Date.now();
  const dateInput=quoteForm.querySelector('input[name="date"]');
  const validationSummary=quoteForm.querySelector('.validation-summary');
  const successOverlay=document.querySelector('#form-success-overlay');
  const successClose=document.querySelector('#success-close');

  const localToday=()=>{
    const d=new Date();
    const offset=d.getTimezoneOffset()*60000;
    return new Date(d-offset).toISOString().slice(0,10);
  };
  const setDateMinimum=()=>{if(dateInput)dateInput.min=localToday();};
  setDateMinimum();

  const requiredFields=[
    ['name','Full name'],['email','Email'],['phone','Phone'],['service','Service required'],
    ['date','Preferred date'],['origin','Collection / origin'],['destination','Delivery / destination'],
    ['requirements','Cargo / requirements'],['privacy_consent','Privacy consent']
  ];

  const fieldValid=(el)=>{
    if(el.type==='checkbox')return el.checked;
    const value=(el.value||'').trim();
    if(!value)return false;
    if(el.type==='email')return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    if(el.type==='date')return value>=localToday();
    if(el.name==='requirements'){
      const words=value.split(/\s+/).filter(Boolean);
      return value.length>=15 && words.length>=3;
    }
    return true;
  };

  const clearFieldError=(el)=>{el.closest('.field')?.classList.remove('has-error');el.removeAttribute('aria-invalid');};
  const markFieldError=(el)=>{el.closest('.field')?.classList.add('has-error');el.setAttribute('aria-invalid','true');};

  const validateAll=()=>{
    setDateMinimum();
    const missing=[];
    requiredFields.forEach(([name,label])=>{
      const el=quoteForm.elements[name];
      if(!el)return;
      if(fieldValid(el)){
        clearFieldError(el);
      }else{
        markFieldError(el);
        missing.push(name==='requirements' ? 'Cargo / requirements (please provide at least one complete sentence)' : label);
      }
    });
    if(validationSummary){
      if(missing.length){
        validationSummary.innerHTML=`<strong>Please complete the highlighted fields.</strong>${missing.join(', ')}.`;
        validationSummary.classList.add('show');
      }else{
        validationSummary.textContent='';
        validationSummary.classList.remove('show');
      }
    }
    return missing.length===0;
  };

  requiredFields.forEach(([name])=>{
    const el=quoteForm.elements[name];
    if(!el)return;
    const evt=el.tagName==='SELECT'||el.type==='date'?'change':'input';
    el.addEventListener(evt,()=>{
      if(fieldValid(el))clearFieldError(el);
      if(validationSummary?.classList.contains('show'))validateAll();
    });
  });

  successClose?.addEventListener('click',()=>successOverlay?.classList.remove('show'));
  successOverlay?.addEventListener('click',e=>{if(e.target===successOverlay)successOverlay.classList.remove('show');});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')successOverlay?.classList.remove('show');});

  quoteForm.addEventListener('submit',async e=>{
    e.preventDefault();
    if(!validateAll()){
      const first=quoteForm.querySelector('.field.has-error input,.field.has-error select,.field.has-error textarea');
      first?.focus();
      validationSummary?.scrollIntoView({behavior:'smooth',block:'center'});
      return;
    }

    const status=quoteForm.querySelector('.form-status');
    const button=quoteForm.querySelector('button[type="submit"]');
    const label=button?.querySelector('.submit-label');
    const fd=new FormData(quoteForm);

    if((fd.get('_honey')||'').toString().trim()){
      quoteForm.reset();setDateMinimum();
      return;
    }

    const payload={
      name:(fd.get('name')||'').toString(),
      company:(fd.get('company')||'').toString(),
      email:(fd.get('email')||'').toString(),
      phone:(fd.get('phone')||'').toString(),
      service:(fd.get('service')||'').toString(),
      date:(fd.get('date')||'').toString(),
      origin:(fd.get('origin')||'').toString(),
      destination:(fd.get('destination')||'').toString(),
      requirements:(fd.get('requirements')||'').toString(),
      privacy_consent:fd.get('privacy_consent')==='on' ? 'yes' : '',
      _honey:(fd.get('_honey')||'').toString(),
      _t:Date.now()-formLoadedAt
    };

    if(status){status.textContent='Sending your enquiry…';status.className='form-status sending';}
    if(button)button.disabled=true;
    if(label)label.textContent='Sending…';

    try{
      const response=await fetch(quoteForm.action,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
      const result=await response.json().catch(()=>({}));
      if(!response.ok||result.success===false)throw new Error(result.message||'Submission failed');
      quoteForm.reset();setDateMinimum();
      quoteForm.querySelectorAll('.field.has-error').forEach(x=>x.classList.remove('has-error'));
      if(validationSummary){validationSummary.textContent='';validationSummary.classList.remove('show');}
      if(status){status.textContent='Your request was sent successfully.';status.className='form-status success';}
      successOverlay?.classList.add('show');
      successClose?.focus();
    }catch(err){
      if(status){status.textContent='We could not send your enquiry right now. Please email info@zidedele.co.za or call 035 797 4025.';status.className='form-status error';}
    }finally{
      if(button)button.disabled=false;
      if(label)label.textContent='Send enquiry →';
    }
  });
}
