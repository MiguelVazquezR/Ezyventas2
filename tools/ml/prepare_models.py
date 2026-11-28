#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Fase 3 — Pipeline reproducible de modelos ONNX para el Modo Caja Inteligente.

Descarga los checkpoints públicos fijados por commit, los exporta a ONNX, valida
PyTorch contra onnxruntime, cuantiza INT8 (y fp16 como reserva), publica el artefacto
elegido en public/models/ y escribe los manifiestos.

Uso típico (Windows, desde la raíz del repo):

    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\prepare_models.py --help
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\prepare_models.py
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\prepare_models.py --models embeddings
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\prepare_models.py --verify-only

Si se ejecuta con un Python sin las dependencias, el script crea/usa
`tools/ml/.venv` y se re-ejecuta dentro de él (ver requirements.txt).
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import statistics
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ML_DIR = Path(__file__).resolve().parent
REPO_ROOT = ML_DIR.parents[1]
VENV_DIR = ML_DIR / ".venv"
SRC_DIR = ML_DIR / "models_src"
BUILD_DIR = ML_DIR / "models_build"
SAMPLES_DIR = BUILD_DIR / "samples"
PUBLIC_MODELS = REPO_ROOT / "public" / "models"
REQUIREMENTS = ML_DIR / "requirements.txt"
PYTORCH_CPU_INDEX = "https://download.pytorch.org/whl/cpu"

MODELS: dict[str, dict[str, Any]] = {
    "embeddings": {
        "key": "embeddings",
        "name": "DINOv2-small",
        "role": "embeddings",
        "repo_id": "facebook/dinov2-small",
        "revision": "ed25f3a31f01632728cabb09d1542f84ab7b0056",
        "license": "apache-2.0",
        "source_url": "https://huggingface.co/facebook/dinov2-small",
        "gated": False,
        "prefix": "dinov2-small",
        "manifest_file": "dinov2-small.manifest.json",
        "model_version": "dinov2-small-onnx-2026-11",
        "embedding_dim": 384,
        "input_shape": [1, 3, 224, 224],
    },
    "detector": {
        "key": "detector",
        "name": "RT-DETR-R18vd",
        "role": "detector",
        "repo_id": "PekingU/rtdetr_r18vd",
        "revision": "ac77a11ff0170a41b771c03264987f8ce2b0d753",
        "license": "apache-2.0",
        "source_url": "https://huggingface.co/PekingU/rtdetr_r18vd",
        "gated": False,
        "prefix": "rtdetr-r18vd",
        "manifest_file": "rtdetr-r18vd.manifest.json",
        "model_version": "rtdetr-r18vd-onnx-2026-11",
        "input_shape": [1, 3, 640, 640],
    },
}

# Umbrales exigidos por la Fase 3.
THRESHOLDS = {
    "embeddings": {"cosine_mean": 0.99, "cosine_p5": 0.98},
    "detector": {"iou_mean": 0.98, "pt_iou": 0.99, "pt_score_diff": 1e-3},
}

# Orden exacto de clases COCO-80 del checkpoint (config.id2label, 0..79).
COCO_CLASSES = [
    "person", "bicycle", "car", "motorbike", "aeroplane", "bus", "train", "truck",
    "boat", "traffic light", "fire hydrant", "stop sign", "parking meter", "bench",
    "bird", "cat", "dog", "horse", "sheep", "cow", "elephant", "bear", "zebra",
    "giraffe", "backpack", "umbrella", "handbag", "tie", "suitcase", "frisbee",
    "skis", "snowboard", "sports ball", "kite", "baseball bat", "baseball glove",
    "skateboard", "surfboard", "tennis racket", "bottle", "wine glass", "cup",
    "fork", "knife", "spoon", "bowl", "banana", "apple", "sandwich", "orange",
    "broccoli", "carrot", "hot dog", "pizza", "donut", "cake", "chair", "sofa",
    "pottedplant", "bed", "diningtable", "toilet", "tvmonitor", "laptop", "mouse",
    "remote", "keyboard", "cell phone", "microwave", "oven", "toaster", "sink",
    "refrigerator", "book", "clock", "vase", "scissors", "teddy bear", "hair drier",
    "toothbrush",
]


# --------------------------------------------------------------------------------------
# Utilidades generales
# --------------------------------------------------------------------------------------

def _log(message: str) -> None:
    print(f"[prepare_models] {message}", flush=True)


def _die(message: str, code: int = 1) -> None:
    print(f"\n[ERROR] {message}\n", file=sys.stderr, flush=True)
    raise SystemExit(code)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat()


def rel(path: Path) -> str:
    try:
        return path.resolve().relative_to(REPO_ROOT).as_posix()
    except ValueError:
        return path.as_posix()


def venv_python() -> Path:
    if os.name == "nt":
        return VENV_DIR / "Scripts" / "python.exe"
    return VENV_DIR / "bin" / "python"


def _deps_available(python: Path) -> bool:
    probe = (
        "import torch, transformers, onnx, onnxruntime, huggingface_hub, numpy, PIL;"
        "print(torch.__version__)"
    )
    completed = subprocess.run(
        [str(python), "-c", probe],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
    )
    return completed.returncode == 0


def ensure_venv(no_venv: bool) -> None:
    """Crea el venv si falta, instala requirements si están incompletos y se re-ejecuta."""
    if no_venv:
        if not _deps_available(Path(sys.executable)):
            _die(
                "El intérprete activo no tiene las dependencias.\n"
                f"Instálalas con:\n  {sys.executable} -m pip install -r {REQUIREMENTS} "
                f"--extra-index-url {PYTORCH_CPU_INDEX}"
            )
        return

    if Path(sys.executable).resolve() == venv_python().resolve():
        if not _deps_available(Path(sys.executable)):
            _log("Faltan dependencias en el venv; instalando requirements.txt...")
            subprocess.check_call(
                [
                    str(venv_python()), "-m", "pip", "install",
                    "-r", str(REQUIREMENTS),
                    "--extra-index-url", PYTORCH_CPU_INDEX,
                ]
            )
        return

    if not venv_python().exists():
        base = os.environ.get("EZY_ML_PYTHON")
        if not base:
            base = shutil.which("python") or sys.executable
        _log(f"Creando entorno virtual en {rel(VENV_DIR)} con {base}")
        subprocess.check_call([base, "-m", "venv", str(VENV_DIR)])
        subprocess.check_call([str(venv_python()), "-m", "pip", "install", "--upgrade", "pip"])

    if not _deps_available(venv_python()):
        _log("Instalando dependencias fijadas (puede tardar varios minutos la primera vez)...")
        subprocess.check_call(
            [
                str(venv_python()), "-m", "pip", "install",
                "-r", str(REQUIREMENTS),
                "--extra-index-url", PYTORCH_CPU_INDEX,
            ]
        )

    _log(f"Re-ejecutando dentro de {rel(venv_python())}")
    os.execv(str(venv_python()), [str(venv_python()), str(Path(__file__).resolve()), *sys.argv[1:]])


# --------------------------------------------------------------------------------------
# Descarga de checkpoints
# --------------------------------------------------------------------------------------

def download_source(model: dict[str, Any], force: bool = False) -> Path:
    from huggingface_hub import snapshot_download
    from huggingface_hub.errors import GatedRepoError, RepositoryNotFoundError

    dest = SRC_DIR / model["prefix"]
    dest.mkdir(parents=True, exist_ok=True)
    marker = dest / "model.safetensors"
    if marker.exists() and not force:
        _log(f"{model['repo_id']}: checkpoint ya presente en {rel(dest)} (omitido)")
        return dest

    _log(
        f"Descargando {model['repo_id']} @ {model['revision'][:12]} "
        f"(licencia {model['license']}, público)"
    )
    try:
        snapshot_download(
            repo_id=model["repo_id"],
            revision=model["revision"],
            local_dir=str(dest),
            allow_patterns=[
                "config.json",
                "preprocessor_config.json",
                "model.safetensors",
                "README.md",
            ],
        )
    except GatedRepoError:
        _die(
            f"{model['repo_id']} exige aceptar términos o un token. "
            "D8 dice que no se pide token: detente y consulta al humano."
        )
    except RepositoryNotFoundError:
        _die(f"No existe o no es accesible {model['repo_id']}. Revisa el commit/repositorio.")
    except Exception as exc:  # pragma: no cover - depende de red
        _die(
            f"Falló la descarga de {model['repo_id']} ({type(exc).__name__}: {exc}).\n"
            "No se rodea la restricción. Ejecuta a mano:\n"
            f'  .\\tools\\ml\\.venv\\Scripts\\python.exe -c "from huggingface_hub import snapshot_download; '
            f"snapshot_download(repo_id='{model['repo_id']}', revision='{model['revision']}', "
            f"local_dir='{rel(dest)}')\""
        )

    if not marker.exists():
        files = sorted(p.name for p in dest.glob("*"))
        _die(f"{model['repo_id']}: falta model.safetensors tras la descarga. Archivos: {files}")
    return dest


# --------------------------------------------------------------------------------------
# Carga y envoltorios de exportación
# --------------------------------------------------------------------------------------

def load_embedding_model(src: Path):
    import torch
    from transformers import Dinov2Model

    try:
        model = Dinov2Model.from_pretrained(
            str(src), local_files_only=True, attn_implementation="eager"
        )
    except TypeError:
        model = Dinov2Model.from_pretrained(str(src), local_files_only=True)
    model.eval()
    torch.set_grad_enabled(False)

    class EmbeddingWrapper(torch.nn.Module):
        def __init__(self, inner: torch.nn.Module) -> None:
            super().__init__()
            self.inner = inner

        def forward(self, pixel_values):
            hidden = self.inner(pixel_values=pixel_values).last_hidden_state
            return hidden[:, 0, :]  # token CLS

    return EmbeddingWrapper(model).eval()


def load_detector_model(src: Path):
    import torch
    from transformers import RTDetrForObjectDetection

    try:
        model = RTDetrForObjectDetection.from_pretrained(
            str(src), local_files_only=True, attn_implementation="eager"
        )
    except TypeError:
        model = RTDetrForObjectDetection.from_pretrained(str(src), local_files_only=True)
    model.eval()
    torch.set_grad_enabled(False)

    class DetectorWrapper(torch.nn.Module):
        def __init__(self, inner: torch.nn.Module) -> None:
            super().__init__()
            self.inner = inner

        def forward(self, pixel_values):
            output = self.inner(pixel_values=pixel_values)
            return output.logits, output.pred_boxes

    return DetectorWrapper(model).eval()


# --------------------------------------------------------------------------------------
# Exportación
# --------------------------------------------------------------------------------------

def export_onnx(kind: str, wrapper, out: Path, input_shape: list[int]) -> None:
    import torch

    out.parent.mkdir(parents=True, exist_ok=True)
    if kind == "embeddings":
        output_names = ["embedding"]
    else:
        output_names = ["logits", "pred_boxes"]

    dummy = torch.zeros(*input_shape, dtype=torch.float32)
    dynamic_axes = {"pixel_values": {0: "batch"}}
    for name in output_names:
        dynamic_axes[name] = {0: "batch"}

    _log(f"Exportando {out.name} (opset 17, dynamo=False)...")
    started = time.perf_counter()
    torch.onnx.export(
        wrapper,
        (dummy,),
        str(out),
        input_names=["pixel_values"],
        output_names=output_names,
        dynamic_axes=dynamic_axes,
        opset_version=17,
        do_constant_folding=True,
        dynamo=False,
        export_params=True,
    )
    _log(f"  export terminado en {time.perf_counter() - started:.1f}s → {out.stat().st_size / 1e6:.1f} MB")


def onnx_io_summary(path: Path) -> dict[str, Any]:
    import onnx

    model = onnx.load(str(path), load_external_data=False)
    initializer_names = {item.name for item in model.graph.initializer}

    def describe(values):
        result = []
        for value in values:
            if value.name in initializer_names:
                continue
            tensor = value.type.tensor_type
            dims = []
            for dim in tensor.shape.dim:
                if dim.dim_value:
                    dims.append(dim.dim_value)
                elif dim.dim_param:
                    dims.append(dim.dim_param)
                else:
                    dims.append("?")
            result.append(
                {
                    "name": value.name,
                    "shape": dims,
                    "dtype": onnx.TensorProto.DataType.Name(tensor.elem_type).lower(),
                }
            )
        return result

    return {
        "inputs": describe(model.graph.input),
        "outputs": describe(model.graph.output),
        "opset": max((op.version for op in model.opset_import), default=None),
        "ir_version": model.ir_version,
    }


# --------------------------------------------------------------------------------------
# Preprocesado e imágenes de validación
# --------------------------------------------------------------------------------------

def build_processors(src_emb: Path, src_det: Path):
    from transformers import AutoImageProcessor, RTDetrImageProcessor

    embeddings = None
    detector = None
    if (src_emb / "preprocessor_config.json").exists():
        embeddings = AutoImageProcessor.from_pretrained(str(src_emb), local_files_only=True)
    if (src_det / "preprocessor_config.json").exists():
        detector = RTDetrImageProcessor.from_pretrained(str(src_det), local_files_only=True)
    return embeddings, detector


def _generated_image(index: int):
    """Imagen sintética determinista con objetos tipo mostrador (para no depender de assets)."""
    import numpy as np
    from PIL import Image, ImageDraw, ImageFilter

    rng = np.random.default_rng(20261127 + index)
    width, height = 1280, 720
    base = rng.integers(20, 70, size=3).astype(np.float32)
    yy = np.linspace(0, 1, height, dtype=np.float32)[:, None, None]
    gradient = yy * rng.uniform(15, 60)
    noise = rng.normal(0, 6, size=(height, width, 1)).astype(np.float32)
    array = np.clip(base + gradient + noise, 0, 255).astype(np.uint8)
    array = np.broadcast_to(array, (height, width, 3)).copy()
    image = Image.fromarray(array, "RGB").filter(ImageFilter.GaussianBlur(0.6))
    draw = ImageDraw.Draw(image, "RGBA")

    for _ in range(4):
        x1, y1 = int(rng.integers(40, 1000)), int(rng.integers(120, 500))
        w, h = int(rng.integers(120, 260)), int(rng.integers(100, 220))
        color = tuple(int(v) for v in rng.integers(60, 230, size=3)) + (235,)
        draw.rounded_rectangle([x1, y1, x1 + w, y1 + h], radius=18, fill=color,
                               outline=(20, 20, 20, 160), width=4)
        draw.rectangle([x1 + 18, y1 + 18, x1 + w - 18, y1 + 52],
                       fill=tuple(max(0, c - 45) for c in color[:3]) + (255,))
    return image


def collect_samples(limit: int = 8) -> list[Path]:
    """Mezcla 4 fotos del repo (si existen) con imágenes sintéticas deterministas."""
    from PIL import Image

    SAMPLES_DIR.mkdir(parents=True, exist_ok=True)
    generated: list[Path] = []
    for index in range(4):
        target = SAMPLES_DIR / f"synthetic-{index:02d}.png"
        if not target.exists():
            _generated_image(index).save(target, format="PNG")
        generated.append(target)

    repo_images: list[Path] = []
    images_root = REPO_ROOT / "public" / "images"
    if images_root.exists():
        candidates = sorted(
            p for p in images_root.rglob("*")
            if p.suffix.lower() in {".jpg", ".jpeg", ".png", ".webp"}
        )
        for candidate in candidates:
            try:
                with Image.open(candidate) as probe:
                    if min(probe.size) >= 256:
                        repo_images.append(candidate)
            except Exception:
                continue
        if len(repo_images) > 4:
            step = len(repo_images) / 4
            repo_images = [repo_images[int(i * step)] for i in range(4)]

    samples = repo_images + generated
    if len(samples) < 5:
        _die("No se reunieron al menos 5 imágenes de validación.")
    return samples[:limit]


def preprocess(processor, path: Path):
    from PIL import Image

    with Image.open(path) as image:
        rgb = image.convert("RGB")
        encoded = processor(images=rgb, return_tensors="pt")
    return encoded["pixel_values"].cpu().numpy().astype("float32")


# --------------------------------------------------------------------------------------
# Métricas
# --------------------------------------------------------------------------------------

def cosine_similarity(a, b) -> float:
    import numpy as np

    a = a.reshape(-1).astype("float64")
    b = b.reshape(-1).astype("float64")
    denominator = float(np.linalg.norm(a) * np.linalg.norm(b))
    if denominator == 0.0:
        return 0.0
    return float(np.dot(a, b) / denominator)


def cxcywh_to_xyxy(boxes):
    import numpy as np

    boxes = np.asarray(boxes, dtype="float64")
    cx, cy, w, h = boxes[..., 0], boxes[..., 1], boxes[..., 2], boxes[..., 3]
    return np.stack([cx - w / 2, cy - h / 2, cx + w / 2, cy + h / 2], axis=-1)


def iou_per_query(boxes_a, boxes_b) -> "Any":
    import numpy as np

    a = cxcywh_to_xyxy(boxes_a)
    b = cxcywh_to_xyxy(boxes_b)
    x1 = np.maximum(a[..., 0], b[..., 0])
    y1 = np.maximum(a[..., 1], b[..., 1])
    x2 = np.minimum(a[..., 2], b[..., 2])
    y2 = np.minimum(a[..., 3], b[..., 3])
    inter = np.clip(x2 - x1, 0, None) * np.clip(y2 - y1, 0, None)
    area_a = np.clip(a[..., 2] - a[..., 0], 0, None) * np.clip(a[..., 3] - a[..., 1], 0, None)
    area_b = np.clip(b[..., 2] - b[..., 0], 0, None) * np.clip(b[..., 3] - b[..., 1], 0, None)
    union = area_a + area_b - inter
    return np.where(union > 0, inter / union, 1.0)


def sigmoid(x):
    import numpy as np

    return 1.0 / (1.0 + np.exp(-x))


def _percentile(values: list[float], q: float) -> float:
    import numpy as np

    return float(np.percentile(np.asarray(values, dtype="float64"), q))


# --------------------------------------------------------------------------------------
# Inferencia y latencia
# --------------------------------------------------------------------------------------

def make_session(path: Path):
    import onnxruntime as ort

    options = ort.SessionOptions()
    # Sin overrides: onnxruntime usa intra_op_num_threads por defecto (0 = automático).
    session = ort.InferenceSession(
        str(path), sess_options=options, providers=["CPUExecutionProvider"]
    )
    return session


def ort_run(session, pixel_values):
    inputs = {item.name: pixel_values for item in session.get_inputs()}
    return session.run(None, inputs)


def torch_run(wrapper, pixel_values):
    import torch

    with torch.inference_mode():
        outputs = wrapper(torch.from_numpy(pixel_values))
    if isinstance(outputs, tuple):
        return [item.detach().cpu().numpy() for item in outputs]
    return [outputs.detach().cpu().numpy()]


def measure_latency(session, pixel_values, warmup: int, runs: int) -> dict[str, float]:
    for _ in range(warmup):
        ort_run(session, pixel_values)
    timings: list[float] = []
    for _ in range(runs):
        started = time.perf_counter()
        ort_run(session, pixel_values)
        timings.append((time.perf_counter() - started) * 1000.0)
    return {
        "mean": round(statistics.fmean(timings), 2),
        "p95": round(_percentile(timings, 95), 2),
        "runs": runs,
        "batch": int(pixel_values.shape[0]),
    }


# --------------------------------------------------------------------------------------
# Validación de equivalencia y cuantización
# --------------------------------------------------------------------------------------

def validate_embeddings(pt_wrapper, session, processor, samples: list[Path]) -> dict[str, Any]:
    import numpy as np

    cosines = []
    max_abs = 0.0
    for sample in samples:
        pixel_values = preprocess(processor, sample)
        pt = torch_run(pt_wrapper, pixel_values)[0]
        ort = ort_run(session, pixel_values)[0]
        cosines.append(cosine_similarity(pt, ort))
        max_abs = max(max_abs, float(np.max(np.abs(pt - ort))))

    return {
        "samples": len(samples),
        "sample_files": [rel(path) for path in samples],
        "cosine_mean": round(float(np.mean(cosines)), 8),
        "cosine_min": round(float(np.min(cosines)), 8),
        "cosine_p5": round(_percentile(cosines, 5), 8),
        "max_abs_diff": round(max_abs, 8),
        "passed": bool(np.mean(cosines) > 0.99 and np.min(cosines) > 0.99),
    }


def _detector_quality(reference: list[np.ndarray], candidate: list[np.ndarray], top_k: int = 100) -> dict[str, Any]:
    """Compara logits/pred_boxes de referencia y candidato query a query (top-k por score)."""
    import numpy as np

    ious: list[float] = []
    score_drops: list[float] = []
    class_matches = 0
    total = 0
    discrepancy_count = 0
    discrepancies: list[dict[str, Any]] = []
    high_ious: list[float] = []
    high_drops: list[float] = []

    for image_index, (ref_logits, ref_boxes) in enumerate(reference):
        cand_logits, cand_boxes = candidate[image_index]
        ref_probs = sigmoid(ref_logits[0])
        cand_probs = sigmoid(cand_logits[0])
        order = np.argsort(-ref_probs.max(axis=-1))[:top_k]
        iou = iou_per_query(ref_boxes[0][order], cand_boxes[0][order])
        ious.extend(float(value) for value in iou)
        drop = ref_probs[order].max(axis=-1) - cand_probs[order].max(axis=-1)
        score_drops.extend(float(value) for value in drop)
        class_matches += int(np.sum(ref_probs[order].argmax(-1) == cand_probs[order].argmax(-1)))
        total += int(len(order))
        # Subconjunto "confiable": lo que la app realmente mostraría (score >= 0.3).
        confident = np.where(ref_probs.max(axis=-1) >= 0.3)[0]
        if len(confident):
            high_ious.extend(float(value) for value in iou_per_query(ref_boxes[0][confident], cand_boxes[0][confident]))
            high_drops.extend(
                float(value)
                for value in (ref_probs[confident].max(axis=-1) - cand_probs[confident].max(axis=-1))
            )
        low = np.where(iou < 0.98)[0]
        discrepancy_count += int(len(low))
        for position in low[:3]:
            query = int(order[position])
            discrepancies.append(
                {
                    "sample": image_index,
                    "query": query,
                    "iou": round(float(iou[position]), 6),
                    "score_ref": round(float(ref_probs[query].max()), 6),
                    "score_cand": round(float(cand_probs[query].max()), 6),
                }
            )

    return {
        "queries_compared": total,
        "iou_mean": round(float(np.mean(ious)), 8),
        "iou_min": round(float(np.min(ious)), 8),
        "score_drop_mean": round(float(np.mean(score_drops)), 8),
        "score_drop_max": round(float(np.max(score_drops)), 8),
        "class_agreement": round(class_matches / total, 6) if total else 0.0,
        "high_confidence": {
            "threshold": 0.3,
            "queries": len(high_ious),
            "iou_mean": round(float(np.mean(high_ious)), 8) if high_ious else None,
            "iou_min": round(float(np.min(high_ious)), 8) if high_ious else None,
            "score_drop_mean": round(float(np.mean(high_drops)), 8) if high_drops else None,
        },
        "discrepancies": discrepancies[:20],
        "discrepancies_total": discrepancy_count,
    }


def validate_detector(pt_wrapper, session, processor, samples: list[Path]) -> dict[str, Any]:
    import numpy as np

    reference: list[list[np.ndarray]] = []
    candidate: list[list[np.ndarray]] = []
    pt_iou: list[float] = []
    score_diffs: list[float] = []
    discrepancies: list[dict[str, Any]] = []

    for image_index, sample in enumerate(samples):
        pixel_values = preprocess(processor, sample)
        pt = torch_run(pt_wrapper, pixel_values)
        ort = ort_run(session, pixel_values)
        reference.append(pt)
        candidate.append(ort)

        pt_probs, ort_probs = sigmoid(pt[0][0]), sigmoid(ort[0][0])
        iou = iou_per_query(pt[1][0], ort[1][0])
        pt_iou.extend(float(value) for value in iou)
        diff = float(np.max(np.abs(pt_probs - ort_probs)))
        score_diffs.append(diff)
        bad = np.where((iou < 0.99) | (np.abs(pt_probs.max(axis=-1) - ort_probs.max(axis=-1)) > 1e-3))[0]
        for query in bad[:3]:
            discrepancies.append(
                {
                    "sample": image_index,
                    "query": int(query),
                    "iou": round(float(iou[query]), 6),
                    "score_diff": round(float(np.abs(pt_probs[query] - ort_probs[query]).max()), 8),
                }
            )

    quality = _detector_quality(reference, candidate, top_k=100)
    return {
        "samples": len(samples),
        "sample_files": [rel(path) for path in samples],
        "iou_mean": round(float(np.mean(pt_iou)), 8),
        "iou_min": round(float(np.min(pt_iou)), 8),
        "max_score_diff": round(max(score_diffs), 8),
        "discrepancies": discrepancies[:20],
        "discrepancies_total": len(discrepancies),
        "top100": quality,
        "passed": bool(np.mean(pt_iou) >= 0.99 and max(score_diffs) < 1e-3),
    }


def quantize_int8(source: Path, target: Path) -> None:
    from onnxruntime.quantization import QuantType, quantize_dynamic

    # Solo MatMul/Gemm: onnxruntime CPU no implementa ConvInteger y el export de
    # RT-DETR/DINOv2 deja las convoluciones en fp32 (el backbone sigue en fp32).
    if target.exists():
        target.unlink()
    _log(f"Cuantizando INT8 (dinámica MatMul/Gemm, pesos QInt8 por canal): {target.name}")
    quantize_dynamic(
        model_input=str(source),
        model_output=str(target),
        op_types_to_quantize=["MatMul", "Gemm"],
        weight_type=QuantType.QInt8,
        per_channel=True,
        reduce_range=False,
        use_external_data_format=False,
    )


def convert_fp16(source: Path, target: Path, keep_io_types: bool = True) -> None:
    import onnx

    try:
        from onnxruntime.transformers.float16 import convert_float_to_float16
    except Exception as exc:  # pragma: no cover
        raise RuntimeError(f"No se pudo importar el conversor fp16 de onnxruntime: {exc}") from exc
    if target.exists():
        target.unlink()
    _log(f"Convirtiendo a fp16 (reserva): {target.name}")
    model = onnx.load(str(source))
    # El kernel CPU de Resize cúbico no acepta fp16; Pow tampoco tiene kernel fp16.
    # Se dejan en fp32 y el conversor inserta los casts necesarios.
    converted = convert_float_to_float16(
        model, keep_io_types=keep_io_types, op_block_list=["Resize", "Pow"]
    )
    onnx.save(converted, str(target))


# --------------------------------------------------------------------------------------
# Publicación y manifiestos
# --------------------------------------------------------------------------------------

def publish_artifact(build_path: Path, prefix: str) -> tuple[Path, str, int]:
    PUBLIC_MODELS.mkdir(parents=True, exist_ok=True)
    digest = sha256_file(build_path)
    final_name = f"{prefix}-{digest[:12]}.onnx"
    final_path = PUBLIC_MODELS / final_name
    if final_path.exists() and sha256_file(final_path) == digest:
        _log(f"Artefacto ya publicado e idéntico: public/models/{final_name}")
    else:
        shutil.copy2(build_path, final_path)
        _log(f"Publicado public/models/{final_name} ({final_path.stat().st_size / 1e6:.1f} MB)")
    for stale in PUBLIC_MODELS.glob(f"{prefix}-*.onnx"):
        if stale.name not in {final_name}:
            stale.unlink()
            _log(f"  eliminado artefacto anterior {stale.name}")
    return final_path, digest, final_path.stat().st_size


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def _preprocessing(kind: str) -> dict[str, Any]:
    if kind == "embeddings":
        return {
            "color_order": "RGB",
            "layout": "NCHW",
            "resize": {
                "mode": "shorter_side",
                "shortest_edge": 256,
                "interpolation": "bicubic",
                "antialias": True,
            },
            "center_crop": [224, 224],
            "rescale": {"factor": 0.00392156862745098, "formula": "value/255"},
            "normalize": {
                "mean": [0.485, 0.456, 0.406],
                "std": [0.229, 0.224, 0.225],
                "formula": "(value/255 - mean) / std",
            },
            "notes": (
                "Equivale a BitImageProcessor de facebook/dinov2-small: shortest edge a 256 "
                "con bicúbica, center crop 224x224, ImageNet mean/std. Sin L2 en el grafo."
            ),
        }
    return {
        "color_order": "RGB",
        "layout": "NCHW",
        "resize": {
            "mode": "exact",
            "height": 640,
            "width": 640,
            "interpolation": "bilinear",
            "antialias": True,
        },
        "rescale": {"factor": 0.00392156862745098, "formula": "value/255"},
        "normalize": {
            "enabled": False,
            "mean": None,
            "std": None,
            "notes": (
                "El preprocessor_config.json de PekingU/rtdetr_r18vd trae do_normalize=false: "
                "NO se resta media ni se divide por desviación. Entrada en [0,1]."
            ),
        },
        "notes": (
            "RTDetrImageProcessor con do_resize=true, size={height:640,width:640}, "
            "resample=bilinear, do_rescale=true, do_normalize=false, do_pad=false."
        ),
    }


def build_manifest(
    model: dict[str, Any],
    artifact: Path,
    digest: str,
    io_summary: dict[str, Any],
    chosen_variant: str,
    variants: dict[str, Any],
    validated: dict[str, Any],
) -> dict[str, Any]:
    output_shapes = {item["name"]: item["shape"] for item in io_summary["outputs"]}
    if model["key"] == "embeddings":
        output = {
            "name": "embedding",
            "shape": ["batch", 384],
            "dtype": "float32",
            "description": (
                "Token CLS (posicion 0) de la ultima capa oculta de DINOv2-small, SIN "
                "normalizacion L2. La app normaliza al comparar."
            ),
        }
    else:
        output = {
            "names": ["logits", "pred_boxes"],
            "logits": {
                "name": "logits",
                "shape": ["batch", 300, 80],
                "dtype": "float32",
                "description": "Logits por query y clase COCO-80. Aplicar sigmoid.",
            },
            "pred_boxes": {
                "name": "pred_boxes",
                "shape": ["batch", 300, 4],
                "dtype": "float32",
                "description": (
                    "Cajas cx,cy,w,h normalizadas a [0,1] respecto a la entrada 640x640. "
                    "No requieren sigmoid."
                ),
            },
            "classes": COCO_CLASSES,
        }

    manifest: dict[str, Any] = {
        "schema": "smartpos.model_manifest.v1",
        "name": model["name"],
        "role": model["role"],
        "model_version": model["model_version"],
        "variant": chosen_variant,
        "file": artifact.name,
        "sha256": digest,
        "bytes": artifact.stat().st_size,
        "input": {
            "name": "pixel_values",
            "shape": ["batch", 3, model["input_shape"][2], model["input_shape"][3]],
            "dtype": "float32",
            "preprocessing": _preprocessing(model["key"]),
        },
        "output": output,
        "license": model["license"],
        "license_url": f"https://huggingface.co/{model['repo_id']}/blob/{model['revision']}/README.md",
        "source_url": model["source_url"],
        "source_commit": model["revision"],
        "source_gated": model["gated"],
        "onnx": {
            "opset": io_summary["opset"],
            "ir_version": io_summary["ir_version"],
            "dynamic_batch": True,
        },
        "validated": validated,
        "variants": variants,
        "created_at": utc_now(),
        "generated_by": "tools/ml/prepare_models.py",
    }
    if model["key"] == "embeddings":
        manifest["embedding_dim"] = 384
        manifest["normalized_l2"] = False
    return manifest


def write_index() -> None:
    entries = []
    gallery_version = None
    for key in ("embeddings", "detector"):
        model = MODELS[key]
        manifest_path = PUBLIC_MODELS / model["manifest_file"]
        if not manifest_path.exists():
            _log(f"AVISO: falta {manifest_path.name}; index.json se genera incompleto")
            continue
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        entries.append(
            {
                "role": model["role"],
                "name": manifest["name"],
                "model_version": manifest["model_version"],
                "manifest": model["manifest_file"],
                "file": manifest["file"],
                "sha256": manifest["sha256"],
                "bytes": manifest["bytes"],
                "variant": manifest["variant"],
            }
        )
        if key == "embeddings":
            gallery_version = manifest["model_version"]

    if not entries:
        _log("index.json no se escribe: no hay manifiestos")
        return

    index = {
        "schema": "smartpos.models_index.v1",
        "gallery_model_version": gallery_version or "unavailable",
        "models": entries,
        "generated_by": "tools/ml/prepare_models.py",
        "created_at": utc_now(),
    }
    write_json(PUBLIC_MODELS / "index.json", index)
    _log(f"index.json escrito ({len(entries)} modelos, gallery_model_version={index['gallery_model_version']})")


# --------------------------------------------------------------------------------------
# Flujos por modelo
# --------------------------------------------------------------------------------------

def process_embeddings(samples, runs: int, force: bool, do_quant: bool) -> dict[str, Any]:
    model = MODELS["embeddings"]
    src = download_source(model, force=force)
    fp32_path = BUILD_DIR / "dinov2-small-fp32.onnx"
    wrapper = load_embedding_model(src)
    if force or not fp32_path.exists():
        export_onnx("embeddings", wrapper, fp32_path, model["input_shape"])
    else:
        _log(f"Reutilizando {rel(fp32_path)} (usa --force para reexportar)")

    processor, _ = build_processors(src, SRC_DIR / MODELS["detector"]["prefix"])
    session = make_session(fp32_path)
    validated = validate_embeddings(wrapper, session, processor, samples)
    _log(
        "DINOv2 PT vs ORT: coseno medio={cosine_mean} min={cosine_min} p5={cosine_p5}".format(**validated)
    )
    if not validated["passed"]:
        _die(f"La validación fp32 de embeddings no cumple el umbral: {validated}")

    variants: dict[str, Any] = {}
    sample_pixels = preprocess(processor, samples[0])
    variants["fp32"] = {
        "file": fp32_path.name,
        "bytes": fp32_path.stat().st_size,
        "latency_ms": measure_latency(session, sample_pixels, warmup=3, runs=runs),
        "quality": {key: validated[key] for key in ("cosine_mean", "cosine_min", "cosine_p5")},
        "adopted": False,
    }

    chosen = "fp32"
    chosen_path = fp32_path
    int8_path = BUILD_DIR / "dinov2-small-int8.onnx"
    if do_quant:
        if force or not int8_path.exists():
            quantize_int8(fp32_path, int8_path)
        int8_session = make_session(int8_path)
        int8_metrics = validate_embeddings(wrapper, int8_session, processor, samples)
        # validate_embeddings compara contra PyTorch; aquí la referencia util del INT8.
        cos_mean = int8_metrics["cosine_mean"]
        cos_p5 = int8_metrics["cosine_p5"]
        variants["int8"] = {
            "file": int8_path.name,
            "bytes": int8_path.stat().st_size,
            "latency_ms": measure_latency(int8_session, sample_pixels, warmup=3, runs=runs),
            "quality": {
                "cosine_mean": cos_mean,
                "cosine_min": int8_metrics["cosine_min"],
                "cosine_p5": cos_p5,
            },
            "adopted": False,
        }
        _log(f"DINOv2 INT8 vs PT: coseno medio={cos_mean} p5={cos_p5}")
        if cos_mean >= THRESHOLDS["embeddings"]["cosine_mean"] and cos_p5 >= THRESHOLDS["embeddings"]["cosine_p5"]:
            chosen, chosen_path = "int8", int8_path
        else:
            variants["int8"]["decision"] = (
                f"rechazada: coseno medio {cos_mean} < 0.99 o p5 {cos_p5} < 0.98"
            )
            fp16_path = BUILD_DIR / "dinov2-small-fp16.onnx"
            try:
                if force or not fp16_path.exists():
                    convert_fp16(fp32_path, fp16_path)
                fp16_session = make_session(fp16_path)
                fp16_metrics = validate_embeddings(wrapper, fp16_session, processor, samples)
                fp16_latency = measure_latency(
                    fp16_session, sample_pixels, warmup=2, runs=max(5, runs // 2)
                )
                variants["fp16"] = {
                    "file": fp16_path.name,
                    "bytes": fp16_path.stat().st_size,
                    "latency_ms": fp16_latency,
                    "quality": {
                        "cosine_mean": fp16_metrics["cosine_mean"],
                        "cosine_min": fp16_metrics["cosine_min"],
                        "cosine_p5": fp16_metrics["cosine_p5"],
                    },
                    "adopted": False,
                }
                quality_ok = (
                    fp16_metrics["cosine_mean"] >= THRESHOLDS["embeddings"]["cosine_mean"]
                    and fp16_metrics["cosine_p5"] >= THRESHOLDS["embeddings"]["cosine_p5"]
                )
                # Solo se adopta fp16 si además aporta una ventaja clara en CPU (>=15%).
                # onnxruntime-web/WASM no tiene soporte fiable de fp16 y el contrato se sirve al navegador.
                latency_ok = fp16_latency["mean"] <= variants["fp32"]["latency_ms"]["mean"] * 0.85
                if quality_ok and latency_ok:
                    chosen, chosen_path = "fp16", fp16_path
                else:
                    variants["fp16"]["decision"] = (
                        "rechazada: "
                        + ("calidad OK" if quality_ok else "calidad insuficiente")
                        + f"; latencia media {fp16_latency['mean']} ms vs fp32 "
                        f"{variants['fp32']['latency_ms']['mean']} ms (sin ventaja >=15%)"
                        "; fp16 no es portable a onnxruntime-web/WASM"
                    )
            except Exception as exc:
                _log(f"fp16 no viable para embeddings: {type(exc).__name__}: {exc}")
                variants["fp16"] = {"error": f"{type(exc).__name__}: {exc}", "adopted": False}

    variants[chosen]["adopted"] = True
    validated["chosen_variant"] = chosen
    validated["chosen_quality"] = variants[chosen]["quality"]
    artifact, digest, size = publish_artifact(chosen_path, model["prefix"])
    io_summary = onnx_io_summary(artifact)
    manifest = build_manifest(model, artifact, digest, io_summary, chosen, variants, validated)
    write_json(PUBLIC_MODELS / model["manifest_file"], manifest)
    _log(f"Manifiesto: public/models/{model['manifest_file']} (variante {chosen}, sha256 {digest[:16]}…)")
    return manifest


def process_detector(samples, runs: int, force: bool, do_quant: bool) -> dict[str, Any]:
    model = MODELS["detector"]
    src = download_source(model, force=force)
    fp32_path = BUILD_DIR / "rtdetr-r18vd-fp32.onnx"
    wrapper = load_detector_model(src)
    if force or not fp32_path.exists():
        export_onnx("detector", wrapper, fp32_path, model["input_shape"])
    else:
        _log(f"Reutilizando {rel(fp32_path)} (usa --force para reexportar)")

    _, processor = build_processors(SRC_DIR / MODELS["embeddings"]["prefix"], src)
    session = make_session(fp32_path)
    validated = validate_detector(wrapper, session, processor, samples)
    _log(
        "RT-DETR PT vs ORT: IoU medio={iou_mean} min={iou_min} max|score|={max_score_diff} "
        "discrepancias={discrepancies_total}".format(**validated)
    )
    if not validated["passed"]:
        _die(f"La validación fp32 del detector no cumple el umbral: {validated}")

    variants: dict[str, Any] = {}
    sample_pixels = preprocess(processor, samples[0])
    variants["fp32"] = {
        "file": fp32_path.name,
        "bytes": fp32_path.stat().st_size,
        "latency_ms": measure_latency(session, sample_pixels, warmup=2, runs=max(5, runs // 2)),
        "quality": {
            "iou_mean": validated["iou_mean"],
            "iou_min": validated["iou_min"],
            "max_score_diff": validated["max_score_diff"],
        },
        "adopted": False,
    }

    chosen = "fp32"
    chosen_path = fp32_path
    int8_path = BUILD_DIR / "rtdetr-r18vd-int8.onnx"
    if do_quant:
        if force or not int8_path.exists():
            quantize_int8(fp32_path, int8_path)
        int8_session = make_session(int8_path)
        int8_quality = compare_variants(wrapper, int8_session, processor, samples, "detector")
        variants["int8"] = {
            "file": int8_path.name,
            "bytes": int8_path.stat().st_size,
            "latency_ms": measure_latency(int8_session, sample_pixels, warmup=2, runs=max(5, runs // 2)),
            "quality": {
                "iou_mean": int8_quality["iou_mean"],
                "iou_min": int8_quality["iou_min"],
                "score_drop_mean": int8_quality["score_drop_mean"],
                "high_confidence": int8_quality["high_confidence"],
            },
            "adopted": False,
        }
        _log(
            f"RT-DETR INT8 vs FP32: IoU medio={int8_quality['iou_mean']} "
            f"caída score media={int8_quality['score_drop_mean']}"
        )
        if int8_quality["iou_mean"] >= THRESHOLDS["detector"]["iou_mean"]:
            chosen, chosen_path = "int8", int8_path
        else:
            variants["int8"]["decision"] = (
                f"rechazada: IoU medio top-100 {int8_quality['iou_mean']} < 0.98 "
                f"(score>=0.3: {int8_quality['high_confidence']['iou_mean']})"
            )
            fp16_path = BUILD_DIR / "rtdetr-r18vd-fp16.onnx"
            try:
                if force or not fp16_path.exists():
                    convert_fp16(fp32_path, fp16_path)
                fp16_session = make_session(fp16_path)
                fp16_quality = compare_variants(wrapper, fp16_session, processor, samples, "detector")
                fp16_latency = measure_latency(
                    fp16_session, sample_pixels, warmup=2, runs=max(5, runs // 2)
                )
                variants["fp16"] = {
                    "file": fp16_path.name,
                    "bytes": fp16_path.stat().st_size,
                    "latency_ms": fp16_latency,
                    "quality": {
                        "iou_mean": fp16_quality["iou_mean"],
                        "iou_min": fp16_quality["iou_min"],
                        "score_drop_mean": fp16_quality["score_drop_mean"],
                        "high_confidence": fp16_quality["high_confidence"],
                    },
                    "adopted": False,
                }
                if fp16_quality["iou_mean"] >= THRESHOLDS["detector"]["iou_mean"]:
                    chosen, chosen_path = "fp16", fp16_path
                else:
                    variants["fp16"]["decision"] = (
                        f"rechazada: IoU medio top-100 {fp16_quality['iou_mean']} < 0.98; "
                        f"en score>=0.3 IoU {fp16_quality['high_confidence']['iou_mean']}, "
                        f"pero sin ventaja de latencia en CPU ({fp16_latency['mean']} ms vs "
                        f"{variants['fp32']['latency_ms']['mean']} ms) y fp16 no es portable a WASM"
                    )
            except Exception as exc:
                _log(f"fp16 no viable para detector: {type(exc).__name__}: {exc}")
                variants["fp16"] = {"error": f"{type(exc).__name__}: {exc}", "adopted": False}

    variants[chosen]["adopted"] = True
    validated["chosen_variant"] = chosen
    validated["chosen_quality"] = variants[chosen]["quality"]
    artifact, digest, size = publish_artifact(chosen_path, model["prefix"])
    io_summary = onnx_io_summary(artifact)
    manifest = build_manifest(model, artifact, digest, io_summary, chosen, variants, validated)
    write_json(PUBLIC_MODELS / model["manifest_file"], manifest)
    _log(f"Manifiesto: public/models/{model['manifest_file']} (variante {chosen}, sha256 {digest[:16]}…)")
    return manifest


def compare_variants(pt_wrapper, session, processor, samples, kind: str) -> dict[str, Any]:
    """Calidad de una variante frente a PyTorch (referencia), con la métrica del rol."""
    if kind == "embeddings":
        metrics = validate_embeddings(pt_wrapper, session, processor, samples)
        return metrics

    reference: list[list[np.ndarray]] = []
    candidate: list[list[np.ndarray]] = []
    for sample in samples:
        pixel_values = preprocess(processor, sample)
        reference.append(torch_run(pt_wrapper, pixel_values))
        candidate.append(ort_run(session, pixel_values))
    return _detector_quality(reference, candidate, top_k=100)


# --------------------------------------------------------------------------------------
# Verificación final
# --------------------------------------------------------------------------------------

def verify_published(expect_models: list[str] | None = None) -> bool:
    import numpy as np
    import onnxruntime as ort

    _log(f"onnxruntime {ort.__version__} · providers={ort.get_available_providers()}")
    index_path = PUBLIC_MODELS / "index.json"
    if not index_path.exists():
        _die(f"No existe {rel(index_path)}; ejecuta primero sin --verify-only.")
    index = json.loads(index_path.read_text(encoding="utf-8"))
    ok = True
    for entry in index["models"]:
        if expect_models and entry["role"] not in expect_models:
            continue
        manifest_path = PUBLIC_MODELS / entry["manifest"]
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        artifact = PUBLIC_MODELS / manifest["file"]
        if not artifact.exists():
            _log(f"FALLO: falta {artifact}")
            ok = False
            continue
        digest = sha256_file(artifact)
        hash_ok = digest == manifest["sha256"]
        bytes_ok = artifact.stat().st_size == manifest["bytes"]
        session = ort.InferenceSession(str(artifact), providers=["CPUExecutionProvider"])
        input_meta = session.get_inputs()[0]
        shape = [dim if isinstance(dim, int) else 1 for dim in input_meta.shape]
        expected_input = [1] + [int(dim) for dim in manifest["input"]["shape"][1:]]
        input_ok = shape == expected_input
        output_names = [item.name for item in session.get_outputs()]
        if manifest["role"] == "embeddings":
            expected_names = ["embedding"]
        else:
            expected_names = ["logits", "pred_boxes"]
        names_ok = output_names == expected_names
        if manifest["role"] == "embeddings":
            smoke = session.run(None, {input_meta.name: np.zeros(shape, dtype=np.float32)})
            shapes = [(1, 384)]
            actual = [tuple(item.shape) for item in smoke]
        else:
            smoke = session.run(None, {input_meta.name: np.zeros(shape, dtype=np.float32)})
            shapes = [(1, 300, 80), (1, 300, 4)]
            actual = [tuple(item.shape) for item in smoke]
        shapes_ok = all(expected == got for expected, got in zip(shapes, actual))
        _log(
            f"{manifest['name']}: sha256={'OK' if hash_ok else 'MISMATCH'} "
            f"bytes={'OK' if bytes_ok else 'MISMATCH'} input={input_meta.name}{shape} "
            f"{'OK' if input_ok else 'MISMATCH'} salidas={output_names} {actual} "
            f"{'OK' if shapes_ok and names_ok else 'FALLO'}"
        )
        ok = ok and hash_ok and bytes_ok and shapes_ok and input_ok and names_ok
    return ok


# --------------------------------------------------------------------------------------
# main
# --------------------------------------------------------------------------------------

def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="prepare_models.py",
        description=(
            "Descarga, exporta, valida y cuantiza los modelos ONNX del Modo Caja Inteligente, "
            "y publica los artefactos elegidos en public/models/."
        ),
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "Ejemplos:\n"
            "  python tools/ml/prepare_models.py\n"
            "  python tools/ml/prepare_models.py --models detector --force\n"
            "  python tools/ml/prepare_models.py --verify-only\n"
            "  python tools/ml/prepare_models.py --no-venv --no-quant\n\n"
            "Salidas:\n"
            "  public/models/<modelo>-<sha256_corto>.onnx\n"
            "  public/models/dinov2-small.manifest.json\n"
            "  public/models/rtdetr-r18vd.manifest.json\n"
            "  public/models/index.json\n"
        ),
    )
    parser.add_argument("--models", choices=["all", "embeddings", "detector"], default="all")
    parser.add_argument("--force", action="store_true", help="Reexporta y recuantiza aunque existan artefactos.")
    parser.add_argument("--no-quant", action="store_true", help="Solo fp32 (no genera INT8/fp16).")
    parser.add_argument("--no-venv", action="store_true", help="No crea/re-ejecuta el venv; usa el entorno activo.")
    parser.add_argument("--verify-only", action="store_true", help="Solo verifica lo publicado (hash, carga y shapes).")
    parser.add_argument("--runs", type=int, default=15, help="Iteraciones para medir latencia (default 15).")
    parser.add_argument("--samples", type=int, default=8, help="Imágenes de validación (default 8, mínimo 5).")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
    args = parse_args(argv)
    ensure_venv(args.no_venv)

    PUBLIC_MODELS.mkdir(parents=True, exist_ok=True)
    BUILD_DIR.mkdir(parents=True, exist_ok=True)

    if args.verify_only:
        return 0 if verify_published() else 1

    samples = collect_samples(limit=max(5, args.samples))
    _log(f"Imágenes de validación ({len(samples)}): " + ", ".join(rel(path) for path in samples))

    should_run = {
        "embeddings": args.models in {"all", "embeddings"},
        "detector": args.models in {"all", "detector"},
    }
    if should_run["embeddings"]:
        _log("=== DINOv2-small: embeddings ===")
        process_embeddings(samples, args.runs, args.force, not args.no_quant)
    if should_run["detector"]:
        _log("=== RT-DETR-R18: detector ===")
        process_detector(samples, args.runs, args.force, not args.no_quant)

    write_index()
    expected = [role for role, enabled in should_run.items() if enabled]
    ok = verify_published(expect_models=expected)
    if not ok:
        _die("La verificación final falló.")
    _log("LISTO. Artefactos y manifiestos en public/models/.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
