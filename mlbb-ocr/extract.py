"""Local PaddleOCR + optional portrait-reference suggestions for MLBB scoreboards."""
import json
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PORTRAITS = ROOT / 'portraits'
CACHE = ROOT / '.cache'

# Laravel may launch Python without Windows' USERPROFILE/HOME variables.
# PaddleOCR's model providers require a writable cache directory during import.
CACHE.mkdir(exist_ok=True)
os.environ.setdefault('HOME', str(ROOT))
os.environ.setdefault('USERPROFILE', str(ROOT))
os.environ.setdefault('MODELSCOPE_CACHE', str(CACHE / 'modelscope'))
os.environ.setdefault('PADDLE_PDX_CACHE_HOME', str(CACHE / 'paddlex'))

import cv2
import numpy as np
from paddleocr import PaddleOCR


def normalized_box(width, height, x1, y1, x2, y2):
    return (int(width * x1), int(height * y1), int(width * x2), int(height * y2))


def crop(image, box):
    x1, y1, x2, y2 = box
    return image[max(0, y1):max(0, y2), max(0, x1):max(0, x2)]


def descriptor(image):
    if image is None or image.size == 0:
        return None
    image = cv2.resize(image, (64, 64), interpolation=cv2.INTER_AREA)
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    return cv2.normalize(cv2.calcHist([hsv], [0, 1], None, [16, 16], [0, 180, 0, 256]), None).flatten()


def suggest(image, category):
    target = descriptor(image)
    directory = PORTRAITS / category
    if target is None or not directory.exists():
        return {'name': None, 'confidence': 0, 'needs_review': True}
    best_name, best_score = None, -1.0
    for source in directory.glob('*'):
        reference = cv2.imread(str(source))
        reference_descriptor = descriptor(reference)
        if reference_descriptor is None:
            continue
        score = float(cv2.compareHist(target.astype('float32'), reference_descriptor.astype('float32'), cv2.HISTCMP_CORREL))
        if score > best_score:
            best_name, best_score = source.stem.replace('-', ' ').title(), score
    confidence = max(0, round(best_score, 2))
    return {'name': best_name if confidence >= 0.68 else None, 'confidence': confidence, 'needs_review': True}


def group_rows(entries, height):
    rows = []
    for entry in sorted(entries, key=lambda item: item['cy']):
        if entry['cy'] < height * 0.20 or entry['cy'] > height * 0.88:
            continue
        target = next((row for row in rows if abs(row['y'] - entry['cy']) < height * 0.055), None)
        if target is None:
            target = {'y': entry['cy'], 'entries': []}
            rows.append(target)
        target['entries'].append(entry)
    return sorted(rows, key=lambda row: row['y'])[:5]


def fields_from_text(text):
    match = re.search(r'(?<!\d)(\d{1,2})\s+(\d{1,2})\s+(\d{1,2})\s+(\d{3,5})(?!\d)', text)
    if not match:
        return {}
    return {'kills': int(match.group(1)), 'deaths': int(match.group(2)), 'assists': int(match.group(3)), 'gold': int(match.group(4))}


def player_name(entries):
    words = [entry['text'] for entry in sorted(entries, key=lambda item: item['x']) if re.search(r'[A-Za-z\u4e00-\u9fff]', entry['text'])]
    return max(words, key=len, default='Unmatched player')


def main(image_path):
    image = cv2.imread(image_path)
    if image is None:
        raise RuntimeError('The uploaded screenshot could not be decoded.')
    height, width = image.shape[:2]
    entries = []
    # PaddlePaddle 3.3.1 has a known CPU oneDNN/PIR inference failure on
    # Windows (`ConvertPirAttribute2RuntimeAttribute` for DoubleAttribute).
    # Disable oneDNN for this CPU OCR worker; inference uses the standard CPU
    # backend instead.
    ocr = PaddleOCR(lang='en', enable_mkldnn=False)
    if hasattr(ocr, 'predict'):
        # PaddleOCR 3.x
        for page in ocr.predict(image_path):
            payload = page.json if hasattr(page, 'json') else page
            payload = payload() if callable(payload) else payload
            if isinstance(payload, str):
                payload = json.loads(payload)
            data = payload.get('res', payload) if isinstance(payload, dict) else {}
            texts = data.get('rec_texts', [])
            scores = data.get('rec_scores', [])
            boxes = data.get('rec_boxes', [])
            for text, score, box in zip(texts, scores, boxes):
                x1, y1, x2, y2 = [float(value) for value in box]
                entries.append({'text': str(text), 'confidence': round(float(score), 2), 'x': (x1 + x2) / 2, 'cy': (y1 + y2) / 2})
    else:
        # PaddleOCR 2.x
        for page in ocr.ocr(image_path, cls=True):
            for box, (text, score) in page:
                points = np.array(box, dtype=float)
                entries.append({'text': str(text), 'confidence': round(float(score), 2), 'x': float(points[:, 0].mean()), 'cy': float(points[:, 1].mean())})
    all_text = ' '.join(item['text'] for item in entries)
    duration = re.search(r'\b\d{1,2}:\d{2}\b', all_text)
    battle_id = re.search(r'(?:BattleID|BattleId)\s*(\d+)', all_text, flags=re.I)
    top = [item for item in entries if item['cy'] < height * 0.20 and item['text'].isdigit()]
    score = [int(item['text']) for item in sorted(top, key=lambda item: item['x']) if 0 <= int(item['text']) <= 99]
    left_rows = group_rows([item for item in entries if item['x'] < width * 0.5], height)
    right_rows = group_rows([item for item in entries if item['x'] >= width * 0.5], height)
    players = []
    for side, rows, hero_x, item_start in [('a', left_rows, 0.165, 0.205), ('b', right_rows, 0.835, 0.635)]:
        for index, row in enumerate(rows):
            row_text = ' '.join(item['text'] for item in sorted(row['entries'], key=lambda item: item['x']))
            hero = suggest(crop(image, normalized_box(width, height, hero_x - .028, row['y'] / height - .055, hero_x + .028, row['y'] / height + .055)), 'heroes')
            items = []
            for slot in range(6):
                x = item_start + (slot * .038)
                items.append(suggest(crop(image, normalized_box(width, height, x, row['y'] / height - .038, x + .035, row['y'] / height + .038)), 'items'))
            players.append({'side': side, 'row': index + 1, 'source_name': player_name(row['entries']), 'ocr_text': row_text, **fields_from_text(row_text), 'hero_suggestion': hero, 'item_suggestions': items})
    print(json.dumps({'summary': {'score_a': score[0] if len(score) > 0 else None, 'score_b': score[-1] if len(score) > 1 else None, 'duration': duration.group(0) if duration else None, 'battle_id': battle_id.group(1) if battle_id else None}, 'players': players, 'raw_text': all_text, 'notice': 'OCR and portrait matches are drafts. Review all names, K/D/A, heroes, and builds before saving.'}))


if __name__ == '__main__':
    main(sys.argv[1])
