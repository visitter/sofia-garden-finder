const DIRECTORY_PATH = '/public/kg/type/kinderGarden/all?filterType=by_region&kgType=0&regionId=0';
function normalizeDirectory(payload) {
  const rows=payload?.items?.kinderGardens;
  if(!Array.isArray(rows)||!rows.length) throw new Error('The source did not return its directory.');
  return rows.map(r=>({id:r.id,name:r.nameStr||r.name?.publicName||'',type:r.name?.publicType||'',region:r.region||'',address:r.address||''}));
}
function normalizeDetail(payload,id) {
  const d=payload?.items?.dz;
  if(!d||Number(d.id)!==Number(id))throw new Error('The source did not return this kindergarten.');
  const group=g=>({id:g.groupId,label:g.label||'Unnamed group',birthYear:g.draft??null,spots:Object.fromEntries((g.spots||[]).map(s=>[s.type,Number.isFinite(s.svobodni)&&s.svobodni>=0?s.svobodni:null]))});
  return {id:d.id,schoolYear:payload.items.schoolYear,nextSchoolYear:payload.items.schoolYearNext,current:(d.currentGroups||[]).filter(g=>g.active).map(group),next:(d.featureGroups||[]).filter(g=>g.active!==0&&g.active!==false).map(group),checkedAt:new Date().toISOString()};
}
function groupName(g){return g.label.replace(/,?\s*родени.*$/iu,'').trim();}
function matchingGroups(detail,filters){return (detail?.[filters.period]||[]).filter(g=>(!filters.group||groupName(g)===filters.group)&&(!filters.birthYear||String(g.birthYear)===filters.birthYear));}
function places(groups,category){const values=groups.map(g=>g.spots[category]).filter(v=>Number.isFinite(v)&&v>=0);return values.length?values.reduce((a,b)=>a+b,0):null;}
function filterRows(rows,details,filters){
 const q=filters.query.toLocaleLowerCase().replace(/№/g,'').trim();
 return rows.map(row=>{const detail=details[row.id];const groups=matchingGroups(detail,filters);return {...row,detail,groups,places:places(groups,filters.category)};}).filter(row=>(!q||row.name.toLocaleLowerCase().replace(/№/g,'').includes(q))&&(!filters.region||row.region===filters.region)&&(!(filters.group||filters.birthYear)||row.groups.length>0)&&(!filters.onlyOpen||row.places>0)&&(!filters.minimum||(row.places!==null&&row.places>=filters.minimum)));
}
