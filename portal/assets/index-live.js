(function(){const t=document.createElement("link").relList;if(t&&t.supports&&t.supports("modulepreload"))return;for(const s of document.querySelectorAll('link[rel="modulepreload"]'))r(s);new MutationObserver(s=>{for(const o of s)if(o.type==="childList")for(const i of o.addedNodes)i.tagName==="LINK"&&i.rel==="modulepreload"&&r(i)}).observe(document,{childList:!0,subtree:!0});function a(s){const o={};return s.integrity&&(o.integrity=s.integrity),s.referrerPolicy&&(o.referrerPolicy=s.referrerPolicy),s.crossOrigin==="use-credentials"?o.credentials="include":s.crossOrigin==="anonymous"?o.credentials="omit":o.credentials="same-origin",o}function r(s){if(s.ep)return;s.ep=!0;const o=a(s);fetch(s.href,o)}})();const y=[{id:"openmvp",repo:"https://github.com/martinroot/openmvp",name:"OpenMVP.online",mark:"★",type:"Portal · Projects",color:"#4f46e5",summary:"Agent portal of the studio fleet: project tracker, gantt and API cost accounting.",url:"https://openmvp.online/",stage:"Fleet portal",stageCaption:"Unified portal over all maximhot agents and projects.",steps:[["Track activity","Live per-project activity from agent runs."],["Watch spend","Hourly API cost and token accounting."],["Plan work","Gantt of today\u0027s agent runs per project."]],seed:31,cadence:40,jobScale:1,tokenScale:1,launch:"2026-11-05",dead:!0},{id:"apimedia",repo:"https://github.com/martinroot/apimedia",name:"ApiMedia",mark:"✦",type:"AI media",color:"#7851d5",summary:"Global preset library for AI images and video.",url:"https://apimedia.live/catalog/all",stage:"Preset library",stageCaption:"Style samples from the existing portal. Choose a preset, then add your own prompt or reference.",steps:[["Find a look","Browse image and video presets."],["Add your input","Start with a prompt or a reference image."],["Generate","Run in the browser, via API, or via MCP."]],pdf:!0,seed:12,cadence:68,jobScale:15,tokenScale:8700,launch:"2026-07-26"},{id:"fabra",repo:"https://github.com/martinroot/fabra",name:"Fabra",mark:"◈",type:"AI agents",color:"#188258",summary:"200 ready-to-run templates for specialized agent tasks.",url:"https://fabra.live/#catalog",stage:"Template library",stageCaption:"Examples of specialist workflows for photos, content, documents and DevOps.",steps:[["Choose a specialist","Pick a template built for the task."],["Brief the agent","Describe the result and attach relevant files."],["Run the workflow","Start a first run in minutes."]],pdf:!0,seed:43,cadence:73,jobScale:18,tokenScale:11300,launch:"2026-08-04"},{id:"fapa",repo:"https://github.com/martinroot/fapa",name:"Fapa",mark:"▶",type:"Short video · 18+",color:"#b23373",summary:"A swipe-first short-video feed for adults.",url:"https://fapa.cam/",stage:"Vertical feed",stageCaption:"An abstract preview of the swipe interaction, creator discovery and tags.",steps:[["Open the feed","Browse vertical short videos."],["Swipe to discover","Move through the feed one clip at a time."],["Explore by interest","Find creators and browse relevant tags."]],pdf:!0,seed:56,cadence:53,jobScale:9,tokenScale:5100,launch:"2026-08-19"},{id:"gitgame",repo:"https://github.com/martinroot/gitgame",name:"GitGame",mark:"▦",type:"Browser games",color:"#2564b9",summary:"A marketplace for playable HTML5 games and their source code.",url:"https://gitgame.live/",stage:"Game previews",stageCaption:"Deep Blue - Fishing and Jamblob Sandbox, from the project’s catalog assets.",steps:[["Browse","Find a community-made browser game."],["Play","Try the playable build in the browser."],["Build on it","Get the source and check the included license."]],pdf:!0,seed:82,cadence:47,jobScale:10,tokenScale:4900,launch:"2026-09-02"},{id:"aispy",repo:"https://github.com/martinroot/aispy",name:"AI Spy",mark:"◎",type:"AI discovery",color:"#a36318",summary:"A public radar for AI launches, categories and open-source tools.",url:"https://aispy.click/",stage:"Discovery radar",stageCaption:"Concept view of the radar’s main discovery sections.",steps:[["Track launches","Scan new AI projects."],["Explore categories","Find projects by topic."],["Find open source","Discover tools with publicly available code."]],pdf:!0,seed:37,cadence:42,jobScale:8,tokenScale:3800,launch:"2026-09-15"},{id:"tapapp",repo:"https://github.com/martinroot/tapapp",name:"TapApp CRM",mark:"▥",type:"App operations",color:"#5447ca",summary:"ToDo CRM, app analytics, paywalls and RevenueCat in one workspace.",url:"https://tapapp.click/",stage:"App workspace",stageCaption:"Switch between analytics, tasks and paywall events. Figures in this product mockup are illustrative.",steps:[["Plan the release","Track work in ToDo CRM."],["Follow performance","Review installs, subscriptions and revenue."],["Run monetization","Manage paywalls and connect RevenueCat events."]],pdf:!0,seed:71,cadence:58,jobScale:12,tokenScale:6200,launch:"2026-09-28"},{id:"dagi",repo:"https://github.com/martinroot/dagi",name:"Dagi",mark:"❖",type:"AI agents · Infra",color:"#7c3aed",summary:"Hermes-style workbench for multi-server agent systems.",url:"https://dagi.skin/",stage:"Agent workbench",stageCaption:"Architecture, memory and preset sections of the Dagi workbench.",steps:[["Design the fleet","Route work to specialist workers."],["Give them memory","Attach custom memory per server."],["Launch a preset","Start runs from the saved preset library."]],seed:23,cadence:61,jobScale:7,tokenScale:3400,launch:"2026-10-06"},{id:"ibody",repo:"https://github.com/martinroot/ibody",name:"IBody",mark:"☾",type:"Generative video · 18+",color:"#db2777",summary:"Uncensored photo animation: any 18+ photo, no signup.",url:"https://ibody.live/en/",stage:"Animation studio",stageCaption:"The animation picker with modes like twerk, blink and zoom-out.",steps:[["Upload a photo","Pick any 18+ image."],["Choose a mode","Select the animation style."],["Get the video","Result in about five seconds."]],seed:64,cadence:66,jobScale:11,tokenScale:7400,launch:"2026-10-14"},{id:"apiadult",repo:"https://github.com/martinroot/apiadult",name:"APIAdult",mark:"⚡",type:"AI API · Uncensored",color:"#9333ea",summary:"One endpoint for uncensored image, video, voice and chat models.",url:"https://apiadult.online/",stage:"Model catalog",stageCaption:"The model grid with pricing and docs links.",steps:[["Pick a model","Browse the uncensored model catalog."],["Get an API key","Create a key from the dashboard."],["Ship to production","Call image, video, voice or chat models."]],seed:29,cadence:70,jobScale:13,tokenScale:9100,launch:"2026-10-21"},{id:"bigpu",repo:"https://github.com/martinroot/bigpu",name:"BI GPU",mark:"▣",type:"GPU infrastructure",color:"#0e7490",summary:"30 model-specific GPU deployment templates for Vast.ai.",url:"https://bigpu.site/",stage:"Template catalog",stageCaption:"Deployment templates: Qwen fleets, team LoRA, RAG, video and research clusters.",steps:[["Choose a stack","Pick a model-specific GPU template."],["Request access","Ask for early access to the catalog."],["Deploy","Target launch time: about 15 minutes."]],seed:47,cadence:51,jobScale:5,tokenScale:2600,launch:"2026-10-27",dead:!0},{id:"cpark",repo:"https://github.com/martinroot/cpark",name:"CPARK",mark:"⇄",type:"Media buying",color:"#b45309",summary:"Media buying workspace: spy, offers, tracking, BI, automation.",url:"https://cpark.click/",stage:"Buying workspace",stageCaption:"Modules of the workspace with a demo team and local actions.",steps:[["Study the funnel","Spy, offers and tracking in one view."],["Automate","Run automation with manual approval."],["Decide on BI","Use BI reports to scale or cut."]],seed:33,cadence:55,jobScale:8,tokenScale:4100,launch:"2026-11-01"},{id:"vibemetrics",name:"VibeMetrics",mark:"⌗",type:"Analytics",color:"#15803d",summary:"ClickHouse analytics portal for the whole OpenMVP network.",url:"https://vibemetrics.click/",repo:"https://github.com/martinroot/vibemetrics",stage:"Analytics portal",stageCaption:"Events, attribution, behaviour and agent reports over ClickHouse.",steps:[["Collect events","Sources stream into self-hosted ClickHouse."],["Attribute","Tie traffic to projects and campaigns."],["Report","Review agent reports and exports, including MCP."]],seed:52,cadence:64,jobScale:9,tokenScale:5300,launch:"2026-10-30"},{id:"dildomll",name:"Dildo MLL",mark:"⚙",type:"ML research",color:"#4d7c0f",summary:"A Qwen3.5-0.8B LoRA expert for one pipeline, on a tiny CPU.",url:"https://dildo.asia/",repo:"https://github.com/martinroot/dildo",stage:"ML pipeline",stageCaption:"Entity contract, training workflow and CPU deployment economics.",steps:[["Define entities","Fix the entity contract for the pipeline."],["Fine-tune","Train a LoRA on Qwen3.5-0.8B."],["Deploy on CPU","Export 4-bit GGUF and serve with llama.cpp."]],seed:41,cadence:46,jobScale:6,tokenScale:3300,launch:"2026-11-04"},{id:"fas",repo:"https://github.com/martinroot/fas",name:"FaceBook Ads Studio",mark:"✎",type:"Ad creative",color:"#1d4ed8",summary:"Facebook video ads end-to-end in one creative workspace.",url:"https://fas.lol/",stage:"Creative studio",stageCaption:"Script, AI scenes, sound and multi-track timeline in one studio.",steps:[["Write the script","Start from a template or a blank brief."],["Generate scenes","AI scenes, music and voice-over."],["Test variants","Cut versions on the multi-track timeline."]],seed:58,cadence:57,jobScale:10,tokenScale:6200,launch:"2026-11-05"},{id:"faptubes",repo:"https://github.com/martinroot/fapacrm",name:"FapaCRM",mark:"▤",type:"Tube network · 18+",color:"#be123c",summary:"Operating system for a live network of 23 adult tube domains.",url:"https://faptubes.click/",stage:"Network panel",stageCaption:"Domains, auto-deploy templates, traffic buying and per-domain profit.",steps:[["Deploy a domain","Auto-deploy a template site."],["Buy traffic","Automated traffic acquisition per domain."],["Count profit","Per-domain revenue and cost accounting."]],seed:76,cadence:59,jobScale:14,tokenScale:6800,launch:"2026-10-11"},{id:"workerskin",repo:"https://github.com/martinroot/worker",name:"Worker.skin",mark:"⚒",type:"AI agents · Infra",color:"#6d28d9",summary:"Worker-side domain of the Dagi multi-server agent system.",url:"https://worker.skin/",stage:"Agent workbench",stageCaption:"The same Dagi workbench served from the worker domain.",steps:[["Route the work","Send jobs to this worker domain."],["Use its memory","Workers keep their own custom memory."],["Collect results","Return outputs to the orchestrating server."]],seed:42,cadence:49,jobScale:4,tokenScale:2200,launch:"2026-11-06",dead:!0},{id:"openpay",repo:"https://github.com/martinroot/openpay",name:"OpenPay",mark:"₿",type:"Payments",color:"#a16207",summary:"Crypto billing for OpenMVP projects, powered by DV.net.",url:"https://openpay.click/",stage:"Billing",stageCaption:"Payment page built on the open-source DV.net platform.",steps:[["Pick a project","Choose a service published on openmvp.online."],["Pay in crypto","Checkout powered by DV.net."],["Get access","The project activates after confirmation."]],seed:8,cadence:44,jobScale:3,tokenScale:1500,launch:"2026-11-07"},{id:"gsbapi",repo:"https://github.com/martinroot/gsbapi",name:"GSB API",mark:"🛡",type:"Security API",color:"#334155",summary:"Google Safe Browsing checks from a three-region Chrome probe farm.",url:"https://gsb-api.lol/",stage:"Probe farm",stageCaption:"Europe, Asia and Latin America Chrome probes with MCP agents.",steps:[["Queue a check","Schedule checks per domain via API or MCP."],["Probe in Chrome","Each check opens the URL in real Chrome."],["Read the verdict","Safe Browsing warnings, $0.0003 per check."]],seed:1972,cadence:43,jobScale:7,tokenScale:2900,launch:"2026-11-08"},{id:"spottygun",repo:"https://github.com/martinroot/spottygun",name:"SpottyGun",mark:"♪",type:"Music · AI",color:"#a855f7",summary:"Batch-first music studio: track variations, playlists and playback in one workspace.",url:"https://spottygun.site/",stage:"Music studio",stageCaption:"Batch generator, variation editor and playlist builder of the workspace.",steps:[["Pick a vibe","Choose a starting track or mood."],["Generate variations","Produce a batch of alternatives."],["Curate & listen","Build a playlist and play it locally."]],launch:"2026-10-24",seed:91,cadence:62,jobScale:9,tokenScale:5600},{id:"humanbro",repo:"https://github.com/martinroot/humanbro",name:"HumanBro",mark:"⊞",type:"Browser automation",color:"#0891b2",summary:"Isolated browser profiles with queued runs over API or MCP.",url:"https://humanbro.autos/",stage:"Profile runner",stageCaption:"Queue a job against a profile with its own session, fingerprint and proxy.",steps:[["Queue a profile job","Send a run via API or MCP."],["Restore the session","Runner restores sign-in, fingerprint and proxy."],["Review exceptions","Operator reviews flagged results."]],launch:"2026-11-02",seed:73,cadence:58,jobScale:11,tokenScale:6400},{id:"aiwebback",repo:"https://github.com/martinroot/aiwebback",name:"AiWebBack",mark:"⟲",type:"Web archive",color:"#ca8a04",summary:"Find, assess and restore websites that disappeared from the open web.",url:"https://aiwebback.space/",stage:"Restoration studio",stageCaption:"DNS/RDAP checks, Wayback snapshots and a private restoration workspace.",steps:[["Scan for the lost page","Check DNS, RDAP and Wayback traces."],["Assess recoverability","Judge what can be restored and how."],["Restore","Bring the useful pages back online."]],launch:"2026-10-18",seed:55,cadence:50,jobScale:7,tokenScale:4100},{id:"bigquery",repo:"https://github.com/martinroot/bigquery",name:"BigQuery.live",mark:"∑",type:"Analytics · AI",color:"#4338ca",summary:"Turns saved Metabase SQL and BigQuery data into Python reports via OpenRouter models.",url:"https://bigquery.live/",stage:"Analytics workbench",stageCaption:"Ask a question; the agent picks the Metabase query, reads BigQuery and builds a Python answer.",steps:[["Ask a business question","Plain-language question, no SQL needed."],["Agent finds the query","Selects the saved Metabase query for the job."],["Reads & reports","Pulls BigQuery data and assembles the Python report."]],launch:"2026-11-05",seed:38,cadence:66,jobScale:10,tokenScale:7200}],I=(new Date).toISOString().slice(0,10),f="2026-07-26",h=864e5,S=Date.parse(I),U=Date.parse(f),C=e=>new Date(e).toISOString().slice(0,10),O={jobs:"Jobs",edits:"Edits",tokens:"Tokens"},H=e=>new Intl.NumberFormat("en",{notation:"compact",maximumFractionDigits:1}).format(e),u=e=>new Intl.NumberFormat("en").format(e),x=e=>new Intl.DateTimeFormat("en",{dateStyle:"medium",timeZone:"UTC"}).format(new Date(e));function N(e){let t=e.seed;function a(n){return t=Math.imul(t,1664525)+1013904223>>>0,Math.floor(t/4294967296*n)}const r=e.launch??f,s=Date.parse(r),o=new Map;let i=0;for(let n=U;n<=S;n+=h){if(n<s)continue;if(i>0){i--;continue}const c=[0,6].includes(new Date(n).getUTCDay()),l=e.dead?n<s+24*h?22-(c?12:0):0:e.cadence-(c?24:0),d=a(100)<l,p=d?[1,1,1,2,2,3][a(6)]:0,g=p?1+a(p*2):0,b=g?g*(120+a(900)):0;o.set(C(n),{date:C(n),edits:p,jobs:g,tokens:b}),d&&!e.dead&&a(100)<14&&(i=2+a(5))}return o}function Q(e){const t=N(e),a={jobs:0,edits:0,tokens:0},s=[{date:e.launch??f,...a}];for(const o of t.values()){for(const i of Object.keys(a))a[i]+=o[i];s.push({date:o.date,...a})}return{records:t,totals:a,series:s}}const k=S-364*h,B=k-new Date(k).getUTCDay()*h,q=Math.floor((S-B)/(7*h))+1;function W(e){const t=[],a=[];for(let r=0;r<q*7;r++){const s=B+r*h,o=C(s),i=s>=k&&s<=S,n=Math.floor(r/7);if(i&&(s===k||o.endsWith("-01"))){const l=new Intl.DateTimeFormat("en",{month:"short",timeZone:"UTC"}).format(new Date(s));t.push({week:n,label:l})}const c=i?e.get(o)??{date:o,edits:0,jobs:0,tokens:0}:null;a.push(c)}return{months:t,days:a}}const j=new Map(y.map(e=>[e.id,Q(e)])),A=document.querySelector("#project-list"),$=document.querySelector("#metric"),R=e=>[...j.values()].reduce((t,a)=>t+a.totals[e],0),v={edits:"Edits",jobs:"Jobs",tokens:"Tokens spent"},L=(e,t)=>e==="tokens"?H(t):u(t),P=(e,t,a=t)=>`<div class="stat"><dt>${e}</dt><dd title="${a}">${t}</dd></div>`;document.querySelector("#overview-stats").innerHTML=`<dl>${P("Projects",y.length)}${Object.keys(v).map(e=>P(v[e],L(e,R(e)),u(R(e)))).join("")}</dl>`;document.querySelector("#project-count").textContent=y.length;const D=e=>new Intl.DateTimeFormat("en",{month:"short",day:"numeric",timeZone:"UTC"}).format(new Date(e));document.querySelector("#snapshot-range").textContent=`${D(f)} – ${x(I)}`;function V(e,t){const{months:a,days:r}=W(t),s=e.launch??f,o=r.findIndex(n=>(n==null?void 0:n.date)===s),i=r.map((n,c)=>{if(!n)return'<span class="cell gap-day" aria-hidden="true"></span>';if(n.date<s)return'<span class="cell" aria-hidden="true"></span>';const{date:l,edits:d,jobs:p,tokens:g}=n,b=`${x(l)} · ${d} ${d===1?"edit":"edits"} · ${u(p)} jobs · ${u(g)} tokens`;return`<button type="button" class="cell" data-index="${c}" data-date="${l}" data-level="${d}" aria-label="${b}" title="${b}" tabindex="${c===o?0:-1}"></button>`}).join("");return`
    <section class="calendar" aria-label="${e.name} activity calendar">
      <div class="calendar-top"><h3>Activity</h3><span>LAST 365 DAYS</span></div>
      <div class="calendar-scroll">
        <div class="heatmap" style="--weeks:${q}">
          <div class="weekdays" aria-hidden="true"><span>Mon</span><span>Wed</span><span>Fri</span></div>
          <div class="heatmap-content">
            <div class="months" aria-hidden="true">${a.map(({week:n,label:c})=>`<span style="grid-column:${n+1}">${c}</span>`).join("")}</div>
            <div class="cells" role="group" aria-label="Use arrow keys to move by day or week">${i}</div>
          </div>
        </div>
      </div>
      <div class="calendar-foot"><p class="day-detail" aria-live="polite">Select a day</p><div class="legend" aria-label="Daily edit counts: 0, 1, 2, or 5"><span>Less</span><i data-level="0"></i><i data-level="1"></i><i data-level="2"></i><i data-level="5"></i><span>More</span></div></div>
    </section>`}function F(e,t){const{series:a,totals:r}=j.get(e.id),s=Math.max(1,r[t]),o=a.map((i,n)=>{const c=1+n*198/(a.length-1),l=59-i[t]*54/s;return`${c.toFixed(1)},${l.toFixed(1)}`}).join(" ");return`<h3>Cumulative ${O[t].toLowerCase()}</h3>
    <strong class="growth-value" title="${u(r[t])}">${L(t,r[t])}</strong>
    <svg class="chart" viewBox="0 0 200 62" role="img" aria-label="${e.name}: cumulative ${t}, from zero to ${u(r[t])}">
      <path class="grid-lines" d="M1 5H199 M1 32H199 M1 59H199"/>
      <polygon class="growth-fill" points="1,59 ${o} 199,59"/>
      <polyline class="growth-line" points="${o}"/>
      <circle cx="199" cy="5" r="2.4"/>
    </svg>
    <div class="chart-axis"><span>${D(a[0].date)}</span><span>${D(I)}</span></div>`}A.innerHTML=y.map(e=>{const{records:t,totals:a}=j.get(e.id);return`<article class="project-row" id="${e.id}" aria-labelledby="${e.id}-name" style="--project-color:${e.color}">
    <div class="project-main">
      <div class="project-identity">
        <span class="project-mark" aria-hidden="true">${e.mark}</span>
        <div><h3 id="${e.id}-name"><a href="${e.url}" target="_blank" rel="noopener noreferrer">${e.name} <span aria-hidden="true">↗</span></a></h3><p class="project-type">${e.type}</p></div>
      </div>
      <p class="project-summary">${e.summary}</p>
      <div class="project-actions">
        ${e.repo?`<a class="outline-button repo-btn" href="${e.repo}" target="_blank" rel="noopener noreferrer" aria-label="${e.name} GitHub repository"><svg viewBox="0 0 16 16" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.67-.89-3.67-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.89 3.75-3.68 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A7.995 7.995 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/></svg>GitHub</a>`:""} 
      </div>
    </div>
    <div class="project-activity">
      <dl class="project-stats">${Object.keys(v).map(r=>P(v[r],L(r,a[r]),u(a[r]))).join("")}</dl>
      ${V(e,t)}
    </div>
    <section class="growth" aria-label="${e.name} growth" data-chart="${e.id}">${F(e,$.value)}</section>
  </article>`}).join("");$.addEventListener("change",()=>{for(const e of y)A.querySelector(`[data-chart="${e.id}"]`).innerHTML=F(e,$.value)});for(const e of A.querySelectorAll(".calendar")){let s=function(o){a.querySelector('[tabindex="0"]').tabIndex=-1,o.tabIndex=0,r.textContent=o.getAttribute("aria-label")};var Z=s;const t=e.querySelector(".calendar-scroll"),a=e.querySelector(".cells"),r=e.querySelector(".day-detail");t.scrollLeft=t.scrollWidth,a.addEventListener("focusin",o=>{o.target.matches("button.cell")&&s(o.target)}),a.addEventListener("click",o=>{o.target.matches("button.cell")&&s(o.target)}),a.addEventListener("keydown",o=>{const i={ArrowUp:-1,ArrowDown:1,ArrowLeft:-7,ArrowRight:7};if(!(o.key in i)||!o.target.matches("button.cell"))return;o.preventDefault();const n=a.querySelector(`[data-index="${Number(o.target.dataset.index)+i[o.key]}"]`);n==null||n.focus()})}const w=document.querySelector("#gallery"),M=document.querySelector("#gallery-content"),z=document.querySelector("#gallery-prev"),J=document.querySelector("#gallery-next");let G,m=0;function E(){const{id:e,name:t,stage:a,stageCaption:r,steps:s}=G;w.querySelector("#gallery-title").textContent=t;const o=document.querySelector(`#preview-${e}`),i=m===0&&!!o,n=i?a:"How it works",c=document.createElement("div");c.className="gallery-frame",i?c.append(o.content.cloneNode(!0)):c.innerHTML=`<ol class="workflow">${s.map(([d,p])=>`<li><b>${d}</b><span>${p}</span></li>`).join("")}</ol>`;const l=document.createElement("div");l.className="gallery-caption",l.innerHTML=`<h3>${n}</h3>${m===0?`<p>${r}</p>`:""}`,M.replaceChildren(c,l),w.querySelector("#gallery-position").textContent=`${m+1} / 2 · ${n}`}A.addEventListener("click",()=>{});function T(){m=1-m,E()}z.addEventListener("click",T);J.addEventListener("click",T);w.addEventListener("keydown",e=>{(e.key==="ArrowLeft"||e.key==="ArrowRight")&&(e.preventDefault(),T())});M.addEventListener("click",e=>{const t=e.target.closest(".tap-controls button");if(t)for(const a of M.querySelectorAll(".tap-controls button"))a.setAttribute("aria-pressed",String(a===t)),document.getElementById(a.getAttribute("aria-controls")).hidden=a!==t});

/* --- live overlay (final): единый набор portal_daily -> archive.json + live.json --- */
;(function(){
  var NF_C=new Intl.NumberFormat("en",{notation:"compact",maximumFractionDigits:1});
  var NF_F=new Intl.NumberFormat("en");
  function cellLevel(j){return j<=0?0:(j<3?1:(j<10?2:5))}
  function base(dd){return Number((dd.getAttribute("title")||"").replace(/[^\d.-]/g,""))||0}
  function setStat(dd,v,compact){dd.setAttribute("title",NF_F.format(v));dd.textContent=compact?NF_C.format(v):NF_F.format(v);}
  function setMoney(dd,usd){dd.setAttribute("title","$"+usd.toFixed(4));dd.textContent="$"+usd.toFixed(2);}
  function jfetch(u){return fetch(u+"?t="+Date.now()).then(function(r){return r.json()})}
  function dayKey(k){return k==="tokens"?"t":"j"}
  function metricLabel(pid,key,total){
    var row=document.getElementById(pid);
    var nm=row?(row.querySelector(".project-name,.project-title,h2,h3")||{textContent:pid}).textContent.trim():pid;
    var g=row?row.querySelector(".growth"):null;
    var svg=g?g.querySelector("svg.chart"):null;
    if(svg)svg.setAttribute("aria-label",nm+": cumulative "+key+", from zero to "+NF_C.format(total));
  }
  function rebuildChart(row,days,today,liveJobs,liveTok,key){
    var g=row.querySelector(".growth");if(!g)return;
    var k=dayKey(key);if(!k)return;
    var start="2026-07-26";
    var cur=new Date(start),end=new Date(today);
    var vals=[],max=1,run=0;
    while(cur<=end){
      var dk=cur.toISOString().slice(0,10);
      var d=days[dk]||{};
      var v=(dk===today)?(key==="tokens"?(liveTok||0):(liveJobs||0)):(d[k]||0);
      run+=v;vals.push(run);
      if(run>max)max=run;
      cur.setUTCDate(cur.getUTCDate()+1);
    }
    var n=vals.length;if(n<2)return;
    var pts=[];
    for(var i=0;i<n;i++){
      var x=(1+i*198/(n-1)).toFixed(2);
      var y=(59-vals[i]*54/max).toFixed(2);
      pts.push(x+","+y);
    }
    var pl=g.querySelector("polyline"),pg=g.querySelector("polygon");
    if(pl)pl.setAttribute("points",pts.join(" "));
    if(pg)pg.setAttribute("points",("1,59 "+pts.join(" ")+" 199,59"));
    var total=vals[n-1];
    var strong=g.querySelector(".growth-value");
    if(strong){strong.setAttribute("title",NF_F.format(total));strong.textContent=key==="tokens"?NF_C.format(total):NF_F.format(total);}
    metricLabel(row.id,key,total);
  }
  Promise.all([jfetch("/live.json"),jfetch("/archive.json").catch(function(){return null})]).then(function(rs){
    var LV=rs[0],ARCH=rs[1]||{};
    if(!LV||!LV.date)return;
    var today=LV.date;
    var T=LV.totals||{jobs:0,tokens:0,cost:0};
    var badge=document.querySelector(".demo-badge");
    if(badge){badge.classList.add("live-badge");badge.innerHTML='<i></i> Live · today';}
    var dr=document.querySelector("#snapshot-range");
    if(dr&&dr.textContent){dr.textContent=dr.textContent.split("–")[0].trim()+" – "+today;}
    var ov=document.querySelector("#overview-stats");
    if(ov){
      var dts=ov.querySelectorAll("dt");
      dts.forEach(function(dt){if(dt.textContent.trim()==="Edits")dt.textContent="Spend";});
      var ovJobs=0,ovTok=0,ovCost=T.cost||0;
      Object.keys(ARCH).forEach(function(pid){
        var pr=ARCH[pid];var pj=0,pt=0;
        Object.keys(pr).forEach(function(dk){ if(dk!==today){pj+=(pr[dk].j||0);pt+=(pr[dk].t||0);} });
        ovJobs+=pj+( (LV.projects&&LV.projects[pid])?((LV.projects[pid].jobs||0)) :0);
        ovTok+=pt;
      });
      ov.querySelectorAll("dd").forEach(function(dd){
        var dt=dd.previousElementSibling;if(!dt)return;
        var lab=dt.textContent.trim();
        if(lab==="Jobs")setStat(dd,ovJobs,false);
        else if(lab==="Tokens spent")setStat(dd,ovTok,true);
        else if(lab==="Spend")setMoney(dd,ovCost);
      });
      var sub=document.querySelector("p.intro");
      if(sub&&/Edits, jobs/.test(sub.textContent))sub.textContent="Spend, jobs and token usage across the studio.";
    }
    var rows=Array.prototype.slice.call(document.querySelectorAll(".project-row"));
    var jobsToday={};
    rows.forEach(function(row){
      var pid=row.id;
      var A=ARCH[pid];if(A===undefined)A=null;
      var pr=LV.projects&&LV.projects[pid];
      row.querySelectorAll(".project-stats dt").forEach(function(dt){if(dt.textContent.trim()==="Edits")dt.textContent="Spend";});
      var liveJobs=pr?(pr.jobs||0):0;
      var liveTok=pr?(pr.tokens||0):0;
      var liveCost=pr?(pr.cost||0):0;
      jobsToday[pid]=liveJobs;
      var archJobs=0,archTok=0;
      if(A){Object.keys(A).forEach(function(dk){if(dk!==today){archJobs+=(A[dk].j||0);archTok+=(A[dk].t||0);}});}
      var totalJobs=archJobs+liveJobs, totalTok=archTok+liveTok;
      row.querySelectorAll(".project-stats dd").forEach(function(dd){
        var dt=dd.previousElementSibling;if(!dt)return;
        var lab=dt.textContent.trim();
        if(lab==="Jobs")setStat(dd,totalJobs,false);
        else if(lab==="Tokens spent")setStat(dd,totalTok,true);
        else if(lab==="Spend")setMoney(dd,liveCost);
      });
      // heatmap: КАЖДАЯ ячейка из единого набора (демо полностью мертво)
      row.querySelectorAll(".cell[data-date]").forEach(function(c){
        var d=c.getAttribute("data-date");
        var j=0,t=0,cost=0,src=null;
        if(A&&A[d]){j=A[d].j||0;t=A[d].t||0;cost=A[d].c||0;src="archive";}
        if(d===today&&pr){j=liveJobs;t=liveTok;cost=liveCost;src="live";}
        var lab=src?(d+" · "+NF_F.format(j)+" jobs · "+NF_F.format(t)+" tokens"+(cost?" · $"+cost.toFixed(4):"")):(d+" · no activity");
        c.setAttribute("data-level",String(cellLevel(j)));
        c.setAttribute("aria-label",lab);c.setAttribute("title",lab);
      });
      // график: восстановить из архива
      if(A){
        var g=row.querySelector(".growth");
        var svg=g?g.querySelector("svg.chart"):null;
        var m=svg?/cumulative (edits|jobs|tokens)/.exec(svg.getAttribute("aria-label")||""):null;
        var key=m?m[1]:"tokens";
        if(key!=="edits")rebuildChart(row,A,today,liveJobs,liveTok,key);
      }
    });
    var parent=rows.length?rows[0].parentNode:null;
    if(parent){
      var act=rows.filter(function(r){return (jobsToday[r.id]||0)>0})
                  .sort(function(a,b){return (jobsToday[b.id]||0)-(jobsToday[a.id]||0)});
      var rest=rows.filter(function(r){return !(jobsToday[r.id]>0)});
      act.concat(rest).forEach(function(r){parent.appendChild(r)});
    }
  }).catch(function(){});
})();
