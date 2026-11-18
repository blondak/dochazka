"""Merge PCOV's executable line map across PHP processes and HTTP requests."""
import json
from pathlib import Path
import sys

root = (Path(__file__).resolve().parents[2] / "src").resolve()
files = {}
for report in Path(sys.argv[1]).iterdir():
    data = json.loads(report.read_text())
    for path, lines in (data or {}).items():
        relative = Path(path).relative_to(root)
        merged = files.setdefault(relative, {})
        for line, status in (lines or {}).items():
            merged[line] = merged.get(line, False) or (report.name.startswith("lines-") and status > 0)

missing = {p.relative_to(root) for p in root.rglob("*.php")} - files.keys()
if missing:
    raise SystemExit(f"Missing coverage data for {sorted(missing)}")
domains = {}
for path, lines in files.items():
    domain = domains.setdefault(path.parts[0], [0, 0])
    domain[0] += sum(lines.values())
    domain[1] += len(lines)
domains["**Total**"] = [sum(group[i] for group in domains.values()) for i in range(2)]
print("| PHP src/ | Covered / executable lines | Coverage |")
print("| --- | ---: | ---: |")
for domain, (covered, total) in sorted(domains.items(), key=lambda row: (row[0].startswith("*"), row[0])):
    print(f"| {domain} | {covered} / {total} | {100*covered/total:.1f}% |")
