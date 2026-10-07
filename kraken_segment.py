#!/usr/bin/env python3
"""
kraken_segment.py  –  Word-level extractor powered by Kraken BLLA + Adaptive Word Segmentation.

Usage:
    python kraken_segment.py <image_path>

Outputs a JSON array to stdout, one object per detected word:
    [{"x": int, "y": int, "w": int, "h": int}, ...]
"""
import os
import sys
import warnings

# Suppress noisy deprecation/library warnings so stdout stays clean
warnings.filterwarnings("ignore")
os.environ["PYTHONWARNINGS"] = "ignore"

import json
import numpy as np

# ── Minimal arg check ──────────────────────────────────────────────────────────
if len(sys.argv) != 2:
    print("Usage: kraken_segment.py <image_path>", file=sys.stderr)
    sys.exit(1)

image_path = sys.argv[1]

# ── Imports ───────────────────────────────────────────────────────────────────
try:
    import torch

    # Auto-detect hardware: Prefer NVIDIA CUDA GPU if available, else multi-threaded CPU
    if torch.cuda.is_available():
        device = "cuda"
    else:
        device = "cpu"
        # Avoid artificial 1-thread throttling: utilize available CPU cores
        cpu_cores = os.cpu_count() or 4
        torch.set_num_threads(min(8, cpu_cores))

    from PIL import Image
    from kraken import blla
    from skimage.filters import threshold_otsu
    from skimage.measure import label, regionprops
except ImportError as exc:
    print(f"Import error: {exc}", file=sys.stderr)
    sys.exit(1)

# ── Load image ─────────────────────────────────────────────────────────────────
try:
    im = Image.open(image_path).convert("RGB")
    im_w, im_h = im.size
except Exception as exc:
    print(f"Cannot open image '{image_path}': {exc}", file=sys.stderr)
    sys.exit(1)

# ── Helper: Segment a single line crop into individual words ───────────────────
def segment_line_into_words(line_crop_pil):
    """
    Given a PIL image of a text line, segments it into individual single words.
    Returns a list of dicts: [{'x': int, 'y': int, 'w': int, 'h': int}, ...] (relative coords).
    """
    arr = np.array(line_crop_pil.convert('L'))
    H, W = arr.shape
    if W < 8 or H < 4:
        return [{'x': 0, 'y': 0, 'w': W, 'h': H}]

    try:
        th = threshold_otsu(arr)
    except Exception:
        th = 128

    # Dark text on light background vs light text on dark background
    if np.mean(arr) > 127:
        bin_img = arr < th
    else:
        bin_img = arr > th

    lbl = label(bin_img)
    # Filter tiny speckle noise
    min_comp_area = max(3, int(H * 0.03))
    props = [p for p in regionprops(lbl) if p.area >= min_comp_area and (p.bbox[2] - p.bbox[0]) >= 2]

    if not props:
        return [{'x': 0, 'y': 0, 'w': W, 'h': H}]

    comps = [{'x0': p.bbox[1], 'y0': p.bbox[0], 'x1': p.bbox[3], 'y1': p.bbox[2]} for p in props]
    comps.sort(key=lambda c: c['x0'])

    # Step 1: Merge horizontally overlapping glyph parts (e.g. i-dots, accents, ligatures)
    merged = []
    for c in comps:
        if not merged:
            merged.append(dict(c))
        else:
            last = merged[-1]
            # If overlapping horizontally or within 2px
            if c['x0'] <= last['x1'] + 2:
                last['x0'] = min(last['x0'], c['x0'])
                last['y0'] = min(last['y0'], c['y0'])
                last['x1'] = max(last['x1'], c['x1'])
                last['y1'] = max(last['y1'], c['y1'])
            else:
                merged.append(dict(c))

    if len(merged) <= 1:
        x0 = merged[0]['x0'] if merged else 0
        y0 = merged[0]['y0'] if merged else 0
        x1 = merged[0]['x1'] if merged else W
        y1 = merged[0]['y1'] if merged else H
        return [{'x': int(x0), 'y': int(y0), 'w': max(1, int(x1 - x0)), 'h': max(1, int(y1 - y0))}]

    # Step 2: Compute gaps between consecutive glyph clusters
    gaps = [merged[i + 1]['x0'] - merged[i]['x1'] for i in range(len(merged) - 1)]

    # Character height estimate
    char_heights = [m['y1'] - m['y0'] for m in merged]
    med_h = np.median(char_heights) if char_heights else H
    min_space = max(5, int(med_h * 0.25))

    # Adaptive gap threshold
    distinct_gaps = [g for g in gaps if g > 0]
    if len(set(distinct_gaps)) > 1:
        try:
            gap_th = max(min_space, int(threshold_otsu(np.array(distinct_gaps))))
        except Exception:
            gap_th = min_space
    else:
        gap_th = min_space

    words = []
    curr = [merged[0]]
    for i, gap in enumerate(gaps):
        nxt = merged[i + 1]
        if gap >= gap_th:
            wx0 = min(item['x0'] for item in curr)
            wy0 = min(item['y0'] for item in curr)
            wx1 = max(item['x1'] for item in curr)
            wy1 = max(item['y1'] for item in curr)
            words.append({'x': int(wx0), 'y': int(wy0), 'w': int(wx1 - wx0), 'h': int(wy1 - wy0)})
            curr = [nxt]
        else:
            curr.append(nxt)

    if curr:
        wx0 = min(item['x0'] for item in curr)
        wy0 = min(item['y0'] for item in curr)
        wx1 = max(item['x1'] for item in curr)
        wy1 = max(item['y1'] for item in curr)
        words.append({'x': int(wx0), 'y': int(wy0), 'w': int(wx1 - wx0), 'h': int(wy1 - wy0)})

    return words

# ── Scale oversized images for line detection ──────────────────────────────────
# Kraken BLLA neural segmenter processes patch convolutions. Scaling down oversized images
# drastically accelerates line detection while original image resolution is preserved for crops.
MAX_SEGMENT_DIM = 1800
if max(im_w, im_h) > MAX_SEGMENT_DIM:
    scale_factor = MAX_SEGMENT_DIM / max(im_w, im_h)
    seg_w = max(1, int(im_w * scale_factor))
    seg_h = max(1, int(im_h * scale_factor))
    segment_input = im.resize((seg_w, seg_h), Image.Resampling.BILINEAR)
    inv_scale = 1.0 / scale_factor
else:
    segment_input = im
    inv_scale = 1.0

# ── Run Kraken baseline segmentation ──────────────────────────────────────────
try:
    with torch.inference_mode():
        segmentation = blla.segment(segment_input, device=device)
except Exception as exc:
    # Graceful fallback to CPU if GPU runs into unexpected memory issues
    if device == "cuda":
        try:
            with torch.inference_mode():
                segmentation = blla.segment(segment_input, device="cpu")
        except Exception as cpu_exc:
            print(f"Kraken segmentation failed: {cpu_exc}", file=sys.stderr)
            sys.exit(1)
    else:
        print(f"Kraken segmentation failed: {exc}", file=sys.stderr)
        sys.exit(1)

# ── Extract line regions from Kraken ──────────────────────────────────────────
line_regions = []
for line in (segmentation.lines or []):
    pts = getattr(line, "boundary", None) or getattr(line, "baseline", [])
    if not pts:
        bbox = getattr(line, "bbox", None)
        if bbox:
            x0, y0, x1, y1 = bbox
        else:
            continue
    else:
        xs = [p[0] for p in pts]
        ys = [p[1] for p in pts]
        x0, y0, x1, y1 = min(xs), min(ys), max(xs), max(ys)

    # Scale back up to original image coordinates if scaled
    if inv_scale != 1.0:
        x0 = x0 * inv_scale
        y0 = y0 * inv_scale
        x1 = x1 * inv_scale
        y1 = y1 * inv_scale

    # Clamp to image boundaries
    x0 = max(0, min(im_w - 1, int(x0)))
    y0 = max(0, min(im_h - 1, int(y0)))
    x1 = max(x0 + 1, min(im_w, int(x1)))
    y1 = max(y0 + 1, min(im_h, int(y1)))

    w = x1 - x0
    h = y1 - y0
    if w > 4 and h > 4:
        line_regions.append({"x": x0, "y": y0, "w": w, "h": h})

# ── Segment each Kraken line into single individual words ──────────────────────
all_word_boxes = []

for line in line_regions:
    lx, ly, lw, lh = line["x"], line["y"], line["w"], line["h"]
    try:
        line_crop = im.crop((lx, ly, lx + lw, ly + lh))
        word_rel_boxes = segment_line_into_words(line_crop)
    except Exception:
        word_rel_boxes = [{'x': 0, 'y': 0, 'w': lw, 'h': lh}]

    for wb in word_rel_boxes:
        abs_x = max(0, min(im_w - 1, lx + wb["x"]))
        abs_y = max(0, min(im_h - 1, ly + wb["y"]))
        abs_w = max(1, min(im_w - abs_x, wb["w"]))
        abs_h = max(1, min(im_h - abs_y, wb["h"]))
        if abs_w > 3 and abs_h > 3:
            all_word_boxes.append({"x": int(abs_x), "y": int(abs_y), "w": int(abs_w), "h": int(abs_h)})

# ── Fallback if no lines or words detected ─────────────────────────────────────
if not all_word_boxes:
    # Attempt direct image word segmentation
    try:
        direct_words = segment_line_into_words(im)
        all_word_boxes = direct_words
    except Exception:
        pass

if not all_word_boxes:
    print("No text regions detected by Kraken.", file=sys.stderr)
    sys.exit(2)

# Sort words reading order (top-to-bottom, left-to-right)
all_word_boxes.sort(key=lambda b: (b['y'] // 30, b['x']))

# ── Output JSON ───────────────────────────────────────────────────────────────
print(json.dumps(all_word_boxes))
