#!/usr/bin/env python3
"""Render E-tiGo client reports from Markdown to A4 PDF.

The PDF is written next to each Markdown file (same name, .pdf), in the
house style used by the client decision reports: green question/decision
headings, green decision boxes, NEW/UPDATED badges, dark table headers and
DejaVu fonts (so ₦, → and CO₂ print correctly).

Setup (once):
    pip install -r doc/tools/requirements.txt

Usage, from the repository root:
    python3 doc/tools/build_client_pdf.py doc/client/OPEN_QUESTIONS_DECISIONS.md
    python3 doc/tools/build_client_pdf.py doc/client/OPEN_QUESTIONS_DECISIONS.md doc/client/SCHEDULED_RIDES_DECISIONS.md

Markdown features: tables, `code`, > blockquotes (rendered as decision
boxes), and inline HTML such as <span class="badge new">NEW</span>,
<span class="badge updated">UPDATED</span>, <h2 class="section">,
<p class="impl"> and <div class="summary" markdown="1">. Links to other
.md files become links to their .pdf versions.

Only the reports already built with this script use its style
(OPEN_QUESTIONS_DECISIONS, SCHEDULED_RIDES_DECISIONS); the older client PDFs
were made with another tool and will look different if rebuilt here.
"""

import re
import sys
from pathlib import Path

import markdown
from weasyprint import HTML

CSS = """
@page { size: A4; margin: 18mm 16mm 20mm 16mm;
  @bottom-center { content: "Page " counter(page) " of " counter(pages); font: 8pt 'DejaVu Sans', sans-serif; color: #888; } }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 1.5; color: #222; }
h1 { font-size: 19pt; margin: 0 0 4pt; padding-bottom: 6pt; border-bottom: 2.5pt solid #1a7f37; }
h2 { font-size: 13pt; margin: 18pt 0 8pt; page-break-after: avoid; }
h2.section { background: #eef2f7; border-left: 4pt solid #1f2d3d; padding: 6pt 10pt; font-size: 11.5pt; }
h3 { font-size: 11.5pt; margin: 16pt 0 4pt; padding-bottom: 3pt; border-bottom: 0.75pt solid #ddd; page-break-after: avoid; }
h4 { font-size: 9.5pt; color: #1a7f37; margin: 8pt 0 3pt; page-break-after: avoid; }
p { margin: 3pt 0 6pt; }
blockquote { margin: 4pt 0 8pt; padding: 6pt 10pt; background: #f3faf4; border-left: 3pt solid #1a7f37; page-break-inside: avoid; }
blockquote p { margin: 2pt 0; }
ul, ol { margin: 2pt 0 6pt; padding-left: 16pt; }
li { margin: 1pt 0; }
code { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.6pt; background: #f1f1f1; padding: 0 2pt; border-radius: 2pt; }
table { width: 100%; border-collapse: collapse; margin: 6pt 0 10pt; font-size: 7.8pt; page-break-inside: auto; }
th { background: #1f2d3d; color: #fff; text-align: left; padding: 4pt 5pt; }
td { border-bottom: 0.5pt solid #ddd; padding: 3.5pt 5pt; vertical-align: top; }
tr { page-break-inside: avoid; }
tr:nth-child(even) td { background: #fafafa; }
hr { border: 0; border-top: 0.75pt solid #ddd; margin: 10pt 0; }
a { color: #1a5fb4; text-decoration: none; }
.summary { background: #f6f8fa; border: 0.75pt solid #e1e4e8; padding: 6pt 12pt; margin: 8pt 0; }
.badge { display: inline-block; font-size: 6.5pt; font-weight: bold; color: #fff; padding: 0.5pt 4pt; border-radius: 2pt; vertical-align: middle; }
.badge.new { background: #6f42c1; }
.badge.updated { background: #d97706; }
.impl { background: #f6f8fa; border: 0.5pt solid #e1e4e8; padding: 4pt 8pt; font-size: 8.3pt; }
.footer { text-align: center; color: #777; font-size: 8pt; margin-top: 16pt; }
"""


def build(source: Path) -> Path:
    """Render one Markdown file and return the PDF path."""
    text = source.read_text(encoding="utf-8")
    # Links to sibling .md files point at their PDF versions in the PDF.
    text = re.sub(
        r"\]\(([^)#]+?)\.md(#[^)]*)?\)",
        lambda m: f"]({m.group(1)}.pdf{m.group(2) or ''})",
        text,
    )
    body = markdown.markdown(text, extensions=["tables", "md_in_html", "sane_lists", "fenced_code"])
    heading = re.search(r"^# (.+)$", text, re.M)
    title = heading.group(1) if heading else source.stem
    html = (
        "<!doctype html><html><head><meta charset='utf-8'>"
        f"<title>{title}</title><style>{CSS}</style></head><body>{body}</body></html>"
    )
    target = source.with_suffix(".pdf")
    HTML(string=html, base_url=str(source.parent)).write_pdf(target)
    return target


def main(paths: list[str]) -> int:
    if not paths:
        print(__doc__)
        return 1

    for path in paths:
        source = Path(path)
        if source.suffix != ".md" or not source.is_file():
            print(f"Skipping {path}: not a Markdown file", file=sys.stderr)
            continue
        print(f"Built {build(source)}")

    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
