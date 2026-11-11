<?php
$maxFiles = (int) ini_get('max_file_uploads');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Folder Batch — Kraken Cropper</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:#050507; --panel:#0d0d10; --card:#121216; --surface:#18181d;
            --border:#26262b; --border-hover:#404048; --text:#fff; --muted:#8b8b96; --dim:#52525b;
            --ok:#4ade80; --warn:#fbbf24; --err:#f87171; --info:#60a5fa;
        }
        html, body { height:100%; background:var(--bg); color:var(--text); font-family:'Inter',sans-serif; }
        body { display:flex; flex-direction:column; overflow:hidden; }
        button { font-family:inherit; }

        .navbar { height:56px; flex:0 0 56px; background:var(--panel); border-bottom:1px solid var(--border);
            display:flex; align-items:center; justify-content:space-between; padding:0 20px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; }
        .pill { padding:3px 10px; background:var(--surface); border:1px solid var(--border); border-radius:999px;
            font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:var(--surface);
            border:1px solid var(--border); border-radius:8px; color:var(--text); font-size:.82rem; font-weight:600;
            cursor:pointer; text-decoration:none; transition:all .15s; }
        .btn:hover { border-color:#fff; }
        .btn:disabled { opacity:.4; cursor:not-allowed; border-color:var(--border); }
        .btn.primary { background:#fff; color:#000; border-color:#fff; font-weight:800; }
        .btn.primary:disabled { background:#fff; }

        /* ── setup ── */
        #setup { max-width:760px; margin:40px auto; padding:0 20px; width:100%; overflow:auto; }
        #setup h1 { font-size:1.5rem; font-weight:800; margin-bottom:6px; }
        #setup p.sub { color:var(--muted); font-size:.9rem; margin-bottom:22px; line-height:1.5; }
        .picker { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
        .drop { border:1.5px dashed var(--border-hover); border-radius:16px; padding:28px 16px; text-align:center;
            background:var(--card); cursor:pointer; transition:all .15s; }
        .drop:hover, .drop.over { border-color:#fff; background:var(--surface); }
        .drop .ic { font-size:2rem; margin-bottom:8px; }
        .drop .t { font-weight:700; font-size:.95rem; }
        .drop .s { color:var(--muted); font-size:.78rem; margin-top:4px; }
        .opts { display:flex; gap:18px; flex-wrap:wrap; align-items:center; margin:16px 0; color:var(--muted); font-size:.85rem; }
        .opts select, .opts input[type=range] { accent-color:#fff; }
        .opts select { background:var(--surface); color:#fff; border:1px solid var(--border); border-radius:8px; padding:6px 10px; }
        #selSummary { color:var(--muted); font-size:.85rem; margin-bottom:14px; min-height:1.2em; }
        input[type=file] { display:none; }

        /* ── session ── */
        #session { flex:1; display:none; min-height:0; flex-direction:column; }
        .progress-bar { flex:0 0 auto; background:var(--panel); border-bottom:1px solid var(--border); padding:10px 20px;
            display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .meter { flex:1; min-width:160px; height:8px; background:var(--surface); border-radius:99px; overflow:hidden; }
        .meter > i { display:block; height:100%; width:0; background:#fff; transition:width .25s; }
        .stat { font-size:.8rem; color:var(--muted); white-space:nowrap; }
        .stat b { color:#fff; }

        .layout { flex:1; min-height:0; display:grid; grid-template-columns:260px 1fr; }
        @media (max-width: 900px) { .layout { grid-template-columns:1fr; } #queue { max-height:140px; } }
        #queue { border-right:1px solid var(--border); background:var(--panel); overflow:auto; }
        .q-item { padding:9px 14px; border-bottom:1px solid var(--border); font-size:.8rem; cursor:default; display:flex; gap:8px; align-items:center; }
        .q-item.clickable { cursor:pointer; }
        .q-item.clickable:hover { background:var(--surface); }
        .q-item.current { background:var(--surface); box-shadow:inset 3px 0 0 #fff; }
        .q-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .chip { font-size:.66rem; font-weight:700; padding:2px 7px; border-radius:99px; border:1px solid var(--border); color:var(--muted); white-space:nowrap; }
        .chip.scanning { color:var(--info); border-color:var(--info); }
        .chip.ready { color:var(--warn); border-color:var(--warn); }
        .chip.saved { color:var(--ok); border-color:var(--ok); }
        .chip.error { color:var(--err); border-color:var(--err); }

        #review { min-width:0; min-height:0; display:flex; flex-direction:column; }
        #waiting, #done { margin:auto; text-align:center; max-width:460px; padding:24px; color:var(--muted); line-height:1.6; }
        #waiting h2, #done h2 { color:#fff; margin-bottom:8px; }
        #reviewBody { flex:1; min-height:0; display:none; grid-template-columns:1fr 1.15fr; }
        @media (max-width: 1100px) { #reviewBody { grid-template-columns:1fr; overflow:auto; } }

        .pane-head { padding:10px 16px; background:var(--panel); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .pane-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.6px; }
        .viewer { border-right:1px solid var(--border); background:#020203; display:flex; flex-direction:column; min-height:0; }
        .viewport { flex:1; overflow:auto; padding:22px; display:flex; flex-direction:column; gap:24px; align-items:center; }
        .page { position:relative; width:100%; margin-top:18px; border-radius:4px; box-shadow:0 10px 40px rgba(0,0,0,.9), 0 0 0 1px var(--border); }
        .page > .cap { position:absolute; top:-17px; left:0; font-size:.68rem; color:var(--muted); }
        .page img { display:block; width:100%; height:auto; border-radius:4px; }
        .overlay { position:absolute; inset:0; }
        .bbox { position:absolute; border:1.5px solid rgba(255,255,255,.18); background:transparent; border-radius:2px; cursor:pointer; transition:all .12s; }
        .bbox.sel { border:1.5px solid rgba(74,222,128,.9); background:rgba(74,222,128,.12); }
        .bbox:hover, .bbox.hl { border-color:#fff; background:rgba(255,255,255,.28); z-index:5; }

        .words { display:flex; flex-direction:column; min-height:0; background:var(--bg); }
        .actions { padding:12px 16px; background:var(--panel); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .grid-wrap { flex:1; overflow:auto; padding:16px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:12px; }
        .wcard { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:8px; cursor:pointer; transition:all .12s; }
        .wcard:hover, .wcard.hl { border-color:#fff; }
        .wcard.sel { border-color:rgba(74,222,128,.7); background:rgba(74,222,128,.05); }
        .wcard .top { display:flex; justify-content:space-between; align-items:center; font-size:.76rem; font-weight:700; }
        .wcard .top .dim { color:var(--dim); font-weight:500; font-size:.7rem; }
        .chk { width:16px; height:16px; accent-color:#4ade80; }
        .thumb { height:70px; background:#000; border:1px solid var(--border); border-radius:6px; display:flex; align-items:center; justify-content:center; padding:4px; overflow:hidden; }
        .thumb img { max-width:100%; max-height:100%; object-fit:contain; }
        .foot { padding:12px 16px; background:var(--panel); border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .foot .hint { font-size:.76rem; color:var(--muted); }

        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.9); z-index:50; align-items:center; justify-content:center; padding:24px; }
        .modal.open { display:flex; }
        .modal img { max-width:90vw; max-height:80vh; background:#000; border-radius:8px; }
        .modal .cap { color:var(--muted); text-align:center; margin-top:10px; font-size:.85rem; }
    </style>
</head>
<body>
    <header class="navbar">
        <div class="brand"><span>Word Cropper</span><span class="pill">Folder batch</span></div>
        <a class="btn" href="index.php">← Single upload</a>
    </header>

    <!-- ── 1. choose a folder ── -->
    <section id="setup">
        <h1>Scan a whole folder</h1>
        <p class="sub">Every document in the folder (images and PDFs, sub-folders included) is scanned and cropped first.
            Then you check each file one by one: <b>Save</b> keeps the cropped images you ticked in <code>output/</code>,
            and the next file opens. The scanned documents themselves are never stored.</p>

        <div class="picker">
            <div class="drop" id="pickFolder"><div class="ic">📁</div><div class="t">Choose a folder</div><div class="s">all sub-folders are included</div></div>
            <div class="drop" id="pickFiles"><div class="ic">📄</div><div class="t">Choose files</div><div class="s">PDF · PNG · JPG · WEBP</div></div>
        </div>
        <input type="file" id="folderInput" webkitdirectory directory multiple>
        <input type="file" id="filesInput" multiple accept="application/pdf,.pdf,image/png,image/jpeg,image/webp">

        <div id="selSummary"></div>

        <div class="opts">
            <label>Crop padding <b id="padVal">4 px</b> <input type="range" id="pad" min="0" max="24" value="4"></label>
            <label>Format
                <select id="fmt">
                    <option value="png" selected>PNG (lossless)</option>
                    <option value="webp">WEBP</option>
                    <option value="jpeg">JPEG</option>
                </select>
            </label>
        </div>
        <button class="btn primary" id="startBtn" disabled>▶ Scan &amp; crop all documents</button>
    </section>

    <!-- ── 2. scan queue + verification ── -->
    <section id="session">
        <div class="progress-bar">
            <div class="stat">Scanned <b id="stScanned">0</b>/<b id="stTotal">0</b></div>
            <div class="meter"><i id="meter"></i></div>
            <div class="stat">Saved <b id="stSaved">0</b> files · <b id="stCrops">0</b> crops</div>
            <div class="stat">Skipped <b id="stSkipped">0</b> · Errors <b id="stErr">0</b></div>
        </div>
        <div class="layout">
            <aside id="queue"></aside>
            <div id="review">
                <div id="waiting"><h2>Scanning…</h2><div>The first document will open here as soon as it is ready.</div></div>
                <div id="done" style="display:none"></div>
                <div id="reviewBody">
                    <section class="viewer">
                        <div class="pane-head">
                            <div class="pane-title" id="docTitle"></div>
                            <div id="pageBtns" style="display:flex;gap:6px"></div>
                        </div>
                        <div class="viewport" id="viewport"></div>
                    </section>
                    <section class="words">
                        <div class="actions">
                            <div class="stat"><b id="selCount">0</b> of <b id="allCount">0</b> <span id="kind">cells</span> ticked</div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn" id="selAll">Select all</button>
                                <button class="btn" id="selNone">Deselect all</button>
                            </div>
                        </div>
                        <div class="grid-wrap"><div class="grid" id="grid"></div></div>
                        <div class="foot">
                            <div class="hint" id="warnHint"></div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn" id="prevBtn">← Previous</button>
                                <button class="btn" id="skipBtn">Skip file →</button>
                                <button class="btn primary" id="saveBtn">💾 Save ticked &amp; next →</button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>

    <div class="modal" id="modal"><div><img id="modalImg" alt=""><div class="cap" id="modalCap"></div></div></div>

<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const API = 'batch_api.php';
    const EXT = /\.(pdf|png|jpe?g|webp)$/i;

    let docs = [];          // {id, file, rel, status, res, sel:Set, saved}
    let cur = -1;           // index of the document under review
    let scanning = false;
    let started = false;

    /* ───────── choose files / folder ───────── */
    const natural = (a, b) => a.rel.localeCompare(b.rel, undefined, {numeric: true, sensitivity: 'base'});

    function setSelection(fileList) {
        const all = Array.from(fileList);
        const ok = all.filter(f => EXT.test(f.name));
        docs = ok.map((f, i) => ({file: f, rel: f.webkitRelativePath || f.name}))
                 .sort(natural)
                 .map((d, i) => ({...d, id: i + 1, status: 'queued', res: null, sel: new Set(), saved: 0}));
        const ignored = all.length - ok.length;
        $('selSummary').textContent = docs.length
            ? docs.length + ' document(s) ready to scan' + (ignored ? ' · ' + ignored + ' other file(s) ignored' : '')
            : (all.length ? 'No PDF or image files found there.' : '');
        $('startBtn').disabled = docs.length === 0;
    }
    $('pickFolder').onclick = () => $('folderInput').click();
    $('pickFiles').onclick = () => $('filesInput').click();
    $('folderInput').onchange = (e) => setSelection(e.target.files);
    $('filesInput').onchange = (e) => setSelection(e.target.files);
    $('pad').oninput = (e) => $('padVal').textContent = e.target.value + ' px';

    // dropping a folder / files on the page
    ['dragenter','dragover'].forEach(n => document.addEventListener(n, e => { e.preventDefault(); }));
    document.addEventListener('drop', async (e) => {
        e.preventDefault();
        if (started) return;
        const items = e.dataTransfer && e.dataTransfer.items;
        if (!items || !items.length || !items[0].webkitGetAsEntry) { setSelection(e.dataTransfer.files); return; }
        const files = [];
        const walk = (entry, path) => new Promise(res => {
            if (entry.isFile) {
                entry.file(f => { Object.defineProperty(f, 'webkitRelativePath', {value: path + f.name}); files.push(f); res(); }, res);
            } else if (entry.isDirectory) {
                const reader = entry.createReader(); const all = [];
                const read = () => reader.readEntries(async list => {
                    if (!list.length) { await Promise.all(all.map(en => walk(en, path + entry.name + '/'))); res(); return; }
                    all.push(...list); read();
                }, res);
                read();
            } else res();
        });
        const entries = Array.from(items).map(i => i.webkitGetAsEntry()).filter(Boolean);
        await Promise.all(entries.map(en => walk(en, '')));
        setSelection(files);
    });

    /* ───────── scan queue ───────── */
    $('startBtn').onclick = async () => {
        if (!docs.length) return;
        started = true;
        $('setup').style.display = 'none';
        $('session').style.display = 'flex';
        $('stTotal').textContent = docs.length;
        renderQueue();
        const fd = new FormData(); fd.append('action', 'reset');
        try { await fetch(API, {method: 'POST', body: fd}); } catch (e) {}
        scanAll();
    };

    async function scanAll() {
        scanning = true;
        for (const d of docs) {
            if (d.status !== 'queued') continue;
            d.status = 'scanning'; renderQueue(); updateStats();
            try {
                const fd = new FormData();
                fd.append('action', 'scan');
                fd.append('id', d.id);
                fd.append('rel', d.rel);
                fd.append('padding', $('pad').value);
                fd.append('output_format', $('fmt').value);
                fd.append('file', d.file, d.file.name);
                const res = await fetch(API, {method: 'POST', body: fd});
                const txt = await res.text();
                let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Unexpected server reply'); }
                if (!j.ok) throw new Error(j.error || 'Scan failed');
                d.res = j; d.sel = new Set(j.words.map(w => w.id)); d.status = 'ready';
            } catch (err) {
                d.status = 'error'; d.error = err.message;
            }
            renderQueue(); updateStats();
            if (cur === -1 && d.status === 'ready') openDoc(docs.indexOf(d));
            else if (isWaiting) advance();   // review was idle, waiting for the next scan
        }
        scanning = false;
        updateStats();
        if (isWaiting) advance();
    }

    /* ───────── queue + stats ───────── */
    function renderQueue() {
        const label = {queued: 'Queued', scanning: 'Scanning…', ready: 'To verify', saved: 'Saved', skipped: 'Skipped', error: 'Error'};
        $('queue').innerHTML = docs.map((d, i) => {
            const cls = d.status === 'saved' ? 'saved' : d.status;
            const clickable = d.status === 'ready' ? ' clickable' : '';
            const extra = d.status === 'saved' ? ' (' + d.saved + ')' : '';
            return `<div class="q-item${clickable}${i === cur ? ' current' : ''}" data-i="${i}" title="${esc(d.rel)}${d.error ? ' — ' + esc(d.error) : ''}">
                <span class="q-name">${esc(d.rel)}</span><span class="chip ${cls}">${label[d.status]}${extra}</span></div>`;
        }).join('');
        $('queue').querySelectorAll('.q-item.clickable').forEach(el => el.onclick = () => openDoc(+el.dataset.i));
    }
    function updateStats() {
        const n = (s) => docs.filter(d => d.status === s).length;
        const scanned = docs.filter(d => !['queued', 'scanning'].includes(d.status)).length;
        $('stScanned').textContent = scanned;
        $('meter').style.width = (docs.length ? scanned / docs.length * 100 : 0) + '%';
        $('stSaved').textContent = n('saved');
        $('stCrops').textContent = docs.reduce((a, d) => a + d.saved, 0);
        $('stSkipped').textContent = n('skipped');
        $('stErr').textContent = n('error');
    }

    /* ───────── review one document ───────── */
    let isWaiting = false;
    const show = (id, on, disp = 'block') => $(id).style.display = on ? disp : 'none';

    function openDoc(i) {
        const d = docs[i];
        if (!d || d.status !== 'ready') return;
        cur = i; isWaiting = false;
        show('waiting', false); show('done', false); show('reviewBody', true, 'grid');
        renderQueue();
        const r = d.res;
        $('docTitle').textContent = d.rel + ' — ' + r.pages.length + ' page' + (r.pages.length > 1 ? 's' : '');
        $('kind').textContent = r.tableMode ? 'cells' : 'words';
        $('warnHint').textContent = (r.warnings || []).join(' • ');
        $('pageBtns').innerHTML = r.pages.map(p => `<button class="btn" style="padding:4px 10px" data-p="${p.no}">P${p.no}</button>`).join('');
        $('pageBtns').querySelectorAll('button').forEach(b => b.onclick = () =>
            document.getElementById('pg-' + b.dataset.p).scrollIntoView({behavior: 'smooth', block: 'start'}));

        $('viewport').innerHTML = r.pages.map(p => {
            const boxes = r.words.filter(w => w.page === p.no).map(w => {
                const b = w.box;
                return `<div class="bbox${d.sel.has(w.id) ? ' sel' : ''}" id="bb-${w.id}" data-id="${w.id}"
                    style="left:${b.x / p.w * 100}%;top:${b.y / p.h * 100}%;width:${b.w / p.w * 100}%;height:${b.h / p.h * 100}%"
                    title="${esc(w.label)}"></div>`;
            }).join('');
            return `<div class="page" id="pg-${p.no}"><div class="cap">Page ${p.no} — ${esc(p.name)}</div>
                <img src="${p.src}" alt="Page ${p.no}"><div class="overlay">${boxes}</div></div>`;
        }).join('');
        $('viewport').scrollTop = 0;

        $('grid').innerHTML = r.words.map(w => `
            <div class="wcard${d.sel.has(w.id) ? ' sel' : ''}" id="wc-${w.id}" data-id="${w.id}">
                <div class="top"><label style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
                    <input type="checkbox" class="chk" data-id="${w.id}"${d.sel.has(w.id) ? ' checked' : ''}><span>${esc(w.label)}</span></label>
                    <span class="dim">${w.w}×${w.h}</span></div>
                <div class="thumb"><img src="${esc(w.file)}" alt="${esc(w.label)}" loading="lazy"></div>
                <div style="text-align:right"><button class="btn" style="padding:3px 8px;font-size:.72rem" data-zoom="${w.id}">🔍 Zoom</button></div>
            </div>`).join('');

        const toggle = (id) => { d.sel.has(id) ? d.sel.delete(id) : d.sel.add(id); sync(d); };
        $('grid').querySelectorAll('.wcard').forEach(c => {
            c.onclick = () => toggle(+c.dataset.id);
            c.onmouseenter = () => { const b = $('bb-' + c.dataset.id); b && b.classList.add('hl'); };
            c.onmouseleave = () => { const b = $('bb-' + c.dataset.id); b && b.classList.remove('hl'); };
        });
        $('grid').querySelectorAll('[data-zoom]').forEach(b => b.onclick = (e) => {
            e.stopPropagation();
            const w = r.words.find(x => x.id === +b.dataset.zoom);
            $('modalImg').src = w.file; $('modalCap').textContent = w.label + ' (' + w.w + '×' + w.h + ' px)';
            $('modal').classList.add('open');
        });
        $('viewport').querySelectorAll('.bbox').forEach(b => {
            b.onclick = () => {
                const id = +b.dataset.id; toggle(id);
                const c = $('wc-' + id); if (c) { c.scrollIntoView({behavior: 'smooth', block: 'center'}); c.classList.add('hl'); setTimeout(() => c.classList.remove('hl'), 1000); }
            };
            b.onmouseenter = () => { const c = $('wc-' + b.dataset.id); c && c.classList.add('hl'); };
            b.onmouseleave = () => { const c = $('wc-' + b.dataset.id); c && c.classList.remove('hl'); };
        });
        sync(d);
    }

    function sync(d) {
        d.res.words.forEach(w => {
            const on = d.sel.has(w.id);
            const c = $('wc-' + w.id), b = $('bb-' + w.id);
            if (c) { c.classList.toggle('sel', on); c.querySelector('.chk').checked = on; }
            if (b) b.classList.toggle('sel', on);
        });
        $('selCount').textContent = d.sel.size;
        $('allCount').textContent = d.res.words.length;
        $('saveBtn').textContent = '💾 Save ' + d.sel.size + ' & next →';
        $('saveBtn').disabled = d.sel.size === 0;
    }
    $('selAll').onclick = () => { const d = docs[cur]; d.res.words.forEach(w => d.sel.add(w.id)); sync(d); };
    $('selNone').onclick = () => { const d = docs[cur]; d.sel.clear(); sync(d); };
    $('modal').onclick = () => $('modal').classList.remove('open');

    /* ───────── save / skip / navigate ───────── */
    async function post(data) {
        const fd = new FormData(); Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(API, {method: 'POST', body: fd});
        return res.json();
    }

    $('saveBtn').onclick = async () => {
        const d = docs[cur]; if (!d || !d.sel.size) return;
        $('saveBtn').disabled = true; $('saveBtn').textContent = 'Saving…';
        try {
            const names = d.res.words.filter(w => d.sel.has(w.id)).map(w => w.filename);
            const j = await post({action: 'save', prefix: d.res.prefix, filenames: JSON.stringify(names)});
            if (!j.ok) throw new Error(j.error || 'Save failed');
            d.saved = j.count; d.status = 'saved'; d.res = {prefix: d.res.prefix};   // free memory
        } catch (err) {
            alert('Could not save: ' + err.message);
            sync(d); return;
        }
        renderQueue(); updateStats(); advance();
    };

    $('skipBtn').onclick = async () => {
        const d = docs[cur]; if (!d) return;
        if (!confirm('Skip this file? Its cropped images will be discarded (nothing is saved).')) return;
        try { await post({action: 'discard', prefix: d.res.prefix}); } catch (e) {}
        d.status = 'skipped'; d.res = {prefix: d.res.prefix};
        renderQueue(); updateStats(); advance();
    };

    $('prevBtn').onclick = () => {
        for (let i = cur - 1; i >= 0; i--) if (docs[i].status === 'ready') { openDoc(i); return; }
    };

    function advance() {
        // next document still waiting for verification, after the current one (then wrap to earlier ones)
        for (let i = cur + 1; i < docs.length; i++) if (docs[i].status === 'ready') { openDoc(i); return; }
        for (let i = 0; i < docs.length; i++) if (docs[i].status === 'ready') { openDoc(i); return; }
        const pending = docs.some(d => d.status === 'queued' || d.status === 'scanning');
        show('reviewBody', false);
        if (pending) {
            isWaiting = true;
            $('waiting').innerHTML = '<h2>Next file is being scanned…</h2><div>It opens here automatically.</div>';
            show('waiting', true);
        } else {
            isWaiting = false;
            finish();
        }
    }

    function finish() {
        const n = (s) => docs.filter(d => d.status === s).length;
        const crops = docs.reduce((a, d) => a + d.saved, 0);
        const bad = docs.filter(d => d.status === 'error');
        $('done').innerHTML = `<h2>✓ All documents verified</h2>
            <div><b style="color:#fff">${n('saved')}</b> file(s) saved · <b style="color:#fff">${crops}</b> cropped image(s) in <code>output/</code><br>
            ${n('skipped')} skipped · ${bad.length} failed to scan.</div>
            ${bad.length ? '<div style="margin-top:10px;font-size:.8rem;color:#f87171;text-align:left">' + bad.map(d => esc(d.rel) + ': ' + esc(d.error)).join('<br>') + '</div>' : ''}
            <div style="margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <button class="btn primary" id="toAnnotate">✍️ Prepare dataset &amp; annotate</button>
                <a class="btn" href="batch.php">📁 Scan another folder</a></div>`;
        show('done', true);
        renderQueue();
        const b = $('toAnnotate');
        if (b) b.onclick = async () => {
            b.disabled = true; b.textContent = 'Preparing images…';
            try {
                const fd = new FormData(); fd.append('action', 'batch_process_htr');
                const j = await (await fetch('index.php', {method: 'POST', body: fd})).json();
                if (!j.success) throw new Error('failed');
                location.href = 'annotate.php';
            } catch (e) { b.disabled = false; b.textContent = 'Failed — try again'; }
        };
    }

    window.addEventListener('beforeunload', (e) => {
        if (started && docs.some(d => ['queued', 'scanning', 'ready'].includes(d.status))) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>
</body>
</html>
