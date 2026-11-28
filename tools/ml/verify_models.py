#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Verificación de los ONNX publicados en public/models/.

Comprueba, para cada modelo del índice:
  1. que el archivo existe y su SHA-256 y tamaño coinciden con el manifiesto;
  2. que onnxruntime.InferenceSession lo carga con CPUExecutionProvider;
  3. que la entrada y las salidas tienen los nombres, shapes y dtype documentados;
  4. que una inferencia de humo (batch 1, ceros) devuelve las shapes del contrato.

Uso:
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\verify_models.py
    .\\tools\\ml\\.venv\\Scripts\\python.exe tools\\ml\\verify_models.py --models detector
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path

ML_DIR = Path(__file__).resolve().parent
REPO_ROOT = ML_DIR.parents[1]
PUBLIC_MODELS = REPO_ROOT / "public" / "models"

EXPECTED = {
    "embeddings": {
        "input": ("pixel_values", [1, 3, 224, 224]),
        "outputs": [("embedding", [1, 384])],
        "embedding_dim": 384,
    },
    "detector": {
        "input": ("pixel_values", [1, 3, 640, 640]),
        "outputs": [("logits", [1, 300, 80]), ("pred_boxes", [1, 300, 4])],
    },
}


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def projected_shape(shape) -> list[int]:
    return [dim if isinstance(dim, int) else 1 for dim in shape]


def verify_model(entry: dict, failures: list[str]) -> None:
    import numpy as np
    import onnxruntime as ort

    role = entry["role"]
    expected = EXPECTED[role]
    label = f"{entry['name']} [{role}]"
    manifest_path = PUBLIC_MODELS / entry["manifest"]
    print(f"\n=== {label} ===")
    print(f"  manifiesto : {manifest_path}")

    if not manifest_path.exists():
        failures.append(f"{label}: falta {manifest_path}")
        return
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    artifact = PUBLIC_MODELS / manifest["file"]
    print(f"  archivo    : {artifact}")
    if not artifact.exists():
        failures.append(f"{label}: falta {artifact.name}")
        return

    actual_sha = sha256_file(artifact)
    actual_bytes = artifact.stat().st_size
    sha_ok = actual_sha == manifest["sha256"]
    bytes_ok = actual_bytes == manifest["bytes"]
    print(f"  sha256     : {actual_sha} {'OK' if sha_ok else 'MISMATCH'}")
    print(f"  bytes      : {actual_bytes} {'OK' if bytes_ok else 'MISMATCH'}")
    if not sha_ok:
        failures.append(f"{label}: sha256 {actual_sha} != manifiesto {manifest['sha256']}")
    if not bytes_ok:
        failures.append(f"{label}: bytes {actual_bytes} != manifiesto {manifest['bytes']}")

    session = ort.InferenceSession(str(artifact), providers=["CPUExecutionProvider"])
    providers = session.get_providers()
    input_meta = session.get_inputs()[0]
    input_shape = projected_shape(input_meta.shape)
    expected_input_name, expected_input_shape = expected["input"]
    input_ok = input_meta.name == expected_input_name and input_shape == expected_input_shape
    print(f"  proveedor  : {providers}")
    print(
        f"  entrada    : {input_meta.name} {input_shape} {input_meta.type} "
        f"{'OK' if input_ok else 'MISMATCH'}"
    )
    if not input_ok:
        failures.append(
            f"{label}: entrada {input_meta.name}{input_shape} != {expected_input_name}{expected_input_shape}"
        )

    outputs_meta = session.get_outputs()
    names = [item.name for item in outputs_meta]
    expected_names = [name for name, _ in expected["outputs"]]
    names_ok = names == expected_names
    print(f"  salidas    : {names} {'OK' if names_ok else 'MISMATCH'}")
    if not names_ok:
        failures.append(f"{label}: salidas {names} != {expected_names}")

    smoke = session.run(None, {input_meta.name: np.zeros(input_shape, dtype=np.float32)})
    actual_shapes = [list(item.shape) for item in smoke]
    expected_shapes = [shape for _, shape in expected["outputs"]]
    shapes_ok = actual_shapes == expected_shapes
    dtypes_ok = all("float" in item.type for item in outputs_meta)
    print(f"  humo       : shapes {actual_shapes} {'OK' if shapes_ok else 'MISMATCH'}")
    print(f"  dtype      : {[item.type for item in outputs_meta]} {'OK' if dtypes_ok else 'MISMATCH'}")
    if not shapes_ok:
        failures.append(f"{label}: shapes {actual_shapes} != {expected_shapes}")
    if not dtypes_ok:
        failures.append(f"{label}: las salidas no son float32")

    if "embedding_dim" in expected:
        dim_ok = manifest.get("embedding_dim") == expected["embedding_dim"]
        print(f"  embedding  : {manifest.get('embedding_dim')} {'OK' if dim_ok else 'MISMATCH'}")
        if not dim_ok:
            failures.append(f"{label}: embedding_dim distinto de {expected['embedding_dim']}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Verifica los ONNX publicados y sus manifiestos.")
    parser.add_argument("--models", choices=["all", "embeddings", "detector"], default="all")
    args = parser.parse_args(argv)

    import onnxruntime as ort

    print(f"onnxruntime {ort.__version__}")
    print(f"providers disponibles: {ort.get_available_providers()}")
    index_path = PUBLIC_MODELS / "index.json"
    if not index_path.exists():
        print(f"FALLO: no existe {index_path}", file=sys.stderr)
        return 1
    index = json.loads(index_path.read_text(encoding="utf-8"))
    print(f"gallery_model_version: {index.get('gallery_model_version')}")
    print(f"index.json: {index_path}")

    failures: list[str] = []
    selected = [entry for entry in index["models"] if args.models == "all" or entry["role"] == args.models]
    if len(selected) < (1 if args.models != "all" else 2):
        failures.append(f"index.json no contiene los modelos esperados: {[e['role'] for e in index['models']]}")
    for entry in selected:
        verify_model(entry, failures)

    print("\n" + "=" * 60)
    if failures:
        print(f"RESULTADO: FALLO ({len(failures)} problema(s))")
        for failure in failures:
            print(f" - {failure}")
        return 1
    print(f"RESULTADO: OK — {len(selected)} modelo(s) verificados")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
