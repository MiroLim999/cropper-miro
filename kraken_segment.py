#!/usr/bin/env python3
"""
kraken_segment.py  –  Word-level extractor powered by Kraken BLLA + Adaptive Word Segmentation.

Usage:
    python kraken_segment.py <image_path>

Outputs a JSON array to stdout, one object per detected word:
    [{"x": int, "y": int, "w": int, "h": int}, ...]

Optimizations vs. previous version:
  * Speed    – image is binarized ONCE (global Otsu, numpy) instead of per line,
               scipy.ndimage labelling replaces skimage.regionprops (much faster),
               torch uses all CPU cores + flush-denormal, inference_mode.
  * Accuracy – each line is masked by its Kraken boundary POLYGON, so ascenders /
               descenders of neighbouring lines no longer leak into a word box,
               overlapping duplicate boxes are removed (NMS), words are output in
               true reading order (Kraken line order, then left→right).
"""
import os
import sys
import warnings

warnings.filterwarnings("ignore")
os.environ["PYTHONWARNINGS"] = "ignore"

_cores = os.cpu_count() or 4
os.environ.setdefault("OMP_NUM_THREADS", str(_cores))
os.environ.setdefault("MKL_NUM_THREADS", str(_cores))

import json
import numpy as np

# ── Minimal arg check ──────────────────────────────────────────────────────────
if len(sys.argv) != 2:
    print("Usage: kraken_segment.py <image_path>", file=sys.stderr)
    sys.exit(1)

image_path = sys.argv[1]

# Kraken's BLLA model rescales EVERY image to 1800 px height internally, which is
# the main bottleneck on CPU. We lower the network input height adaptively:
#   - never above the image's own height (no pointless upscaling)
#   - clamped to [MIN_NET_H, MAX_NET_H]
# Benchmarks (CPU): 1800px=21.6s, 1400=13.4s, 1200=9.5s, 1000=6.8s, same lines found.
# Override with env KRAKEN_MAX_NET_H (raise for tiny/dense text).
MAX_NET_H = int(os.environ.get("KRAKEN_MAX_NET_H", "1200"))
MIN_NET_H = int(os.environ.get("KRAKEN_MIN_NET_H", "900"))

# ── Imports ───────────────────────────────────────────────────────────────────
try:
    import torch

    if torch.cuda.is_available():
        device = "cuda"
    else:
        device = "cpu"
        torch.set_num_threads(_cores)
        torch.set_flush_denormal(True)

    from PIL import Image, ImageDraw
    from scipy import ndimage
    from kraken import blla
    from kraken.lib import vgsl
    from importlib import resources
except ImportError as exc:
    print(f"Import error: {exc}", file=sys.stderr)
    sys.exit(1)

# ── Load image ─────────────────────────────────────────────────────────────────
try:
    im = Image.open(image_path)
    im.load()
    im = im.convert("RGB")
    im_w, im_h = im.size
except Exception as exc:
    print(f"Cannot open image '{image_path}': {exc}", file=sys.stderr)
    sys.exit(1)


# ── Fast Otsu threshold (numpy histogram) ─────────────────────────────────────
def otsu(values, bins=256):
    values = np.asarray(values).ravel()
    if values.size == 0:
        return 128
    lo, hi = float(values.min()), float(values.max())
    if hi - lo < 1e-6:
        return lo
    hist, edges = np.histogram(values, bins=bins, range=(lo, hi))
    hist = hist.astype(np.float64)
    centers = (edges[:-1] + edges[1:]) / 2
    w0 = np.cumsum(hist)
    w1 = w0[-1] - w0
    m0 = np.cumsum(hist * centers)
    mu0 = m0 / np.maximum(w0, 1e-9)
    mu1 = (m0[-1] - m0) / np.maximum(w1, 1e-9)
    between = w0 * w1 * (mu0 - mu1) ** 2
    return float(centers[int(np.argmax(between))])


# ── Global binarization (done once for whole image) ───────────────────────────
gray = np.asarray(im.convert("L"), dtype=np.uint8)
g_th = otsu(gray)
if gray.mean() > 127:          # dark text on light background (normal)
    ink = gray < g_th
else:                          # light text on dark background
    ink = gray > g_th


# ── Grid-line (table border) detection ────────────────────────────────────────
# Long straight horizontal / vertical ink runs are table or form lines. They are
# removed from the ink mask so they never join words, and they define "cells"
# that every word crop is later clipped to.
def detect_grid(ink_mask):
    H, W = ink_mask.shape
    u = ink_mask.astype(np.uint8)
    Lh = max(40, W // 15)
    Lv = max(40, H // 15)
    hor = ndimage.maximum_filter1d(ndimage.minimum_filter1d(u, Lh, axis=1), Lh, axis=1).astype(bool)
    ver = ndimage.maximum_filter1d(ndimage.minimum_filter1d(u, Lv, axis=0), Lv, axis=0).astype(bool)
    # thicken by 1px to swallow anti-aliased edges
    hor = ndimage.maximum_filter1d(hor.astype(np.uint8), 3, axis=0).astype(bool)
    ver = ndimage.maximum_filter1d(ver.astype(np.uint8), 3, axis=1).astype(bool)
    return hor, ver


h_lines, v_lines = detect_grid(ink)
grid_mask = h_lines | v_lines
has_grid = bool(grid_mask.sum() > 0) and (h_lines.any() or v_lines.any())
ink = ink & ~grid_mask

if has_grid:
    cell_lbl, _n_cells = ndimage.label(~grid_mask)
    cell_objs = ndimage.find_objects(cell_lbl)
else:
    cell_lbl, cell_objs = None, None


def cell_bounds(x, y, w, h):
    """Bounds (x0,y0,x1,y1) of the grid cell containing the box centre."""
    if cell_lbl is None:
        return None
    cx = min(im_w - 1, max(0, x + w // 2)); cy = min(im_h - 1, max(0, y + h // 2))
    k = int(cell_lbl[cy, cx])
    if k == 0:
        return None
    sl = cell_objs[k - 1]
    return [int(sl[1].start), int(sl[0].start), int(sl[1].stop), int(sl[0].stop)]


# ── Split one (masked) line into words ────────────────────────────────────────
def segment_line_into_words(line_ink, vcols=None):
    """
    line_ink: boolean ndarray (H, W) – True where ink belongs to this line.
    Returns list of word boxes relative to the line crop.
    """
    H, W = line_ink.shape
    if W < 8 or H < 4 or not line_ink.any():
        return []

    lbl, n = ndimage.label(line_ink)
    if n == 0:
        return []
    slices = ndimage.find_objects(lbl)
    areas = np.bincount(lbl.ravel())[1:]

    min_area = max(3, int(H * 0.03))
    comps = []
    for i, sl in enumerate(slices):
        if sl is None or areas[i] < min_area:
            continue
        y0, y1 = sl[0].start, sl[0].stop
        x0, x1 = sl[1].start, sl[1].stop
        if (y1 - y0) < 2:
            continue
        comps.append([x0, y0, x1, y1])

    if not comps:
        return []

    comps.sort(key=lambda c: c[0])

    # Merge horizontally overlapping glyph parts (i-dots, accents, broken strokes)
    merged = [comps[0][:]]
    for c in comps[1:]:
        last = merged[-1]
        if c[0] <= last[2] + 1:
            last[0] = min(last[0], c[0]); last[1] = min(last[1], c[1])
            last[2] = max(last[2], c[2]); last[3] = max(last[3], c[3])
        else:
            merged.append(c[:])

    def box(group):
        x0 = min(g[0] for g in group); y0 = min(g[1] for g in group)
        x1 = max(g[2] for g in group); y1 = max(g[3] for g in group)
        return {"x": int(x0), "y": int(y0), "w": int(x1 - x0), "h": int(y1 - y0)}

    if len(merged) == 1:
        return [box(merged)]

    gaps = np.array([merged[i + 1][0] - merged[i][2] for i in range(len(merged) - 1)])

    # x-height estimate from glyph heights (robust to tall capitals / descenders)
    heights = np.array([m[3] - m[1] for m in merged])
    med_h = float(np.median(heights)) if heights.size else H

    # Lower bound: inter-letter gaps are rarely > 25% of x-height
    min_space = max(4, int(round(med_h * 0.25)))
    # Upper bound: a gap wider than ~0.9 x-height is always a word space
    max_space = max(min_space + 1, int(round(med_h * 0.9)))

    pos = gaps[gaps > 0]
    if pos.size >= 3 and np.unique(pos).size > 1:
        gap_th = otsu(pos, bins=min(64, int(pos.max()) + 1))
        gap_th = int(min(max(gap_th, min_space), max_space))
    else:
        gap_th = min_space

    words, curr = [], [merged[0]]
    for i, gap in enumerate(gaps):
        crosses_grid = vcols is not None and vcols[merged[i][2]:merged[i + 1][0]].any()
        if gap > gap_th or crosses_grid:
            words.append(box(curr))
            curr = [merged[i + 1]]
        else:
            curr.append(merged[i + 1])
    words.append(box(curr))
    return words


# ── Load BLLA model with adaptive network input height ─────────────────────────────
try:
    seg_model = vgsl.TorchVGSLModel.load_model(
        str(resources.files("kraken").joinpath("blla.mlmodel")))
    b, c, h, w = seg_model.input
    net_h = int(max(MIN_NET_H, min(MAX_NET_H, h, im_h)))
    seg_model.input = (b, c, net_h, w)
except Exception:
    seg_model = None  # fall back to Kraken's default loader

# ── Run Kraken baseline segmentation (coords returned in original image space) ─
def run_blla(dev):
    with torch.inference_mode():
        if seg_model is not None:
            return blla.segment(im, model=seg_model, device=dev)
        return blla.segment(im, device=dev)

try:
    segmentation = run_blla(device)
except Exception as exc:
    if device == "cuda":
        try:
            segmentation = run_blla("cpu")
        except Exception as cpu_exc:
            print(f"Kraken segmentation failed: {cpu_exc}", file=sys.stderr)
            sys.exit(1)
    else:
        print(f"Kraken segmentation failed: {exc}", file=sys.stderr)
        sys.exit(1)

# ── Build line regions (bbox + polygon) in original coordinates ───────────────
lines = []
for line in (segmentation.lines or []):
    poly = getattr(line, "boundary", None)
    pts = poly or getattr(line, "baseline", None)
    if pts:
        pts = [(float(p[0]), float(p[1])) for p in pts]
        xs = [p[0] for p in pts]; ys = [p[1] for p in pts]
        x0, y0, x1, y1 = min(xs), min(ys), max(xs), max(ys)
    else:
        bbox = getattr(line, "bbox", None)
        if not bbox:
            continue
        x0, y0, x1, y1 = bbox
        poly = None

    x0 = max(0, min(im_w - 1, int(x0)))
    y0 = max(0, min(im_h - 1, int(y0)))
    x1 = max(x0 + 1, min(im_w, int(np.ceil(x1))))
    y1 = max(y0 + 1, min(im_h, int(np.ceil(y1))))
    if (x1 - x0) <= 4 or (y1 - y0) <= 4:
        continue

    lines.append({"x0": x0, "y0": y0, "x1": x1, "y1": y1,
                  "poly": pts if poly else None})

# ── Segment each line into words using polygon-masked ink ────────────────────
all_word_boxes = []

for li, ln in enumerate(lines):
    x0, y0, x1, y1 = ln["x0"], ln["y0"], ln["x1"], ln["y1"]
    line_ink = ink[y0:y1, x0:x1]

    if ln["poly"] and len(ln["poly"]) >= 3:
        mask_img = Image.new("1", (x1 - x0, y1 - y0), 0)
        ImageDraw.Draw(mask_img).polygon(
            [(px - x0, py - y0) for px, py in ln["poly"]], fill=1, outline=1)
        line_ink = line_ink & np.asarray(mask_img, dtype=bool)

    try:
        vc = v_lines[y0:y1, x0:x1].any(axis=0) if has_grid else None
        rel = segment_line_into_words(line_ink, vc)
    except Exception:
        rel = []
    if not rel:
        continue

    for wb in rel:
        ax = x0 + wb["x"]; ay = y0 + wb["y"]
        aw = min(im_w - ax, wb["w"]); ah = min(im_h - ay, wb["h"])
        if aw > 3 and ah > 3:
            all_word_boxes.append({"x": int(ax), "y": int(ay), "w": int(aw), "h": int(ah),
                                   "_line": li})

# ── Fallback: no Kraken lines → segment whole page as one block ──────────────
if not all_word_boxes:
    try:
        for wb in segment_line_into_words(ink):
            if wb["w"] > 3 and wb["h"] > 3:
                all_word_boxes.append({**wb, "_line": 0})
    except Exception:
        pass

if not all_word_boxes:
    print("No text regions detected by Kraken.", file=sys.stderr)
    sys.exit(2)

# ── Remove duplicates from overlapping line polygons (NMS on containment) ────
def overlap_ratio(a, b):
    ix = max(0, min(a["x"] + a["w"], b["x"] + b["w"]) - max(a["x"], b["x"]))
    iy = max(0, min(a["y"] + a["h"], b["y"] + b["h"]) - max(a["y"], b["y"]))
    inter = ix * iy
    if inter == 0:
        return 0.0
    return inter / float(min(a["w"] * a["h"], b["w"] * b["h"]))

all_word_boxes.sort(key=lambda b: b["w"] * b["h"], reverse=True)
kept = []
for b in all_word_boxes:
    if all(overlap_ratio(b, k) < 0.8 for k in kept):
        kept.append(b)

# ── Keep ONLY handwritten words (drop machine-printed text) ───────────────────
# Heuristics (no extra model needed):
#   1. Ink colour – if the page has coloured ink (blue/red pen) over black print,
#      words whose ink is mostly coloured are handwriting.
#   2. Stroke-width regularity – printed glyphs have near-constant stroke width,
#      pen strokes vary. Measured on the ridge of the distance transform.
HANDWRITTEN_ONLY = os.environ.get("KRAKEN_HANDWRITTEN_ONLY", "0") == "1"
CV_THRESHOLD = float(os.environ.get("KRAKEN_HW_CV", "0.30"))
rgb = np.asarray(im, dtype=np.int16)
chroma = rgb.max(axis=2) - rgb.min(axis=2)
colored_ink = ink & (chroma > 45)
page_colored = colored_ink.sum() > max(200, 0.02 * ink.sum())


def is_handwritten(b):
    x, y, w, h = b["x"], b["y"], b["w"], b["h"]
    m = ink[y:y + h, x:x + w]
    n = int(m.sum())
    if n < 20:
        return False
    if page_colored:
        return colored_ink[y:y + h, x:x + w].sum() / n > 0.35
    dt = ndimage.distance_transform_edt(m)
    ridge = (dt == ndimage.maximum_filter(dt, size=3)) & (dt > 0.5)
    vals = dt[ridge]
    if vals.size < 8:
        return False
    cv = float(vals.std() / max(vals.mean(), 1e-6))
    return cv > CV_THRESHOLD


if HANDWRITTEN_ONLY:
    hw = [b for b in kept if is_handwritten(b)]
    if hw:
        kept = hw
    else:
        print("No handwritten words detected; keeping all words.", file=sys.stderr)

# ── Reading order: Kraken line order, then left → right ──────────────────────
kept.sort(key=lambda b: (b["_line"], b["x"]))
for b in kept:
    b.pop("_line", None)
    cb = cell_bounds(b["x"], b["y"], b["w"], b["h"])
    if cb:
        b["cell"] = cb

print(json.dumps(kept))
