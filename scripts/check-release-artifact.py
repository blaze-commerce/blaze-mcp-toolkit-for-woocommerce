#!/usr/bin/env python3
"""Release artifact and promotion checks shared by scripts/release-candidate.sh and deploy.yml.

Standard library only; runs on Python 3.8 and later. Every subcommand fails closed: exit 1 with an
"ERROR:" line on stderr for a failed check, exit 2 for a usage error. Values printed on stdout are
KEY=value lines whose values have already been validated (hex digests, numeric IDs, versions), so
callers can append them to $GITHUB_ENV.

  check ZIP --version V --repo DIR --source-sha SHA [--sha256 HEX] [--manifest-out FILE] [--extract-to DIR]
      Inspects every archive entry before anything is extracted: exactly the slug root, ordinary
      files and directories only (no symlinks, devices, FIFOs), no duplicate, traversal, absolute or
      unusual paths, no .git/.svn, no encrypted entries, no empty directories, no data before the
      archive. Then checks the package against the source commit: every file the source would ship
      (tracked files after .distignore, vendor-src/commerce-abilities mapped to
      vendor/woocommerce/commerce-abilities) is present and byte-identical, nothing excluded by
      .distignore is present, and the only files without a git source are Composer's generated
      vendor/autoload.php and vendor/composer/*, which are accepted as the bytes the digest names
      (never regenerated or normalised). The plugin header Version, BLZMCP_VERSION and the readme
      Stable tag must each appear once and equal V. Emits a sorted per-file sha256 manifest.
  manifest-dir DIR [--expect FILE] [--manifest-out FILE]
      The same manifest for an extracted tree (regular files only); --expect fails on any
      missing, extra or changed file.
  expected --repo DIR --source-sha SHA
      Prints the source-backed inventory the package must contain, one path per line.
  tag-info --event EVENT --tag TAG [--sha256 HEX] [--dry-run-input true|false]
      Exact tag grammar. A push deploys only a final tag (vX.Y.Z); a dispatch accepts a final or
      a pre-release tag (vX.Y.Z-rcN) and is always a dry run (a request for dry_run=false fails).
  tag-annotation --tag TAG --commit SHA --ref-json FILE --tag-json FILE
      The GitHub ref and tag objects of an annotated tag at SHA whose message has exactly one
      candidate-sha256 line and exactly one source-sha line, with source-sha equal to SHA.
  release-state --releases-json FILE --version V --source-sha SHA [--sha256 HEX] [--require-staged]
      Classifies the GitHub releases for vV: none, ambiguous, published, draft-empty, staged,
      staged-unverified (asset API gave no digest) or draft-mismatch.
  guard-dest --root DIR --path PATH --kind dir|file
      Fails when PATH, after resolving symlinks, is the checkout or inside it (dist/ included).
"""

from __future__ import annotations

import argparse
import fnmatch
import hashlib
import io
import json
import os
import re
import stat
import subprocess
import sys
import zipfile

SLUG = "blaze-mcp-toolkit-for-woocommerce"
VENDOR_SRC = "vendor-src/commerce-abilities/"
VENDOR_DST = "vendor/woocommerce/commerce-abilities/"
# Composer's generated runtime: accepted without a git source, as the reviewed bytes the digest names.
GENERATED_REQUIRED = (
    "vendor/autoload.php",
    "vendor/composer/autoload_real.php",
    "vendor/composer/autoload_static.php",
    "vendor/composer/ClassLoader.php",
)
MAX_ENTRIES = 20000
MAX_FILE_BYTES = 64 * 1024 * 1024
MAX_TOTAL_BYTES = 256 * 1024 * 1024
COMPONENT_RE = re.compile(r"[A-Za-z0-9._+@-]+")
NUM = r"(?:0|[1-9][0-9]*)"
VERSION_RE = re.compile(NUM + r"\." + NUM + r"\." + NUM)
FINAL_TAG_RE = re.compile(r"v(" + NUM + r"\." + NUM + r"\." + NUM + r")")
RC_TAG_RE = re.compile(r"v(" + NUM + r"\." + NUM + r"\." + NUM + r")-rc([1-9][0-9]*)")
SHA256_RE = re.compile(r"[0-9a-f]{64}")
COMMIT_RE = re.compile(r"[0-9a-f]{40}|[0-9a-f]{64}")


class CheckError(Exception):
    pass


def fail(msg: str) -> None:
    raise CheckError(msg)


def full(regex: re.Pattern, value: str) -> re.Match | None:
    # fullmatch, never match/$: "$" would accept a trailing newline.
    return regex.fullmatch(value) if isinstance(value, str) else None


def want_sha256(value: str, what: str = "sha256") -> str:
    if not full(SHA256_RE, value):
        fail(f"{what} must be 64 lowercase hexadecimal characters")
    return value


def want_commit(value: str, what: str = "source sha") -> str:
    if not full(COMMIT_RE, value):
        fail(f"{what} must be a full lowercase hexadecimal commit id")
    return value


def want_version(value: str) -> str:
    if not full(VERSION_RE, value):
        fail("version must be x.y.z (no leading zeros)")
    return value


def emit(**pairs: object) -> None:
    for key, value in pairs.items():
        text = str(value)
        if "\n" in text or "\r" in text:
            fail(f"refusing to print a multi-line value for {key}")
        print(f"{key}={text}")


# ---------------------------------------------------------------------------------------------
# Source side: git tree, .distignore and the expected inventory.


def git(repo: str, *args: str) -> bytes:
    proc = subprocess.run(["git", "-C", repo, *args], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if proc.returncode != 0:
        fail(f"git {' '.join(args[:2])} failed: {proc.stderr.decode('utf-8', 'replace').strip()[:200]}")
    return proc.stdout


def resolve_commit(repo: str, sha: str) -> str:
    want_commit(sha)
    got = git(repo, "rev-parse", "--verify", "--quiet", sha + "^{commit}").decode().strip()
    if got != sha:
        fail(f"{sha} is not a commit in {repo}")
    return sha


def object_format(repo: str) -> str:
    proc = subprocess.run(["git", "-C", repo, "rev-parse", "--show-object-format"], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    fmt = proc.stdout.decode().strip() if proc.returncode == 0 else ""
    return fmt or "sha1"


def blob_id(data: bytes, fmt: str) -> str:
    h = hashlib.new(fmt)
    h.update(b"blob %d\0" % len(data))
    h.update(data)
    return h.hexdigest()


def load_distignore(repo: str, sha: str) -> list[tuple[str, bool]]:
    text = git(repo, "show", f"{sha}:.distignore").decode("utf-8")
    patterns = []
    for raw in text.split("\n"):
        line = raw[:-1] if raw.endswith("\r") else raw
        if line == "" or line.startswith("#") or line.startswith(";"):
            continue
        if line != line.strip() or line.startswith(("!", "+ ", "- ")) or "\\" in line:
            fail(f".distignore pattern not supported by this checker: {line!r}")
        dir_only = line.endswith("/")
        body = line[:-1] if dir_only else line
        if "/" in body or body == "":
            fail(f".distignore pattern not supported by this checker (anchored or nested): {line!r}")
        patterns.append((body, dir_only))
    if not patterns:
        fail(".distignore has no patterns")
    return patterns


def excluded(path: str, patterns: list[tuple[str, bool]]) -> str | None:
    """rsync --exclude-from semantics for unanchored patterns: a pattern matches any path component;
    a trailing-slash pattern matches directories only. Returns the matching pattern, or None."""
    parts = path.split("/")
    for i, part in enumerate(parts):
        is_dir = i < len(parts) - 1
        for pattern, dir_only in patterns:
            if dir_only and not is_dir:
                continue
            if fnmatch.fnmatchcase(part, pattern):
                return pattern + ("/" if dir_only else "")
    return None


def expected_inventory(repo: str, sha: str) -> tuple[dict[str, str], list[tuple[str, bool]], str]:
    """Maps each path the package must ship (relative to the slug root) to its git blob id."""
    resolve_commit(repo, sha)
    fmt = object_format(repo)
    patterns = load_distignore(repo, sha)
    out = git(repo, "ls-tree", "-r", "-z", "--full-tree", sha)
    required: dict[str, str] = {}
    for record in out.split(b"\0"):
        if not record:
            continue
        meta, path_b = record.split(b"\t", 1)
        mode, kind, oid = meta.decode().split(" ")
        path = path_b.decode("utf-8")
        ship = VENDOR_DST + path[len(VENDOR_SRC):] if path.startswith(VENDOR_SRC) else path
        if excluded(ship, patterns):
            continue
        if kind != "blob" or mode not in ("100644", "100755"):
            fail(f"{path} would ship but is not a regular file in git (mode {mode}, {kind})")
        if ship in required:
            fail(f"{ship} has two sources at {sha}")
        required[ship] = oid
    if not required:
        fail(f"nothing would ship from {sha}")
    return required, patterns, fmt


# ---------------------------------------------------------------------------------------------
# Archive side.


def check_name(name: str) -> tuple[list[str], bool]:
    if not name:
        fail("archive has an entry with an empty name")
    if name.startswith("/") or "\\" in name or ":" in name:
        fail(f"archive entry has an absolute or non-portable path: {name!r}")
    is_dir = name.endswith("/")
    body = name[:-1] if is_dir else name
    parts = body.split("/")
    for part in parts:
        if part in ("", ".", ".."):
            fail(f"archive entry has an empty, '.' or '..' path component: {name!r}")
        if part.lower() in (".git", ".svn"):
            fail(f"archive entry is version-control metadata: {name!r}")
        if not full(COMPONENT_RE, part):
            fail(f"archive entry has unsupported characters: {name!r}")
    if parts[0] != SLUG:
        fail(f"archive entry is outside the {SLUG}/ root: {name!r}")
    if len(parts) == 1 and not is_dir:
        fail(f"archive root {SLUG} must be a directory")
    return parts, is_dir


def entry_kind(info: zipfile.ZipInfo, is_dir: bool) -> None:
    mode = info.external_attr >> 16
    if info.create_system == 3 and mode:
        ftype = stat.S_IFMT(mode)
        if is_dir and ftype not in (0, stat.S_IFDIR):
            fail(f"archive entry {info.orig_filename!r} is not an ordinary directory (mode {oct(mode)})")
        if not is_dir and ftype not in (0, stat.S_IFREG):
            what = "a symlink" if ftype == stat.S_IFLNK else f"a special entry (mode {oct(mode)})"
            fail(f"archive entry {info.orig_filename!r} is {what}")
    if not is_dir and info.external_attr & 0x10:
        fail(f"archive entry {info.orig_filename!r} is flagged as a directory but named as a file")


def read_archive(data: bytes) -> dict[str, bytes]:
    """Validates every entry and returns {path relative to the slug root: bytes} for files."""
    try:
        zf = zipfile.ZipFile(io.BytesIO(data))
    except (zipfile.BadZipFile, OSError, ValueError) as exc:
        fail(f"not a readable zip archive: {exc}")
    with zf:
        infos = zf.infolist()
        if not infos:
            fail("archive is empty")
        if len(infos) > MAX_ENTRIES:
            fail(f"archive has more than {MAX_ENTRIES} entries")
        if min(i.header_offset for i in infos) != 0:
            fail("archive has data before its first entry")
        seen: set[str] = set()
        folded: set[str] = set()
        files: dict[str, bytes] = {}
        dirs: set[str] = set()
        total = 0
        for info in infos:
            name = info.orig_filename
            parts, is_dir = check_name(name)
            rel = "/".join(parts[1:])
            key = rel + ("/" if is_dir else "")
            if key in seen:
                fail(f"archive has a duplicate entry: {name!r}")
            if key.lower() in folded:
                fail(f"archive has entries that differ only by case: {name!r}")
            seen.add(key)
            folded.add(key.lower())
            entry_kind(info, is_dir)
            if info.flag_bits & 0x1:
                fail(f"archive entry {name!r} is encrypted")
            if info.compress_type not in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED):
                fail(f"archive entry {name!r} uses an unsupported compression method")
            if is_dir:
                if info.file_size != 0:
                    fail(f"directory entry {name!r} has data")
                if rel:
                    dirs.add(rel)
                continue
            if info.file_size > MAX_FILE_BYTES:
                fail(f"archive entry {name!r} is larger than {MAX_FILE_BYTES} bytes")
            total += info.file_size
            if total > MAX_TOTAL_BYTES:
                fail(f"archive expands to more than {MAX_TOTAL_BYTES} bytes")
            try:
                content = zf.read(info)
            except (zipfile.BadZipFile, OSError, ValueError, RuntimeError, NotImplementedError) as exc:
                fail(f"archive entry {name!r} cannot be read: {exc}")
            if len(content) != info.file_size:
                fail(f"archive entry {name!r} size does not match its header")
            files[rel] = content
    parents: set[str] = set()
    for rel in files:
        bits = rel.split("/")
        for i in range(1, len(bits)):
            parents.add("/".join(bits[:i]))
    for rel in files:
        if rel in parents:
            fail(f"archive path is both a file and a directory: {rel!r}")
    for d in sorted(dirs):
        if d in files:
            fail(f"archive path is both a file and a directory: {d!r}")
        if d not in parents:
            fail(f"archive has an empty directory that no manifest entry covers: {d}/")
    if not files:
        fail("archive has no files")
    return files


def manifest_text(files: dict[str, bytes]) -> str:
    lines = [f"{hashlib.sha256(files[p]).hexdigest()}  {p}\n" for p in sorted(files, key=lambda s: s.encode("utf-8"))]
    return "".join(lines)


def single_value(text: str, regex: re.Pattern, what: str) -> str:
    found = regex.findall(text)
    if len(found) != 1:
        fail(f"{what} must appear exactly once (found {len(found)})")
    return found[0]


def check_versions(files: dict[str, bytes], version: str) -> None:
    main = files.get(f"{SLUG}.php")
    readme = files.get("readme.txt")
    if main is None or readme is None:
        fail(f"package has no {SLUG}.php or readme.txt")
    main_text = main.decode("utf-8", "replace")
    readme_text = readme.decode("utf-8", "replace")
    header = single_value(main_text, re.compile(r"^ \* Version:[ \t]+([^\s]+)[ \t]*\r?$", re.M), "plugin header Version")
    constant = single_value(main_text, re.compile(r"define\(\s*'BLZMCP_VERSION',\s*'([^']*)'\s*\)"), "BLZMCP_VERSION")
    stable = single_value(readme_text, re.compile(r"^Stable tag:[ \t]*([^\s]+)[ \t]*\r?$", re.M), "readme Stable tag")
    for what, got in (("plugin header Version", header), ("BLZMCP_VERSION", constant), ("readme Stable tag", stable)):
        if got != version:
            fail(f"{what} is {got!r}, expected {version}")


def check_package(files: dict[str, bytes], repo: str, sha: str, version: str) -> None:
    required, patterns, fmt = expected_inventory(repo, sha)
    problems: list[str] = []
    for rel in sorted(files):
        pattern = excluded(rel, patterns)
        if pattern:
            problems.append(f"{rel}: excluded by .distignore ({pattern})")
            continue
        if rel in required:
            if blob_id(files[rel], fmt) != required[rel]:
                problems.append(f"{rel}: bytes differ from {sha[:12]}")
        elif rel == "vendor/autoload.php" or rel.startswith("vendor/composer/"):
            continue  # Composer output: identified by the candidate digest, never regenerated here.
        else:
            problems.append(f"{rel}: no source at {sha[:12]} and not Composer output")
    for rel in sorted(required):
        if rel not in files:
            problems.append(f"{rel}: missing from the package")
    for rel in GENERATED_REQUIRED:
        if rel not in files:
            problems.append(f"{rel}: Composer runtime file missing from the package")
    if problems:
        shown = "\n  ".join(problems[:50])
        more = f"\n  ... and {len(problems) - 50} more" if len(problems) > 50 else ""
        fail(f"package does not match source {sha}:\n  {shown}{more}")
    check_versions(files, version)


def write_new(path: str, data: bytes) -> None:
    with open(path, "xb") as fh:  # never overwrite
        fh.write(data)


def extract(files: dict[str, bytes], dest: str) -> None:
    if os.path.lexists(dest):
        fail(f"extraction directory already exists: {dest}")
    os.makedirs(dest, mode=0o755)
    for rel in sorted(files):
        target = os.path.join(dest, *rel.split("/"))
        os.makedirs(os.path.dirname(target), mode=0o755, exist_ok=True)
        write_new(target, files[rel])
        os.chmod(target, 0o644)


def cmd_check(args: argparse.Namespace) -> None:
    version = want_version(args.version)
    sha = want_commit(args.source_sha)
    with open(args.zip, "rb") as fh:
        data = fh.read()  # one read: the digest and the checks see the same bytes
    digest = hashlib.sha256(data).hexdigest()
    if args.sha256 is not None and digest != want_sha256(args.sha256):
        fail(f"{os.path.basename(args.zip)} sha256 is {digest}, expected {args.sha256}")
    files = read_archive(data)
    check_package(files, args.repo, sha, version)
    manifest = manifest_text(files)
    if args.manifest_out:
        write_new(args.manifest_out, manifest.encode("utf-8"))
    if args.extract_to:
        extract(files, args.extract_to)
    emit(SHA256=digest, FILES=len(files), MANIFEST_SHA256=hashlib.sha256(manifest.encode("utf-8")).hexdigest())


def dir_files(root: str) -> dict[str, bytes]:
    files: dict[str, bytes] = {}

    def walk(path: str, rel: str) -> None:
        entries = sorted(os.scandir(path), key=lambda e: e.name)
        if not entries and rel:
            fail(f"empty directory: {rel}/")
        for entry in entries:
            sub = f"{rel}/{entry.name}" if rel else entry.name
            st = os.lstat(entry.path)
            if stat.S_ISDIR(st.st_mode):
                walk(entry.path, sub)
            elif stat.S_ISREG(st.st_mode):
                with open(entry.path, "rb") as fh:
                    files[sub] = fh.read()
            else:
                fail(f"not a regular file or directory: {sub}")

    if not os.path.isdir(root) or os.path.islink(root):
        fail(f"not a directory: {root}")
    walk(root, "")
    return files


def cmd_manifest_dir(args: argparse.Namespace) -> None:
    manifest = manifest_text(dir_files(args.dir))
    if args.manifest_out:
        write_new(args.manifest_out, manifest.encode("utf-8"))
    if args.expect:
        with open(args.expect, "r", encoding="utf-8") as fh:
            want = fh.read()
        if want != manifest:
            def parse(text: str) -> dict[str, str]:
                return {line[66:]: line[:64] for line in text.splitlines() if len(line) > 66}

            a, b = parse(want), parse(manifest)
            diffs = [f"missing: {p}" for p in sorted(set(a) - set(b))]
            diffs += [f"extra: {p}" for p in sorted(set(b) - set(a))]
            diffs += [f"changed: {p}" for p in sorted(set(a) & set(b)) if a[p] != b[p]]
            fail(f"{args.dir} does not match {args.expect}:\n  " + "\n  ".join(diffs[:50] or ["(manifest text differs)"]))
    emit(FILES=manifest.count("\n"), MANIFEST_SHA256=hashlib.sha256(manifest.encode("utf-8")).hexdigest())


def cmd_expected(args: argparse.Namespace) -> None:
    required, _, _ = expected_inventory(args.repo, args.source_sha)
    for rel in sorted(required):
        print(rel)


# ---------------------------------------------------------------------------------------------
# Deploy request, tag annotation and release selection.


def cmd_tag_info(args: argparse.Namespace) -> None:
    tag = args.tag
    if len(tag) > 64:
        fail("tag is too long")
    final = full(FINAL_TAG_RE, tag)
    rc = full(RC_TAG_RE, tag)
    if args.event == "push":
        if rc:
            fail(f"{tag} is a pre-release tag; pre-release tag pushes never deploy")
        if not final:
            fail(f"{tag!r} is not a release tag (vX.Y.Z)")
        if args.sha256:
            fail("a tag push takes the digest from the tag annotation, not an input")
        emit(VERSION=final.group(1), KIND="final", DRY_RUN="false", RELEASE_TAG=tag)
        return
    if args.event == "workflow_dispatch":
        if not (final or rc):
            fail(f"{tag!r} is not a release tag (vX.Y.Z) or a pre-release tag (vX.Y.Z-rcN)")
        if args.dry_run_input not in ("true", ""):
            fail("a dispatch never publishes; dry_run=false is refused (publication is an annotated release-tag push)")
        sha = want_sha256(args.sha256 or "", "the sha256 input")
        version = (final or rc).group(1)
        emit(VERSION=version, KIND="final" if final else "rc", DRY_RUN="true", RELEASE_TAG="v" + version, WANT_SHA=sha)
        return
    fail(f"unsupported event {args.event!r}")


def load_json(path: str) -> object:
    with open(path, "r", encoding="utf-8") as fh:
        text = fh.read()
    try:
        return json.loads(text)
    except ValueError as exc:
        fail(f"{path} is not JSON: {exc}")


def cmd_tag_annotation(args: argparse.Namespace) -> None:
    tag = args.tag
    if not full(FINAL_TAG_RE, tag):
        fail(f"{tag!r} is not a release tag")
    commit = want_commit(args.commit, "commit")
    ref = load_json(args.ref_json)
    obj = load_json(args.tag_json)
    if not isinstance(ref, dict) or not isinstance(obj, dict):
        fail("ref and tag JSON must be objects")
    if ref.get("ref") != f"refs/tags/{tag}":
        fail(f"ref is {ref.get('ref')!r}, expected refs/tags/{tag}")
    target = ref.get("object") or {}
    if target.get("type") != "tag":
        fail(f"{tag} is a lightweight tag; it must be annotated with candidate-sha256 and source-sha")
    if obj.get("sha") != target.get("sha") or not full(COMMIT_RE, str(obj.get("sha"))):
        fail("tag object does not match the ref")
    if obj.get("tag") != tag:
        fail(f"tag object is named {obj.get('tag')!r}, not {tag}")
    tagged = obj.get("object") or {}
    if tagged.get("type") != "commit" or tagged.get("sha") != commit:
        fail(f"{tag} points at {tagged.get('type')} {tagged.get('sha')}, not commit {commit}")
    message = obj.get("message")
    if not isinstance(message, str):
        fail("tag object has no message")
    lines = message.split("\n")
    values = {}
    for key, regex in (("candidate-sha256", SHA256_RE), ("source-sha", COMMIT_RE)):
        hits = [line for line in lines if line.strip().lower().startswith(key)]
        if len(hits) != 1:
            fail(f"{tag} annotation must have exactly one {key} line (found {len(hits)})")
        m = re.fullmatch(re.escape(key) + r":[ \t]*(" + regex.pattern + r")[ \t]*", hits[0])
        if not m:
            fail(f"{tag} annotation {key} line is malformed")
        values[key] = m.group(1)
    if values["source-sha"] != commit:
        fail(f"{tag} is at {commit}, approved source-sha is {values['source-sha']}")
    emit(WANT_SHA=values["candidate-sha256"], WANT_SOURCE=values["source-sha"])


def load_releases(path: str) -> list:
    """Accepts one JSON array or a stream of arrays (gh api --paginate prints one per page)."""
    with open(path, "r", encoding="utf-8") as fh:
        text = fh.read()
    decoder = json.JSONDecoder()
    pos, out = 0, []
    while True:
        while pos < len(text) and text[pos].isspace():
            pos += 1
        if pos >= len(text):
            break
        try:
            page, pos = decoder.raw_decode(text, pos)
        except ValueError as exc:
            fail(f"releases JSON is malformed: {exc}")
        if not isinstance(page, list):
            fail("releases JSON must be an array (or a stream of arrays)")
        out.extend(page)
    return out


def classify(releases: list, version: str, source: str, sha: str | None) -> dict:
    tag = "v" + version
    asset_name = f"{SLUG}-{version}.zip"
    mine = [r for r in releases if isinstance(r, dict) and r.get("tag_name") == tag]
    if not mine:
        return {"STATE": "none", "REASON": f"no release for {tag}"}
    if len(mine) > 1:
        return {"STATE": "ambiguous", "REASON": f"{len(mine)} releases for {tag}"}
    rel = mine[0]
    rid = rel.get("id")
    if not isinstance(rid, int) or isinstance(rid, bool) or rid <= 0:
        fail("release has no numeric id")
    base = {"RELEASE_ID": rid}
    if rel.get("draft") is not True:
        return {**base, "STATE": "published", "REASON": f"release {tag} is not a draft"}
    if rel.get("target_commitish") != source:
        return {**base, "STATE": "draft-mismatch", "REASON": f"draft {tag} targets {rel.get('target_commitish')!r}, not {source}"}
    assets = rel.get("assets")
    if not isinstance(assets, list):
        fail("release assets are not a list")
    if not assets:
        return {**base, "STATE": "draft-empty", "REASON": f"draft {tag} has no asset"}
    names = [a.get("name") if isinstance(a, dict) else None for a in assets]
    if len(assets) != 1 or names[0] != asset_name:
        if all(n == asset_name for n in names) and all(a.get("state") != "uploaded" for a in assets):
            return {**base, "STATE": "draft-empty", "REASON": f"draft {tag} has only an incomplete upload"}
        return {**base, "STATE": "draft-mismatch", "REASON": f"draft {tag} must hold exactly one asset named {asset_name} (has {len(assets)})"}
    asset = assets[0]
    aid = asset.get("id")
    if not isinstance(aid, int) or isinstance(aid, bool) or aid <= 0:
        fail("asset has no numeric id")
    base["ASSET_ID"] = aid
    if asset.get("state") != "uploaded":
        return {**base, "STATE": "draft-empty", "REASON": f"{asset_name} upload is incomplete"}
    digest = asset.get("digest")
    if isinstance(digest, str) and digest:
        m = re.fullmatch(r"sha256:([0-9a-f]{64})", digest)
        if not m:
            return {**base, "STATE": "draft-mismatch", "REASON": f"{asset_name} digest is not a sha256"}
        base["ASSET_DIGEST"] = m.group(1)
        if sha is None:
            return {**base, "STATE": "staged-unverified", "REASON": "no expected sha256 given"}
        if m.group(1) != sha:
            return {**base, "STATE": "draft-mismatch", "REASON": f"{asset_name} sha256 is {m.group(1)}, expected {sha}"}
        return {**base, "STATE": "staged", "REASON": f"{asset_name} sha256 matches"}
    return {**base, "STATE": "staged-unverified", "REASON": "the asset API gave no digest; download and hash it"}


def cmd_release_state(args: argparse.Namespace) -> None:
    version = want_version(args.version)
    source = want_commit(args.source_sha)
    sha = want_sha256(args.sha256) if args.sha256 is not None else None
    result = classify(load_releases(args.releases_json), version, source, sha)
    if args.require_staged and result["STATE"] not in ("staged", "staged-unverified"):
        fail(f"release v{version} is not a staged draft ({result['STATE']}): {result['REASON']}")
    reason = re.sub(r"[\r\n]+", " ", str(result.pop("REASON")))
    emit(**result)
    print(f"REASON={reason}")


def cmd_guard_dest(args: argparse.Namespace) -> None:
    root = os.path.realpath(args.root)
    path = os.path.realpath(args.path)
    if "\n" in path or "\r" in path:
        fail("path contains a newline")
    if path == root or os.path.commonpath([root, path]) == root:
        fail(f"{args.path} resolves to {path}, which is the checkout or inside it ({root}); candidates live outside the checkout and dist/")
    if args.kind == "file" and os.path.lexists(path) and not os.path.isfile(path):
        fail(f"{path} is not a regular file")
    if args.kind == "dir" and os.path.lexists(path) and not os.path.isdir(path):
        fail(f"{path} is not a directory")
    emit(PATH=path)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    sub = parser.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("check")
    p.add_argument("zip")
    p.add_argument("--version", required=True)
    p.add_argument("--repo", required=True)
    p.add_argument("--source-sha", required=True)
    p.add_argument("--sha256")
    p.add_argument("--manifest-out")
    p.add_argument("--extract-to")
    p.set_defaults(func=cmd_check)

    p = sub.add_parser("manifest-dir")
    p.add_argument("dir")
    p.add_argument("--expect")
    p.add_argument("--manifest-out")
    p.set_defaults(func=cmd_manifest_dir)

    p = sub.add_parser("expected")
    p.add_argument("--repo", required=True)
    p.add_argument("--source-sha", required=True)
    p.set_defaults(func=cmd_expected)

    p = sub.add_parser("tag-info")
    p.add_argument("--event", required=True)
    p.add_argument("--tag", required=True)
    p.add_argument("--sha256", default="")
    p.add_argument("--dry-run-input", default="")
    p.set_defaults(func=cmd_tag_info)

    p = sub.add_parser("tag-annotation")
    p.add_argument("--tag", required=True)
    p.add_argument("--commit", required=True)
    p.add_argument("--ref-json", required=True)
    p.add_argument("--tag-json", required=True)
    p.set_defaults(func=cmd_tag_annotation)

    p = sub.add_parser("release-state")
    p.add_argument("--releases-json", required=True)
    p.add_argument("--version", required=True)
    p.add_argument("--source-sha", required=True)
    p.add_argument("--sha256")
    p.add_argument("--require-staged", action="store_true")
    p.set_defaults(func=cmd_release_state)

    p = sub.add_parser("guard-dest")
    p.add_argument("--root", required=True)
    p.add_argument("--path", required=True)
    p.add_argument("--kind", choices=("dir", "file"), required=True)
    p.set_defaults(func=cmd_guard_dest)

    args = parser.parse_args(argv)
    try:
        args.func(args)
    except CheckError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    except OSError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
