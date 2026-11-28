#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Genera el set sintético de escenas de mostrador para comparar proponedores.

El Modo Caja Inteligente necesita comparar estrategias de propuesta de regiones
(`EmptyCounterDiff`, rejilla, saliencia, etc.) antes de tener fotos reales. Este script
crea escenas deterministas de 1280x720 con 1–6 productos, su referencia de mostrador
vacío (la que consume `EmptyCounterDiff`) y los arrays RGB crudos que leerá el arnés JS.

Salidas (por defecto en `tools/ml/synthetic/`, gitignored):

    frames/<escena>.rgb.bin          uint8 RGB HWC 1280*720*3 = 2 764 800 bytes
    frames/<escena>.empty.rgb.bin    la misma escena sin productos
    frames/index.json                dimensiones, rutas, hashes y recuento
    ground_truth.json                cajas exactas por escena
    previews/<escena>.png            solo 3 escenas (vista previa)

Uso:
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\evaluate_proposers.py
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\evaluate_proposers.py --scenes 24 --seed 7
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\evaluate_proposers.py --force
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\evaluate_proposers.py --from-photos tools\\ml\\synthetic\\real_raw

El modo `--from-photos` convierte pares reales `<id>_empty.<ext>` / `<id>_scene.<ext>`
de 1280x720 al mismo formato de frames.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import sys
import time
from pathlib import Path
from typing import Any

ML_DIR = Path(__file__).resolve().parent
REPO_ROOT = ML_DIR.parents[1]
DEFAULT_OUT = ML_DIR / "synthetic"
WIDTH, HEIGHT = 1280, 720
CHANNELS = 3
BYTES_PER_FRAME = WIDTH * HEIGHT * CHANNELS
SUPERSAMPLE = 2

THEMES = [
    {"name": "madera_oscura", "base": (74, 48, 30), "texture": "wood", "warm": True},
    {"name": "madera_clara", "base": (168, 128, 88), "texture": "wood", "warm": True},
    {"name": "concreto", "base": (118, 118, 122), "texture": "concrete", "warm": False},
    {"name": "marmol", "base": (214, 212, 205), "texture": "marble", "warm": False},
    {"name": "pizarra", "base": (52, 56, 62), "texture": "concrete", "warm": False},
    {"name": "laminado_verde", "base": (62, 92, 88), "texture": "wood", "warm": False},
    {"name": "beige_calido", "base": (192, 166, 132), "texture": "marble", "warm": True},
]

PRODUCT_TYPES = ["caja", "botella", "fruta", "bolsa", "lata"]


def _log(message: str) -> None:
    print(f"[evaluate_proposers] {message}", flush=True)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


# --------------------------------------------------------------------------------------
# Fondo sintético
# --------------------------------------------------------------------------------------

def make_background(rng, theme: dict, width: int, height: int):
    import numpy as np
    from PIL import Image, ImageFilter

    yy, xx = np.mgrid[0:height, 0:width].astype(np.float32)
    scale = max(1, width // WIDTH)
    base = np.asarray(theme["base"], dtype=np.float32)
    color = np.empty((height, width, 3), dtype=np.float32)
    color[:] = base

    # Gradiente vertical + luz cálida/fría.
    vertical = (yy / max(1, height - 1))[..., None]
    color += vertical * np.asarray([-18, -14, -10], dtype=np.float32)
    light_color = np.asarray([52, 38, 24] if theme["warm"] else [28, 34, 44], dtype=np.float32)
    color += (1.0 - vertical) * light_color

    # Foco de luz.
    cx, cy = rng.uniform(0.25, 0.75) * width, rng.uniform(0.05, 0.35) * height
    radius = rng.uniform(0.45, 0.85) * width
    distance = np.sqrt((xx - cx) ** 2 + (yy - cy) ** 2)
    hotspot = np.exp(-(distance / radius) ** 2)[..., None] * rng.uniform(18, 46)
    color += hotspot

    # Textura.
    if theme["texture"] == "wood":
        # Vetas horizontales que ondulan suavemente + juntas de tablón.
        phase = (
            yy * rng.uniform(0.16, 0.28)
            + 2.4 * np.sin(xx * rng.uniform(0.0025, 0.0045))
            + 0.7 * np.sin(xx * 0.011)
        )
        grain = (
            15.0 * np.sin(phase + rng.uniform(0, 6.28))
            + 6.0 * np.sin(phase * 2.7 + 1.3)
            + 2.5 * np.sin(xx * 0.03)
        )
        color += grain[..., None] * np.asarray([1.0, 0.78, 0.55], dtype=np.float32)
        seam_width = max(1, int(scale * 2))
        seam_period = int(rng.integers(220, 360) * scale)
        seam = ((np.arange(width) % seam_period) < seam_width).astype(np.float32)
        color -= seam[None, :, None] * np.asarray([26, 18, 12], dtype=np.float32)
    elif theme["texture"] == "marble":
        vein = np.sin(
            xx * rng.uniform(0.0025, 0.004)
            + 3.2 * np.sin(yy * 0.0035)
            + 0.8 * np.sin((xx + yy) * 0.002)
            + rng.uniform(0, 6.28)
        )
        vein = np.abs(vein) ** 5
        color -= (vein * rng.uniform(26, 46))[..., None]
        thin = np.abs(np.sin(xx * 0.006 + 2.0 * np.sin(yy * 0.005))) ** 16
        color -= (thin * 16)[..., None]
        color += (np.sin(xx * 0.02 + yy * 0.013) * 2.0)[..., None]
    else:
        noise = rng.normal(0, 1, size=(height, width)).astype(np.float32)
        noise = np.asarray(
            Image.fromarray(((noise * 28) + 128).clip(0, 255).astype(np.uint8)).filter(
                ImageFilter.GaussianBlur(max(1, width // 320))
            ),
            dtype=np.float32,
        ) - 128.0
        color += (noise * 0.55)[..., None]

    # Viñeta y micro-ruido de sensor.
    nx = (xx / max(1, width - 1) - 0.5) * 2.0
    ny = (yy / max(1, height - 1) - 0.5) * 2.0
    vignette = 1.0 - 0.22 * np.clip(nx * nx + ny * ny, 0, 1.4)
    color *= vignette[..., None]
    color += rng.normal(0, 3.0, size=(height, width, 1)).astype(np.float32)
    color += rng.normal(0, 1.5, size=(height, width, 3)).astype(np.float32)

    # Banda superior (pared/fondo) y borde del mostrador.
    horizon = int(height * rng.uniform(0.13, 0.19))
    wall_tint = np.asarray([-26, -24, -22] if theme["warm"] else [-22, -22, -24], dtype=np.float32)
    color[:horizon] += wall_tint
    color[horizon:horizon + max(2, height // 220)] *= 0.72

    array = np.clip(color, 0, 255).astype(np.uint8)
    image = Image.fromarray(array, "RGB").filter(ImageFilter.GaussianBlur(0.8))
    return image


# --------------------------------------------------------------------------------------
# Productos
# --------------------------------------------------------------------------------------

def _shade(color: tuple[int, int, int], factor: float) -> tuple[int, int, int]:
    return tuple(max(0, min(255, int(channel * factor))) for channel in color)


def _product_color(rng) -> tuple[int, int, int]:
    palette = [
        (196, 64, 52), (212, 140, 36), (48, 108, 176), (44, 132, 92),
        (140, 72, 152), (222, 196, 72), (34, 40, 48), (232, 232, 226),
        (176, 92, 48), (52, 148, 156),
    ]
    return palette[int(rng.integers(0, len(palette)))]


def draw_product(draw, rng, kind: str, bbox: tuple[int, int, int, int], scale: int) -> None:
    x1, y1, x2, y2 = (value * scale for value in bbox)
    width, height = max(1, x2 - x1), max(1, y2 - y1)
    color = _product_color(rng)
    outline = _shade(color, 0.45)
    line_width = max(2, scale * 2)

    if kind == "caja":
        radius = max(6, int(min(width, height) * 0.10))
        draw.rounded_rectangle([x1, y1, x2, y2], radius=radius, fill=color,
                               outline=outline, width=line_width)
        # Cara superior y sombra inferior.
        top_h = int(height * 0.16)
        draw.rounded_rectangle([x1, y1, x2, y1 + top_h], radius=radius,
                               fill=_shade(color, 1.22), outline=outline, width=max(1, line_width // 2))
        draw.rectangle([x1 + line_width, y2 - int(height * 0.12), x2 - line_width, y2 - line_width],
                       fill=_shade(color, 0.78))
        # Etiqueta y "texto".
        band_y1 = y1 + int(height * 0.32)
        band_y2 = y1 + int(height * 0.70)
        draw.rectangle([x1 + int(width * 0.10), band_y1, x2 - int(width * 0.10), band_y2],
                       fill=(244, 242, 232))
        for offset in (0.42, 0.55, 0.66):
            draw.rectangle(
                [x1 + int(width * 0.18), y1 + int(height * offset),
                 x2 - int(width * 0.18), y1 + int(height * offset) + max(2, scale * 2)],
                fill=_shade(color, 0.35),
            )

    elif kind == "botella":
        body_top = y1 + int(height * 0.34)
        body_left = x1 + int(width * 0.12)
        body_right = x2 - int(width * 0.12)
        neck_left = x1 + int(width * 0.37)
        neck_right = x1 + int(width * 0.63)
        neck_top = y1 + int(height * 0.16)
        cap_top = y1
        # Cuerpo.
        draw.rounded_rectangle([body_left, body_top, body_right, y2 - max(1, scale)],
                               radius=max(4, int(width * 0.10)), fill=color,
                               outline=outline, width=line_width)
        # Hombros y cuello.
        draw.polygon(
            [(body_left, body_top), (neck_left, neck_top), (neck_right, neck_top),
             (body_right, body_top)],
            fill=_shade(color, 1.06), outline=outline,
        )
        draw.rectangle([neck_left, neck_top, neck_right, body_top + int(height * 0.03)],
                       fill=_shade(color, 1.02), outline=outline, width=max(1, line_width // 2))
        # Tapa.
        draw.rounded_rectangle(
            [neck_left - int(width * 0.02), cap_top, neck_right + int(width * 0.02), neck_top],
            radius=max(2, int(width * 0.03)), fill=_shade(color, 0.40), outline=outline,
            width=max(1, line_width // 2),
        )
        # Brillo y etiqueta.
        draw.rectangle([body_left + int(width * 0.10), body_top + int(height * 0.08),
                        body_left + int(width * 0.18), y2 - int(height * 0.10)],
                       fill=(255, 255, 255))
        draw.rectangle([body_left + int(width * 0.06), body_top + int(height * 0.24),
                        body_right - int(width * 0.06), body_top + int(height * 0.54)],
                       fill=(238, 234, 214))
        draw.rectangle([body_left + int(width * 0.12), body_top + int(height * 0.32),
                        body_right - int(width * 0.12), body_top + int(height * 0.38)],
                       fill=_shade(color, 0.35))

    elif kind == "fruta":
        radius = max(2, int(min(width, height) * 0.46))
        center = ((x1 + x2) // 2, (y1 + y2) // 2 + int(height * 0.06))
        draw.ellipse(
            [center[0] - radius, center[1] - radius, center[0] + radius, center[1] + radius],
            fill=color, outline=outline, width=line_width,
        )
        # Sombra inferior y brillo.
        draw.arc(
            [center[0] - radius, center[1] - radius, center[0] + radius, center[1] + radius],
            20, 160, fill=_shade(color, 0.55), width=max(2, scale * 3),
        )
        hx = center[0] - int(radius * 0.38)
        hy = center[1] - int(radius * 0.42)
        hr = max(2, int(radius * 0.28))
        draw.ellipse([hx - hr, hy - hr // 2, hx + hr, hy + hr // 2], fill=(255, 250, 236))
        # Tallo y hoja.
        draw.line([center[0], center[1] - radius, center[0] + int(radius * 0.05), y1 + int(height * 0.04)],
                  fill=(78, 58, 28), width=max(2, scale * 3))
        draw.ellipse(
            [center[0], y1 + int(height * 0.02), center[0] + int(radius * 0.5), y1 + int(height * 0.18)],
            fill=(60, 120, 56), outline=(40, 84, 40),
        )

    elif kind == "bolsa":
        top = y1 + int(height * 0.10)
        draw.polygon(
            [(x1 + int(width * 0.06), top), (x1 + int(width * 0.22), y1),
             (x1 + int(width * 0.50), top), (x1 + int(width * 0.78), y1),
             (x2 - int(width * 0.06), top), (x2 - int(width * 0.02), y2 - int(height * 0.06)),
             (x1 + int(width * 0.02), y2 - int(height * 0.06))],
            fill=color, outline=outline,
        )
        draw.polygon(
            [(x1 + int(width * 0.06), top), (x2 - int(width * 0.06), top),
             (x2 - int(width * 0.08), top + int(height * 0.12)),
             (x1 + int(width * 0.08), top + int(height * 0.12))],
            fill=_shade(color, 1.20),
        )
        # Arrugas.
        for index in range(3):
            offset = int(width * (0.20 + index * 0.18))
            draw.line([x1 + offset, top + int(height * 0.18), x1 + offset - int(width * 0.04), y2 - int(height * 0.12)],
                      fill=_shade(color, 0.72), width=max(1, scale * 2))
        # Etiqueta.
        draw.rounded_rectangle(
            [x1 + int(width * 0.26), y1 + int(height * 0.40),
             x2 - int(width * 0.26), y1 + int(height * 0.66)],
            radius=max(2, scale * 3), fill=(242, 238, 220),
        )
        draw.rectangle(
            [x1 + int(width * 0.32), y1 + int(height * 0.48),
             x2 - int(width * 0.32), y1 + int(height * 0.52)],
            fill=_shade(color, 0.30),
        )

    else:  # lata
        draw.rounded_rectangle([x1, y1, x2, y2], radius=max(4, int(width * 0.16)),
                               fill=color, outline=outline, width=line_width)
        draw.ellipse([x1, y1, x2, y1 + int(height * 0.12)], fill=_shade(color, 1.28),
                     outline=outline, width=max(1, line_width // 2))
        draw.ellipse([x1, y2 - int(height * 0.10), x2, y2], fill=_shade(color, 0.72),
                     outline=outline, width=max(1, line_width // 2))
        draw.rectangle(
            [x1 + int(width * 0.16), y1 + int(height * 0.24),
             x2 - int(width * 0.16), y1 + int(height * 0.70)],
            fill=(236, 232, 214),
        )
        draw.rectangle(
            [x1 + int(width * 0.22), y1 + int(height * 0.34),
             x2 - int(width * 0.22), y1 + int(height * 0.40)],
            fill=_shade(color, 0.28),
        )


# --------------------------------------------------------------------------------------
# Escenas
# --------------------------------------------------------------------------------------

def make_scene(rng, index: int, theme: dict, seed: int):
    import numpy as np
    from PIL import Image, ImageDraw, ImageFilter

    scale = SUPERSAMPLE
    width, height = WIDTH * scale, HEIGHT * scale
    background = make_background(rng, theme, width, height)

    # 1–6 productos; se garantiza variedad local y global.
    count = int(rng.integers(1, 7))
    types = [PRODUCT_TYPES[int(rng.integers(0, len(PRODUCT_TYPES)))] for _ in range(count)]
    if count >= 3:
        types[0] = "caja"
        types[1] = "botella" if types[0] != "botella" else "fruta"
        types[2] = "bolsa" if types[1] != "bolsa" else "fruta"

    # Primero se calculan las cajas para poder ordenar por profundidad.
    placements: list[dict[str, Any]] = []
    for object_index, kind in enumerate(types):
        for _ in range(60):
            object_width = int(rng.uniform(90, 250))
            object_height = int(rng.uniform(90, 230))
            if kind == "botella":
                object_height = int(max(object_height, object_width * 1.8))
            if kind == "caja":
                object_height = int(min(max(object_height, object_width * 0.7), object_width * 1.5))
            x1 = int(rng.uniform(40, WIDTH - object_width - 40))
            y2 = int(rng.uniform(max(430, object_height + 120), HEIGHT - 26))
            y1 = y2 - object_height
            if y1 < 170:
                continue
            candidate = {"x1": x1, "y1": y1, "x2": x1 + object_width, "y2": y2}
            if all(_iou(candidate, other) < 0.30 for other in placements):
                break
        else:
            candidate = {"x1": x1, "y1": y1, "x2": x1 + object_width, "y2": y2}
        candidate["kind"] = kind
        candidate["index"] = object_index
        placements.append(candidate)

    placements.sort(key=lambda item: (item["y2"], item["x1"]))
    for draw_order, item in enumerate(placements):
        item["draw_order"] = draw_order

    # Sombras de contacto (solo en la escena, no en la referencia vacía).
    shadow = Image.new("L", (width, height), 0)
    shadow_draw = ImageDraw.Draw(shadow)
    for item in placements:
        x1, y1, x2, y2 = (item[key] * scale for key in ("x1", "y1", "x2", "y2"))
        pad_x = int((x2 - x1) * 0.14)
        pad_y = max(4, int((y2 - y1) * 0.06))
        shadow_draw.rounded_rectangle(
            [x1 - pad_x + 10 * scale, y2 - pad_y + 12 * scale, x2 + pad_x + 10 * scale, y2 + pad_y + 12 * scale],
            radius=max(4, int((x2 - x1) * 0.20)), fill=96,
        )
    shadow = shadow.filter(ImageFilter.GaussianBlur(9 * scale))
    black = Image.new("RGB", (width, height), (0, 0, 0))
    scene = Image.composite(black, background, shadow)

    draw = ImageDraw.Draw(scene)
    for item in placements:
        draw_product(
            draw, rng, item["kind"],
            (item["x1"], item["y1"], item["x2"], item["y2"]), scale,
        )

    # Micro-detalle compartido: polvo/arañazos tenues en la superficie.
    # RNG separado para que el polvo no altere la secuencia de productos entre escenas.
    grain_rng = np.random.default_rng(seed + index * 7919 + 17)
    grain_array = grain_rng.integers(0, 256, size=(height, width), dtype=np.uint8)
    grain = Image.fromarray(grain_array, "L").filter(ImageFilter.GaussianBlur(0.6))
    dust = grain.point(lambda value: int(value * 0.035))
    scene = Image.composite(
        Image.new("RGB", (width, height), (220, 220, 220)),
        scene,
        dust,
    )
    empty_full = Image.composite(
        Image.new("RGB", (width, height), (220, 220, 220)),
        background,
        dust,
    )

    scene = scene.resize((WIDTH, HEIGHT), Image.Resampling.LANCZOS)
    empty = empty_full.resize((WIDTH, HEIGHT), Image.Resampling.LANCZOS)

    objects = []
    for item in placements:
        bbox = [item["x1"], item["y1"], item["x2"], item["y2"]]
        occluders = [
            other["index"] for other in placements
            if other["draw_order"] < item["draw_order"] and _iou(item, other) > 0.05
        ]
        objects.append(
            {
                "id": f"obj-{item['index']:02d}",
                "type": item["kind"],
                "label": "producto",
                "bbox_xyxy": bbox,
                "bbox_xywh": [bbox[0], bbox[1], bbox[2] - bbox[0], bbox[3] - bbox[1]],
                "area_px": (bbox[2] - bbox[0]) * (bbox[3] - bbox[1]),
                "occluded": bool(occluders),
                "occluders": occluders,
            }
        )

    return scene, empty, {
        "id": f"scene-{index:03d}",
        "theme": theme["name"],
        "product_count": count,
        "horizon_y": None,
        "objects": objects,
    }


def _iou(a: dict, b: dict) -> float:
    x1 = max(a["x1"], b["x1"])
    y1 = max(a["y1"], b["y1"])
    x2 = min(a["x2"], b["x2"])
    y2 = min(a["y2"], b["y2"])
    inter = max(0, x2 - x1) * max(0, y2 - y1)
    area_a = max(0, a["x2"] - a["x1"]) * max(0, a["y2"] - a["y1"])
    area_b = max(0, b["x2"] - b["x1"]) * max(0, b["y2"] - b["y1"])
    union = area_a + area_b - inter
    return inter / union if union else 0.0


# --------------------------------------------------------------------------------------
# Escritura
# --------------------------------------------------------------------------------------

def _paths(out: Path, scene_id: str) -> dict[str, Path]:
    return {
        "rgb": out / "frames" / f"{scene_id}.rgb.bin",
        "empty": out / "frames" / f"{scene_id}.empty.rgb.bin",
        "preview": out / "previews" / f"{scene_id}.png",
    }


def valid_existing(out: Path, seed: int, scenes: int) -> bool:
    index_path = out / "frames" / "index.json"
    gt_path = out / "ground_truth.json"
    if not index_path.exists() or not gt_path.exists():
        return False
    try:
        index = json.loads(index_path.read_text(encoding="utf-8"))
        ground_truth = json.loads(gt_path.read_text(encoding="utf-8"))
    except Exception:
        return False
    if index.get("seed") != seed or index.get("frame_count") != scenes:
        return False
    if len(ground_truth.get("scenes", [])) != scenes:
        return False
    for frame in index.get("frames", []):
        for key in ("rgb", "empty_rgb"):
            path = out / frame[key]
            if not path.exists() or path.stat().st_size != BYTES_PER_FRAME:
                return False
    return True


def _photo_pairs(src: Path) -> list[tuple[str, Path, Path]]:
    """Empareja <id>_scene.<ext> con <id>_empty.<ext> para cada escena."""
    extensions = (".jpg", ".jpeg", ".png", ".webp")
    pairs: list[tuple[str, Path, Path]] = []
    for scene in sorted(src.iterdir()):
        if scene.suffix.lower() not in extensions or not scene.stem.endswith("_scene"):
            continue
        base = scene.stem[: -len("_scene")]
        empty = None
        for extension in extensions:
            candidate = src / f"{base}_empty{extension}"
            if candidate.exists():
                empty = candidate
                break
        if empty is None:
            raise SystemExit(
                f"[ERROR] Falta {base}_empty.<jpg|jpeg|png|webp> junto a {scene.name}"
            )
        pairs.append((base, scene, empty))
    return pairs


def ingest_photos(src: Path, out: Path, previews: int, force: bool) -> None:
    """Convierte pares de fotos reales 1280x720 al formato frames/*.rgb.bin + GT."""
    from PIL import Image

    if not src.is_dir():
        raise SystemExit(f"[ERROR] No existe la carpeta de fotos: {src}")
    pairs = _photo_pairs(src)
    if not pairs:
        raise SystemExit(
            f"[ERROR] No hay pares <id>_scene / <id>_empty en {src}.\n"
            "Nombra las fotos así: s001_empty.jpg y s001_scene.jpg (mismo tamaño y encuadre)."
        )
    if out.exists() and not force:
        if (out / "frames" / "index.json").exists():
            _log(f"Set real ya presente en {out} (usa --force para reconvertir).")
            return
        shutil.rmtree(out)
    elif out.exists() and force:
        shutil.rmtree(out)
    (out / "frames").mkdir(parents=True, exist_ok=True)
    (out / "previews").mkdir(parents=True, exist_ok=True)

    frame_entries = []
    gt_scenes = []
    missing_annotations = 0
    try:
        relative_out = out.relative_to(REPO_ROOT).as_posix()
    except ValueError:
        relative_out = out.as_posix()

    for index, (base, scene_path, empty_path) in enumerate(pairs):
        with Image.open(scene_path) as scene_image:
            scene = scene_image.convert("RGB")
        with Image.open(empty_path) as empty_image:
            empty = empty_image.convert("RGB")
        if scene.size != (WIDTH, HEIGHT) or empty.size != (WIDTH, HEIGHT):
            raise SystemExit(
                f"[ERROR] {base}: se exigen {WIDTH}x{HEIGHT}; recibido escena={scene.size}, "
                f"vacío={empty.size}. Recorta/escala antes de convertir para no desalinear el GT."
            )

        rgb_path = out / "frames" / f"{base}.rgb.bin"
        empty_rgb_path = out / "frames" / f"{base}.empty.rgb.bin"
        rgb_path.write_bytes(scene.tobytes())
        empty_rgb_path.write_bytes(empty.tobytes())

        annotation_path = src / f"{base}.json"
        objects = []
        if annotation_path.exists():
            annotation = json.loads(annotation_path.read_text(encoding="utf-8-sig"))
            for object_index, item in enumerate(annotation.get("objects", [])):
                bbox = item.get("bbox_xyxy")
                if not (isinstance(bbox, list) and len(bbox) == 4):
                    raise SystemExit(
                        f"[ERROR] {annotation_path.name}: objects[{object_index}].bbox_xyxy inválido"
                    )
                x1, y1, x2, y2 = (float(value) for value in bbox)
                if not (0 <= x1 < x2 <= WIDTH and 0 <= y1 < y2 <= HEIGHT):
                    raise SystemExit(
                        f"[ERROR] {annotation_path.name}: bbox fuera de {WIDTH}x{HEIGHT}: {bbox}"
                    )
                objects.append(
                    {
                        "id": f"obj-{object_index:02d}",
                        "type": item.get("type", "producto"),
                        "label": item.get("label", "producto"),
                        "bbox_xyxy": [round(x1), round(y1), round(x2), round(y2)],
                        "bbox_xywh": [round(x1), round(y1), round(x2 - x1), round(y2 - y1)],
                        "area_px": round((x2 - x1) * (y2 - y1)),
                        "occluded": bool(item.get("occluded", False)),
                        "occluders": [],
                    }
                )
        else:
            missing_annotations += 1

        preview_path = None
        if index < previews:
            scene.save(out / "previews" / f"{base}.png", format="PNG", optimize=True)
            preview_path = f"previews/{base}.png"

        gt_scenes.append(
            {
                "id": base,
                "source": "real_photo",
                "source_images": {"scene": scene_path.name, "empty": empty_path.name},
                "theme": "real",
                "product_count": len(objects),
                "rgb": f"frames/{base}.rgb.bin",
                "empty_rgb": f"frames/{base}.empty.rgb.bin",
                "preview_png": preview_path,
                "bytes": BYTES_PER_FRAME,
                "objects": objects,
            }
        )
        frame_entries.append(
            {
                "id": base,
                "rgb": f"frames/{base}.rgb.bin",
                "empty_rgb": f"frames/{base}.empty.rgb.bin",
                "preview_png": preview_path,
                "bytes": BYTES_PER_FRAME,
                "sha256": sha256_file(rgb_path),
                "empty_sha256": sha256_file(empty_rgb_path),
                "product_count": len(objects),
                "theme": "real",
            }
        )
        _log(f"{base}  objetos={len(objects)}  {'(sin anotación)' if not annotation_path.exists() else ''}")

    index_payload = {
        "schema": "smartpos.proposers.frames.v1",
        "description": (
            "Arrays RGB crudos de fotos reales de mostrador y su referencia vacía. "
            "Cada frame es uint8 HWC 1280x720x3 (2 764 800 bytes), sin cabecera."
        ),
        "generated_by": "tools/ml/evaluate_proposers.py --from-photos",
        "source": "real_photos",
        "seed": None,
        "width": WIDTH,
        "height": HEIGHT,
        "channels": CHANNELS,
        "dtype": "uint8",
        "color_order": "RGB",
        "layout": "HWC",
        "bytes_per_frame": BYTES_PER_FRAME,
        "frame_count": len(frame_entries),
        "empty_reference_per_scene": True,
        "paths_relative_to": relative_out,
        "ground_truth": "ground_truth.json",
        "frames": frame_entries,
    }
    ground_truth = {
        "schema": "smartpos.proposers.ground_truth.v1",
        "generated_by": "tools/ml/evaluate_proposers.py --from-photos",
        "source": "real_photos",
        "seed": None,
        "width": WIDTH,
        "height": HEIGHT,
        "notes": (
            "Una referencia de mostrador vacío por escena, con el mismo encuadre. "
            "Las cajas provienen de <id>.json (objects[].bbox_xyxy) y son axis-aligned."
        ),
        "scene_count": len(gt_scenes),
        "scenes": gt_scenes,
    }
    (out / "frames" / "index.json").write_text(
        json.dumps(index_payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    (out / "ground_truth.json").write_text(
        json.dumps(ground_truth, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    _log(f"Listo: {len(gt_scenes)} escenas reales en {out}")
    if missing_annotations:
        _log(
            f"AVISO: {missing_annotations}/{len(gt_scenes)} escenas sin <id>.json; "
            "sin cajas no se puede calcular IoU de propuestas (sí el diff contra el vacío)."
        )


def generate(out: Path, scenes: int, seed: int, previews: int) -> None:
    import random

    import numpy as np

    rng = np.random.default_rng(seed)
    random.seed(seed)
    (out / "frames").mkdir(parents=True, exist_ok=True)
    (out / "previews").mkdir(parents=True, exist_ok=True)

    frame_entries = []
    gt_scenes = []
    preview_indices = set(range(0, scenes, max(1, scenes // max(1, previews)))) if previews else set()
    preview_indices = set(sorted(preview_indices)[:previews])
    started = time.perf_counter()

    for index in range(scenes):
        theme = THEMES[index % len(THEMES)]
        scene, empty, gt = make_scene(rng, index, theme, seed)
        paths = _paths(out, gt["id"])

        rgb_bytes = scene.tobytes()
        empty_bytes = empty.tobytes()
        if len(rgb_bytes) != BYTES_PER_FRAME or len(empty_bytes) != BYTES_PER_FRAME:
            raise RuntimeError(f"{gt['id']}: buffer RGB inesperado ({len(rgb_bytes)} bytes)")
        paths["rgb"].write_bytes(rgb_bytes)
        paths["empty"].write_bytes(empty_bytes)

        preview_path = None
        if index in preview_indices:
            scene.save(paths["preview"], format="PNG", optimize=True)
            preview_path = f"previews/{gt['id']}.png"

        gt["rgb"] = f"frames/{gt['id']}.rgb.bin"
        gt["empty_rgb"] = f"frames/{gt['id']}.empty.rgb.bin"
        gt["preview_png"] = preview_path
        gt["bytes"] = BYTES_PER_FRAME
        gt_scenes.append(gt)

        frame_entries.append(
            {
                "id": gt["id"],
                "rgb": f"frames/{gt['id']}.rgb.bin",
                "empty_rgb": f"frames/{gt['id']}.empty.rgb.bin",
                "preview_png": preview_path,
                "bytes": BYTES_PER_FRAME,
                "sha256": sha256_file(paths["rgb"]),
                "empty_sha256": sha256_file(paths["empty"]),
                "product_count": gt["product_count"],
                "theme": gt["theme"],
            }
        )
        _log(
            f"{gt['id']}  theme={gt['theme']:<15} productos={gt['product_count']}  "
            f"cajas={len(gt['objects'])}"
        )

    elapsed = time.perf_counter() - started
    index_payload = {
        "schema": "smartpos.proposers.frames.v1",
        "description": (
            "Arrays RGB crudos de escenas sintéticas de mostrador y su referencia vacía. "
            "Cada frame es uint8 HWC 1280x720x3 (2 764 800 bytes), sin cabecera."
        ),
        "generated_by": "tools/ml/evaluate_proposers.py",
        "seed": seed,
        "width": WIDTH,
        "height": HEIGHT,
        "channels": CHANNELS,
        "dtype": "uint8",
        "color_order": "RGB",
        "layout": "HWC",
        "bytes_per_frame": BYTES_PER_FRAME,
        "frame_count": len(frame_entries),
        "empty_reference_per_scene": True,
        "paths_relative_to": "tools/ml/synthetic",
        "ground_truth": "ground_truth.json",
        "frames": frame_entries,
    }
    ground_truth = {
        "schema": "smartpos.proposers.ground_truth.v1",
        "generated_by": "tools/ml/evaluate_proposers.py",
        "seed": seed,
        "width": WIDTH,
        "height": HEIGHT,
        "notes": (
            "Una referencia de mostrador vacío por escena, con el mismo fondo e iluminación. "
            "Cada caja es el bounding box axis-aligned exacto del producto dibujado."
        ),
        "scene_count": len(gt_scenes),
        "scenes": gt_scenes,
    }
    (out / "frames" / "index.json").write_text(
        json.dumps(index_payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    (out / "ground_truth.json").write_text(
        json.dumps(ground_truth, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    _log(
        f"Listo: {len(gt_scenes)} escenas, {len(gt_scenes) * 2} frames, "
        f"{len(preview_indices)} PNG de vista previa, {elapsed:.1f}s."
    )
    _log(f"Índice: {(out / 'frames' / 'index.json').as_posix()}")
    _log(f"Ground truth: {(out / 'ground_truth.json').as_posix()}")


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="evaluate_proposers.py",
        description=(
            "Genera escenas sintéticas de mostrador (1280x720) o convierte pares de fotos "
            "reales (vacío + cargado) al mismo formato de frames + ground truth."
        ),
        epilog=(
            "Las escenas sintéticas son deterministas para una misma --seed. Salida gitignored "
            "en tools/ml/synthetic/. El arnés JS debe leer frames/index.json."
        ),
    )
    parser.add_argument("--out", type=Path, default=None, help="Carpeta de salida.")
    parser.add_argument("--scenes", type=int, default=20, help="Número de escenas (mínimo 20).")
    parser.add_argument("--seed", type=int, default=20261127, help="Semilla determinista.")
    parser.add_argument("--previews", type=int, default=3, help="PNG de vista previa (default 3).")
    parser.add_argument("--force", action="store_true", help="Regenera aunque el set exista.")
    parser.add_argument(
        "--from-photos",
        type=Path,
        default=None,
        metavar="DIR",
        help=(
            "Convierte pares <id>_scene.<ext> / <id>_empty.<ext> de DIR (1280x720) en frames. "
            "Anotaciones opcionales en <id>.json con objects[].bbox_xyxy."
        ),
    )
    args = parser.parse_args(argv)
    if args.from_photos is None:
        if args.scenes < 20:
            parser.error("--scenes debe ser >= 20 (requisito de la Fase 3)")
        if args.previews < 0 or args.previews > args.scenes:
            parser.error("--previews debe estar entre 0 y --scenes")
    return args


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    if args.from_photos is not None:
        out = (args.out or (DEFAULT_OUT / "real")).resolve()
        ingest_photos(args.from_photos.resolve(), out, args.previews, args.force)
        return 0
    out = (args.out or DEFAULT_OUT).resolve()
    if not args.force and valid_existing(out, args.seed, args.scenes):
        _log(f"Set ya presente y válido en {out} (usa --force para regenerar).")
        return 0
    if args.force and out.exists():
        shutil.rmtree(out)
    generate(out, args.scenes, args.seed, args.previews)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
