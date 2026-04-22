"""
read_time.py — Membaca angka timer dari display treadmill (7-segment)
Metode: Multi-pass EasyOCR + preprocessing OpenCV
"""

import cv2
import numpy as np
import json
import re
import os
import warnings
from collections import Counter
from datetime import datetime

# Suppress noisy warnings
warnings.filterwarnings("ignore")
os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

# ─── Konfigurasi ────────────────────────────────────────────
BASE_DIR = os.path.dirname(__file__)
UPLOADS_DIR = os.path.join(BASE_DIR, "..", "uploads")
TIME_IMAGE_PATH = os.path.join(UPLOADS_DIR, "latest_time.jpg")
DISTANCE_IMAGE_PATH = os.path.join(UPLOADS_DIR, "latest_distance.jpg")
IMAGE_PATH = os.path.join(UPLOADS_DIR, "latest.jpg")
OUTPUT_JSON = os.path.join(UPLOADS_DIR, "latest_result.json")
STATUS_JSON = os.path.join(UPLOADS_DIR, "ocr_status.json")
DEBUG_DIR = os.path.join(UPLOADS_DIR, "debug")
FAST_MODE = True

# Rentang warna HSV untuk display 7-segment (merah, oranye, hijau)
COLOR_RANGES = [
    # Merah bawah
    (np.array([0, 80, 80]), np.array([10, 255, 255])),
    # Merah atas
    (np.array([160, 80, 80]), np.array([180, 255, 255])),
    # Oranye
    (np.array([10, 80, 80]), np.array([25, 255, 255])),
    # Hijau
    (np.array([35, 80, 80]), np.array([85, 255, 255])),
    # Kuning
    (np.array([25, 80, 80]), np.array([35, 255, 255])),
    # Biru (beberapa display LED biru)
    (np.array([100, 80, 80]), np.array([130, 255, 255])),
]


def save_result(time_value, confidence, method, raw_text="", distance_value=None, distance_raw_ocr=""):
    """Simpan hasil OCR ke JSON."""
    result = {
        "time_value": time_value,
        "distance": distance_value,
        "confidence": confidence,
        "method": method,
        "raw_ocr": raw_text,
        "distance_raw_ocr": distance_raw_ocr,
        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    }
    with open(OUTPUT_JSON, "w") as f:
        json.dump(result, f, indent=2)
    print(f"\n>>> HASIL: {time_value} [{confidence}] via {method}")
    return result


def set_processing_status(is_processing, message):
    payload = {
        "processing": bool(is_processing),
        "message": message,
        "updated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
    }
    with open(STATUS_JSON, "w") as f:
        json.dump(payload, f, indent=2)


# ─── Preprocessing Gambar ────────────────────────────────────

def segment_by_color(img):
    """
    Segmentasi warna: isolasi piksel terang dari display LED/7-segment.
    Display treadmill biasanya merah/oranye/hijau pada background gelap.
    """
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    combined_mask = np.zeros(hsv.shape[:2], dtype=np.uint8)

    for lower, upper in COLOR_RANGES:
        mask = cv2.inRange(hsv, lower, upper)
        combined_mask = cv2.bitwise_or(combined_mask, mask)

    # Juga coba deteksi piksel yang sangat terang (brightness tinggi)
    # Untuk display LCD yang mungkin putih
    v_channel = hsv[:, :, 2]
    bright_mask = cv2.threshold(v_channel, 200, 255, cv2.THRESH_BINARY)[1]
    s_channel = hsv[:, :, 1]
    # Piksel terang + saturasi rendah = putih (LCD), saturasi tinggi = warna (LED)
    led_bright = cv2.bitwise_and(bright_mask, cv2.threshold(s_channel, 40, 255, cv2.THRESH_BINARY)[1])
    combined_mask = cv2.bitwise_or(combined_mask, led_bright)

    # Morphological cleaning
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (3, 3))
    combined_mask = cv2.morphologyEx(combined_mask, cv2.MORPH_CLOSE, kernel, iterations=2)
    combined_mask = cv2.morphologyEx(combined_mask, cv2.MORPH_OPEN, kernel, iterations=1)

    return combined_mask


def preprocess_variants(img):
    """
    Buat beberapa varian preprocessing untuk multi-pass OCR.
    Setiap varian punya kelebihan untuk kondisi tertentu.
    """
    variants = []
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    # --- Varian 1: CLAHE + Otsu ---
    clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)
    blurred = cv2.GaussianBlur(enhanced, (3, 3), 0)
    _, thresh1 = cv2.threshold(blurred, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    if np.sum(thresh1 == 255) / thresh1.size > 0.5:
        thresh1 = cv2.bitwise_not(thresh1)
    variants.append(("clahe_otsu", thresh1))

    # --- Varian 2: Segmentasi warna (LED display) ---
    color_mask = segment_by_color(img)
    if np.sum(color_mask > 0) > 50:
        k = cv2.getStructuringElement(cv2.MORPH_RECT, (3, 3))
        color_dilated = cv2.dilate(color_mask, k, iterations=2)
        variants.append(("color_seg", color_dilated))

    if not FAST_MODE:
        # --- Varian 3: Adaptive threshold ---
        adapt = cv2.adaptiveThreshold(
            enhanced, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 21, 5
        )
        if np.sum(adapt == 255) / adapt.size > 0.5:
            adapt = cv2.bitwise_not(adapt)
        variants.append(("adaptive", adapt))

    # --- Varian 4: Brightness threshold (Value channel) ---
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    v = hsv[:, :, 2]
    max_v = np.max(v)
    if max_v > 50:
        thresholds = [(0.60, "bright_60")] if FAST_MODE else [(0.40, "bright_40"), (0.60, "bright_60")]
        for pct, label in thresholds:
            threshold_val = int(max_v * pct)
            _, bright = cv2.threshold(v, threshold_val, 255, cv2.THRESH_BINARY)
            k = cv2.getStructuringElement(cv2.MORPH_RECT, (2, 2))
            bright = cv2.morphologyEx(bright, cv2.MORPH_CLOSE, k, iterations=1)
            variants.append((label, bright))

    # --- Varian 5: CLAHE invert (angka putih di bg hitam -> hitam di putih) ---
    if not FAST_MODE:
        inv = cv2.bitwise_not(enhanced)
        variants.append(("clahe_inv", inv))

    # --- Varian 6: Gambar asli grayscale ---
    variants.append(("original", gray))

    return variants


def resize_for_ocr(img, target_height=160):
    """Resize gambar agar angka cukup besar untuk OCR."""
    h, w = img.shape[:2]
    if h < target_height:
        scale = target_height / h
        new_w = int(w * scale)
        img = cv2.resize(img, (new_w, target_height), interpolation=cv2.INTER_CUBIC)
    return img


def add_border(img, border=20, color=0):
    """Tambah border di sekitar gambar untuk membantu OCR mendeteksi tepi digit."""
    return cv2.copyMakeBorder(img, border, border, border, border,
                              cv2.BORDER_CONSTANT, value=color)


def crop_display_region(img):
    """
    Deteksi dan crop area display timer.
    Strategi: cari area dengan piksel terang yang terkonsentrasi.
    """
    # Strategi 1: Cari via kontur pada threshold
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)
    _, binary = cv2.threshold(blurred, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    contours, _ = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    h_img, w_img = img.shape[:2]

    if contours:
        candidates = []
        for cnt in contours:
            x, y, w, h = cv2.boundingRect(cnt)
            area = w * h
            aspect = w / max(h, 1)
            if area > (h_img * w_img * 0.03) and 1.2 < aspect < 10:
                candidates.append((area, x, y, w, h))

        if candidates:
            candidates.sort(reverse=True)
            _, x, y, w, h = candidates[0]
            pad = 10
            x1 = max(0, x - pad)
            y1 = max(0, y - pad)
            x2 = min(w_img, x + w + pad)
            y2 = min(h_img, y + h + pad)
            crop = img[y1:y2, x1:x2]
            if crop.size > 0:
                return crop

    # Strategi 2: Cari via color segmentation
    color_mask = segment_by_color(img)
    coords = cv2.findNonZero(color_mask)
    if coords is not None and len(coords) > 20:
        x, y, w, h = cv2.boundingRect(coords)
        pad = 15
        x1 = max(0, x - pad)
        y1 = max(0, y - pad)
        x2 = min(w_img, x + w + pad)
        y2 = min(h_img, y + h + pad)
        crop = img[y1:y2, x1:x2]
        if crop.size > 0:
            return crop

    return img


# ─── OCR Engine ──────────────────────────────────────────────

_easyocr_reader = None

def get_easyocr_reader():
    global _easyocr_reader
    if _easyocr_reader is None:
        import easyocr
        _easyocr_reader = easyocr.Reader(['en'], gpu=False, verbose=False)
    return _easyocr_reader


def read_easyocr(img):
    try:
        reader = get_easyocr_reader()
        results = reader.readtext(
            img,
            allowlist='0123456789:.',
            paragraph=False,
            min_size=3,
            text_threshold=0.25,
            low_text=0.25,
            link_threshold=0.4,
            width_ths=1.2,
            mag_ratio=1.5,
        )
        if not results:
            return None, 0

        texts = []
        total_conf = 0
        for (bbox, text, conf) in results:
            texts.append(text)
            total_conf += conf

        return "".join(texts), total_conf / len(results)

    except Exception:
        return None, 0


# ─── Ekstrak Format Waktu ────────────────────────────────────

def extract_time_format(raw_text):
    """
    Ekstrak format waktu dari raw OCR text.
    Prioritas: H:MM:SS > MM:SS > digit mentah.
    Validasi: menit dan detik harus 0-59.
    """
    if not raw_text:
        return None

    cleaned = raw_text.strip()

    # Pola H:MM:SS (cek ini DULU sebelum MM:SS)
    match = re.search(r"(\d{1,2}):(\d{2}):(\d{2})", cleaned)
    if match:
        h, m, s = int(match.group(1)), int(match.group(2)), int(match.group(3))
        if 0 <= m <= 59 and 0 <= s <= 59:
            return match.group(0)

    # Pola MM:SS
    match = re.search(r"(\d{1,2}):(\d{2})", cleaned)
    if match:
        m, s = int(match.group(1)), int(match.group(2))
        if 0 <= m <= 59 and 0 <= s <= 59:
            return match.group(0)

    # Pola dengan titik sebagai pemisah (beberapa display pakai titik)
    match = re.search(r"(\d{1,2})\.(\d{2})", cleaned)
    if match:
        m, s = int(match.group(1)), int(match.group(2))
        if 0 <= m <= 59 and 0 <= s <= 59:
            return f"{match.group(1)}:{match.group(2)}"

    # Dari digit murni
    digits = re.sub(r"[^\d]", "", cleaned)

    if len(digits) == 4:
        m, s = int(digits[:2]), int(digits[2:])
        if 0 <= m <= 59 and 0 <= s <= 59:
            return f"{digits[:2]}:{digits[2:]}"
    elif len(digits) == 3:
        m, s = int(digits[0]), int(digits[1:])
        if 0 <= m <= 9 and 0 <= s <= 59:
            return f"{digits[0]}:{digits[1:]}"
    elif len(digits) == 6:
        h, m, s = int(digits[:2]), int(digits[2:4]), int(digits[4:])
        if 0 <= m <= 59 and 0 <= s <= 59:
            return f"{digits[:2]}:{digits[2:4]}:{digits[4:]}"
    elif len(digits) == 5:
        h, m, s = int(digits[0]), int(digits[1:3]), int(digits[3:])
        if 0 <= m <= 59 and 0 <= s <= 59:
            return f"{digits[0]}:{digits[1:3]}:{digits[3:]}"
    elif len(digits) == 2:
        v = int(digits)
        if 0 <= v <= 59:
            return f"0:{digits}"

    return None


def extract_distance_format(raw_text):
    if not raw_text:
        return None

    cleaned = raw_text.strip().lower().replace(',', '.')

    match = re.search(r"(\d{1,2}\.\d{1,2})", cleaned)
    if match:
        return f"{match.group(1)} km"

    match = re.search(r"(\d{1,2})", cleaned)
    if match:
        return f"{match.group(1)} km"

    return None


def read_distance_value(distance_img):
    try:
        cropped = crop_display_region(distance_img)
        variants = preprocess_variants(cropped)
        if FAST_MODE and len(variants) > 3:
            variants = variants[:3]
        candidates = []
        for name, vimg in variants:
            processed = resize_for_ocr(vimg)
            processed = add_border(processed)
            text, conf = read_easyocr(processed)
            if not text:
                continue
            dist = extract_distance_format(text)
            if dist:
                candidates.append((dist, text, conf, name))

        raw_text, raw_conf = read_easyocr(cropped)
        if raw_text:
            dist = extract_distance_format(raw_text)
            if dist:
                candidates.append((dist, raw_text, raw_conf, "raw"))

        if not candidates:
            return None, ""

        best = max(candidates, key=lambda c: c[2])

        cv2.imwrite(os.path.join(DEBUG_DIR, "distance_00_original.jpg"), distance_img)
        cv2.imwrite(os.path.join(DEBUG_DIR, "distance_01_cropped.jpg"), cropped)
        processed_debug = None
        for name, vimg in variants:
            if name == "clahe_otsu":
                processed_debug = vimg
                break
        if processed_debug is None and variants:
            processed_debug = variants[0][1]
        if processed_debug is not None:
            cv2.imwrite(os.path.join(DEBUG_DIR, "distance_02_processed.jpg"), processed_debug)

        return best[0], best[1]
    except Exception:
        return None, ""


# ─── Multi-Pass OCR ──────────────────────────────────────────

def run_multi_pass_ocr(img_cropped, variants):
    """Jalankan EasyOCR satu per satu pada setiap varian."""
    candidates = []
    total = len(variants) + (0 if FAST_MODE else 1)

    for step, (name, vimg) in enumerate(variants, 1):
        processed = resize_for_ocr(vimg)
        processed = add_border(processed)

        print(f"[{step}/{total}] {name}", end="")
        text, conf = read_easyocr(processed)
        if text:
            time_str = extract_time_format(text)
            if time_str:
                candidates.append((time_str, conf, name))
                print(f"  -> {time_str} ({conf:.0%})")
            else:
                print(f"  -> '{text}' (invalid)")
        else:
            print(f"  -> -")

    if not FAST_MODE:
        # Gambar asli tanpa preprocessing
        print(f"[{total}/{total}] raw", end="")
        text, conf = read_easyocr(img_cropped)
        if text:
            time_str = extract_time_format(text)
            if time_str:
                candidates.append((time_str, conf, "raw"))
                print(f"  -> {time_str} ({conf:.0%})")
            else:
                print(f"  -> '{text}' (invalid)")
        else:
            print(f"  -> -")

    return candidates


def pick_best_result(candidates):
    """
    Pilih hasil terbaik dari semua kandidat OCR.
    Strategi: voting (hasil yang paling sering muncul) + confidence tertinggi.
    """
    if not candidates:
        return None, 0, "none"

    # Hitung voting: hasil yang muncul paling sering
    time_counts = Counter(c[0] for c in candidates)
    most_common_time, vote_count = time_counts.most_common(1)[0]

    # Dari kandidat dengan waktu yang sama, ambil confidence tertinggi
    matching = [c for c in candidates if c[0] == most_common_time]
    best = max(matching, key=lambda c: c[1])

    time_str, conf, method = best

    # Tentukan confidence level berdasarkan voting + OCR confidence
    if vote_count >= 3 and conf > 0.7:
        conf_level = "high"
    elif vote_count >= 2 and conf > 0.5:
        conf_level = "medium"
    elif conf > 0.8:
        conf_level = "medium"
    else:
        conf_level = "low"

    print(f"\nVoting: '{most_common_time}' x{vote_count}, conf={conf:.0%} -> {conf_level}")

    return time_str, conf_level, method


# ─── Main ────────────────────────────────────────────────────

def main():
    set_processing_status(True, "OCR sedang diproses")
    try:
        time_image = TIME_IMAGE_PATH if os.path.exists(TIME_IMAGE_PATH) else IMAGE_PATH

        if not os.path.exists(time_image):
            print(f"Gambar tidak ditemukan: {IMAGE_PATH}")
            save_result("ERROR", "error", "none")
            set_processing_status(False, "OCR selesai (gagal)")
            return

        os.makedirs(DEBUG_DIR, exist_ok=True)

        img = cv2.imread(time_image)
        if img is None:
            print("Gagal membaca gambar")
            save_result("ERROR", "error", "none")
            set_processing_status(False, "OCR selesai (gagal)")
            return

        print(f"Gambar: {img.shape[1]}x{img.shape[0]}")

        # Crop area display
        cropped = crop_display_region(img)
        cv2.imwrite(os.path.join(DEBUG_DIR, "01_cropped.jpg"), cropped)
        cv2.imwrite(os.path.join(DEBUG_DIR, "time_00_original.jpg"), img)
        cv2.imwrite(os.path.join(DEBUG_DIR, "time_01_cropped.jpg"), cropped)
        print(f"Crop  : {cropped.shape[1]}x{cropped.shape[0]}")

        # Buat varian preprocessing
        variants = preprocess_variants(cropped)
        if FAST_MODE and len(variants) > 4:
            variants = variants[:4]
        for i, (name, vimg) in enumerate(variants):
            cv2.imwrite(os.path.join(DEBUG_DIR, f"02_{name}.jpg"), vimg)
        processed_time = None
        for name, vimg in variants:
            if name == "clahe_otsu":
                processed_time = vimg
                break
        if processed_time is None and variants:
            processed_time = variants[0][1]
        if processed_time is not None:
            cv2.imwrite(os.path.join(DEBUG_DIR, "time_02_processed.jpg"), processed_time)

        print(f"\nMemproses {len(variants)+1} varian satu per satu...")
        print("-" * 40)

        candidates = run_multi_pass_ocr(cropped, variants)

        # Ringkasan
        print("\n" + "-" * 40)
        print(f"Kandidat: {len(candidates)}")
        for t, c, m in candidates:
            print(f"  {t} ({c:.0%}) [{m}]")

        # Pilih terbaik
        time_str, conf_level, method = pick_best_result(candidates)

        if time_str:
            raw_texts = "; ".join(f"{t}({m})" for t, _, m in candidates)
            distance_value = None
            distance_raw_ocr = ""

            if os.path.exists(DISTANCE_IMAGE_PATH):
                dist_img = cv2.imread(DISTANCE_IMAGE_PATH)
                if dist_img is not None:
                    distance_value, distance_raw_ocr = read_distance_value(dist_img)

            save_result(time_str, conf_level, method, raw_texts, distance_value, distance_raw_ocr)
            set_processing_status(False, "OCR selesai")
        else:
            print("\nGagal: tidak ada hasil valid")
            save_result("ERROR", "error", "none")
            set_processing_status(False, "OCR selesai (gagal)")
    except Exception as e:
        print(f"Unhandled OCR error: {e}")
        save_result("ERROR", "error", "none")
        set_processing_status(False, "OCR selesai (gagal)")


if __name__ == "__main__":
    main()
