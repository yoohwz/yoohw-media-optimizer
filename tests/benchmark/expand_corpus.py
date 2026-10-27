#!/usr/bin/env python3
"""Import local, hash-pinned images into a new private offline benchmark corpus."""

import argparse
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import shutil

from PIL import Image

from corpus import fixed_header
from run import SRGB, color_managed, digest, read_manifest


def checked_hash(path, expected, label):
    if not isinstance(expected, str) or not re.fullmatch(r"[0-9a-f]{64}", expected):
        raise ValueError("invalid sha256: " + label)
    if digest(path) != expected:
        raise ValueError("hash mismatch: " + label)


def image_details(path):
    with Image.open(path) as image:
        image.load()
        icc = image.info.get("icc_profile")
        return dict(width=image.width, height=image.height, mode=image.mode,
                    orientation=image.getexif().get(274, 1),
                    icc_sha256=hashlib.sha256(icc).hexdigest() if icc else None,
                    icc_profile="embedded" if icc else None)


def expand(base_manifest, sources_spec, output):
    base_manifest, sources_spec = Path(base_manifest).resolve(strict=True), Path(sources_spec).resolve(strict=True)
    output = Path(output).resolve()
    base_hash, spec_hash = digest(base_manifest), digest(sources_spec)
    base_data = json.loads(base_manifest.read_text())
    base_rows = read_manifest(base_manifest)
    spec = json.loads(sources_spec.read_text())
    if not isinstance(spec, dict) or not isinstance(spec.get("images"), list) or not spec["images"]:
        raise ValueError("sources spec must contain a nonempty images list")
    watched = [(base_manifest, base_hash), (sources_spec, spec_hash)]
    base_references, imports = {}, []
    ids = {row["id"] for row in base_rows}
    for row in base_rows:
        watched.append((Path(row["source"]), row["sha256"]))
        if row.get("reference_path"):
            relative = Path(row["reference_path"])
            if relative.is_absolute():
                raise ValueError("reference path must be relative")
            reference = (base_manifest.parent / relative).resolve(strict=True)
            if not reference.is_relative_to(base_manifest.parent) or not reference.is_file():
                raise ValueError("reference escapes base corpus")
            checked_hash(reference, row.get("reference_sha256"), row["id"] + " reference")
            base_references[row["id"]] = reference
            watched.append((reference, row["reference_sha256"]))
    for entry in spec["images"]:
        if not isinstance(entry, dict):
            raise ValueError("source entry must be an object")
        for field in ("id", "path", "sha256", "group", "independent_master_id", "provenance"):
            if not entry.get(field):
                raise ValueError("missing source field: " + field)
        ident = entry["id"]
        if not isinstance(ident, str) or not re.fullmatch(r"[A-Za-z0-9_-]+", ident):
            raise ValueError("invalid image id")
        if ident in ids:
            raise ValueError("duplicate image id: " + ident)
        ids.add(ident)
        if not isinstance(entry["group"], str) or not isinstance(entry["independent_master_id"], str):
            raise ValueError("group and independent_master_id must be text")
        if not isinstance(entry["provenance"], (str, dict)):
            raise ValueError("provenance must be text or an object")
        if "color_policy" in entry and not isinstance(entry["color_policy"], str):
            raise ValueError("color_policy must be text")
        max_edge = entry.get("max_edge", 0)
        if type(max_edge) is not int or max_edge not in (0, 1600):
            raise ValueError("max_edge must be 0 (original) or 1600")
        if not isinstance(entry["path"], str):
            raise ValueError("source path must be text")
        source = (sources_spec.parent / entry["path"]).resolve(strict=True)
        if not source.is_file():
            raise ValueError("source is not a file")
        checked_hash(source, entry["sha256"], ident)
        watched.append((source, entry["sha256"]))
        imports.append((entry, source, max_edge))
    if output.is_relative_to(base_manifest.parent) or any(path.is_relative_to(output) for path, _ in watched):
        raise ValueError("output directory overlaps inputs")
    for path, expected in watched:
        checked_hash(path, expected, path.name)
    output.mkdir(parents=True, mode=0o700, exist_ok=False)
    output.chmod(0o700)
    (output / "images").mkdir()
    (output / "references").mkdir()
    rows = []
    srgb = fixed_header(SRGB.tobytes())
    try:
        for entry in base_rows:
            row = {key: value for key, value in entry.items() if key != "source"}
            destination = output / "images" / (row["id"] + Path(entry["source"]).suffix.lower())
            shutil.copyfile(entry["source"], destination)
            checked_hash(destination, row["sha256"], row["id"] + " copy")
            row["path"] = destination.relative_to(output).as_posix()
            if row["id"] in base_references:
                reference = output / "references" / (row["id"] + ".png")
                shutil.copyfile(base_references[row["id"]], reference)
                checked_hash(reference, row["reference_sha256"], row["id"] + " reference copy")
                row["reference_path"] = reference.relative_to(output).as_posix()
            rows.append(row)
        for entry, source, max_edge in imports:
            with Image.open(source) as image:
                reference_image = color_managed(image, assume_srgb=entry.get("color_policy") == "assume_srgb")
            suffix = ".png" if max_edge else source.suffix.lower()
            if not re.fullmatch(r"\.[a-z0-9]{1,10}", suffix):
                raise ValueError("source must have an image extension")
            destination = output / "images" / (entry["id"] + suffix)
            if max_edge:
                reference_image.thumbnail((max_edge, max_edge), Image.Resampling.LANCZOS)
                reference_image.save(destination, format="PNG", icc_profile=srgb)
                # References always decode the saved input, including derivative cases.
                with Image.open(destination) as image:
                    reference_image = color_managed(image)
            else:
                shutil.copyfile(source, destination)
                checked_hash(destination, entry["sha256"], entry["id"] + " copy")
            reference = output / "references" / (entry["id"] + ".png")
            reference_image.save(reference, format="PNG", icc_profile=srgb)
            row = {key: entry[key] for key in ("id", "group", "independent_master_id", "provenance")}
            row.update(image_details(destination))
            row.update(path=destination.relative_to(output).as_posix(), sha256=digest(destination),
                       reference_path=reference.relative_to(output).as_posix(), reference_sha256=digest(reference),
                       import_operation="exif_icc_to_srgb_png_resize" if max_edge else "byte_identical_copy",
                       source_sha256=entry["sha256"], max_edge=max_edge)
            if entry.get("color_policy"):
                row["color_policy"] = entry["color_policy"]
            rows.append(row)
    finally:
        # A failed decode/copy still checks every original; never clean partial output.
        for path, expected in watched:
            checked_hash(path, expected, path.name + " after import")
    result = dict(base_data, corpus="expanded-offline", images=rows,
                  base_manifest_sha256=base_hash, sources_spec_sha256=spec_hash,
                  input_hashes_unchanged=True, counts=dict(Counter(row["group"] for row in rows)))
    result["counts"]["independent_masters"] = len({row.get("independent_master_id", row["id"]) for row in rows})
    (output / "manifest.json").write_text(json.dumps(result, indent=2) + "\n")
    return output / "manifest.json"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-manifest", required=True, type=Path)
    parser.add_argument("--sources", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    print(expand(args.base_manifest, args.sources, args.output))


if __name__ == "__main__":
    main()
