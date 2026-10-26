#!/usr/bin/env python3
"""Bounded, offline codec experiment. No WordPress or plugin runtime mutation.

Requires Pillow with ImageCms/WebP/AVIF, cwebp, avifenc and ssimulacra2.
Run: python3 tests/benchmark/run.py MANIFEST.json NEW_OUTPUT_DIRECTORY
Results are experimental comparisons, not universal quality thresholds.
"""

import argparse
import csv
import hashlib
import html
import io
import json
import math
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import time

from PIL import Image, ImageChops, ImageCms, ImageOps, __version__ as pillow_version


SRGB = ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB"))
PROFILES = (
    "webp_baseline82", "webp_sharp82_srgb", "webp_sharp82_keep_icc",
    "webp_lossless_srgb", "avif_baseline82", "avif_444_82_srgb",
    "avif_420_82_srgb", "avif_444_70_srgb",
)
OPTIONAL_PROFILES = ("webp_icc82",)


def digest(path):
    with open(path, "rb") as handle:
        return hashlib.file_digest(handle, "sha256").hexdigest()


def read_manifest(path):
    data = json.loads(path.read_text())
    if not isinstance(data, dict) or not isinstance(data.get("images"), list) or not data["images"]:
        raise ValueError("manifest must contain a nonempty images list")
    seen = set()
    images = []
    for entry in data["images"]:
        if not isinstance(entry, dict):
            raise ValueError("image entry must be an object")
        for key in ("id", "path", "group", "provenance", "sha256"):
            if not entry.get(key):
                raise ValueError("missing manifest field: " + key)
        if not isinstance(entry["group"], str) or not isinstance(entry["provenance"], (str, dict)):
            raise ValueError("group must be text and provenance must be text or an object")
        if "expected_failure" in entry and not isinstance(entry["expected_failure"], (bool, str)):
            raise ValueError("expected_failure must be boolean or an error substring")
        if "color_policy" in entry and not isinstance(entry["color_policy"], str):
            raise ValueError("color_policy must be text")
        if not isinstance(entry["id"], str) or not re.fullmatch(r"[A-Za-z0-9_-]+", entry["id"]):
            raise ValueError("invalid image id")
        if entry["id"] in seen:
            raise ValueError("duplicate image id")
        seen.add(entry["id"])
        if not isinstance(entry["path"], str) or Path(entry["path"]).is_absolute():
            raise ValueError("source path must be relative to manifest")
        source = (path.parent / entry["path"]).resolve(strict=True)
        if not source.is_relative_to(path.parent.resolve()) or not source.is_file():
            raise ValueError("source escapes manifest directory or is not a file")
        if not isinstance(entry["sha256"], str) or not re.fullmatch(r"[0-9a-f]{64}", entry["sha256"]):
            raise ValueError("invalid source sha256")
        if digest(source) != entry["sha256"]:
            raise ValueError("source hash mismatch: " + entry["id"])
        images.append(dict(entry, source=str(source)))
    return images


def run_process(args, timeout):
    """wait4 measures this child (including its workers), never a cumulative maximum.

    Darwin ru_maxrss is bytes; Linux reports KiB. Logs use temporary files to
    avoid pipe deadlocks and only the last 16 KiB are retained in the report.
    """
    started = time.perf_counter()
    with tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
        child = subprocess.Popen([str(x) for x in args], stdout=stdout, stderr=stderr, start_new_session=True)
        timed_out = False
        while True:
            pid, status, usage = os.wait4(child.pid, os.WNOHANG)
            if pid:
                break
            if time.perf_counter() - started > timeout:
                timed_out = True
                os.killpg(child.pid, signal.SIGKILL)
                _, status, usage = os.wait4(child.pid, 0)
                break
            time.sleep(0.005)
        child.returncode = os.waitstatus_to_exitcode(status)
        output = {}
        for key, handle in (("stdout", stdout), ("stderr", stderr)):
            handle.seek(0, os.SEEK_END)
            handle.seek(max(0, handle.tell() - 16384))
            output[key] = handle.read().decode("utf-8", "replace")
    return dict(output, args=[str(x) for x in args], returncode=child.returncode,
                timed_out=timed_out, wall_seconds=time.perf_counter() - started,
                peak_rss_bytes=int(usage.ru_maxrss * (1 if sys.platform == "darwin" else 1024)))


def color_managed(image, assume_srgb=False):
    """Orient, interpret ICC, then transform pixels; do not relabel unknown CMYK."""
    image.load()
    image = ImageOps.exif_transpose(image)
    alpha = image.convert("RGBA").getchannel("A") if "A" in image.getbands() or "transparency" in image.info else None
    icc = image.info.get("icc_profile")
    if icc:
        try:
            source_profile = ImageCms.ImageCmsProfile(io.BytesIO(icc))
            source = image if image.mode in ("RGB", "CMYK", "LAB") else image.convert("RGB")
            result = ImageCms.profileToProfile(source, source_profile, SRGB, outputMode="RGB")
        except (OSError, ValueError, ImageCms.PyCMSError) as error:
            raise ValueError("invalid ICC or incompatible profile: " + str(error)) from error
    else:
        if image.mode == "CMYK":
            raise ValueError("unprofiled CMYK: no trustworthy color interpretation")
        if not assume_srgb:
            raise ValueError("untagged RGB requires explicit assume_srgb policy")
        result = image.convert("RGB")
    if alpha is not None:
        result.putalpha(alpha)
    result.info["icc_profile"] = SRGB.tobytes()
    return result


def worker_decode(source, target):
    # The output of an encoder without ICC is explicitly interpreted as sRGB.
    # This lets the score expose source-profile removal rather than hiding it.
    with Image.open(source) as image:
        result = color_managed(image, assume_srgb=True)
    # Even the internal worker refuses existing destinations and source aliases.
    with open(target, "xb") as handle:
        result.save(handle, format="PNG", icc_profile=SRGB.tobytes())


def alpha_error(reference, candidate):
    left = reference.convert("RGBA").getchannel("A")
    right = candidate.convert("RGBA").getchannel("A")
    if left.size != right.size:
        raise ValueError("dimension mismatch")
    histogram = ImageChops.difference(left, right).histogram()
    total = left.width * left.height
    return {"alpha_max_error": max(i for i, count in enumerate(histogram) if count),
            "alpha_mean_error": sum(i * count for i, count in enumerate(histogram)) / total}


def composite(image, background):
    image = image.convert("RGBA")
    base = Image.new("RGBA", image.size, (background, background, background, 255))
    return Image.alpha_composite(base, image).convert("RGB")


def metric_score(metric, reference, candidate, directory, timeout):
    if reference.size != candidate.size:
        raise ValueError("dimension mismatch")
    if min(reference.size) < 8:
        raise ValueError("SSIMULACRA2 requires minimum 8x8 dimensions")
    scores, commands = [], []
    # Two explicit opaque composites avoid differing CLI alpha conventions.
    for background in (0, 255):
        left, right = directory / ("reference-%s.png" % background), directory / ("candidate-%s.png" % background)
        composite(reference, background).save(left, icc_profile=SRGB.tobytes())
        composite(candidate, background).save(right, icc_profile=SRGB.tobytes())
        process = run_process([metric, left, right], timeout)
        commands.append(process)
        if process["returncode"] or process["timed_out"]:
            raise ValueError("metric command failed: " + json.dumps(process))
        try:
            score = float(process["stdout"].strip())
        except ValueError as error:
            raise ValueError("invalid metric output: " + process["stdout"]) from error
        if not math.isfinite(score):
            raise ValueError("nonfinite metric score")
        scores.append(score)
    return {"ssimulacra2_black": scores[0], "ssimulacra2_white": scores[1],
            "ssimulacra2_worst": min(scores), "metric_processes": commands, **alpha_error(reference, candidate)}


def encode_args(profile, tools, source, normalized, preserved, target):
    if profile == "webp_baseline82":
        return [tools["cwebp"], "-quiet", "-q", "82", source, "-o", target]
    if profile == "avif_baseline82":
        return [tools["avifenc"], "-q", "82", source, target]
    if profile == "webp_icc82":
        return [tools["cwebp"], "-quiet", "-q", "82", "-metadata", "icc", source, "-o", target]
    if profile.startswith("webp_"):
        args = [tools["cwebp"], "-quiet", "-metadata", "icc"]
        if "lossless" in profile:
            args += ["-lossless", "-q", "100", "-m", "6"]
        else:
            args += ["-q", "82", "-m", "6", "-sharp_yuv"]
        return args + [preserved if "keep_icc" in profile else normalized, "-o", target]
    quality = "70" if "_70_" in profile else "82"
    yuv = "420" if "_420_" in profile else "444"
    return [tools["avifenc"], "-q", quality, "--speed", "6", "--jobs", "1", "--yuv", yuv, normalized, target]


def percentile(values, fraction):
    values = sorted(values)
    index = (len(values) - 1) * fraction
    lower = math.floor(index)
    return values[lower] + (values[math.ceil(index)] - values[lower]) * (index - lower)


def aggregate(rows):
    groups = {}
    for row in rows:
        if row["status"] != "ok":
            continue
        for group in (row["group"], "ALL"):
            groups.setdefault((group, row["profile"]), []).append(row)
    summaries = []
    for (group, profile), entries in sorted(groups.items()):
        result = {"group": group, "profile": profile, "count": len(entries)}
        for field in ("bytes", "source_bytes_ratio", "encode_wall_seconds", "encode_peak_rss_bytes", "decode_wall_seconds", "decode_peak_rss_bytes", "ssimulacra2_worst", "alpha_max_error"):
            values = [entry[field] for entry in entries if entry.get(field) is not None]
            if not values:
                result[field] = None
                continue
            result[field] = {"median": percentile(values, 0.5), "p95": percentile(values, 0.95), "min": min(values), "max": max(values)}
        result["worst_image"] = min(entries, key=lambda row: row["ssimulacra2_worst"])["id"]
        summaries.append(result)
    pairs = []
    lookup = {(row["id"], row["profile"]): row for row in rows if row["status"] == "ok"}
    for row in rows:
        if row["status"] != "ok" or "baseline" in row["profile"]:
            continue
        baseline = lookup.get((row["id"], row["profile"].split("_")[0] + "_baseline82"))
        if baseline:
            pairs.append({"id": row["id"], "group": row["group"], "profile": row["profile"],
                          "baseline": baseline["profile"], "bytes_ratio": row["bytes"] / baseline["bytes"],
                          "score_delta": row["ssimulacra2_worst"] - baseline["ssimulacra2_worst"],
                          "encode_time_ratio": row["encode_wall_seconds"] / baseline["encode_wall_seconds"] if row.get("encode_wall_seconds") is not None and baseline.get("encode_wall_seconds") else None,
                          "same_quality_number": "_82_" in row["profile"] or "sharp82" in row["profile"] or row["profile"] == "webp_icc82"})
    paired_summary = []
    for group, profile in sorted({(row["group"], row["profile"]) for row in pairs}):
        entries = [row for row in pairs if row["group"] == group and row["profile"] == profile]
        paired_summary.append({"group": group, "profile": profile, "count": len(entries),
                               **{field: {"median": percentile(values, .5), "p95": percentile(values, .95),
                                          "min": min(values), "max": max(values)} if values else None
                                  for field in ("bytes_ratio", "score_delta", "encode_time_ratio")
                                  for values in ([entry[field] for entry in entries if entry[field] is not None],)}})
    return {"summary": summaries, "paired": pairs, "paired_summary": paired_summary}


def write_gallery(output, rows):
    fragments = ["<!doctype html><meta charset=utf-8><title>Offline codec comparison</title>",
                 "<style>body{font:15px system-ui;margin:2rem;background:#eee}section{background:white;padding:1rem;margin:1rem 0}img{max-width:45%;vertical-align:top;background:repeating-conic-gradient(#ddd 0% 25%,#fff 0% 50%) 0/16px 16px}details img{max-width:none;image-rendering:auto}a{margin-right:1rem}</style>",
                 "<h1>Offline codec comparison</h1><p>Reference sRGB at left; decoded candidate at right. Expand for 1:1 inspection. Scores do not establish a universal acceptance threshold. Browser display is not a calibrated color oracle.</p>"]
    for row in rows:
        if row["status"] != "ok":
            continue
        reference, candidate = row["reference"], row["decoded"]
        label = html.escape("%s / %s: %.2f SSIMULACRA2, %s bytes" % (row["id"], row["profile"], row["ssimulacra2_worst"], row["bytes"]))
        fragments.append('<section><h2>%s</h2><img loading="lazy" src="%s"><img loading="lazy" src="%s"><details><summary>1:1</summary><a href="%s">Reference PNG</a><a href="%s">Candidate PNG</a><div><img loading="lazy" src="%s"><img loading="lazy" src="%s"></div></details></section>' % (label, reference, candidate, reference, candidate, reference, candidate))
    (output / "gallery.html").write_text("\n".join(fragments))


def save_reports(output, report):
    report.update(aggregate(report["rows"]))
    (output / "results.json").write_text(json.dumps(report, indent=2) + "\n")
    flat = [{key: value for key, value in row.items() if not isinstance(value, (dict, list))} for row in report["rows"]]
    fields = sorted(set().union(*(row.keys() for row in flat))) if flat else ["id", "status"]
    with (output / "results.csv").open("w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(flat)
    write_gallery(output, report["rows"])


def benchmark(manifest, output, profiles, timeout, tools, plugin_baseline=None):
    images = read_manifest(manifest)
    plugin_data, plugin_rows = None, {}
    if plugin_baseline:
        plugin_data = json.loads(plugin_baseline.read_text())
        if plugin_data.get("source_hashes_unchanged") is not True:
            raise ValueError("plugin baseline must prove unchanged source hashes")
        for row in plugin_data.get("images", []):
            key = (row.get("id"), row.get("codec"))
            if key in plugin_rows:
                raise ValueError("duplicate plugin baseline row")
            plugin_rows[key] = row
    # Exclusive creation is deliberate. Never reuse a partially completed run.
    if any(output.resolve() == Path(entry["source"]) or Path(entry["source"]).is_relative_to(output.resolve()) for entry in images):
        raise ValueError("output directory overlaps sources")
    output.mkdir(parents=True, exist_ok=False)
    report = {"schema_version": 1, "manifest": str(manifest.resolve()), "manifest_sha256": digest(manifest),
              "python": sys.version, "platform": sys.platform, "pillow": pillow_version,
              "lcms": ImageCms.core.littlecms_version, "profiles": profiles, "timeout_seconds": timeout,
              "baseline_label": "actual plugin seam outputs; see plugin_baseline provenance" if plugin_data else "command-equivalent external baseline; not a PHP/WordPress engine run",
              "plugin_baseline": plugin_data,
              "decode_measurement": "fresh Python process: Pillow decode + EXIF orientation + ICC to sRGB + PNG write + process startup",
              "rss_measurement": "os.wait4 per-child ru_maxrss, converted to bytes; includes child workers",
              "score_policy": "ICC transform to sRGB; explicit manifest assume_srgb for untagged RGB; black/white opaque composites, worst SSIMULACRA2 and separate alpha error",
              "limitations": ["single encode per image/profile; no throughput confidence interval", "no browser or calibrated display certification", "quality numbers are not equivalent across codecs", "AVIF baseline uses tool defaults including threading/speed, candidates pin speed6/jobs1", "encoder timings exclude shared source decode, ICC normalization and reference preparation"],
              "tools": {}, "rows": []}
    failed = False
    try:
        for key, version_args in (("cwebp", ["-version"]), ("avifenc", ["--version"]), ("ssimulacra2", ["--help"])):
            report["tools"][key] = dict(run_process([tools[key], *version_args], timeout), binary_sha256=digest(Path(tools[key])))
        for entry in images:
            source = Path(entry["source"])
            directory = output / entry["id"]
            directory.mkdir()
            normalized, preserved = directory / "reference.png", directory / "preserved.png"
            try:
                with Image.open(source) as image:
                    orientation = image.getexif().get(274, 1)
                    original_mode = image.mode
                    reference = color_managed(image, assume_srgb=entry.get("color_policy") in ("assume_srgb", "untagged_srgb"))
                reference.save(normalized, icc_profile=SRGB.tobytes())
                with Image.open(source) as image:
                    oriented = ImageOps.exif_transpose(image)
                    if oriented.mode != "CMYK":
                        oriented.save(preserved, icc_profile=oriented.info.get("icc_profile", b""))
                if entry.get("expected_failure"):
                    raise ValueError("expected failure probe unexpectedly decoded")
            except Exception as error:
                expected = entry.get("expected_failure")
                matched = bool(expected) and (expected is True or str(expected).lower() in str(error).lower()) and "unexpectedly decoded" not in str(error)
                report["rows"].append({"id": entry["id"], "group": entry["group"], "profile": "preflight", "status": "expected_error" if matched else "error", "error": str(error)})
                failed |= not matched
                continue
            for profile in profiles:
                row = {"id": entry["id"], "group": entry["group"], "profile": profile, "source_bytes": source.stat().st_size,
                       "source_sha256_before": digest(source), "status": "error", "reference": str(normalized.relative_to(output)),
                       "source_orientation": orientation}
                unhandled_orientation = not plugin_data and "baseline" in profile and orientation in range(2, 9)
                report["rows"].append(row)
                if profile == "webp_icc82" and orientation in range(2, 9):
                    row.update(status="not_applicable", error="metadata-only control bounded to neutral orientation")
                    continue
                if profile == "webp_lossless_srgb" and not ("A" in reference.getbands() or entry["group"] in ("graphics", "text", "screenshot", "screenshots", "alpha", "transparent")):
                    row.update(status="not_applicable", error="lossless profile bounded to graphics/alpha")
                    continue
                if "keep_icc" in profile and not preserved.exists():
                    row.update(status="unsupported_input", error="CMYK cannot be represented as preserved PNG; no false profile assignment")
                    continue
                row_directory = directory / profile
                row_directory.mkdir()
                target = row_directory / ("encoded.webp" if profile.startswith("webp") else "encoded.avif")
                decoded = row_directory / "decoded.png"
                try:
                    if plugin_data and "baseline" in profile:
                        baseline = plugin_rows.get((entry["id"], profile.split("_")[0]))
                        if not baseline or baseline.get("error"):
                            raise ValueError("missing or failed plugin baseline: " + str(baseline))
                        original_output = (plugin_baseline.parent / baseline["path"]).resolve(strict=True)
                        if not original_output.is_relative_to(plugin_baseline.parent.resolve()) or not original_output.is_file():
                            raise ValueError("plugin baseline path escapes its directory")
                        if digest(original_output) != baseline["sha256"]:
                            raise ValueError("plugin baseline hash mismatch")
                        shutil.copyfile(original_output, target)
                        row.update(encode_process=None, encode_wall_seconds=baseline.get("seconds"), encode_peak_rss_bytes=None,
                                   engine=baseline.get("engine"), encode_measurement="plugin seam wall time; no per-encode peak RSS available")
                    else:
                        process = run_process(encode_args(profile, tools, source, normalized, preserved, target), timeout)
                        row.update(encode_process=process, encode_wall_seconds=process["wall_seconds"], encode_peak_rss_bytes=process["peak_rss_bytes"])
                        if process["returncode"] or process["timed_out"] or not target.is_file() or target.stat().st_size == 0:
                            if original_mode == "CMYK" and "baseline" in profile and not process["timed_out"]:
                                row.update(status="unsupported_input", error="raw CMYK rejected by external encoder; normalized candidate is measured separately")
                                continue
                            raise ValueError("encoding failed or empty output")
                    row.update(bytes=target.stat().st_size, source_bytes_ratio=target.stat().st_size / source.stat().st_size,
                               encoded=str(target.relative_to(output)))
                    process = run_process([sys.executable, str(Path(__file__).resolve()), "--decode", str(target), str(decoded)], timeout)
                    row.update(decode_process=process, decode_wall_seconds=process["wall_seconds"], decode_peak_rss_bytes=process["peak_rss_bytes"])
                    if process["returncode"] or process["timed_out"] or not decoded.is_file():
                        raise ValueError("decode failed")
                    with Image.open(decoded) as candidate:
                        candidate.load()
                        if candidate.size != reference.size and unhandled_orientation:
                            row.update(status="observed_orientation_limitation", error="raw command baseline drops orientation; dimensions differ", decoded=str(decoded.relative_to(output)))
                            continue
                        row.update(metric_score(tools["ssimulacra2"], reference, candidate, row_directory, timeout))
                    row.update(status="ok", decoded=str(decoded.relative_to(output)))
                    if unhandled_orientation:
                        row.update(status="observed_orientation_limitation", error="raw command baseline does not normalize EXIF; same dimensions do not prove displayed identity")
                except Exception as error:
                    row["error"] = str(error)
                    failed = True
                finally:
                    row["source_sha256_after"] = digest(source)
                    if row["source_sha256_after"] != row["source_sha256_before"]:
                        raise RuntimeError("SOURCE MUTATED: " + str(source))
            print(entry["id"], flush=True)
        report["source_hashes_unchanged"] = all(digest(Path(entry["source"])) == entry["sha256"] for entry in images)
        failed |= not report["source_hashes_unchanged"]
    except BaseException:
        failed = True
        raise
    finally:
        report["source_hashes_unchanged"] = all(Path(entry["source"]).is_file() and digest(Path(entry["source"])) == entry["sha256"] for entry in images)
        failed |= not report["source_hashes_unchanged"]
        report["execution_ok"] = not failed
        save_reports(output, report)
    return 1 if failed else 0


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    if argv and argv[0] == "--decode":
        if len(argv) != 3:
            raise ValueError("--decode requires source and destination")
        worker_decode(argv[1], argv[2])
        return 0
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("manifest", type=Path)
    parser.add_argument("output", type=Path)
    parser.add_argument("--profiles", nargs="+", choices=PROFILES + OPTIONAL_PROFILES, default=list(PROFILES))
    parser.add_argument("--timeout", type=float, default=120)
    parser.add_argument("--plugin-baseline", type=Path, help="score actual plugin outputs from plugin-baseline.php JSON; replaces baseline profile encode step")
    for tool in ("cwebp", "avifenc", "ssimulacra2"):
        parser.add_argument("--" + tool, default=tool)
    args = parser.parse_args(argv)
    if not 0 < args.timeout <= 600 or len(set(args.profiles)) != len(args.profiles):
        parser.error("timeout must be in (0,600]; profiles must be unique")
    tools = {}
    for name in ("cwebp", "avifenc", "ssimulacra2"):
        tool = shutil.which(getattr(args, name))
        if not tool:
            parser.error("missing executable: " + name)
        tools[name] = str(Path(tool).resolve())
    return benchmark(args.manifest.resolve(), args.output.absolute(), args.profiles, args.timeout, tools,
                     args.plugin_baseline.resolve() if args.plugin_baseline else None)


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError, RuntimeError) as error:
        print("benchmark error:", error, file=sys.stderr)
        sys.exit(1)
