#!/usr/bin/env python3
"""
pdf_to_pages.py - Render every page of a PDF to a PNG.

Usage:  python pdf_to_pages.py <pdf_path> <out_dir> [dpi]
Prints a JSON array of the written PNG paths (page order) to stdout.
Uses pypdfium2 installed in ./pylibs (no system tools needed).
"""
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "pylibs"))

if len(sys.argv) < 3:
    print("Usage: pdf_to_pages.py <pdf_path> <out_dir> [dpi]", file=sys.stderr)
    sys.exit(1)

try:
    import pypdfium2 as pdfium
except ImportError as exc:
    print(f"pypdfium2 missing (run: pip install --target pylibs pypdfium2): {exc}", file=sys.stderr)
    sys.exit(1)

pdf_path, out_dir = sys.argv[1], sys.argv[2]
dpi = int(sys.argv[3]) if len(sys.argv) > 3 else 150
os.makedirs(out_dir, exist_ok=True)

try:
    pdf = pdfium.PdfDocument(pdf_path)
    paths = []
    for i in range(len(pdf)):
        page = pdf[i]
        # keep the longest side <= 2400 px so huge page sizes don't exhaust memory
        w, h = page.get_size()
        scale = min(dpi / 72.0, 2400.0 / max(w, h))
        img = page.render(scale=scale).to_pil().convert("RGB")
        out = os.path.join(out_dir, f"page_{i + 1:03d}.png")
        img.save(out)
        paths.append(out)
        page.close()
    pdf.close()
except Exception as exc:
    print(f"Cannot render PDF: {exc}", file=sys.stderr)
    sys.exit(1)

print(json.dumps(paths))
