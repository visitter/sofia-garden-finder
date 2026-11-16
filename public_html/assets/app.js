const $=id=>document.getElementById(id);
const escapeHTML=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const categories={general:'Общ ред',chronic:'Хронични заболявания',sop:'СОП'};
let rows=[],details={},page=1,filtered=[],refreshedAt=null,refreshMeta=null,busy=false;
const pageSize=12;
const formatTime=d=>new Intl.DateTimeFormat('bg-BG',{timeZone:'Europe/Sofia',day:'numeric',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(new Date(d));
function filters(){return {query:$('query').value,region:$('region').value,period:$('period').value,group:$('group').value,birthYear:$('birthYear').value,category:$('category').value,minimum:Math.max(0,Number($('minimum').value)||0),onlyOpen:$('onlyOpen').checked};}
function populate(id,values,label){const old=$(id).value;$(id).innerHTML='<option value="">'+label+'</option>'+values.map(v=>'<option value="'+escapeHTML(v)+'">'+escapeHTML(v)+'</option>').join('');if(values.includes(old))$(id).value=old;}
function populateOptions(){
 populate('region',[...new Set(rows.map(r=>r.region).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'bg')),'Всички райони');
 const gs=Object.values(details).flatMap(d=>d[$('period').value]||[]);
 populate('group',[...new Set(gs.map(groupName))].sort((a,b)=>a.localeCompare(b,'bg')),'Всички групи');
 populate('birthYear',[...new Set(gs.map(g=>String(g.birthYear)).filter(y=>/^\d{4}$/.test(y)))].sort().reverse(),'Всички години');
 const d=Object.values(details).find(d=>d.schoolYear);
 if(d){$('period').options[0].textContent=d.schoolYear+' · Текуща';$('period').options[1].textContent=(d.nextSchoolYear||'Следваща година')+' · Следваща';}
}
const gardenSVG='<svg viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M5 13l9-8 9 8v11H5V13Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M11 24v-7h6v7M9 12h2m6 0h2M14 3v3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
function listingTimestamp(detail){
 const value=detail?.checkedAt;
 if(!value||!Number.isFinite(Date.parse(value)))return '<div class="listing-updated">Няма данни за последната проверка.</div>';
 return '<div class="listing-updated">Данни, актуализирани на: <time datetime="'+escapeHTML(value)+'">'+escapeHTML(formatTime(value))+'</time> (София)</div>';
}
function render(){
 const f=filters();filtered=filterRows(rows,details,f);
 filtered.sort((a,b)=>$('sort').value==='places'?(b.places??-1)-(a.places??-1)||a.name.localeCompare(b.name,'bg',{numeric:true}):$('sort').value==='district'?a.region.localeCompare(b.region,'bg')||a.name.localeCompare(b.name,'bg',{numeric:true}):a.name.localeCompare(b.name,'bg',{numeric:true}));
 page=Math.min(page,Math.max(1,Math.ceil(filtered.length/pageSize)));
 $('matchCount').textContent=filtered.length;
 const known=filtered.filter(r=>r.places!==null);
 $('placeCount').textContent=known.length?known.reduce((n,r)=>n+r.places,0).toLocaleString('bg-BG'):'—';
 $('groupCount').textContent=filtered.reduce((n,r)=>n+r.groups.length,0).toLocaleString('bg-BG');
 $('categoryCaption').textContent=categories[f.category].toLowerCase();
 $('totalCount').textContent=rows.length+' в списъка';
 $('legend').innerHTML='<b>'+escapeHTML($('period').selectedOptions[0].textContent)+'</b> · '+escapeHTML(categories[f.category])+' · Броят места отговаря на избраните филтри.';
 if(!busy){const failed=rows.filter(r=>!details[r.id]).length;const stale=refreshMeta?.failedIds?.length||0;const partial=known.length&&known.length<filtered.length;const nextEmpty=f.period==='next'&&!Object.values(details).some(d=>d.next?.length);$('notice').textContent=stale?stale+' заведения не са обновени при последната проверка. За тях са запазени по-старите данни и часът под „Виж групите“.':nextEmpty?'Все още няма публикувани групи за следващата учебна година. Изберете текущата година.':failed?failed+' заведения нямат данни за групите. Липсващите данни не се броят като нула.':partial?'За някои заведения няма публикуван брой за избрания филтър. Общият брой включва само известните стойности.':'';}
 const slice=filtered.slice((page-1)*pageSize,page*pageSize);
 $('results').innerHTML=slice.length?slice.map(r=>{
 const count=r.places;const tags=[...new Set(r.groups.map(groupName))];
 const tagHTML=tags.slice(0,3).map(g=>'<span class="tag" lang="bg">'+escapeHTML(g)+'</span>').join('')+(tags.length>3?'<span class="tag">+'+(tags.length-3)+' още</span>':'');
 return '<article class="card"><div class="card-main"><div class="garden-icon">'+gardenSVG+'</div><div class="garden-info"><h3 lang="bg"><a target="_blank" rel="noopener" href="https://kg.sofia.bg/#/kindergarten/'+r.id+'">'+escapeHTML(r.name)+'</a></h3><div class="location"><span lang="bg">⌖ '+escapeHTML(r.region)+'</span><span lang="bg">'+escapeHTML(r.address)+'</span></div></div><div class="vacancy"><span class="badge '+(count===null?'unknown':count===0?'zero':'')+'">'+(count===null?'Няма данни':count===0?'Няма места':count+' '+(count===1?'място':'места'))+'</span><small>'+r.groups.length+' '+(r.groups.length===1?'група':'групи')+'</small></div></div><div class="card-bottom"><div class="group-tags">'+(tagHTML||'<span class="tag">'+(r.detail?'Няма публикувани групи за избора':'Няма данни за групите')+'</span>')+'</div><button class="details-button" aria-expanded="false" aria-controls="detail-'+r.id+'" data-id="'+r.id+'">Виж групите ↓</button></div>'+listingTimestamp(r.detail)+'<div class="group-details" id="detail-'+r.id+'" hidden></div></article>';
 }).join(''):'<div class="empty"><strong>Няма намерени заведения.</strong>Опитайте друго име, група или район, или намалете минимума свободни места.<br><button class="text-button" data-reset="true">Изчисти филтрите и покажи всички →</button></div>';
 $('pageLabel').textContent=filtered.length?'Показани '+((page-1)*pageSize+1)+'–'+Math.min(page*pageSize,filtered.length)+' от '+filtered.length+' заведения':'0 заведения';
 $('prev').disabled=page===1;$('next').disabled=page*pageSize>=filtered.length;
 if(refreshedAt)$('updated').textContent='Последна проверка: '+formatTime(refreshedAt)+' (София) · Обновяване от 06:00 ч.';
}
function detailHTML(row){
 const f=filters();const value=v=>Number.isFinite(v)&&v>=0?'<strong>'+v+'</strong>':'—';
 const gs=row.groups;
 return (gs.length?'<table><thead><tr><th>Група</th>'+Object.entries(categories).map(([k,v])=>'<th class="'+(k===f.category?'selected-cat':'')+'">'+escapeHTML(v)+'</th>').join('')+'</tr></thead><tbody>'+gs.map(g=>'<tr><td lang="bg">'+escapeHTML(g.label)+'</td>'+Object.keys(categories).map(k=>'<td class="'+(k===f.category?'selected-cat':'')+'">'+value(g.spots[k])+'</td>').join('')+'</tr>').join('')+'</tbody></table>':'<p class="helper">Няма публикувани данни за групите за избрания филтър.</p>')+'<div class="detail-foot"><span>'+(row.detail?'Проверено: '+formatTime(row.detail.checkedAt)+' (София). ':'')+'Местата са по отделни категории за прием. — означава липса на данни.</span><a target="_blank" rel="noopener" href="https://kg.sofia.bg/#/kindergarten/'+row.id+'">Официална страница ↗</a></div>';
}
function reset(){for(const id of ['query','region','group','birthYear'])$(id).value='';$('minimum').value='0';$('onlyOpen').checked=false;$('period').value='current';$('category').value='general';$('sort').value='name';populateOptions();page=1;render();}
async function getJSON(path){const r=await fetch(path,{cache:'no-store'});if(!r.ok)throw new Error('Източникът не е достъпен');return r.json();}
async function refresh(){
 if(busy)return;busy=true;$('refresh').disabled=true;
 try{const snapshot=await getJSON('api.php');rows=snapshot.rows;details=snapshot.details;refreshedAt=snapshot.checkedAt;refreshMeta=snapshot.refresh;populateOptions();busy=false;render();}
 catch{busy=false;render();$('notice').textContent='Неуспешно зареждане. Показани са последните налични данни. Опитайте отново след малко.';}
 finally{busy=false;$('refresh').disabled=false;}
}
for(const id of ['query','region','period','group','birthYear','category','minimum','onlyOpen','sort'])$(id).addEventListener(id==='query'||id==='minimum'?'input':'change',()=>{if(id==='period')populateOptions();page=1;render();});
$('reset').addEventListener('click',reset);$('refresh').addEventListener('click',refresh);
for(const [id,step] of [['prev',-1],['next',1]])$(id).addEventListener('click',()=>{page+=step;render();$('results').scrollIntoView({behavior:'smooth',block:'start'});});
$('results').addEventListener('click',e=>{if(e.target.closest('[data-reset]'))return reset();const b=e.target.closest('[data-id]');if(!b)return;const id=Number(b.dataset.id),row=filtered.find(r=>r.id===id),box=$('detail-'+id);const expanded=b.getAttribute('aria-expanded')==='true';if(!expanded)box.innerHTML=detailHTML(row);box.hidden=expanded;b.setAttribute('aria-expanded',String(!expanded));b.textContent=expanded?'Виж групите ↓':'Скрий групите ↑';});
(async()=>{try{const snapshot=await getJSON('api.php');rows=snapshot.rows;details=snapshot.details;refreshedAt=snapshot.checkedAt;refreshMeta=snapshot.refresh;populateOptions();render();}catch{$('updated').textContent='Данните не са достъпни';$('results').innerHTML='<div class="empty"><strong>Списъкът не можа да се зареди.</strong>Опитайте бутона „Зареди данните“ по-късно.</div>';$('prev').disabled=true;$('next').disabled=true;}})();
