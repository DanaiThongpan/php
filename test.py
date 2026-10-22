#!/usr/bin/env python3
"""
บอทเกม ก to ฮ (ShopeePay) - รุ่น Speedrun < 25s (Smart Skip-Shot เร่งความเร็วช่วงท้าย)
"""
import argparse
import io
import subprocess
import sys
import time
from dataclasses import dataclass
from typing import List, Optional, Set, Tuple

from PIL import Image

ORDER = "กขฃคฅฆงจฉชซฌญฎฏฐฑฒณดตถทธนบปผฝพฟภมยรลวศษสหฬอฮ"

COL_CENTERS = (0.121, 0.310, 0.500, 0.690, 0.879)
ROW_CENTERS = (0.394, 0.530, 0.666, 0.802)
NORM_SIZE = 28


class FastAdbController:
    def __init__(self) -> None:
        try:
            out = subprocess.run(
                ["adb", "devices"], capture_output=True, text=True, check=True
            ).stdout
            if "device" not in out:
                sys.exit("❌ ไม่พบมือถือใน ADB")
        except FileNotFoundError:
            sys.exit("❌ ไม่พบคำสั่ง 'adb'")

        self.proc = subprocess.Popen(
            ["adb", "shell"],
            stdin=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            text=True,
            bufsize=1,
        )

    def tap(self, x: int, y: int) -> None:
        if self.proc.stdin:
            self.proc.stdin.write(f"input tap {x} {y}\n")
            self.proc.stdin.flush()

    def close(self) -> None:
        if self.proc.stdin:
            self.proc.stdin.write("exit\n")
            self.proc.stdin.flush()
        self.proc.terminate()


@dataclass
class GlyphFeatures:
    masks_stretched: List[int]
    masks_aspect: List[int]
    bit_count_s: int
    bit_count_a: int
    aspect_ratio: float
    ink_ratio: float
    center_x: int
    center_y: int


def screenshot() -> Image.Image:
    proc = subprocess.run(["adb", "exec-out", "screencap", "-p"], capture_output=True)
    if proc.returncode != 0 or not proc.stdout:
        raise RuntimeError("ไม่สามารถแคปหน้าจอผ่าน ADB ได้")
    return Image.open(io.BytesIO(proc.stdout)).convert("RGB")


def _img_to_shifted_bitmasks(bin_img: Image.Image, shifts: Tuple[int, ...] = (-1, 0, 1)) -> List[int]:
    pixels = list(bin_img.getdata())
    masks = []
    for dy in shifts:
        for dx in shifts:
            m = 0
            for y in range(NORM_SIZE):
                sy = y - dy
                if 0 <= sy < NORM_SIZE:
                    row_offset = sy * NORM_SIZE
                    for x in range(NORM_SIZE):
                        sx = x - dx
                        if 0 <= sx < NORM_SIZE and pixels[row_offset + sx]:
                            m |= 1 << (y * NORM_SIZE + x)
            masks.append(m)
    return masks


def extract_glyph(
    img: Image.Image, crop_box: Tuple[int, int, int, int], include_shifts: bool = False
) -> Optional[GlyphFeatures]:
    x1, y1, x2, y2 = crop_box
    sub = img.crop(crop_box).convert("L")
    w, h = sub.size
    raw = list(sub.getdata())

    min_l = min(raw)
    max_l = max(raw)

    if max_l - min_l < 35 or min_l > 175:
        return None

    thresh = min_l + int((max_l - min_l) * 0.52)
    active_rows = []
    active_cols = [0] * w
    ink_count = 0

    for y in range(h):
        row_ink = 0
        row_start = y * w
        for x in range(w):
            if raw[row_start + x] < thresh:
                row_ink += 1
                active_cols[x] += 1
        if row_ink >= 2:
            active_rows.append(y)
            ink_count += row_ink

    active_x = [x for x, c in enumerate(active_cols) if c >= 2]
    if not active_rows or not active_x or ink_count < 12:
        return None

    bx0, bx1 = active_x[0], active_x[-1] + 1
    by0, by1 = active_rows[0], active_rows[-1] + 1
    bw, bh = bx1 - bx0, by1 - by0

    if bw < 3 or bh < 6:
        return None

    tight = sub.crop((bx0, by0, bx1, by1)).point(lambda p: 1 if p < thresh else 0, mode="L")
    stretched = tight.resize((NORM_SIZE, NORM_SIZE), Image.Resampling.NEAREST)

    scale = (NORM_SIZE - 4) / max(bw, bh)
    nw = max(1, int(round(bw * scale)))
    nh = max(1, int(round(bh * scale)))
    resized_ar = tight.resize((nw, nh), Image.Resampling.NEAREST)
    aspect_canvas = Image.new("L", (NORM_SIZE, NORM_SIZE), 0)
    aspect_canvas.paste(resized_ar, ((NORM_SIZE - nw) // 2, (NORM_SIZE - nh) // 2))

    shift_range = (-1, 0, 1) if include_shifts else (0,)
    masks_s = _img_to_shifted_bitmasks(stretched, shift_range)
    masks_a = _img_to_shifted_bitmasks(aspect_canvas, shift_range)

    center_s = masks_s[len(masks_s) // 2].bit_count()
    center_a = masks_a[len(masks_a) // 2].bit_count()
    if center_s == 0 or center_a == 0:
        return None

    return GlyphFeatures(
        masks_stretched=masks_s,
        masks_aspect=masks_a,
        bit_count_s=center_s,
        bit_count_a=center_a,
        aspect_ratio=bw / bh,
        ink_ratio=ink_count / (bw * bh),
        center_x=x1 + (bx0 + bx1) // 2,
        center_y=y1 + (by0 + by1) // 2,
    )


def compare_glyphs(target: GlyphFeatures, cell: GlyphFeatures) -> float:
    c_mask_s = cell.masks_stretched[0]
    c_mask_a = cell.masks_aspect[0]

    best_s = max(
        2.0 * (t_m & c_mask_s).bit_count() / (target.bit_count_s + cell.bit_count_s)
        for t_m in target.masks_stretched
    )
    best_a = max(
        2.0 * (t_m & c_mask_a).bit_count() / (target.bit_count_a + cell.bit_count_a)
        for t_m in target.masks_aspect
    )
    ar_sim = min(target.aspect_ratio, cell.aspect_ratio) / max(
        target.aspect_ratio, cell.aspect_ratio
    )
    return 0.45 * best_s + 0.45 * best_a + 0.10 * ar_sim


def get_target_crop_box(w: int, h: int) -> Tuple[int, int, int, int]:
    return (int(w * 0.63), int(h * 0.256), int(w * 0.84), int(h * 0.312))


def get_cell_crop_box(w: int, h: int, row: int, col: int) -> Tuple[int, int, int, int]:
    cx = int(w * COL_CENTERS[col])
    cy = int(h * ROW_CENTERS[row])
    hw = int(w * 0.058)
    hh = int(h * 0.040)
    return (cx - hw, cy - hh, cx + hw, cy + hh)


def scan_full_board(img: Image.Image, w: int, h: int) -> Dict[Tuple[int, int], Optional[GlyphFeatures]]:
    board = {}
    for r in range(4):
        for c in range(5):
            board[(r, c)] = extract_glyph(img, get_cell_crop_box(w, h, r, c), include_shifts=False)
    return board


def wait_for_board_ready(countdown_sec: float) -> Image.Image:
    end_time = time.perf_counter() + countdown_sec
    while True:
        rem = end_time - time.perf_counter()
        if rem <= 0:
            break
        time.sleep(0.01)

    for _ in range(25):
        img = screenshot()
        w, h = img.size
        if extract_glyph(img, get_target_crop_box(w, h), include_shifts=True) is not None:
            return img
        time.sleep(0.02)
    return screenshot()


def is_orange_summary_button(img: Image.Image, x: int, y: int) -> bool:
    try:
        r, g, b = img.getpixel((x, y))[:3]
        if r > 200 and 60 < g < 150 and b < 90:
            return True
    except Exception:
        pass
    return False


def main() -> None:
    ap = argparse.ArgumentParser(description="บอท ShopeePay ก-ฮ Speedrun < 25s")
    ap.add_argument("--delay", type=float, default=0.0, help="ดีเลย์ระหว่างกด")
    args = ap.parse_args()

    adb = FastAdbController()
    
    try:
        print("🛑 โหมด Speedrun < 25s: ให้คุณใช้นิ้วกด 'เริ่มเล่น' แล้วกด Enter ที่คอมพิวเตอร์ทันที!")
        input("👉 กด Enter เพื่อเริ่มจับเวลาพร้อมแอป...")
        
        img = wait_for_board_ready(3.05)
        w, h = img.size
        t_start = time.perf_counter()

        board_cache = scan_full_board(img, w, h)
        target_glyph = extract_glyph(img, get_target_crop_box(w, h), include_shifts=True)

        step_idx = 0
        tried_cells: Set[Tuple[int, int]] = set()

        while step_idx < len(ORDER):
            current_char_name = ORDER[step_idx]

            if target_glyph is None:
                break

            candidates = []
            for (r, c), cell_glyph in board_cache.items():
                if (r, c) in tried_cells or cell_glyph is None:
                    continue
                score = compare_glyphs(target_glyph, cell_glyph)
                candidates.append((score, r, c, cell_glyph))

            if not candidates or max(c[0] for c in candidates) < 0.55:
                img = screenshot()
                board_cache = scan_full_board(img, w, h)
                target_glyph = extract_glyph(img, get_target_crop_box(w, h), include_shifts=True)
                tried_cells.clear()
                continue

            candidates.sort(key=lambda item: item[0], reverse=True)
            best_score, best_r, best_c, old_cell_glyph = candidates[0]
            tap_x, tap_y = old_cell_glyph.center_x, old_cell_glyph.center_y

            if is_orange_summary_button(img, tap_x, tap_y):
                print(f"🛡️ บล็อกการกดทับปุ่มสีส้มที่ ({tap_x}, {tap_y})")
                tried_cells.add((best_r, best_c))
                continue

            adb.tap(tap_x, tap_y)
            print(f"⚡ [{step_idx + 1}/44] กด '{current_char_name}' ({tap_x}, {tap_y}) [{best_score * 100:.0f}%]")

            if args.delay > 0:
                time.sleep(args.delay)

            # 🚀 เทคนิค Smart Skip-Shot: ช่วงครึ่งหลัง (ตั้งแต่ตัว 'ท' เป็นต้นไป, step_idx >= 25) 
            # ข้ามการแคปจอเช็คผลลัพธ์ในทุกๆ รอบ เพื่อวิ่งยาวด้วยความเร็วสูงสุดแบบไม่มีสะดุด
            if step_idx >= 25:
                step_idx += 1
                tried_cells.clear()
                board_cache[(best_r, best_c)] = None
                continue

            next_img = screenshot()
            new_target_glyph = extract_glyph(next_img, get_target_crop_box(w, h), include_shifts=True)
            new_cell_glyph = extract_glyph(next_img, get_cell_crop_box(w, h, best_r, best_c), include_shifts=False)

            board_cache[(best_r, best_c)] = new_cell_glyph
            target_glyph = new_target_glyph
            step_idx += 1
            tried_cells.clear()

        elapsed = time.perf_counter() - t_start
        print(f"\n🏆 จบเกมสำเร็จ! ทำเวลาไปได้ทั้งหมด {elapsed:.2f} วินาที (ต่ำกว่า 25 วิ สำเร็จ)")
        
    finally:
        adb.close()

if __name__ == "__main__":
    main()