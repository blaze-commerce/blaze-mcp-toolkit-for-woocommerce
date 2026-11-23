#!/usr/bin/env python3
"""Offline stand-in for the `gh` calls scripts/release-candidate.sh makes. Never touches GitHub.

State lives in $GH_STUB_STATE: releases.json (the API's release list) and assets/<id>.bin.
Every call is appended to calls.log. Knobs:
  GH_STUB_FAIL=upload,create,edit,api,download  make those operations fail (exit 1); a failing
                                                create still creates the draft without its asset,
                                                as an interrupted upload does
  GH_STUB_NO_DIGEST=1                           list assets without a digest field
  GH_STUB_PAGES=2                               print the release list as 2 concatenated pages
Any other gh command fails, so an unexpected call (delete, publish) cannot pass silently.
"""

import hashlib
import json
import os
import shutil
import sys

STATE = os.environ["GH_STUB_STATE"]
FAIL = set(filter(None, os.environ.get("GH_STUB_FAIL", "").split(",")))
REL = os.path.join(STATE, "releases.json")


def load():
    if not os.path.exists(REL):
        return []
    with open(REL) as fh:
        return json.load(fh)


def save(releases):
    with open(REL, "w") as fh:
        json.dump(releases, fh, indent=1)


def next_id(releases):
    ids = [r["id"] for r in releases] + [a["id"] for r in releases for a in r.get("assets", [])]
    return max(ids + [100]) + 1


def opts(args):
    """Splits gh-style arguments into flags and positionals."""
    flags, pos, i = {}, [], 0
    takes_value = {"--repo", "--target", "--title", "--notes", "--pattern", "--dir", "-H"}
    while i < len(args):
        a = args[i]
        if a in takes_value:
            flags[a] = args[i + 1]
            i += 2
        elif a.startswith("--"):
            flags[a] = True
            i += 1
        else:
            pos.append(a)
            i += 1
    return flags, pos


def find(releases, tag):
    hits = [r for r in releases if r["tag_name"] == tag]
    if len(hits) != 1:
        sys.exit(f"gh stub: {len(hits)} releases for {tag}")
    return hits[0]


def add_asset(releases, rel, path, clobber):
    name = os.path.basename(path)
    existing = [a for a in rel["assets"] if a["name"] == name]
    if existing and not clobber:
        sys.exit(f"gh stub: asset {name} already exists")
    rel["assets"] = [a for a in rel["assets"] if a["name"] != name]
    aid = next_id(releases)
    with open(path, "rb") as fh:
        data = fh.read()
    os.makedirs(os.path.join(STATE, "assets"), exist_ok=True)
    with open(os.path.join(STATE, "assets", f"{aid}.bin"), "wb") as fh:
        fh.write(data)
    rel["assets"].append({"id": aid, "name": name, "state": "uploaded", "size": len(data), "digest": "sha256:" + hashlib.sha256(data).hexdigest()})


def main(argv):
    with open(os.path.join(STATE, "calls.log"), "a") as fh:
        fh.write(" ".join(argv) + "\n")
    if not argv:
        sys.exit("gh stub: no command")
    releases = load()
    if argv[0] == "api":
        if "api" in FAIL:
            sys.exit("gh stub: api failure (injected)")
        flags, pos = opts(argv[1:])
        if len(pos) != 1 or not pos[0].endswith("/releases?per_page=100") or "--paginate" not in flags:
            sys.exit(f"gh stub: unsupported api call {argv}")
        out = []
        for r in releases:
            r = json.loads(json.dumps(r))
            if os.environ.get("GH_STUB_NO_DIGEST"):
                for a in r.get("assets", []):
                    a.pop("digest", None)
            out.append(r)
        pages = int(os.environ.get("GH_STUB_PAGES", "1"))
        if pages > 1 and len(out) > 1:
            half = len(out) // 2
            sys.stdout.write(json.dumps(out[:half]) + json.dumps(out[half:]) + "\n")
        else:
            sys.stdout.write(json.dumps(out) + "\n")
        return
    if argv[0] != "release" or len(argv) < 3:
        sys.exit(f"gh stub: unsupported command {argv}")
    sub, tag = argv[1], argv[2]
    flags, pos = opts(argv[3:])
    if flags.get("--repo") != "blaze-commerce/blaze-mcp-toolkit-for-woocommerce":
        sys.exit("gh stub: wrong --repo")
    if sub == "create":
        if "--draft" not in flags or not flags.get("--target"):
            sys.exit("gh stub: release create without --draft/--target")
        if any(r["tag_name"] == tag for r in releases):
            sys.exit("gh stub: release exists")
        rel = {"id": next_id(releases), "tag_name": tag, "draft": True, "target_commitish": flags["--target"], "name": flags.get("--title"), "assets": []}
        releases.append(rel)
        if "create" in FAIL or "upload" in FAIL:
            save(releases)
            sys.exit("gh stub: asset upload failed (injected); the draft was created without it")
        for p in pos:
            add_asset(releases, rel, p, False)
        save(releases)
    elif sub == "upload":
        if "upload" in FAIL:
            sys.exit("gh stub: upload failed (injected)")
        rel = find(releases, tag)
        if not rel["draft"]:
            sys.exit("gh stub: refusing to upload to a published release")
        for p in pos:
            add_asset(releases, rel, p, "--clobber" in flags)
        save(releases)
    elif sub == "edit":
        if "edit" in FAIL:
            sys.exit("gh stub: edit failed (injected)")
        if "--draft" not in flags:
            sys.exit("gh stub: release edit without --draft would publish")
        rel = find(releases, tag)
        if flags.get("--target"):
            rel["target_commitish"] = flags["--target"]
        save(releases)
    elif sub == "download":
        if "download" in FAIL:
            sys.exit("gh stub: download failed (injected)")
        rel = find(releases, tag)
        hits = [a for a in rel["assets"] if a["name"] == flags.get("--pattern")]
        if len(hits) != 1:
            sys.exit("gh stub: no matching asset")
        shutil.copyfile(os.path.join(STATE, "assets", f"{hits[0]['id']}.bin"), os.path.join(flags["--dir"], hits[0]["name"]))
    else:
        sys.exit(f"gh stub: unsupported release command {sub}")


if __name__ == "__main__":
    main(sys.argv[1:])
