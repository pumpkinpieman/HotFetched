#!/usr/bin/env python3
"""Apply the HotFetched 12-channel MMU patch safely and idempotently.

Run from anywhere:
    python3 apply_mmu12_patch.py /path/to/HotFetched

The script creates *.pre-mmu12 backups before changing existing files.
It refuses to guess when the expected upstream code is not found.
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
from pathlib import Path

MARKER = "HotFetched MMU12 support"
MMU12_URL = "https://github.com/cjbaar/Prusa-Firmware-MMU-12x"


class PatchError(RuntimeError):
    pass


def replace_exact(text: str, old: str, new: str, *, label: str, count: int = 1) -> str:
    found = text.count(old)
    if found != count:
        raise PatchError(f"{label}: expected {count} match(es), found {found}")
    return text.replace(old, new, count)


def backup(path: Path) -> Path:
    backup_path = path.with_name(path.name + ".pre-mmu12")
    if not backup_path.exists():
        shutil.copy2(path, backup_path)
    return backup_path


def atomic_write(path: Path, content: str) -> None:
    tmp = path.with_name(path.name + ".mmu12.tmp")
    tmp.write_text(content, encoding="utf-8")
    tmp.replace(path)


def patch_bootstrap(path: Path) -> bool:
    text = path.read_text(encoding="utf-8")
    if MARKER in text:
        return False

    text = replace_exact(
        text,
        "    $eSlots = max(1, $eSlots);\n\n    return [",
        "    $eSlots = max(1, $eSlots);\n"
        "    // HotFetched MMU12 support: preserve normal board-dependent choices,\n"
        "    // then add the special 12-logical-tool mode.\n"
        "    $extruderOptions = array_map('strval', range(1, max(5, $eSlots)));\n"
        "    if (!in_array('12', $extruderOptions, true)) {\n"
        "        $extruderOptions[] = '12';\n"
        "    }\n\n"
        "    return [",
        label="bootstrap: create extruder option list",
    )

    old_requires = "'requires' => ['extruders' => ['2', '3', '4', '5', '6', '7', '8']]"
    new_requires = "'requires' => ['extruders' => ['2', '3', '4', '5', '6', '7', '8', '12']]"
    if text.count(old_requires) < 2:
        raise PatchError(
            "bootstrap: expected at least two extruder dependency lists; "
            f"found {text.count(old_requires)}"
        )
    text = text.replace(old_requires, new_requires)

    text = replace_exact(
        text,
        "    // MMU, which reports 5 tools but uses a single E motor. So allow up to 5\n"
        "    // for MMU users while still reflecting the board's physical limit.\n"
        "    'options' => array_map('strval', range(1, max(5, $eSlots))),\n"
        "    'hint' => $eSlots . ' physical E slot(s) on this board; more than that requires an MMU'],",
        "    // MMUs expose logical tools while continuing to use one physical E motor.\n"
        "    // 12 is reserved for an extendable, MMU3-compatible 12-channel setup.\n"
        "    'options' => $extruderOptions,\n"
        "    'hint' => $eSlots . ' physical E slot(s) on this board; 12 requires compatible extendable MMU firmware'],",
        label="bootstrap: use MMU12 option list",
    )

    text = replace_exact(
        text,
        "    $nExt = max(1, min(8, (int)($v['extruders'] ?? 1)));\n"
        "    $set('EXTRUDERS', (string)$nExt);",
        "    // HotFetched MMU12 support. Do not clamp 12 back to 8. The API's\n"
        "    // select validation remains the primary allow-list; this is defense-in-depth.\n"
        "    $requestedExt = (int)($v['extruders'] ?? 1);\n"
        "    $allowedExt = [1, 2, 3, 4, 5, 6, 7, 8, 12];\n"
        "    $nExt = in_array($requestedExt, $allowedExt, true) ? $requestedExt : 1;\n"
        "    $set('EXTRUDERS', (string)$nExt);",
        label="bootstrap: remove 8-extruder clamp",
    )

    backup(path)
    atomic_write(path, text)
    return True


def patch_build_worker(path: Path) -> bool:
    text = path.read_text(encoding="utf-8")
    if "12 logical tools require MMU model" in text:
        return False

    old = (
        "// Prusa MMU2S/MMU3 are 5-port units: Marlin requires EXTRUDERS = 5 exactly.\n"
        "$mmu = (string)($vals['mmu_model'] ?? 'none');\n"
        "if (marlin_mmu_needs_5($mmu) && (int)($vals['extruders'] ?? 1) !== 5) {\n"
        "    $conflicts[] = \"{$mmu} is a 5-port unit and requires exactly 5 extruders - set Extruders to 5\";\n"
        "}"
    )
    new = """// HotFetched MMU12 support. Stock PRUSA_MMU2(S)/MMU3 host modes are
// fixed to five logical tools in Marlin. A 12-channel MMU3-compatible
// unit must use Marlin's extendable MMU host mode.
$mmu = (string)($vals['mmu_model'] ?? 'none');
$extendableMmu = in_array($mmu, ['EXTENDABLE_EMU_MMU2', 'EXTENDABLE_EMU_MMU2S'], true);
if ($nExtSel === 12 && !$extendableMmu) {
    $conflicts[] = '12 logical tools require MMU model EXTENDABLE_EMU_MMU2S '
        . '(or EXTENDABLE_EMU_MMU2) plus the Prusa-Firmware-MMU-12x firmware. '
        . 'Standard PRUSA_MMU3 is fixed to exactly 5 EXTRUDERS in Marlin.';
} elseif (marlin_mmu_needs_5($mmu) && $nExtSel !== 5) {
    $conflicts[] = "{$mmu} is a 5-port unit and requires exactly 5 extruders - set Extruders to 5";
}
""".rstrip("\n")

    text = replace_exact(text, old, new, label="build worker: MMU validation")

    backup(path)
    atomic_write(path, text)
    return True


def patch_project(path: Path) -> bool:
    text = path.read_text(encoding="utf-8")
    changed = False

    if 'id="mmu12Warning"' not in text:
        old = '        <div id="cfgGroups"></div>'
        new = (
            '        <div id="cfgGroups"></div>\n'
            '        <p id="mmu12Warning" class="cfg-warning mmu12-warning" hidden>\n'
            '            <strong>Only select if you have 12 extruder MMU3</strong><br>\n'
            '            Use Marlin MMU model <code>EXTENDABLE_EMU_MMU2S</code> and the\n'
            f'            <a href="{MMU12_URL}" target="_blank" rel="noopener noreferrer">Prusa-Firmware-MMU-12x firmware</a>.\n'
            '            Standard <code>PRUSA_MMU3</code> is limited to five logical tools in Marlin.\n'
            '        </p>'
        )
        text = replace_exact(text, old, new, label="project UI: insert MMU12 warning")
        changed = True

    if "mmu12Warning.hidden" not in text:
        old = (
            "function cfgApplyVisibility() {\n"
            "    const values = cfgCollect();"
        )
        new = (
            "function cfgApplyVisibility() {\n"
            "    const values = cfgCollect();\n\n"
            "    // HotFetched MMU12 support: show the hardware/firmware warning only\n"
            "    // while the special 12-logical-tool choice is active.\n"
            "    const mmu12Warning = document.getElementById('mmu12Warning');\n"
            "    if (mmu12Warning) {\n"
            "        mmu12Warning.hidden = String(values.extruders ?? '') !== '12';\n"
            "    }"
        )
        text = replace_exact(text, old, new, label="project UI: toggle MMU12 warning")
        changed = True

    if changed:
        backup(path)
        atomic_write(path, text)
    return changed


def patch_style(path: Path) -> bool:
    text = path.read_text(encoding="utf-8")
    if ".mmu12-warning" in text:
        return False
    block = (
        "\n\n/* HotFetched MMU12 support */\n"
        ".mmu12-warning {\n"
        "  color: #ff4d4d;\n"
        "  font-weight: 700;\n"
        "  margin: 10px 0 16px;\n"
        "}\n"
        ".mmu12-warning a {\n"
        "  color: inherit;\n"
        "  text-decoration: underline;\n"
        "}\n"
    )
    backup(path)
    atomic_write(path, text.rstrip() + block)
    return True


def patch_dockerfile(path: Path) -> bool:
    text = path.read_text(encoding="utf-8")
    changed = False

    if "COPY --chown=www-data:www-data webroot/" not in text:
        text = replace_exact(
            text,
            "COPY webroot/ /var/www/html/webroot/",
            "# HotFetched MMU12 support / image optimization: assign ownership in the COPY\n"
            "# layer instead of duplicating the entire app tree in a later recursive chown.\n"
            "COPY --chown=www-data:www-data webroot/ /var/www/html/webroot/",
            label="Dockerfile: COPY ownership optimization",
        )
        changed = True

    old_run = (
        "RUN find /var/www/html/webroot -name '*.php' -print0 | xargs -0 -n1 php -l \\\n"
        "    && mkdir -p /var/www/html/private/projects \\\n"
        "    && chown -R www-data:www-data /var/www/html"
    )
    new_run = (
        "RUN find /var/www/html/webroot -name '*.php' -print0 | xargs -0 -n1 php -l \\\n"
        "    && install -d -o www-data -g www-data /var/www/html/private \\\n"
        "    && install -d -o www-data -g www-data /var/www/html/private/projects"
    )
    if old_run in text:
        text = replace_exact(
            text, old_run, new_run, label="Dockerfile: remove recursive chown layer"
        )
        changed = True
    elif "install -d -o www-data -g www-data /var/www/html/private/projects" not in text:
        raise PatchError("Dockerfile: expected app lint/chown block was not found")

    if changed:
        backup(path)
        atomic_write(path, text)
    return changed


def patch_dockerignore(path: Path) -> bool:
    block = (
        "# HotFetched MMU12 support / keep the Docker build context small\n"
        ".git\n"
        ".github\n"
        "deploy\n"
        "private\n"
        "*.zip\n"
        "*.tar\n"
        "*.tar.gz\n"
        "*.log\n"
        ".DS_Store\n"
        "__pycache__/\n"
        "*.pre-mmu12\n"
    )
    if not path.exists():
        atomic_write(path, block)
        return True

    text = path.read_text(encoding="utf-8")
    if "HotFetched MMU12 support / keep the Docker build context small" in text:
        return False
    backup(path)
    atomic_write(path, text.rstrip() + "\n\n" + block)
    return True


def lint_php(repo: Path, files: list[Path]) -> None:
    php = shutil.which("php")
    if php is None:
        print("[note] php CLI not found on host; Docker build will run the repository's PHP lint gate.")
        return
    for path in files:
        proc = subprocess.run(
            [php, "-l", str(path)],
            cwd=repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            check=False,
        )
        if proc.returncode != 0:
            raise PatchError(f"PHP lint failed for {path}:\n{proc.stdout}")
        print(f"[ok] PHP lint: {path.relative_to(repo)}")


def verify(repo: Path) -> list[str]:
    checks = {
        "webroot/bootstrap.php": [
            "HotFetched MMU12 support",
            "$extruderOptions[] = '12';",
            "$allowedExt = [1, 2, 3, 4, 5, 6, 7, 8, 12];",
        ],
        "webroot/build_worker.php": [
            "12 logical tools require MMU model EXTENDABLE_EMU_MMU2S",
        ],
        "webroot/project.php": [
            'id="mmu12Warning"',
            "mmu12Warning.hidden",
            MMU12_URL,
        ],
        "webroot/style.css": [".mmu12-warning"],
        "Dockerfile": ["COPY --chown=www-data:www-data webroot/"],
        ".dockerignore": ["HotFetched MMU12 support / keep the Docker build context small"],
    }
    failures: list[str] = []
    for rel, needles in checks.items():
        path = repo / rel
        if not path.is_file():
            failures.append(f"missing {rel}")
            continue
        text = path.read_text(encoding="utf-8")
        for needle in needles:
            if needle not in text:
                failures.append(f"{rel}: missing {needle!r}")
    return failures


def restore(repo: Path) -> None:
    restored = 0
    for rel in [
        "webroot/bootstrap.php",
        "webroot/build_worker.php",
        "webroot/project.php",
        "webroot/style.css",
        "Dockerfile",
        ".dockerignore",
    ]:
        path = repo / rel
        bak = path.with_name(path.name + ".pre-mmu12")
        if bak.exists():
            shutil.copy2(bak, path)
            restored += 1
            print(f"[restored] {rel}")
    print(f"Restored {restored} file(s).")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("repo", nargs="?", default=".", help="HotFetched repository root")
    parser.add_argument("--check", action="store_true", help="verify only; do not modify files")
    parser.add_argument("--restore", action="store_true", help="restore *.pre-mmu12 backups")
    parser.add_argument("--skip-docker", action="store_true", help="do not optimize Dockerfile/.dockerignore")
    args = parser.parse_args()

    repo = Path(args.repo).expanduser().resolve()
    required = [
        repo / "webroot/bootstrap.php",
        repo / "webroot/build_worker.php",
        repo / "webroot/project.php",
        repo / "webroot/style.css",
        repo / "Dockerfile",
    ]
    missing = [str(p) for p in required if not p.is_file()]
    if missing:
        print("Not a compatible HotFetched checkout; missing:\n  " + "\n  ".join(missing), file=sys.stderr)
        return 2

    if args.restore:
        restore(repo)
        return 0

    if args.check:
        failures = verify(repo)
        if failures:
            print("MMU12 verification failed:\n  " + "\n  ".join(failures), file=sys.stderr)
            return 1
        print("MMU12 patch verification passed.")
        return 0

    changed: list[str] = []
    operations = [
        (repo / "webroot/bootstrap.php", patch_bootstrap),
        (repo / "webroot/build_worker.php", patch_build_worker),
        (repo / "webroot/project.php", patch_project),
        (repo / "webroot/style.css", patch_style),
    ]
    if not args.skip_docker:
        operations.extend([
            (repo / "Dockerfile", patch_dockerfile),
            (repo / ".dockerignore", patch_dockerignore),
        ])

    try:
        for path, func in operations:
            if func(path):
                changed.append(str(path.relative_to(repo)))
                print(f"[patched] {path.relative_to(repo)}")
            else:
                print(f"[unchanged] {path.relative_to(repo)}")

        lint_php(repo, [
            repo / "webroot/bootstrap.php",
            repo / "webroot/build_worker.php",
            repo / "webroot/project.php",
        ])

        failures = verify(repo)
        if args.skip_docker:
            failures = [f for f in failures if not f.startswith("Dockerfile") and not f.startswith(".dockerignore")]
        if failures:
            raise PatchError("post-patch verification failed:\n  " + "\n  ".join(failures))
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        print("Existing files were backed up as *.pre-mmu12. Run with --restore to roll back.", file=sys.stderr)
        return 1

    print("\nMMU12 patch applied successfully.")
    print("Select Extruders = 12 and MMU model = EXTENDABLE_EMU_MMU2S.")
    print(f"MMU firmware reference: {MMU12_URL}")
    print("Next: rebuild the Docker image and run a real Marlin compile before flashing.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
