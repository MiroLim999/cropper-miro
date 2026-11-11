#!/usr/bin/env python3
"""
table_cells.py - Find the table grid on a scanned page and output every cell.

Usage:  python table_cells.py <image_path> <deskewed_output_path>

The page is first straightened (small scan skew is common), the straightened
image is written to <deskewed_output_path>, and a JSON object is printed:
  {"width": W, "height": H, "angle": deg,
   "cols": [x, ...], "rows": [y, ...],          # grid line positions
   "cells": [{"x","y","w","h","row","col"}, ...]}   # only cells that contain ink
All coordinates refer to the deskewed image.

Uses only numpy, scipy and Pillow (fast, no Kraken / torch).
Exit code 2 = no table grid found on the page.
"""
import json
import sys

import numpy as np
from PIL import Image
from scipy import ndimage

if len(sys.argv) != 3:
    print("Usage: table_cells.py <image_path> <deskewed_output_path>", file=sys.stderr)
    sys.exit(1)

try:
    src = Image.open(sys.argv[1])
    src.load()
    src = src.convert("RGB")
except Exception as exc:
    print(f"Cannot open image: {exc}", file=sys.stderr)
    sys.exit(1)

# All detection runs on a copy of about 1450 px width (the filter sizes below are
# tuned for that); the crops themselves keep the full resolution.
full = src
SC = min(1.0, 1450.0 / src.width)
if SC < 1.0:
    src = src.resize((max(1, round(src.width * SC)), max(1, round(src.height * SC))), Image.LANCZOS)


def darkness(gray):
    """Ink-ness of every pixel relative to its local paper colour (uneven scans)."""
    bg = ndimage.uniform_filter(ndimage.maximum_filter(gray, 5), 51)
    return np.clip(bg - gray, 0, None)


gray0 = np.asarray(src.convert("L"), dtype=np.float32)
d0 = darkness(gray0)

# ── Deskew: pick the angle that makes the line profiles the sharpest ──────────
# Thin horizontal rules give the sharpest row profile only when the page is level,
# so the angle is chosen on horizontal-edge energy (steps of 0.05 deg after a coarse pass).
gy = np.abs(np.roll(gray0, -1, axis=0) - np.roll(gray0, 1, axis=0)).astype(np.float32)
gy_img = Image.fromarray(gy[int(gy.shape[0] * 0.15):])


def angle_score(ang):
    r = np.asarray(gy_img.rotate(float(ang), resample=Image.BILINEAR), dtype=np.float32)
    return float(ndimage.uniform_filter1d(r.sum(axis=1), 2).var())


best_ang = max(np.arange(-4, 4.01, 0.25), key=angle_score)
best_ang = float(max(np.arange(best_ang - 0.25, best_ang + 0.26, 0.05), key=angle_score))

if abs(best_ang) >= 0.1:
    fill = tuple(int(v) for v in np.median(np.asarray(src).reshape(-1, 3), axis=0))
    src = src.rotate(best_ang, resample=Image.BICUBIC, fillcolor=fill)
    d = darkness(np.asarray(src.convert("L"), dtype=np.float32))
else:
    best_ang = 0.0
    d = d0

if best_ang:
    fill_full = tuple(int(v) for v in np.median(np.asarray(full.resize((200, 200))).reshape(-1, 3), axis=0))
    full = full.rotate(best_ang, resample=Image.BICUBIC, fillcolor=fill_full)
full.save(sys.argv[2])
H, W = d.shape


def merge_peaks(cands, min_gap):
    """cands: [(pos, score)] -> keep the strongest of any cluster closer than min_gap."""
    out = []
    for pos, sc in sorted(cands):
        if out and pos - out[-1][0] < min_gap:
            if sc > out[-1][1]:
                out[-1] = (pos, sc)
            continue
        out.append((pos, sc))
    return out


gray_small = np.asarray(src.convert("L"), dtype=np.float32)
gs = ndimage.gaussian_filter(gray_small, 0.8)
local = np.clip(ndimage.uniform_filter(gs, 31) - gs, 0, None)

# ── Vertical lines: contrast of a column against its neighbours, over the table
#    height. Real lines are far stronger than text strokes (clear gap in scores).
cy0, cy1 = int(H * 0.2), int(H * 0.98)
p = local[cy0:cy1].sum(axis=0)
res = ndimage.uniform_filter1d(p - 0.5 * (np.roll(p, 4) + np.roll(p, -4)), 3)
cand = [(x, float(res[x])) for x in range(4, W - 4) if res[x] > 0 and res[x] == res[x - 4:x + 5].max()]
# Page-edge shadows / scanner borders are not grid lines
cand = [c for c in cand if 0.013 * W < c[0] < 0.996 * W]
cand = sorted(cand, key=lambda c: -c[1])[:40]


def line_coverage(x, y0, y1, thr=8.0, close=7):
    """Fraction of rows y0..y1 where column x (+-2 px jitter) is a thin dark line."""
    best = 0.0
    for dx in (-2, -1, 0, 1, 2):
        xx = min(max(x + dx, 7), W - 8)
        c = np.min([gs[y0:y1, xx + k] for k in (-1, 0, 1)], axis=0)
        nbh = 0.5 * (gs[y0:y1, xx - 6] + gs[y0:y1, xx + 6])
        on = ndimage.binary_closing((nbh - c) > thr, np.ones(close))
        best = max(best, float(on.mean()))
    return best


# A real column line stays dark over (nearly) the whole table height; text does not.
strong = merge_peaks([(x, line_coverage(x, cy0, cy1) * 1e6 + sc)
                      for x, sc in cand if line_coverage(x, cy0, cy1) >= 0.75], 25)
col_lines = sorted(x for x, _ in strong)

# Faint lines (partly overwritten by handwriting) are still clean inside the printed
# header band, so they are confirmed there instead.
if len(col_lines) >= 3:
    ycol = np.median(np.vstack([local[:, max(0, x - 2):x + 3].mean(axis=1) for x in col_lines]), axis=0)
    ycol = ndimage.uniform_filter1d(ycol, 9)
    body = float(np.median(ycol[int(H * 0.4):int(H * 0.9)]))
    start = int(H * 0.4)
    ty = 0
    for y in range(start, -1, -1):
        if ycol[y] <= 0.5 * body:
            ty = y + 1
            break
    hy0, hy1 = ty + 4, min(H, ty + int(0.05 * H))
    topscore = max((c[1] for c in cand), default=1)
    extra = []
    if hy1 - hy0 > 20:
        for x, sc in cand:
            if sc < 0.03 * topscore or any(abs(x - cl) < 25 for cl in col_lines):
                continue
            if line_coverage(x, hy0, hy1, thr=6.0, close=5) >= 0.92                     and line_coverage(x, cy0, cy1, thr=5.0, close=15) >= 0.55:
                extra.append((x, sc))
    medw0 = float(np.median(np.diff(col_lines))) if len(col_lines) > 1 else 0.0
    accepted = list(col_lines)
    for x, _ in merge_peaks(extra, 25):
        trial = sorted(accepted + [x])
        i = trial.index(x)
        left = x - trial[i - 1] if i > 0 else 1e9
        right = trial[i + 1] - x if i + 1 < len(trial) else 1e9
        if min(left, right) >= 0.35 * medw0:      # a sliver is a text stroke, not a column
            accepted = trial
    col_lines = accepted

# Two lines closer than a third of a normal column: keep only the stronger one.
if len(col_lines) > 3:
    medw1 = float(np.median(np.diff(col_lines)))
    changed = True
    while changed and len(col_lines) > 3:
        changed = False
        for i in range(len(col_lines) - 1):
            if col_lines[i + 1] - col_lines[i] < 0.3 * medw1:
                ca = line_coverage(col_lines[i], cy0, cy1)
                cb = line_coverage(col_lines[i + 1], cy0, cy1)
                del col_lines[i + (1 if ca >= cb else 0)]
                changed = True
                break

if len(col_lines) < 3:
    print("No table grid detected.", file=sys.stderr)
    sys.exit(2)

# ── Horizontal lines: thin dark ridges that stay continuous inside every column
#    band (text only produces short, broken ridges).
ridge = ((np.roll(gs, 3, axis=0) + np.roll(gs, -3, axis=0)) / 2 - gs) > 6
covs = []
for a, b in zip(col_lines[:-1], col_lines[1:]):
    if b - a < 30:
        continue
    band = ndimage.binary_closing(ridge[:, a + 4:b - 3], np.ones((1, 9)))
    covs.append(ndimage.maximum_filter1d(band.mean(axis=1), 3))
# Handwriting breaks a row line in the wide text columns but not in the clean ones
# (Entry No., Sex, Nationality ...), so a line is judged by its best columns.
covs = np.sort(np.vstack(covs), axis=0)[::-1]
score = np.sqrt(covs[:max(3, (covs.shape[0] + 1) // 2)].mean(axis=0) * covs.mean(axis=0))
rc = [(y, float(score[y])) for y in range(int(H * 0.12), H - 6)
      if score[y] >= 0.55 and score[y] == score[y - 3:y + 4].max()]
rc = merge_peaks(rc, 14)
row_lines = [r[0] for r in rc]


def regularise_rows(rc):
    """
    Register-style tables have evenly spaced rows. The row pitch is found by
    autocorrelation of the line-continuity profile (immune to text underlines that
    look like extra lines), then a lattice is fitted and followed down the page.
    Returns None when the rows are not regular (caller keeps the raw lines).
    """
    y0, y1 = int(H * 0.25), int(H * 0.97)
    sig = score[y0:y1] - score[y0:y1].mean()
    best = None
    for P in range(22, 100):
        ac = float((sig[:-P] * sig[P:]).sum()) / (len(sig) - P)
        if best is None or ac > best[0]:
            best = (ac, P)
    if best is None or best[0] <= 0:
        return None
    P0 = best[1]
    # prefer the fundamental period over its multiples (2P, 3P look similar)
    for div in (3, 2):
        q = P0 / div
        if q >= 22:
            qi = int(round(q))
            ac = float((sig[:-qi] * sig[qi:]).sum()) / (len(sig) - qi)
            if ac >= 0.75 * best[0]:
                P0 = qi
                break
    sm = ndimage.uniform_filter1d(score, 3)
    # phase: lattice offset whose lines carry the most line evidence
    phase, pbest = 0, -1.0
    for a0 in range(P0):
        ys = np.arange(y0 + a0, y1, P0)
        v = sm[ys].sum()
        if v > pbest:
            phase, pbest = a0, v
    # refine pitch/offset by regressing real ridge peaks against lattice index
    ks, ys_ = [], []
    for k, yl in enumerate(range(y0 + phase, y1, P0)):
        lo, hi = max(0, int(yl - 0.2 * P0)), min(H - 1, int(yl + 0.2 * P0))
        seg = score[lo:hi + 1]
        if seg.size and seg.max() >= 0.5:
            ks.append(k)
            ys_.append(lo + int(np.argmax(seg)))
    if len(ks) < 6:
        return None
    p, a = np.polyfit(ks, ys_, 1)
    for _ in range(2):
        resid = np.abs(np.array(ys_) - (a + p * np.array(ks)))
        keep = resid < 0.15 * p
        if keep.sum() < 5:
            return None
        p, a = np.polyfit(np.array(ks)[keep], np.array(ys_)[keep], 1)
    a += 0.0
    base = y0 + phase           # lattice y for k=0 is a (already absolute via ys_)
    kmin = int(np.floor((0 - a) / p))
    kmax = int(np.ceil((H - a) / p))
    lat = [int(round(a + p * kk)) for kk in range(kmin, kmax + 1) if 0 < a + p * kk < H - 1]
    # snap every lattice line to the real ridge nearby, following the drift line by line
    snapped = []
    i0 = next((i for i, y in enumerate(lat) if y >= 0.2 * H), 0)
    for i, y in enumerate(lat):
        if i < i0:
            snapped.append(y)      # above the table: nothing to follow
            continue
        exp = y if i == i0 else snapped[-1] + p     # follow the drift line by line
        lo, hi = max(0, int(exp - 0.12 * p)), min(H - 1, int(exp + 0.12 * p))
        seg = score[lo:hi + 1]
        y = lo + int(np.argmax(seg)) if seg.size and seg.max() >= 0.4 else int(round(exp))
        snapped.append(y)
    return snapped, p


reg = regularise_rows(rc)
if reg:
    lat, pitch = reg
    # top of the table = where the vertical grid lines begin
    ycol = np.median(np.vstack([local[:, max(0, x - 2):x + 3].mean(axis=1) for x in col_lines]), axis=0)
    ycol = ndimage.uniform_filter1d(ycol, 9)
    body = float(np.median(ycol[int(H * 0.4):int(H * 0.9)]))
    first = next((y for y in lat if y > H * 0.2), lat[0])
    top_y = 0
    for y in range(first, -1, -1):
        if ycol[y] <= 0.5 * body:
            top_y = y + 1
            break
    # the header band may be taller than a row: drop lattice lines inside it
    # (a header cell may hold two text lines, so a lattice line near the top only counts
    #  as the header's bottom edge when a real ruled line is there)
    def header_edge_ok(y):
        if y >= top_y + 1.6 * pitch:
            return True
        return y >= top_y + 0.8 * pitch and float(score[max(0, y - 3):y + 4].max()) >= 0.6
    row_lines = [top_y] + [y for y in lat if y > top_y and header_edge_ok(y)]

# ── Outer columns whose border line is hidden at the page edge ────────────────
# If a strip of table (row lines continue through it) lies between the outermost
# detected line and the page edge, close it with the page edge.
def strip_has_rows(x0, x1):
    if x1 - x0 < 20:
        return False
    band = ndimage.binary_closing(ridge[:, x0 + 3:x1 - 3], np.ones((1, 9))).mean(axis=1)
    band = ndimage.maximum_filter1d(band, 3)
    vals = [band[min(H - 1, y)] for y in row_lines[1:-1]]
    return bool(vals) and float(np.mean(vals)) >= 0.5


widths = np.diff(col_lines)
medw = float(np.median(widths)) if len(widths) else 0
if medw:
    if W - 3 - col_lines[-1] >= 0.4 * medw and strip_has_rows(col_lines[-1], W - 3):
        col_lines.append(W - 3)
    if col_lines[0] - 3 >= 0.4 * medw and strip_has_rows(3, col_lines[0]):
        col_lines.insert(0, 3)

if len(row_lines) < 3:
    print("No table grid detected.", file=sys.stderr)
    sys.exit(2)

# Local binary ink mask for deciding whether a cell is empty
ink = local > max(14.0, float(np.percentile(local, 85)))

def has_text(x0, y0, x1, y1):
    """
    True when the cell holds real writing/print: at least one dark stroke blob that is
    big enough and is not a ruled line or page-edge shadow (those run across the cell).
    Paper grain only gives tiny speckles, so it never counts.
    """
    # white paper margin (below / beside the table) means this is not a table cell
    if float((gray_small[y0:y1, x0:x1] > 238).mean()) > 0.3:
        return False
    sub = local[y0:y1, x0:x1] > 30
    if sub.size == 0 or not sub.any():
        return False
    lbl, n = ndimage.label(sub, structure=np.ones((3, 3)))
    if n == 0:
        return False
    cw = x1 - x0
    good = 0
    for i, sl in enumerate(ndimage.find_objects(lbl)):
        h = sl[0].stop - sl[0].start
        w = sl[1].stop - sl[1].start
        area = int((lbl[sl] == i + 1).sum())
        if area < 25 or h < 7:
            continue
        if w > 0.8 * cw and h < 0.35 * (y1 - y0):   # a line / shadow edge, not a word
            continue
        if h >= 0.92 * (y1 - y0) and w < 0.25 * cw:  # vertical rule / page-edge shadow
            continue
        good += 1
    return good >= 1


cells = []
row_white = {}
INSET = 3
for ri in range(len(row_lines) - 1):
    for ci in range(len(col_lines) - 1):
        x0, x1 = col_lines[ci] + INSET, col_lines[ci + 1] - INSET
        y0, y1 = row_lines[ri] + INSET, row_lines[ri + 1] - INSET
        if x1 - x0 < 8 or y1 - y0 < 8:
            continue
        row_white.setdefault(ri + 1, []).append(float((gray_small[y0:y1, x0:x1] > 238).mean()))
        if not has_text(x0 + 2, y0 + 2, x1 - 2, y1 - 2):
            continue  # empty cell
        cells.append({"x": int(round(x0 / SC)), "y": int(round(y0 / SC)),
                      "w": int(round((x1 - x0) / SC)), "h": int(round((y1 - y0) / SC)),
                      "row": ri + 1, "col": ci + 1})

# A row that is mostly white paper lies below / outside the table: drop it entirely.
bad_rows = {r for r, v in row_white.items() if float(np.median(v)) > 0.15}
cells = [c for c in cells if c["row"] not in bad_rows]

print(json.dumps({"width": full.width, "height": full.height, "angle": best_ang,
                  "cols": [int(round(c / SC)) for c in col_lines],
                  "rows": [int(round(r / SC)) for r in row_lines], "cells": cells}))
