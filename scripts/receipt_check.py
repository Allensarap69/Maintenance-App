#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
Receipt authenticity checker for GCash payment receipts.

Usage:
    python receipt_check.py --image <path> [--amount 575.00] [--ref 1234567890] [--ela-dir <dir>]

Prints a single JSON object to stdout:
    verdict            likely_genuine | suspicious | unreadable | skipped
    checks.gcash_brand      GCash/InstaPay markers found in the text
    checks.amount_match     expected deposit amount found in the text
    checks.ref_match        the typed 13-digit reference number found in the text
    checks.amounts_seen     all amounts the OCR spotted (for admin review)
    ela.image / ela.flag    ELA forensics for JPEG receipts
"""
import argparse, json, os, re, sys

def ocr_text(path):
    import cv2
    from rapidocr_onnxruntime import RapidOCR
    img = cv2.imread(path)
    engine = RapidOCR()
    work = img
    if work is not None:
        # Downscale large photos — inference time scales with pixels, and
        # ~1000px on the long side is enough for receipt text.
        h, w = work.shape[:2]
        if max(h, w) > 1000:
            s = 1000.0 / max(h, w)
            work = cv2.resize(work, (int(w * s), int(h * s)))
    result, _ = engine(work if work is not None else path, use_cls=False)
    lines = [line[1] for line in result] if result else []  # (box, text, score)
    # Small text can vanish after downscaling — retry once at full resolution.
    if len(' '.join(lines).strip()) < 8 and work is not img and img is not None:
        result, _ = engine(img, use_cls=False)
        lines = [line[1] for line in result] if result else []
    return lines

def find_amounts(text):
    # P575.00 / ₱575.00 / PHP 575 / 575.00 / 1,250.50
    amounts = []
    for m in re.finditer(r'(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+\.\d{1,2}|\d+)', text):
        try:
            amounts.append(float(m.group(1).replace(',', '')))
        except ValueError:
            pass
    return amounts

def ela(path, out_dir):
    """Error Level Analysis for JPEG receipts. Returns dict or None for non-JPEG."""
    ext = os.path.splitext(path)[1].lower()
    if ext not in ('.jpg', '.jpeg'):
        return None
    try:
        import cv2, numpy as np
        img = cv2.imread(path)
        if img is None:
            return None
        tmp = os.path.join(out_dir, '_ela_tmp.jpg')
        cv2.imwrite(tmp, img, [cv2.IMWRITE_JPEG_QUALITY, 90])
        resaved = cv2.imread(tmp)
        os.remove(tmp)
        if resaved is None:
            return None
        diff = cv2.absdiff(img, resaved)
        gray = cv2.cvtColor(diff, cv2.COLOR_BGR2GRAY)
        mean = float(gray.mean())
        p99 = float(np.percentile(gray, 99))
        vis = cv2.convertScaleAbs(gray, alpha=15.0)
        ela_path = os.path.join(out_dir, 'ela_' + os.path.basename(path) + '.jpg')
        cv2.imwrite(ela_path, vis)
        # Heuristic: genuine phone-camera JPEGs recompress fairly evenly;
        # strong hotspots (high p99 vs low mean) suggest pasted content.
        flag = bool(p99 > 60 and mean > 4)
        return {'image': ela_path.replace('\\', '/'), 'flag': flag,
                'mean': round(mean, 2), 'p99': round(p99, 1)}
    except Exception as e:
        return {'error': str(e)}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--image', required=True)
    ap.add_argument('--amount', type=float, default=0.0)
    ap.add_argument('--ref', default='')
    ap.add_argument('--ela-dir', default='')
    ap.add_argument('--ela-only', action='store_true')
    a = ap.parse_args()

    if a.ela_only:
        out = {}
        if a.ela_dir and os.path.isfile(a.image):
            e = ela(a.image, a.ela_dir)
            if e:
                e['image'] = os.path.relpath(e.get('image', ''), a.ela_dir).replace('\\', '/') if e.get('image') else None
                out['ela'] = e
        print(json.dumps(out))
        return

    if not os.path.isfile(a.image):
        print(json.dumps({'verdict': 'skipped', 'reason': 'file_missing'}))
        return
    if a.image.lower().endswith('.pdf'):
        print(json.dumps({'verdict': 'skipped', 'reason': 'pdf_not_analyzed'}))
        return

    try:
        texts = ocr_text(a.image)
    except Exception as e:
        print(json.dumps({'verdict': 'skipped', 'reason': 'ocr_failed', 'error': str(e)}))
        return

    joined = ' '.join(texts)
    lower = joined.lower()
    digits_only = re.sub(r'\D', '', joined)

    if len(joined.strip()) < 8:
        print(json.dumps({'verdict': 'unreadable', 'checks': {}, 'ocr_len': len(joined.strip())}))
        return

    brand = bool(re.search(r'gcash|g-xchange|gxchange|instapay|express send|send money|via gcash', lower))
    amounts = find_amounts(joined)
    amount_match = any(abs(v - a.amount) < 0.51 for v in amounts) if a.amount > 0 else False
    ref_match = bool(a.ref) and a.ref in digits_only
    date_present = bool(re.search(
        r'\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4}|'
        r'(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s*\d{1,2}', lower))

    score = sum([brand, amount_match, ref_match])
    if a.ref and a.amount > 0:
        verdict = 'likely_genuine' if score >= 2 and amount_match and ref_match else 'suspicious'
    else:
        verdict = 'likely_genuine' if brand and amount_match else 'suspicious'

    out = {
        'verdict': verdict,
        'checks': {
            'gcash_brand': brand,
            'amount_match': amount_match,
            'ref_match': ref_match,
            'date_present': date_present,
            'amounts_seen': amounts[:12],
        },
        'expected': {'amount': a.amount, 'ref': a.ref},
        'ocr_preview': joined[:300],
    }

    if a.ela_dir:
        e = ela(a.image, a.ela_dir)
        if e:
            e['image'] = os.path.relpath(e.get('image', ''), a.ela_dir).replace('\\', '/') if e.get('image') else None
            out['ela'] = e

    print(json.dumps(out))

if __name__ == '__main__':
    main()
