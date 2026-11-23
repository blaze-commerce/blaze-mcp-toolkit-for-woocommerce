#!/usr/bin/env python3
"""Fails a workflow that puts a ${{ }} expression inside a run script, or uses inputs.* anywhere
but as the value of an env mapping (NAME: ${{ ... }}). Prints "clean" or the offending lines.

  scan_workflow.py .github/workflows/deploy.yml
"""

import re
import sys


def main(path):
    lines = open(path, encoding="utf-8").read().split("\n")
    bad, run_indent = [], None
    for n, line in enumerate(lines, 1):
        indent = len(line) - len(line.lstrip(" "))
        if run_indent is not None and line.strip() and indent <= run_indent:
            run_indent = None
        if run_indent is not None:
            if "${{" in line:
                bad.append(f"{n}: expression inside a run script")
            continue
        m = re.match(r"^( *)(- )?run: *(.*)$", line)
        if m:
            if "${{" in m.group(3):
                bad.append(f"{n}: expression inside a run script")
            if m.group(3).startswith("|") or m.group(3).startswith(">") or m.group(3) == "":
                run_indent = len(m.group(1)) + (2 if m.group(2) else 0)
            continue
        if "inputs." in line and "${{" in line and not re.match(r"^ *[A-Z][A-Z0-9_]*: \$\{\{", line):
            bad.append(f"{n}: input used outside an env mapping")
    print("\n".join(bad) if bad else "clean")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1]))
