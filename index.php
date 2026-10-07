<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Cropper</title>
    <meta name="description" content="Crop multiple regions from an image and download them all at once.">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --black:   #0a0a0a;
            --white:   #ffffff;
            --gray-50: #fafafa;
            --gray-100:#f4f4f4;
            --gray-200:#e8e8e8;
            --gray-300:#d0d0d0;
            --gray-400:#a0a0a0;
            --gray-500:#707070;
            --gray-700:#333333;
            --gray-900:#111111;
            --accent:  #0a0a0a;
            --radius:  10px;
        }

        html, body { height: 100%; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--white);
            color: var(--black);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Header ─────────────────────────────── */
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            height: 58px;
            border-bottom: 1.5px solid var(--gray-200);
            background: var(--white);
            flex-shrink: 0;
        }
        .logo {
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: -.3px;
            color: var(--black);
            display: flex;
            align-items: center;
            gap: 9px;
        }
        .logo-dot {
            width: 8px; height: 8px;
            background: var(--black);
            border-radius: 50%;
        }
        .header-meta {
            font-size: .75rem;
            color: var(--gray-400);
            font-weight: 400;
        }

        /* ── Layout ──────────────────────────────── */
        .layout {
            display: flex;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        /* ── Left: canvas area ───────────────────── */
        .left {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow: hidden;
        }

        /* Toolbar strip */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            border-bottom: 1px solid var(--gray-200);
            flex-wrap: wrap;
            flex-shrink: 0;
            background: var(--white);
        }
        .sep { width: 1px; height: 22px; background: var(--gray-200); }

        /* Auto-crop nav link */
        .autocrop-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .75rem;
            font-weight: 600;
            color: var(--gray-700);
            background: var(--gray-100);
            border: 1.5px solid var(--gray-300);
            border-radius: 7px;
            padding: 6px 14px;
            text-decoration: none;
            transition: all .15s;
        }
        .autocrop-link:hover {
            background: var(--black);
            color: var(--white);
            border-color: var(--black);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 16px;
            border-radius: 7px;
            font-family: 'Poppins', sans-serif;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            transition: all .15s;
            border: 1.5px solid transparent;
            white-space: nowrap;
        }
        .btn:disabled { opacity: .35; cursor: not-allowed; }

        .btn-outline {
            background: var(--white);
            border-color: var(--gray-300);
            color: var(--gray-700);
        }
        .btn-outline:hover:not(:disabled) {
            border-color: var(--black);
            color: var(--black);
        }

        .btn-black {
            background: var(--black);
            border-color: var(--black);
            color: var(--white);
        }
        .btn-black:hover:not(:disabled) {
            background: var(--gray-900);
        }

        /* Aspect select */
        .sel {
            background: var(--white);
            border: 1.5px solid var(--gray-300);
            color: var(--gray-700);
            font-family: 'Poppins', sans-serif;
            font-size: .78rem;
            font-weight: 500;
            border-radius: 7px;
            padding: 7px 11px;
            outline: none;
            cursor: pointer;
            transition: border-color .15s;
        }
        .sel:focus { border-color: var(--black); }

        .coords-tag {
            font-size: .72rem;
            font-weight: 500;
            color: var(--gray-400);
            font-variant-numeric: tabular-nums;
            margin-left: auto;
        }
        .coords-tag b { color: var(--black); font-weight: 600; }

        /* Canvas wrapper */
        .canvas-wrap {
            flex: 1;
            overflow: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            position: relative;
            padding: 24px;
        }
        .canvas-wrap::-webkit-scrollbar { width: 6px; height: 6px; }
        .canvas-wrap::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }

        #canvas {
            display: block;
            cursor: crosshair;
            user-select: none;
            box-shadow: 0 4px 32px rgba(0,0,0,.12);
        }

        /* Upload overlay */
        #upload-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            z-index: 5;
        }
        .upload-box {
            background: var(--white);
            border: 2px dashed var(--gray-300);
            border-radius: 16px;
            padding: 52px 48px;
            text-align: center;
            cursor: pointer;
            max-width: 400px;
            width: 90%;
            transition: all .2s;
            position: relative;
        }
        .upload-box:hover, .upload-box.over {
            border-color: var(--black);
            background: var(--gray-50);
        }
        .upload-box input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .upload-icon { font-size: 2.8rem; margin-bottom: 16px; }
        .upload-box h2 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--black);
        }
        .upload-box p {
            font-size: .8rem;
            color: var(--gray-400);
            line-height: 1.6;
        }

        /* Bottom action bar */
        .action-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-top: 1px solid var(--gray-200);
            flex-shrink: 0;
            background: var(--white);
        }
        .selection-hint {
            font-size: .78rem;
            color: var(--gray-400);
            font-weight: 500;
        }
        .selection-hint b { color: var(--black); }

        /* ── Right: Cart ─────────────────────────── */
        .cart-panel {
            width: 260px;
            flex-shrink: 0;
            border-left: 1.5px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            background: var(--white);
            overflow: hidden;
        }
        .cart-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .cart-title {
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .3px;
            text-transform: uppercase;
            color: var(--black);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .cart-badge {
            background: var(--black);
            color: var(--white);
            font-size: .65rem;
            font-weight: 700;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-clear-cart {
            background: none;
            border: none;
            font-size: .72rem;
            color: var(--gray-400);
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            transition: color .15s;
        }
        .btn-clear-cart:hover { color: var(--black); }

        .cart-list {
            flex: 1;
            overflow-y: auto;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cart-list::-webkit-scrollbar { width: 4px; }
        .cart-list::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }

        .cart-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            gap: 10px;
            color: var(--gray-400);
            text-align: center;
            padding: 20px;
        }
        .cart-empty .icon { font-size: 2.2rem; }
        .cart-empty p { font-size: .78rem; line-height: 1.6; }

        /* Cart item */
        .cart-item {
            border: 1.5px solid var(--gray-200);
            border-radius: 10px;
            overflow: hidden;
            animation: slideIn .22s ease both;
            background: var(--white);
            transition: border-color .15s;
        }
        .cart-item:hover { border-color: var(--gray-400); }
        @keyframes slideIn {
            from { opacity:0; transform:translateY(8px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .cart-thumb {
            width: 100%;
            max-height: 100px;
            object-fit: contain;
            background: var(--gray-100);
            display: block;
        }
        .cart-item-footer {
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .cart-item-label {
            font-size: .7rem;
            font-weight: 600;
            color: var(--gray-700);
        }
        .cart-item-size {
            font-size: .65rem;
            color: var(--gray-400);
        }
        .cart-item-del {
            background: none;
            border: none;
            font-size: .75rem;
            color: var(--gray-400);
            cursor: pointer;
            transition: color .15s;
            padding: 2px 4px;
            line-height: 1;
        }
        .cart-item-del:hover { color: var(--black); }

        /* Cart footer */
        .cart-footer {
            padding: 12px 14px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex-shrink: 0;
        }
        .cart-footer .sel { width: 100%; }
        .dl-btn {
            width: 100%;
            justify-content: center;
            padding: 10px;
            font-size: .82rem;
            border-radius: 8px;
        }

        /* ── Live preview strip ─────────────────── */
        .preview-strip {
            border-top: 1px solid var(--gray-200);
            background: var(--white);
            flex-shrink: 0;
            display: none;
            align-items: center;
            gap: 14px;
            padding: 10px 20px;
        }
        .preview-strip.visible { display: flex; }
        .preview-label {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: var(--gray-400);
            white-space: nowrap;
        }
        .preview-canvas-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--gray-100);
            border: 1.5px solid var(--gray-200);
            border-radius: 8px;
            overflow: hidden;
            height: 80px;
        }
        #preview-canvas {
            max-width: 100%;
            max-height: 80px;
            object-fit: contain;
            display: block;
        }
        .preview-dims {
            font-size: .7rem;
            font-weight: 600;
            color: var(--gray-500);
            white-space: nowrap;
            min-width: 72px;
            text-align: right;
        }

        /* ── Cart thumb clickable ──────────────── */
        .cart-thumb {
            cursor: zoom-in;
            transition: opacity .15s;
        }
        .cart-thumb:hover { opacity: .85; }

        /* ── Lightbox ───────────────────────────── */
        #lightbox {
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(0,0,0,.82);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            cursor: zoom-out;
        }
        #lightbox.open { display: flex; }
        .lb-inner {
            position: relative;
            max-width: 90vw;
            max-height: 88vh;
            cursor: default;
            animation: lbIn .18s ease both;
        }
        @keyframes lbIn {
            from { opacity:0; transform:scale(.93); }
            to   { opacity:1; transform:scale(1); }
        }
        #lb-img {
            display: block;
            max-width: 90vw;
            max-height: 80vh;
            border-radius: 10px;
            box-shadow: 0 24px 64px rgba(0,0,0,.6);
            object-fit: contain;
            background: var(--gray-100);
        }
        .lb-meta {
            margin-top: 12px;
            text-align: center;
            color: rgba(255,255,255,.7);
            font-size: .78rem;
            font-weight: 500;
        }
        .lb-meta b { color: #fff; }
        .lb-close {
            position: absolute;
            top: -14px;
            right: -14px;
            width: 32px; height: 32px;
            background: var(--black);
            border: 2px solid rgba(255,255,255,.2);
            border-radius: 50%;
            color: #fff;
            font-size: .9rem;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: background .15s;
        }
        .lb-close:hover { background: #333; }
        .lb-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0,0,0,.55);
            border: 1.5px solid rgba(255,255,255,.15);
            color: #fff;
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: background .15s;
        }
        .lb-nav:hover { background: rgba(0,0,0,.8); }
        .lb-prev { left: -52px; }
        .lb-next { right: -52px; }
    </style>
</head>
<body>

<header>
    <div class="logo">
        <div class="logo-dot"></div>
        Image Cropper
    </div>
    <a href="autocrop.php" class="autocrop-link" id="autocrop-link">🐙 Auto Word Cropper (Kraken)</a>
    <span class="header-meta">Draw a crop → Add to Cart → Download All</span>
</header>

<div class="layout">

    <!-- LEFT: canvas -->
    <div class="left">

        <!-- Toolbar -->
        <div class="toolbar" id="toolbar" style="display:none">
            <button class="btn btn-outline" id="btn-change">↺ Change Image</button>
            <input type="file" id="file-input-hidden" accept="image/*, application/pdf" style="display:none">
            <div class="sep"></div>
            <label style="font-size:.75rem;font-weight:500;color:var(--gray-500)">Aspect</label>
            <select class="sel" id="aspect-sel">
                <option value="free">Free</option>
                <option value="1:1">1 : 1</option>
                <option value="4:3">4 : 3</option>
                <option value="3:4">3 : 4</option>
                <option value="16:9">16 : 9</option>
                <option value="9:16">9 : 16</option>
                <option value="3:2">3 : 2</option>
            </select>
            <div class="sep"></div>
            <button class="btn btn-outline" id="btn-zoom-out" disabled>−</button>
            <span id="zoom-label" style="font-size:.75rem;font-weight:600;color:var(--gray-700);min-width:36px;text-align:center">100%</span>
            <button class="btn btn-outline" id="btn-zoom-in" disabled>+</button>
            <button class="btn btn-outline" id="btn-fit" disabled>Fit</button>
            <div class="coords-tag" id="coords-tag">No selection</div>
        </div>

        <!-- Canvas area -->
        <div class="canvas-wrap" id="canvas-wrap">
            <div id="upload-overlay">
                <label class="upload-box" id="drop-zone">
                    <div class="upload-icon">🖼</div>
                    <h2>Drop an image here</h2>
                    <p>or click to browse<br>PNG · JPG · WEBP · PDF</p>
                    <input type="file" id="file-input" accept="image/*, application/pdf">
                </label>
            </div>
            <canvas id="canvas"></canvas>
        </div>

        <!-- Live preview strip -->
        <div class="preview-strip" id="preview-strip">
            <span class="preview-label">Preview</span>
            <div class="preview-canvas-wrap">
                <canvas id="preview-canvas"></canvas>
            </div>
            <span class="preview-dims" id="preview-dims">–</span>
        </div>

        <!-- Action bar -->
        <div class="action-bar" id="action-bar" style="display:none">
            <span class="selection-hint" id="sel-hint">Draw a crop area on the image</span>
            <div style="margin-left:auto;display:flex;gap:10px">
                <button class="btn btn-outline" id="btn-reset" disabled>Clear</button>
                <button class="btn btn-black" id="btn-add-cart" disabled>Add to Cart →</button>
            </div>
        </div>

    </div>

    <!-- RIGHT: cart -->
    <div class="cart-panel">
        <div class="cart-header">
            <div class="cart-title">
                Cart
                <div class="cart-badge" id="cart-badge">0</div>
            </div>
            <button class="btn-clear-cart" id="btn-clear-cart" style="display:none">Clear all</button>
        </div>
        <div class="cart-list" id="cart-list">
            <div class="cart-empty" id="cart-empty">
                <div class="icon">🛒</div>
                <p>Your crop selections<br>will appear here.<br><br>Draw a box and click<br><b>Add to Cart</b>.</p>
            </div>
        </div>
        <div class="cart-footer" id="cart-footer" style="display:none">
            <select class="sel" id="dl-format">
                <option value="image/png">PNG</option>
                <option value="image/jpeg">JPEG</option>
                <option value="image/webp">WebP</option>
            </select>
            <button class="btn btn-black dl-btn" id="btn-dl-all">⬇ Download All</button>
        </div>
    </div>

</div>

<!-- Lightbox -->
<div id="lightbox" onclick="closeLightbox(event)">
    <div class="lb-inner">
        <button class="lb-close" onclick="closeLb()">✕</button>
        <button class="lb-nav lb-prev" id="lb-prev" onclick="lbNav(-1)">‹</button>
        <button class="lb-nav lb-next" id="lb-next" onclick="lbNav(1)">›</button>
        <img id="lb-img" src="" alt="Crop preview">
        <div class="lb-meta" id="lb-meta"></div>
    </div>
</div>

<script>
// ── State ────────────────────────────────────────────────────
let sourceImg = null;
let zoom = 1;
let sel = null;          // { x, y, w, h } in canvas px
let cart = [];           // [{ dataURL, w, h }]
let dragging = false;
let dragMode = 'new';
let dragStart = { x:0, y:0 };
let selStart = null;

const HANDLE = 9;
const MIN_SEL = 5;

// ── Refs ─────────────────────────────────────────────────────
const canvas     = document.getElementById('canvas');
const ctx        = canvas.getContext('2d');
const overlay    = document.getElementById('upload-overlay');
const toolbar    = document.getElementById('toolbar');
const actionBar  = document.getElementById('action-bar');
const cartList   = document.getElementById('cart-list');
const cartEmpty  = document.getElementById('cart-empty');
const cartBadge  = document.getElementById('cart-badge');
const cartFooter = document.getElementById('cart-footer');
const coordsTag  = document.getElementById('coords-tag');
const zoomLabel  = document.getElementById('zoom-label');
const selHint    = document.getElementById('sel-hint');
const btnAddCart = document.getElementById('btn-add-cart');
const btnReset   = document.getElementById('btn-reset');
const btnClearCart=document.getElementById('btn-clear-cart');
const aspectSel  = document.getElementById('aspect-sel');

// ── File load ─────────────────────────────────────────────────
// Add pdfjsLib global setup
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

function loadFile(file) {
    if (!file) return;

    const finalizeLoad = (img) => {
        sourceImg = img;
        sel = null;
        zoom = fitZoom();
        resizeCanvas();
        render();
        overlay.style.display    = 'none';
        toolbar.style.display    = 'flex';
        actionBar.style.display  = 'flex';
        updateBtnState();
        updateCoords();
    };

    if (file.type === 'application/pdf') {
        const fileReader = new FileReader();
        fileReader.onload = async function() {
            const typedarray = new Uint8Array(this.result);
            const pdf = await pdfjsLib.getDocument(typedarray).promise;
            const page = await pdf.getPage(1);
            const viewport = page.getViewport({ scale: 2.0 }); // render at 2x for quality
            const pcvs = document.createElement('canvas');
            const pctx = pcvs.getContext('2d');
            pcvs.width = viewport.width;
            pcvs.height = viewport.height;
            await page.render({ canvasContext: pctx, viewport: viewport }).promise;
            
            const img = new Image();
            img.onload = () => finalizeLoad(img);
            img.src = pcvs.toDataURL('image/png');
        };
        fileReader.readAsArrayBuffer(file);
        return;
    }

    if (!file.type.startsWith('image/')) return;
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => finalizeLoad(img);
    img.src = url;
}

document.getElementById('file-input').addEventListener('change', e => loadFile(e.target.files[0]));
document.getElementById('file-input-hidden').addEventListener('change', e => {
    loadFile(e.target.files[0]);
    e.target.value = '';
});
document.getElementById('btn-change').addEventListener('click', () => {
    document.getElementById('file-input-hidden').click();
});

// Drag-over on drop zone
const dz = document.getElementById('drop-zone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('over'));
dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.classList.remove('over');
    loadFile(e.dataTransfer.files[0]);
});
// Also allow drop on canvas wrap when image is loaded
document.getElementById('canvas-wrap').addEventListener('dragover', e => e.preventDefault());
document.getElementById('canvas-wrap').addEventListener('drop', e => {
    e.preventDefault();
    if (sourceImg) loadFile(e.dataTransfer.files[0]);
});

// ── Zoom helpers ──────────────────────────────────────────────
function fitZoom() {
    const wrap = document.getElementById('canvas-wrap');
    const mw = (wrap.clientWidth  || 800) - 48;
    const mh = (wrap.clientHeight || 600) - 48;
    return Math.min(mw / sourceImg.naturalWidth, mh / sourceImg.naturalHeight, 1);
}

function resizeCanvas() {
    canvas.width  = Math.round(sourceImg.naturalWidth  * zoom);
    canvas.height = Math.round(sourceImg.naturalHeight * zoom);
    zoomLabel.textContent = Math.round(zoom * 100) + '%';
}

function setZoom(z) {
    const old = zoom;
    zoom = Math.max(0.05, Math.min(8, z));
    if (sel) {
        const r = zoom / old;
        sel = { x:sel.x*r, y:sel.y*r, w:sel.w*r, h:sel.h*r };
    }
    resizeCanvas();
    render();
}

document.getElementById('btn-zoom-in').addEventListener('click',  () => setZoom(zoom * 1.25));
document.getElementById('btn-zoom-out').addEventListener('click', () => setZoom(zoom / 1.25));
document.getElementById('btn-fit').addEventListener('click', () => { zoom=fitZoom(); resizeCanvas(); render(); });
canvas.addEventListener('wheel', e => { e.preventDefault(); setZoom(zoom * (e.deltaY<0 ? 1.12 : .88)); }, {passive:false});
window.addEventListener('resize', () => { if (sourceImg) { zoom=fitZoom(); resizeCanvas(); render(); } });

// ── Render ────────────────────────────────────────────────────
function render() {
    if (!sourceImg) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(sourceImg, 0, 0, canvas.width, canvas.height);

    // Draw already cropped areas from cart
    if (cart.length > 0) {
        ctx.save();
        ctx.fillStyle = 'rgba(40, 167, 69, 0.25)'; // green tint
        ctx.strokeStyle = 'rgba(40, 167, 69, 0.8)';
        ctx.lineWidth = 2;
        cart.forEach(item => {
            if (item.ox !== undefined) {
                ctx.fillRect(item.ox * zoom, item.oy * zoom, item.ow * zoom, item.oh * zoom);
                ctx.strokeRect(item.ox * zoom, item.oy * zoom, item.ow * zoom, item.oh * zoom);
            }
        });
        ctx.restore();
    }

    if (!sel) {
        updateLivePreview(null);
        return;
    }

    // Dim area outside selection
    ctx.save();
    ctx.fillStyle = 'rgba(0,0,0,0.45)';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.globalCompositeOperation = 'destination-out';
    ctx.fillStyle = 'rgba(0,0,0,1)';
    ctx.fillRect(sel.x, sel.y, sel.w, sel.h);
    ctx.globalCompositeOperation = 'source-over';
    ctx.drawImage(sourceImg, 0, 0, canvas.width, canvas.height);
    ctx.restore();

    // Border
    ctx.save();
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2;
    ctx.strokeRect(sel.x+.5, sel.y+.5, sel.w, sel.h);

    // Rule of thirds
    ctx.strokeStyle = 'rgba(255,255,255,.35)';
    ctx.lineWidth = .8;
    ctx.setLineDash([4,4]);
    [1,2].forEach(i => {
        ctx.beginPath(); ctx.moveTo(sel.x+sel.w/3*i, sel.y); ctx.lineTo(sel.x+sel.w/3*i, sel.y+sel.h); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(sel.x, sel.y+sel.h/3*i); ctx.lineTo(sel.x+sel.w, sel.y+sel.h/3*i); ctx.stroke();
    });
    ctx.setLineDash([]);

    // Handles
    getHandles().forEach(h => {
        ctx.fillStyle   = '#fff';
        ctx.strokeStyle = '#000';
        ctx.lineWidth   = 1.5;
        ctx.beginPath();
        ctx.roundRect(h.x-HANDLE/2, h.y-HANDLE/2, HANDLE, HANDLE, 2);
        ctx.fill(); ctx.stroke();
    });
    ctx.restore();

    updateLivePreview(sel);
}

// ── Live preview strip ─────────────────────────────────────────
const previewStrip  = document.getElementById('preview-strip');
const previewCanvas = document.getElementById('preview-canvas');
const previewDims   = document.getElementById('preview-dims');
const pCtx          = previewCanvas.getContext('2d');

function updateLivePreview(s) {
    if (!s || !sourceImg) {
        previewStrip.classList.remove('visible');
        return;
    }
    previewStrip.classList.add('visible');
    const rw = Math.round(s.w / zoom);
    const rh = Math.round(s.h / zoom);
    const rx = Math.round(s.x / zoom);
    const ry = Math.round(s.y / zoom);
    previewCanvas.width  = rw;
    previewCanvas.height = rh;
    pCtx.clearRect(0, 0, rw, rh);
    pCtx.drawImage(sourceImg, rx, ry, rw, rh, 0, 0, rw, rh);
    previewDims.textContent = rw + ' × ' + rh + 'px';
}

// ── Handles ───────────────────────────────────────────────────
function getHandles() {
    if (!sel) return [];
    const {x,y,w,h} = sel;
    return [
        {id:'nw',x,y},{id:'n',x:x+w/2,y},{id:'ne',x:x+w,y},
        {id:'e',x:x+w,y:y+h/2},{id:'se',x:x+w,y:y+h},
        {id:'s',x:x+w/2,y:y+h},{id:'sw',x,y:y+h},{id:'w',x,y:y+h/2}
    ];
}
function hitHandle(mx,my) {
    for (const h of getHandles())
        if (Math.abs(mx-h.x)<=HANDLE && Math.abs(my-h.y)<=HANDLE) return h.id;
    return null;
}
function inSel(mx,my) {
    return sel && mx>sel.x && mx<sel.x+sel.w && my>sel.y && my<sel.y+sel.h;
}
function clamp(v,lo,hi) { return Math.max(lo, Math.min(hi, v)); }

function enforceAspect(w,h) {
    const v = aspectSel.value;
    if (v==='free') return {w,h};
    const [aw,ah] = v.split(':').map(Number);
    const r = aw/ah;
    return w/h > r ? {w:h*r,h} : {w,h:w/r};
}

// ── Mouse events ──────────────────────────────────────────────
function canvasXY(e) {
    const r = canvas.getBoundingClientRect();
    const s = e.touches ? e.touches[0] : e;
    return { x: s.clientX-r.left, y: s.clientY-r.top };
}

canvas.addEventListener('mousedown',  startDrag);
canvas.addEventListener('touchstart', startDrag, {passive:false});

function startDrag(e) {
    if (!sourceImg) return;
    e.preventDefault();
    const {x,y} = canvasXY(e);
    dragStart = {x,y};
    selStart  = sel ? {...sel} : null;
    const h   = hitHandle(x,y);
    if (h)         { dragMode = h;     dragging=true; return; }
    if (inSel(x,y)){ dragMode = 'move'; dragging=true; return; }
    dragMode='new'; dragging=true;
    sel=null; render();
}

window.addEventListener('mousemove',  moveDrag);
window.addEventListener('touchmove',  moveDrag, {passive:false});

function moveDrag(e) {
    if (!dragging) {
        if (!sourceImg) return;
        const {x,y} = canvasXY(e);
        const h = hitHandle(x,y);
        if (h) { canvas.style.cursor = cursorFor(h); return; }
        canvas.style.cursor = inSel(x,y) ? 'move' : 'crosshair';
        return;
    }
    e.preventDefault();
    const {x,y} = canvasXY(e);
    const dx=x-dragStart.x, dy=y-dragStart.y;
    const W=canvas.width, H=canvas.height;

    if (dragMode==='new') {
        const {w,h} = enforceAspect(Math.abs(dx), Math.abs(dy));
        sel = { x:dx>=0?dragStart.x:dragStart.x-w, y:dy>=0?dragStart.y:dragStart.y-h, w, h };
    } else if (dragMode==='move') {
        sel = { x:clamp(selStart.x+dx,0,W-selStart.w), y:clamp(selStart.y+dy,0,H-selStart.h), w:selStart.w, h:selStart.h };
    } else {
        let {x:sx,y:sy,w:sw,h:sh} = selStart;
        let nx=sx,ny=sy,nw=sw,nh=sh;
        if (dragMode.includes('e')) nw=Math.max(MIN_SEL,sw+dx);
        if (dragMode.includes('s')) nh=Math.max(MIN_SEL,sh+dy);
        if (dragMode.includes('w')) { nw=Math.max(MIN_SEL,sw-dx); nx=sx+sw-nw; }
        if (dragMode.includes('n')) { nh=Math.max(MIN_SEL,sh-dy); ny=sy+sh-nh; }
        const asp=enforceAspect(nw,nh);
        if (dragMode.includes('n')) ny=ny+(nh-asp.h);
        if (dragMode.includes('w')) nx=nx+(nw-asp.w);
        sel = { x:clamp(nx,0,W-asp.w), y:clamp(ny,0,H-asp.h), w:asp.w, h:asp.h };
    }
    render();
    updateCoords();
}

window.addEventListener('mouseup',  endDrag);
window.addEventListener('touchend', endDrag);

function endDrag() {
    if (!dragging) return;
    dragging=false;
    if (sel && (sel.w<MIN_SEL||sel.h<MIN_SEL)) { sel=null; render(); }
    updateBtnState();
    updateCoords();
}

function cursorFor(h) {
    return {nw:'nw-resize',ne:'ne-resize',sw:'sw-resize',se:'se-resize',
            n:'n-resize',s:'s-resize',e:'e-resize',w:'w-resize'}[h]||'move';
}

// ── Coords display ─────────────────────────────────────────────
function updateCoords() {
    if (!sel) { coordsTag.textContent='No selection'; return; }
    const rw=Math.round(sel.w/zoom), rh=Math.round(sel.h/zoom);
    coordsTag.innerHTML=`<b>${rw} × ${rh}</b> px`;
}

// ── UI state ──────────────────────────────────────────────────
function updateBtnState() {
    const hasSel = !!sel;
    btnAddCart.disabled = !hasSel;
    btnReset.disabled   = !hasSel;
    document.getElementById('btn-zoom-in').disabled  = !sourceImg;
    document.getElementById('btn-zoom-out').disabled = !sourceImg;
    document.getElementById('btn-fit').disabled      = !sourceImg;
    selHint.innerHTML = hasSel
        ? `Selection: <b>${Math.round(sel.w/zoom)} × ${Math.round(sel.h/zoom)}px</b> — ready to add`
        : 'Draw a crop area on the image';
}

// ── Add to cart ───────────────────────────────────────────────
btnAddCart.addEventListener('click', () => {
    if (!sel || !sourceImg) return;

    // Render crop to offscreen canvas
    const rw=Math.round(sel.w/zoom), rh=Math.round(sel.h/zoom);
    const rx=Math.round(sel.x/zoom), ry=Math.round(sel.y/zoom);
    const tmp=document.createElement('canvas');
    tmp.width=rw; tmp.height=rh;
    tmp.getContext('2d').drawImage(sourceImg, rx, ry, rw, rh, 0, 0, rw, rh);
    const dataURL=tmp.toDataURL('image/png');

    cart.push({ dataURL, w:rw, h:rh, ox:rx, oy:ry, ow:rw, oh:rh });
    refreshCart();

    // Flash feedback
    const origText = btnAddCart.textContent;
    btnAddCart.textContent = '✓ Added!';
    btnAddCart.style.background = '#222';
    setTimeout(() => {
        btnAddCart.textContent = 'Add to Cart →';
        btnAddCart.style.background = '';
    }, 900);

    // Clear selection so user can draw another
    sel=null; render(); updateBtnState(); updateCoords();
});

// ── Cart ───────────────────────────────────────────────────────
function refreshCart() {
    cartBadge.textContent = cart.length;
    const hasItems = cart.length > 0;
    btnClearCart.style.display = hasItems ? 'inline' : 'none';
    cartFooter.style.display   = hasItems ? 'flex'   : 'none';

    if (!hasItems) {
        cartList.innerHTML = '';
        cartList.appendChild(cartEmpty);
        return;
    }

    cartList.innerHTML = '';
    cart.forEach((item, i) => {
        const div = document.createElement('div');
        div.className = 'cart-item';

        // Thumbnail — click opens lightbox
        const img = document.createElement('img');
        img.className = 'cart-thumb';
        img.src = item.dataURL;
        img.alt = `Crop ${i + 1}`;
        img.title = 'Click to preview';
        img.addEventListener('click', () => openLightbox(i));
        div.appendChild(img);

        const footer = document.createElement('div');
        footer.className = 'cart-item-footer';
        footer.innerHTML = `
            <div>
                <div class="cart-item-label">Crop ${i + 1}</div>
                <div class="cart-item-size">${item.w} × ${item.h}px</div>
            </div>`;

        const del = document.createElement('button');
        del.className = 'cart-item-del';
        del.textContent = '✕';
        del.title = 'Remove';
        del.addEventListener('click', () => { cart.splice(i, 1); refreshCart(); render(); });
        footer.appendChild(del);
        div.appendChild(footer);
        cartList.appendChild(div);
    });
}

btnClearCart.addEventListener('click', () => { cart = []; refreshCart(); render(); });

// ── Lightbox ──────────────────────────────────────────────────
let lbIdx = 0;
const lightbox = document.getElementById('lightbox');
const lbImg    = document.getElementById('lb-img');
const lbMeta   = document.getElementById('lb-meta');

function openLightbox(idx) {
    lbIdx = idx;
    showLb();
    lightbox.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function showLb() {
    const item = cart[lbIdx];
    if (!item) return;
    lbImg.src = item.dataURL;
    lbMeta.innerHTML = `<b>Crop ${lbIdx + 1}</b> &nbsp;·&nbsp; ${item.w} × ${item.h}px`;
    document.getElementById('lb-prev').style.display = lbIdx > 0                  ? 'flex' : 'none';
    document.getElementById('lb-next').style.display = lbIdx < cart.length - 1    ? 'flex' : 'none';
}

function lbNav(dir) {
    lbIdx = Math.max(0, Math.min(cart.length - 1, lbIdx + dir));
    showLb();
}

function closeLb() {
    lightbox.classList.remove('open');
    document.body.style.overflow = '';
}

function closeLightbox(e) {
    if (e.target === lightbox) closeLb();
}

window.addEventListener('keydown', e => {
    if (!lightbox.classList.contains('open')) return;
    if (e.key === 'Escape')      closeLb();
    if (e.key === 'ArrowLeft')   lbNav(-1);
    if (e.key === 'ArrowRight')  lbNav(1);
});

// ── Download all ──────────────────────────────────────────────
document.getElementById('btn-dl-all').addEventListener('click', async () => {
    if (!cart.length) return;
    const fmt   = document.getElementById('dl-format').value;
    const ext   = fmt.split('/')[1];
    const dlBtn = document.getElementById('btn-dl-all');

    dlBtn.textContent = '⏳ Zipping…';
    dlBtn.disabled    = true;

    const zip = new JSZip();
    for (let i = 0; i < cart.length; i++) {
        let dataURL = cart[i].dataURL;
        if (fmt !== 'image/png') {
            const tmp = document.createElement('canvas');
            const im  = new Image();
            await new Promise(res => { im.onload = res; im.src = dataURL; });
            tmp.width = im.naturalWidth; tmp.height = im.naturalHeight;
            tmp.getContext('2d').drawImage(im, 0, 0);
            dataURL = tmp.toDataURL(fmt, 0.95);
        }
        zip.file(`crop_${String(i + 1).padStart(2, '0')}.${ext}`, dataURL.split(',')[1], { base64: true });
    }

    const blob = await zip.generateAsync({ type: 'blob' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = `crops_${Date.now()}.zip`; a.click();
    URL.revokeObjectURL(url);

    dlBtn.textContent = '⬇ Download All';
    dlBtn.disabled    = false;
});

// ── Reset selection ───────────────────────────────────────────
btnReset.addEventListener('click', () => { sel = null; render(); updateBtnState(); updateCoords(); });

// ── Aspect change ─────────────────────────────────────────────
aspectSel.addEventListener('change', () => {
    if (!sel) return;
    const { w, h } = enforceAspect(sel.w, sel.h);
    sel = { ...sel, w, h }; render(); updateCoords();
});

// ── Keyboard shortcuts (non-lightbox) ────────────────────────
window.addEventListener('keydown', e => {
    if (lightbox.classList.contains('open')) return;
    if (e.target.tagName === 'SELECT') return;
    if (e.key === 'Escape') btnReset.click();
    if ((e.key === 'Enter' || e.key === ' ') && !btnAddCart.disabled) btnAddCart.click();
    if (e.key === '+' || e.key === '=') setZoom(zoom * 1.15);
    if (e.key === '-') setZoom(zoom / 1.15);
});

// ── Initial render ────────────────────────────────────────────
refreshCart();
</script>
</body>
</html>