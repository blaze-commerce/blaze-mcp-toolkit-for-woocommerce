#!/usr/bin/env python3
"""Builds release zips for tests/release/test-promotion.sh, faithful or deliberately broken.

  make_zip.py OUT SRC_DIR [OPS_JSON]

SRC_DIR holds the package root directory (blaze-mcp-toolkit-for-woocommerce/). Every file under it
is added with its directory entries, in sorted order. OPS_JSON is a list of operations applied as
the archive is written (names are raw archive names, kept exactly as given):
  ["add", NAME, TEXT]        extra regular file entry
  ["dir", NAME]              extra directory entry
  ["symlink", NAME, TARGET]  symlink entry (unix mode 120777)
  ["fifo", NAME]             FIFO entry (unix mode 010644)
  ["dup", NAME]              a second entry for an existing file
  ["drop", NAME]             leave an existing file out
  ["replace", NAME, TEXT]    write TEXT instead of the file's bytes
  ["flip", NAME]             change the file's last byte (same size, different bytes)
  ["encrypt", NAME]          set the encrypted flag on an existing file's entry
  ["prepend", TEXT]          put bytes in front of the archive
"""

import json
import os
import sys
import zipfile


def info(name, mode):
    zi = zipfile.ZipInfo(name, date_time=(2026, 11, 22, 0, 0, 0))
    zi.create_system = 3
    zi.external_attr = mode << 16
    zi.compress_type = zipfile.ZIP_DEFLATED
    return zi


def main(out, src, ops):
    files, dirs = [], []
    for base, dnames, fnames in os.walk(src):
        dnames.sort()
        rel_base = os.path.relpath(base, src).replace(os.sep, "/")
        if rel_base != ".":
            dirs.append(rel_base + "/")
        for f in sorted(fnames):
            rel = f if rel_base == "." else f"{rel_base}/{f}"
            files.append(rel)
    drop = {o[1] for o in ops if o[0] == "drop"}
    replace = {o[1]: o[2].encode() for o in ops if o[0] == "replace"}
    encrypt = {o[1] for o in ops if o[0] == "encrypt"}
    dup = {o[1] for o in ops if o[0] == "dup"}
    flip = {o[1] for o in ops if o[0] == "flip"}
    tmp = out + ".tmp"
    with zipfile.ZipFile(tmp, "w") as zf:
        for d in sorted(dirs):
            zi = info(d, 0o40755)
            zi.compress_type = zipfile.ZIP_STORED
            zi.external_attr |= 0x10
            zf.writestr(zi, b"")
        for rel in sorted(files):
            if rel in drop:
                continue
            with open(os.path.join(src, rel), "rb") as fh:
                data = replace.get(rel, fh.read())
            if rel in flip and data:
                data = data[:-1] + bytes([data[-1] ^ 0x01])
            for _ in range(2 if rel in dup else 1):
                zi = info(rel, 0o100644)
                zf.writestr(zi, data)
                if rel in encrypt:
                    zf.filelist[-1].flag_bits |= 0x1
        for o in ops:
            if o[0] == "add":
                zf.writestr(info(o[1], 0o100644), o[2].encode())
            elif o[0] == "dir":
                zi = info(o[1], 0o40755)
                zi.external_attr |= 0x10
                zf.writestr(zi, b"")
            elif o[0] == "symlink":
                zf.writestr(info(o[1], 0o120777), o[2].encode())
            elif o[0] == "fifo":
                zf.writestr(info(o[1], 0o010644), b"")
    prefix = b"".join(o[1].encode() for o in ops if o[0] == "prepend")
    with open(tmp, "rb") as fh:
        body = fh.read()
    os.remove(tmp)
    with open(out, "wb") as fh:
        fh.write(prefix + body)


if __name__ == "__main__":
    import warnings

    warnings.simplefilter("ignore")  # duplicate-name warnings are the point of the "dup" op
    main(sys.argv[1], sys.argv[2], json.loads(sys.argv[3]) if len(sys.argv) > 3 else [])
