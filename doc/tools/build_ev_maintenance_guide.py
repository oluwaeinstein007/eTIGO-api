#!/usr/bin/env python3
"""Build the EV charging, vehicle maintenance and repair requests guide (A4 PDF)
for product design and frontend engineers.

Setup (once):
    pip install -r doc/tools/requirements.txt

Usage, from the repository root:
    python3 doc/tools/build_ev_maintenance_guide.py doc/technical/EV-Charging-and-Vehicle-Maintenance-Guide.pdf

The content lives in this file. Keep it in step with API_REFERENCE.md when
the EV, maintenance or repair endpoints change. Needs the DejaVu fonts
(fonts-dejavu-core on Debian/Ubuntu) so that the naira sign prints.
"""
import sys
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_LEFT
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import (
    BaseDocTemplate, PageTemplate, Frame, Paragraph, Spacer, Table, TableStyle,
    PageBreak, Preformatted, KeepTogether, CondPageBreak,
)
from reportlab.platypus.tableofcontents import TableOfContents
from reportlab.graphics.shapes import Drawing, Rect, String, Line, Polygon

OUT = sys.argv[1]

F = "/usr/share/fonts/truetype/dejavu/"
pdfmetrics.registerFont(TTFont("Sans", F + "DejaVuSans.ttf"))
pdfmetrics.registerFont(TTFont("Sans-Bold", F + "DejaVuSans-Bold.ttf"))
pdfmetrics.registerFont(TTFont("Mono", F + "DejaVuSansMono.ttf"))
pdfmetrics.registerFont(TTFont("Mono-Bold", F + "DejaVuSansMono-Bold.ttf"))
from reportlab.pdfbase.pdfmetrics import registerFontFamily
registerFontFamily("Sans", normal="Sans", bold="Sans-Bold", italic="Sans", boldItalic="Sans-Bold")

INK = colors.HexColor("#1F2A2E")
MUTED = colors.HexColor("#5B6B70")
ACCENT = colors.HexColor("#0B6E4F")
ACCENT_LIGHT = colors.HexColor("#E3F2EC")
RULE = colors.HexColor("#D5DEDB")
CODE_BG = colors.HexColor("#F4F6F5")
WARN = colors.HexColor("#9A5B00")
WARN_BG = colors.HexColor("#FFF4E0")
NOTE_BG = colors.HexColor("#EAF2FB")
NOTE = colors.HexColor("#1F5A96")

# Status chip colours for the diagrams.
C_WAIT = colors.HexColor("#FDF1D6")
C_GOOD = colors.HexColor("#DDF1E6")
C_LIVE = colors.HexColor("#D9E8FA")
C_END = colors.HexColor("#ECEFEE")
C_BAD = colors.HexColor("#FBE1DE")

W, H = A4
MARGIN = 18 * mm
CONTENT_W = W - 2 * MARGIN

s_body = ParagraphStyle("body", fontName="Sans", fontSize=9.2, leading=13.2, textColor=INK, spaceAfter=5)
s_small = ParagraphStyle("small", parent=s_body, fontSize=8, leading=10.6, spaceAfter=0)
s_cell = ParagraphStyle("cell", parent=s_body, fontSize=7.9, leading=10.2, spaceAfter=0)
s_cell_b = ParagraphStyle("cellb", parent=s_cell, fontName="Sans-Bold")
s_head_cell = ParagraphStyle("hcell", parent=s_cell, fontName="Sans-Bold", textColor=colors.white)
s_bullet = ParagraphStyle("bullet", parent=s_body, leftIndent=12, bulletIndent=2, spaceAfter=2.5)
s_part = ParagraphStyle("part", fontName="Sans-Bold", fontSize=22, leading=27, textColor=ACCENT, spaceAfter=4)
s_part_sub = ParagraphStyle("partsub", parent=s_body, fontSize=10.5, leading=15, textColor=MUTED, spaceAfter=14)
s_h1 = ParagraphStyle("h1", fontName="Sans-Bold", fontSize=14.5, leading=19, textColor=INK, spaceBefore=10, spaceAfter=6)
s_h2 = ParagraphStyle("h2", fontName="Sans-Bold", fontSize=11.2, leading=15, textColor=ACCENT, spaceBefore=9, spaceAfter=4)
s_h3 = ParagraphStyle("h3", fontName="Sans-Bold", fontSize=9.6, leading=13, textColor=INK, spaceBefore=6, spaceAfter=3)
s_code = ParagraphStyle("code", fontName="Mono", fontSize=7.1, leading=9.1, textColor=INK)
s_endpoint = ParagraphStyle("ep", fontName="Mono", fontSize=8.2, leading=11, textColor=INK)


def esc(t):
    return t.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def c(t):
    """Inline code."""
    return f'<font name="Mono" size="8" color="#0B4F3A">{esc(t)}</font>'


def P(t, st=s_body):
    return Paragraph(t, st)


def bullets(items):
    return [Paragraph(i, s_bullet, bulletText="•") for i in items]


class DocTemplate(BaseDocTemplate):
    def afterFlowable(self, f):
        if isinstance(f, Paragraph) and getattr(f, "toc_level", None) is not None:
            key = f"h{id(f)}"
            self.canv.bookmarkPage(key)
            self.canv.addOutlineEntry(f.getPlainText(), key, level=f.toc_level, closed=False)
            self.notify("TOCEntry", (f.toc_level, f.getPlainText(), self.page, key))


def heading(text, style, level):
    p = Paragraph(text, style)
    p.toc_level = level
    return p


def part(title, sub):
    return [PageBreak(), heading(title, s_part, 0), P(sub, s_part_sub)]


def h1(t):
    return [CondPageBreak(60 * mm), heading(t, s_h1, 1)]


def h2(t):
    return [CondPageBreak(35 * mm), Paragraph(t, s_h2)]


def h3(t):
    return [CondPageBreak(25 * mm), Paragraph(t, s_h3)]


def table(rows, widths, header=True, zebra=True, first_col_bold=False):
    data = []
    for r_i, row in enumerate(rows):
        out = []
        for c_i, cell in enumerate(row):
            if isinstance(cell, str):
                if header and r_i == 0:
                    st = s_head_cell
                elif first_col_bold and c_i == 0:
                    st = s_cell_b
                else:
                    st = s_cell
                out.append(Paragraph(cell, st))
            else:
                out.append(cell)
        data.append(out)
    total = sum(widths)
    widths = [w / total * CONTENT_W for w in widths]
    t = Table(data, colWidths=widths, repeatRows=1 if header else 0)
    style = [
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("TOPPADDING", (0, 0), (-1, -1), 3.5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3.5),
        ("LINEBELOW", (0, 0), (-1, -1), 0.4, RULE),
    ]
    if header:
        style += [("BACKGROUND", (0, 0), (-1, 0), ACCENT)]
    if zebra:
        for i in range(1 if header else 0, len(data)):
            if i % 2 == 0:
                style.append(("BACKGROUND", (0, i), (-1, i), colors.HexColor("#F7F9F8")))
    t.setStyle(TableStyle(style))
    return [t, Spacer(1, 7)]


def callout(kind, text):
    bg, fg, label = {
        "note": (NOTE_BG, NOTE, "Note"),
        "gap": (WARN_BG, WARN, "Gap"),
        "design": (ACCENT_LIGHT, ACCENT, "Design tip"),
        "pick": (WARN_BG, WARN, "Not client-confirmed"),
    }[kind]
    p = Paragraph(f'<font name="Sans-Bold" color="{fg.hexval().replace("0x", "#")}">{label}.</font> {text}', s_small)
    t = Table([[p]], colWidths=[CONTENT_W])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), bg),
        ("LINEBEFORE", (0, 0), (0, -1), 2.5, fg),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    return [t, Spacer(1, 7)]


def endpoint(method, path, who):
    col = {"GET": "#1F5A96", "POST": "#0B6E4F", "PUT": "#9A5B00", "PATCH": "#9A5B00", "DELETE": "#A3312A"}[method]
    p = Paragraph(
        f'<font name="Mono-Bold" color="{col}">{method}</font>&nbsp;&nbsp;{esc(path)}'
        f'&nbsp;&nbsp;&nbsp;<font name="Sans" size="7.5" color="#5B6B70">{who}</font>', s_endpoint)
    t = Table([[p]], colWidths=[CONTENT_W])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), CODE_BG),
        ("LEFTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    return [t, Spacer(1, 4)]


def code(text, title=None):
    pre = Preformatted(text.strip("\n"), s_code, maxLineLength=108, splitChars=", ", newLineChars="      ")
    rows = []
    if title:
        rows.append([Paragraph(f'<font name="Sans-Bold" size="7.5" color="#5B6B70">{esc(title)}</font>', s_small)])
    rows.append([pre])
    t = Table(rows, colWidths=[CONTENT_W])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), CODE_BG),
        ("BOX", (0, 0), (-1, -1), 0.4, RULE),
        ("LEFTPADDING", (0, 0), (-1, -1), 8),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
    ]))
    return [KeepTogether([t]), Spacer(1, 8)]


# ---------------------------------------------------------------- diagrams

def _box(d, x, y, w, h, title, sub, fill):
    d.add(Rect(x, y, w, h, rx=5, ry=5, fillColor=fill, strokeColor=colors.HexColor("#9DB0AA"), strokeWidth=0.6))
    d.add(String(x + w / 2, y + h - 13, title, fontName="Sans-Bold", fontSize=7.8, fillColor=INK, textAnchor="middle"))
    if sub:
        for i, line in enumerate(sub.split("\n")):
            d.add(String(x + w / 2, y + h - 24 - i * 9, line, fontName="Sans", fontSize=6.6, fillColor=MUTED, textAnchor="middle"))


def _arrow(d, x1, y1, x2, y2, label=None, lx=None, ly=None, anchor="middle", col=MUTED):
    d.add(Line(x1, y1, x2, y2, strokeColor=col, strokeWidth=0.9))
    import math
    a = math.atan2(y2 - y1, x2 - x1)
    s = 4.5
    p1 = (x2 - s * math.cos(a - 0.45), y2 - s * math.sin(a - 0.45))
    p2 = (x2 - s * math.cos(a + 0.45), y2 - s * math.sin(a + 0.45))
    d.add(Polygon([x2, y2, p1[0], p1[1], p2[0], p2[1]], fillColor=col, strokeColor=col, strokeWidth=0.1))
    if label:
        for i, line in enumerate(label.split("\n")):
            d.add(String(lx if lx is not None else (x1 + x2) / 2, (ly if ly is not None else (y1 + y2) / 2 + 3) - i * 8,
                         line, fontName="Sans", fontSize=6.4, fillColor=ACCENT, textAnchor=anchor))


def charge_request_diagram():
    w = CONTENT_W
    d = Drawing(w, 205)
    bw, bh = 80, 40
    gap = (w - 5 * bw) / 4
    xs = [i * (bw + gap) for i in range(5)]
    top = 140
    _box(d, xs[0], top, bw, bh, "pending_review", "driver submitted", C_WAIT)
    _box(d, xs[1], top, bw, bh, "confirmed", "slot booked,\nnothing held yet", C_WAIT)
    _box(d, xs[2], top, bw, bh, "reserved", "stall held, fee taken\n30 min to check in", C_GOOD)
    _box(d, xs[3], top, bw, bh, "active", "charging", C_LIVE)
    _box(d, xs[4], top, bw, bh, "completed", "stall released", C_END)
    mid = top + bh / 2
    above = top + bh + 14
    _arrow(d, xs[0] + bw, mid, xs[1], mid, "Ops\nconfirm", ly=above)
    _arrow(d, xs[1] + bw, mid, xs[2], mid, "slot starts,\nstall free", ly=above)
    _arrow(d, xs[2] + bw, mid, xs[3], mid, "driver taps\nStart", ly=above)
    _arrow(d, xs[3] + bw, mid, xs[4], mid, "driver taps\nFinish", ly=above)
    # queued branch
    qy = 72
    _box(d, xs[2], qy, bw, bh, "queued", "station full,\nnext in line", C_WAIT)
    _arrow(d, xs[1] + bw / 2, top, xs[2], qy + bh / 2, "slot starts,\nstation full", lx=xs[1] + bw / 2 + 4, ly=qy + bh / 2 + 18, anchor="start")
    _arrow(d, xs[2] + bw / 2 + 12, qy + bh, xs[2] + bw / 2 + 12, top, "stall frees,\nfee taken", lx=xs[2] + bw / 2 + 17, ly=qy + bh + 16, anchor="start")
    # end states
    ey = 4
    _box(d, xs[0], ey, bw, bh - 4, "cancelled", "declined / cancelled", C_BAD)
    _box(d, xs[1] + 20, ey, bw, bh - 4, "expired", "missed", C_BAD)
    d.add(String(xs[1] + bw + 40, ey + 20, "Any open status can end early in cancelled or expired.", fontName="Sans", fontSize=7, fillColor=MUTED))
    d.add(String(xs[1] + bw + 40, ey + 10, "See “Ways a request ends early” below.", fontName="Sans", fontSize=7, fillColor=MUTED))
    return [d, Spacer(1, 6)]


def instant_diagram():
    w = CONTENT_W
    d = Drawing(w, 150)
    bw, bh = 96, 40
    _box(d, 0, 55, bw, bh, "Driver taps Reserve", "at a station", C_LIVE)
    x2 = 175
    _box(d, x2, 105, bw, bh, "reserved (201)", "free stall held,\nfee taken now", C_GOOD)
    _box(d, x2, 55, bw, bh, "queued (200)", "a stall frees within\n15 min; no fee yet", C_WAIT)
    _box(d, x2, 5, bw, bh, "retry (200)", "nothing created;\ntry again later", C_END)
    _arrow(d, bw, 75, x2, 125)
    _arrow(d, bw, 75, x2, 75)
    _arrow(d, bw, 75, x2, 25)
    x3 = 345
    _box(d, x3, 105, 130, bh, "same as request flow", "Start → active → Finish", C_END)
    _arrow(d, x2 + bw, 125, x3, 125)
    _arrow(d, x2 + bw, 75, x3 + 20, 105, "stall frees", lx=x2 + bw + 30, ly=92, anchor="start")
    return [d, Spacer(1, 6)]


def record_review_diagram():
    w = CONTENT_W
    d = Drawing(w, 80)
    bw, bh = 110, 40
    _box(d, 0, 20, bw, bh, "Driver logs work", "own car, proof attached", C_LIVE)
    _box(d, 165, 20, bw, bh, "pending", "“Awaiting review”", C_WAIT)
    _box(d, 330, 45, bw + 20, 32, "approved", "due dates recalculated", C_GOOD)
    _box(d, 330, 2, bw + 20, 32, "rejected", "reason shown; resubmit", C_BAD)
    _arrow(d, bw, 40, 165, 40)
    _arrow(d, 165 + bw, 40, 330, 61, "Ops approve", lx=300, ly=58)
    _arrow(d, 165 + bw, 40, 330, 18, "Ops reject", lx=300, ly=17)
    return [d, Spacer(1, 6)]


def paper_timeline():
    w = CONTENT_W
    d = Drawing(w, 92)
    y = 42
    segs = [
        ("Up to date", "ok", C_GOOD, 0.20),
        ("Due soon", "14 days before expiry", C_WAIT, 0.22),
        ("Expired, grace", "7 days: warning only,\ncan still go online", C_BAD, 0.30),
        ("Blocked", "can't go online until a\nrenewed paper is approved", colors.HexColor("#F2B8B0"), 0.28),
    ]
    x = 0
    for title, sub, fill, frac in segs:
        sw = frac * w
        d.add(Rect(x, y, sw - 2, 26, fillColor=fill, strokeColor=None))
        d.add(String(x + 5, y + 10, title, fontName="Sans-Bold", fontSize=7.8, fillColor=INK))
        for i, line in enumerate(sub.split("\n")):
            d.add(String(x + 5, y - 11 - i * 8.5, line, fontName="Sans", fontSize=6.7, fillColor=MUTED))
        x += sw
    marks = [(0.42, "expires 1 Nov"), (0.72, "blocked from 9 Nov")]
    for frac, label in marks:
        mx = frac * w - 1
        d.add(Line(mx, y + 26, mx, y + 36, strokeColor=INK, strokeWidth=0.7))
        d.add(String(mx, y + 40, label, fontName="Sans-Bold", fontSize=7, fillColor=INK, textAnchor="middle"))
    d.add(String(0.20 * w, y + 40, "18 Oct", fontName="Sans", fontSize=7, fillColor=MUTED, textAnchor="middle"))
    return [d, Spacer(1, 6)]


# ---------------------------------------------------------------- pages

def on_page(canv, doc):
    canv.saveState()
    canv.setFont("Sans", 7.2)
    canv.setFillColor(MUTED)
    canv.drawString(MARGIN, 10 * mm, "E-tiGo · EV charging & vehicle maintenance · design and frontend guide")
    canv.drawRightString(W - MARGIN, 10 * mm, f"Page {doc.page}")
    canv.setStrokeColor(RULE)
    canv.setLineWidth(0.4)
    canv.line(MARGIN, 13 * mm, W - MARGIN, 13 * mm)
    canv.restoreState()


def on_cover(canv, doc):
    canv.saveState()
    canv.setFillColor(ACCENT)
    canv.rect(0, H - 92 * mm, W, 92 * mm, fill=1, stroke=0)
    canv.setFillColor(colors.white)
    canv.setFont("Sans", 10)
    canv.drawString(MARGIN, H - 30 * mm, "E-tiGo · Design & frontend reference")
    canv.setFont("Sans-Bold", 30)
    canv.drawString(MARGIN, H - 50 * mm, "EV Charging &")
    canv.drawString(MARGIN, H - 63 * mm, "Vehicle Maintenance")
    canv.setFont("Sans", 11)
    canv.drawString(MARGIN, H - 77 * mm, "Flows, screens, fields and API responses, as built in the backend")
    canv.restoreState()


story = []

# ---------------------------------------------------------------- cover
story.append(Spacer(1, 98 * mm))
story += table([
    ["Prepared for", "Product design (admin dashboard and driver app) and frontend / mobile engineers"],
    ["Date", "28 October 2026"],
    ["Backend version", "staging, after the EV, maintenance and repair gaps PR (28 Oct 2026, revision 2)"],
    ["API base URL", c("https://<staging-host>/api/v1") + " — every path in this guide starts here"],
    ["Postman", "\u201cE-tiGo API v1\u201d collection: Driver \u203a EV Reservations, Repairs, Vehicle Maintenance; Admin \u203a EV Station Management, "
                "EV Charge Requests, EV Reservations, Repair Requests, Vehicle Maintenance"],
    ["Status", "Everything in this guide is built. Revision 2 adds the hire-purchase fee waiver, the Operations screens for all "
               "reservations and for repair requests, and the fields listed in Part D."],
], [28, 72], header=False, zebra=False, first_col_bold=True)[0:1]
story.append(Spacer(1, 10))
story.append(P(
    "<b>Why this document exists.</b> At Friday's presentation the client asked about drivers requesting EV charging and "
    "admins approving those requests. The PRD does not describe the screens or fields. This guide documents what the "
    "backend already does, so design and frontend can build against it without guessing. It also covers vehicle "
    "maintenance, which shares the same driver “my vehicle” area."))
story.append(P(
    "<b>How to use it.</b> Each part starts with what the feature does and the rules behind it, then goes screen by "
    "screen: what the screen is for, which endpoint feeds it, every field you can show, the actions available and "
    "the errors to handle. Example responses are at the end of each part."))

# ---------------------------------------------------------------- contents
story.append(PageBreak())
story.append(P("Contents", s_h1))
toc = TableOfContents()
toc.levelStyles = [
    ParagraphStyle("t0", fontName="Sans-Bold", fontSize=10, leading=15, leftIndent=0, textColor=ACCENT, spaceBefore=6),
    ParagraphStyle("t1", fontName="Sans", fontSize=8.8, leading=12.5, leftIndent=12, textColor=INK),
]
toc.dotsMinLevel = 1
story.append(toc)

# ---------------------------------------------------------------- conventions
story += part("Before you start", "Conventions shared by every endpoint in this guide.")
story += table([
    ["Topic", "What to know"],
    ["Auth", "Every call sends " + c("Authorization: Bearer <token>") + ". Driver endpoints need a driver account; "
             "admin endpoints need an admin account with the <b>Operations</b> role (Super Admin can use them too). "
             "The public station list and station detail need no login."],
    ["Times", "Timestamps are ISO 8601 in UTC, e.g. " + c("2026-10-28T13:30:00.000000Z") + ". Always display them in "
              "Lagos time (WAT, UTC+1). Dates without a time (" + c("2026-11-01") + ") are calendar dates in Lagos."],
    ["Display strings", "A few EV fields are already formatted for display and are <b>not</b> timestamps: "
                        + c("requested_time") + ", " + c("confirmed_slot") + ", " + c("check_in_time") + " (e.g. \u201c02:30 pm\u201d). "
                        "Use " + c("confirmed_slot_at") + " and " + c("check_in_deadline") + " for any date logic."],
    ["Status fields", "EV objects send status as an object: " + c('{"value": "reserved", "label": "Reserved"}') +
                      ". Use " + c("value") + " in code and " + c("label") + " on screen. Maintenance objects send two flat fields: "
                      + c("status") + " and " + c("status_label") + "."],
    ["Money", c("fee_amount") + " is <b>naira</b> as a string (" + c('"500.00"') + "); " + c("fee_amount_kobo") + " is the same in kobo (" + c("50000") + "). "
              + c("pricing_per_kwh") + " on stations is <b>kobo</b> as an integer (" + c("15000") + " = \u20a6150.00). Prefer the kobo fields."],
    ["Lists", "Paginated lists return " + c("meta: {current_page, last_page, per_page, total}") + ". Pass " + c("?page=") +
              " and, where supported, " + c("?per_page=") + " (max 100)."],
    ["Errors", c("422") + " with " + c("{message}") + " or " + c("{message, errors: {field: [..]}}") + " for validation and refused actions; "
               + c("403") + " for someone else's record; " + c("404") + " when not found. Show " + c("message") +
               " to the user as-is: the backend writes it in plain, user-facing language."],
    ["File uploads", "Use " + c("multipart/form-data") + ". Accepted: jpg, jpeg, png, pdf (repairs also accept webp), up to 10 MB."],
    ["Numbers in copy", "Never hard-code the fee, minutes or days in app text. " + c("GET /config") + " returns " + c("ev_charging") + " ("
                        + c("reservation_fee_kobo, check_in_minutes, free_cancellation_minutes, max_queue_size, retry_delay_minutes") + ") and "
                        + c("vehicle_maintenance") + " (" + c("due_soon_days, legal_grace_days, block_on_expired_papers") + "). Operations can change them."],
], [18, 82], first_col_bold=True)

# ================================================================ PART A
story += part("Part A — EV charging",
              "Drivers of electric and plug-in hybrid cars get a charging stall at an E-tiGo station. "
              "Operations run the stations and review drivers' charging requests.")

story += h1("A1. What the feature does")
story.append(P("A driver can get a stall in two ways:"))
story += bullets([
    "<b>Request charging (reviewed by Operations).</b> The driver submits their battery level and a preferred date and time. "
    "Operations confirms a slot (and can move it to another station) or declines with a reason. When the slot starts, the "
    "system automatically holds a free stall for the driver, or puts them first in line if the station is full. "
    "<i>This is the “request and approve” flow the client described.</i>",
    "<b>Reserve now (instant, no review).</b> The driver taps Reserve on a station. The system holds a free stall at once, or "
    "queues the driver if a stall is about to free up, or tells them to try again later.",
])
story.append(P("Either way the driver then drives to the station, taps <b>Start charging</b> at the stall, and taps "
               "<b>Finish</b> when done. Operations manage stations and stalls and watch occupancy live."))
story += h2("Who can use it")
story += bullets([
    "Only drivers whose vehicle " + c("fuel_type") + " is " + c("electric") + " or " + c("plug_in_hybrid") +
    ". The vehicle object has " + c("can_charge: true|false") + "; hide or disable the EV entry point when it is false. "
    "Other drivers get a 422: “EV charging is only available for electric and plug-in hybrid vehicles. Set your vehicle's fuel type if it is one.”",
    "One open request or reservation per driver at a time (pending, confirmed, reserved, queued or active). A second attempt gets "
    "422 “You already have an active reservation.”",
    "Only stations with status " + c("active") + " take reservations and appear in the driver app.",
])
story += h2("Who pays the reservation fee")
story += table([
    ["Driver", "Pays \u20a6500?", "fee_waiver_reason"],
    ["On an E-tiGo hire-purchase (fleet) agreement that is <b>active</b> or <b>paused</b>: still paying the daily \u20a640,000 remittance for the car", "No", c("hire_purchase")],
    ["Hire purchase <b>completed</b> (car paid off)", "Yes", c("null")],
    ["Agreement <b>terminated</b>", "Yes", c("null")],
    ["External driver with their own car (no agreement)", "Yes", c("null")],
    ["Driver whose tier waives the fee (Platinum and Diamond by default)", "No", c("tier")],
], [62, 14, 24])
story.append(P("The rule is checked when the stall is reserved or the request is confirmed, and the result is saved on the reservation. "
               "To show the fee <b>before</b> the driver reserves, read " + c("charging.reservation_fee") + " from " + c("GET /driver/vehicle/status") +
               " (Part A, Screen 1). Suggested copy for a waived fee: \u201cFree while you're paying off your E-tiGo car.\u201d"))

story += h2("Rules and numbers")
story += table([
    ["Rule", "Value", "What it means on screen"],
    ["Reservation fee", "₦500", "Taken from the driver's <b>earnings</b> balance only when a stall is actually held for them "
                                     "(not when they submit a request and not while queued). It may take the balance below zero, down to the "
                                     "wallet's negative limit. If it can't be paid, the hold is cancelled."],
    ["Fee waiver", "hire purchase or tier", "Drivers still paying off an E-tiGo car, and Platinum and Diamond tiers, charge free (see above). " + c("fee_waived: true") + ", " + c("fee_amount_kobo: 0") + "."],
    ["Check-in window", "30 minutes", "After a stall is held, the driver must tap Start within 30 min or the hold expires and the fee is kept. "
                                      "For confirmed requests, " + c("check_in_time") + " = slot time + 30 min."],
    ["Free cancellation", "2 minutes", "Cancelling within 2 min of the fee being taken refunds it. Use " + c("free_cancellation_until") + " to show a countdown."],
    ["Queue", "max 10 per station", "Instant reservations queue only if a busy stall is due to free within 15 min; otherwise the driver is told to retry in 15 min."],
    ["Unreviewed requests", "end of requested day", "A request still pending when its requested date has passed (Lagos time) expires and the driver is told."],
    ["Background checks", "every 1–5 min", "Slot starts are checked every minute; missed check-ins every 5 minutes. Screens should refetch on push or focus."],
], [20, 16, 64], first_col_bold=True)
story += callout("pick", "The 30-minute check-in, 2-minute free cancellation and queue limits are engineering defaults, set in config. "
                         "Only the hire-purchase waiver came from the client (28 Oct 2026). Counting a <i>paused</i> agreement as still paying is an engineering pick.")

story += h1("A2. Statuses")
story.append(P("Every request and reservation is one record (an “EV reservation”) that moves through these statuses. "
               "Design one visual state for each."))
story += table([
    ["value", "label", "Meaning", "What the driver can do"],
    [c("pending_review"), "Pending Review", "Request submitted; waiting for Operations.", "Cancel"],
    [c("confirmed"), "Confirmed", "Operations booked a slot. No stall is held and no fee taken yet.",
     "See slot, station, check-in-by time. Cancel."],
    [c("reserved"), "Reserved", "A stall is held for the driver (" + c("assigned_bay") + "); fee taken.",
     "Navigate, <b>Start charging</b>, Cancel (refund within 2 min)."],
    [c("queued"), "Queued", "Station full; driver is in line for the next stall.", "See position and estimate. Cancel."],
    [c("active"), "Active", "Charging.", "<b>Finish</b>"],
    [c("completed"), "Completed", "Done; stall released.", "—"],
    [c("expired"), "Expired", "Missed: not reviewed in time, no check-in within 30 min, or the slot could not be held in time.", "— (reserve again)"],
    [c("cancelled"), "Cancelled", "Driver cancelled, Operations declined (" + c("decline_reason") + ") or cancelled it (" + c("end_reason") + "), the fee couldn't be paid, or the station closed (reason in " + c("notes") + ").", "\u2014 (reserve again)"],
], [16, 13, 41, 30])

story += h1("A3. Flow: request charging (reviewed)")
story += charge_request_diagram()
story += h2("Ways a request ends early")
story += table([
    ["When", "Result", "Driver is told (push)"],
    ["Operations decline it (while pending, or confirmed before the slot starts)", c("cancelled") + " + " + c("decline_reason"),
     "“Charging Request Declined”: We couldn't book your charging request: <i>reason</i>. You can reserve a stall from the station list."],
    ["Nobody reviews it before the requested day ends", c("expired"), "“Charging Request Declined”: …was not booked in time…"],
    ["Driver cancels (any open status)", c("cancelled"), "— (fee refunded only within 2 min of being taken)"],
    ["At slot start the station isn't active", c("cancelled") + ", reason added to " + c("notes"), "“EV Reservation Cancelled”"],
    ["At slot start the earnings balance can't cover the fee", c("cancelled"), "“EV Reservation Cancelled”"],
    ["Slot found more than 30 min after it started", c("expired"), "“EV Reservation Cancelled”"],
    ["Stall held but the driver doesn't tap Start within 30 min", c("expired") + "; fee kept; stall goes to the next in line", "—"],
    ["Queued, a stall frees, but the fee can't be paid", c("cancelled") + "; next driver in line is served", "\u201cEV Reservation Cancelled\u201d"],
    ["Operations cancel it from the dashboard (any open status), with or without a fee refund", c("cancelled") + " + " + c("end_reason") + ", " + c("ended_by_operations: true"),
     "\u201cEV Reservation Cancelled\u201d: \u2026cancelled by E-tiGo Operations: <i>reason</i>. [fee refunded]"],
    ["Operations end a session the driver didn't finish (reserved or active)", c("completed") + " + " + c("end_reason") + "; fee kept", "\u201cCharging Session Ended\u201d"],
], [40, 25, 35])

story += h1("A4. Flow: reserve now (instant)")
story += instant_diagram()
story.append(P("The reserve call returns one of three outcomes in " + c("outcome") + ". Only " + c("reserved") + " and " + c("queued") +
               " create a record. Instant reservations have no requested date, so they <b>do not</b> appear in the Operations "
               "charge-request queue; Operations see them in the All reservations screen (Screen F) and on the station detail screen."))

# ---------------------------------------------------------------- driver screens
story += h1("A5. Driver app screens")

story += h2("Screen 1 — Charging card on “My vehicle”")
story.append(P("A summary card on the vehicle screen: battery level and the current reservation, with a button into charging."))
story += endpoint("GET", "/driver/vehicle/status", "driver")
story.append(P("Use the " + c("charging") + " block of the response (it is " + c("null") + " for cars that can't charge):"))
story += table([
    ["Field", "Type", "Notes"],
    [c("charging.battery_level_percent"), "int 0–100 | null", "Last level the driver reported when going online."],
    [c("charging.battery_level_at"), "timestamp | null", "When it was reported. Show “updated 2 h ago”."],
    [c("charging.active_reservation"), "object | null", "The open request or reservation, if any."],
    [c("…active_reservation.id / status"), "uuid / string", "Status is a plain string here (not the value/label object)."],
    [c("…active_reservation.station"), "{id, name} | null", ""],
    [c("\u2026active_reservation.confirmed_slot"), "string | null", "Display time, e.g. \u201c02:30 pm\u201d, once confirmed."],
    [c("charging.reservation_fee"), "object", c("{amount_kobo, waived, waiver_reason}") + ": what reserving a stall costs this driver now. Show \u201c\u20a6500 reservation fee\u201d or \u201cFree while you're paying off your E-tiGo car\u201d."],
], [36, 18, 46])
story += h3("Battery level when going online")
story += endpoint("POST", "/driver/toggle-online", "driver")
story.append(P("Optional body field " + c("battery_level_percent") + " (integer 0–100). Stored only for plug-in cars. "
               "Design a quick slider or input in the go-online step for EV drivers."))

story += h2("Screen 2 — Station list / map")
story += endpoint("GET", "/ev-stations?city_id={uuid}", "public, no login")
story += endpoint("GET", "/ev-stations/{station_id}", "public, no login")
story.append(P(c("city_id") + " is required (use the driver's city). Only active stations are listed, sorted by name. "
               "The detail call adds " + c("stalls[]") + " and " + c("city") + "."))
story += table([
    ["Station field", "Type", "Notes"],
    [c("id, name, address"), "string", ""],
    [c("lat, lng"), "decimal string", "Map pin."],
    [c("city_id"), "uuid", ""],
    [c("total_stalls"), "int", ""],
    [c("connector_types"), "string[]", "Any of CCS2, CHAdeMO, Type2, GBT, Tesla. Show as chips."],
    [c("power_output_kw"), "int | null", "e.g. 60 kW."],
    [c("pricing_per_kwh"), "int | null", "<b>Kobo</b> per kWh: divide by 100 for naira. Informational; the app does not charge per kWh."],
    [c("operating_hours"), "{open, close} | null", "“HH:mm” strings, e.g. 07:00–22:00."],
    [c("status"), "{value, label}", "Always active in the driver list."],
    [c("availability"), "object", c("{total, available, occupied, reserved, out_of_service}") + " stall counts. Show “3 of 6 free”."],
    [c("stalls[]"), "array", "Detail only. Each: " + c("id, stall_number, status{value,label}, occupied_since, estimated_departure_at") + "."],
], [24, 18, 58])
story += callout("design", "Disable “Reserve now” when " + c("availability.available") + " is 0 and offer “Request a slot” instead, "
                           "or let the driver try: the API answers with queued or retry.")

story += h2("Screen 3 — Request charging form")
story += endpoint("POST", "/driver/ev-reservations", "driver")
story += table([
    ["Field", "Required", "Rules", "UI suggestion"],
    [c("battery_level"), "yes", "integer 0–100", "Slider with %; prefill from the vehicle's last battery level."],
    [c("requested_date"), "no", "date, today or later (Lagos). Default: today", "Date picker."],
    [c("requested_time"), "no", "text, max 50. Default: now as “hh:mm am”", "Time picker that sends “02:30 pm”. Operations read it as a preference."],
    [c("station_id"), "no", "uuid of a station", "Station picker from Screen 2. If empty, the first active station in the driver's city is used."],
    [c("notes"), "no", "text, max 1000", "\u201cAnything Operations should know?\u201d"],
    [c("estimated_charge_minutes"), "no", "integer 5\u2013480", "\u201cHow long will you charge?\u201d (e.g. 30 / 45 / 60 / 90 min chips). Lets drivers behind them queue instead of being told to retry."],
], [17, 12, 31, 40])
story.append(P("<b>Success (201):</b> " + c("{message: \"Charge request submitted successfully.\", reservation: {…}}") +
               " with status " + c("pending_review") + ". <b>Errors (422):</b> can't charge; already has an open reservation; field validation."))

story += h2("Screen 4 — Reserve now")
story += endpoint("POST", "/driver/ev-reservations/stations/{station_id}/reserve", "driver")
story.append(P("Optional body " + c("estimated_charge_minutes") + " (integer 5–480). Ask “How long will you charge?”: "
               "this sets the stall's expected departure, which lets other drivers queue instead of being told to retry."))
story += table([
    ["outcome", "HTTP", "Body", "Show"],
    [c("reserved"), "201", c("message, outcome, reservation"), "Stall number, check-in countdown (30 min), fee, cancel option."],
    [c("queued"), "200", c("message, outcome, reservation, queue_position, estimated_available_at"), "“You're #2 in line, stall expected about 14:20”. No fee yet."],
    [c("retry"), "200", c("message, outcome, retry_after_minutes, estimated_wait_minutes"), "Nothing was created. “All stalls busy. Try again in 15 min.”"],
], [12, 8, 42, 38])
story.append(P("<b>Errors (422):</b> “This station is not currently operational.”; can't charge; already active; "
               "“Your earnings balance cannot cover the ₦500.00 reservation fee.”; "
               "“Your earnings account is on hold, so the reservation fee cannot be paid. Contact support.”"))

story += h2("Screen 5 — My charging (list and detail)")
story += endpoint("GET", "/driver/ev-reservations?status={value}&per_page=20", "driver")
story += endpoint("GET", "/driver/ev-reservations/{id}", "driver")
story.append(P("Newest first. The detail screen should be driven by " + c("status.value") + ". The reservation object (also used on admin screens):"))
story += table([
    ["Field", "Type", "Show when / notes"],
    [c("id"), "uuid", ""],
    [c("type"), "string", "Always “charging”."],
    [c("status"), "{value, label}", "Always."],
    [c("station_id, station_name, station_address"), "string", "Always (station may be null only in odd data)."],
    [c("station"), "object", "Full station (see Screen 2) when loaded."],
    [c("stall_id, assigned_bay"), "string | null", c("assigned_bay") + " e.g. “Bay 3 · DC fast charger”. reserved / active."],
    [c("battery_level"), "int | null", "Requests only."],
    [c("requested_date, requested_time"), "date / display string", "Requests only. What the driver asked for."],
    [c("notes"), "string | null", "Driver's note; system reasons for a cancellation are appended here."],
    [c("confirmed_slot_at"), "timestamp | null", "confirmed and later. Use for countdowns."],
    [c("confirmed_slot, check_in_time"), "display string | null", "“Confirmed for 02:30 pm, check in by 03:00 pm”."],
    [c("approver_name"), "string | null", "Always “E-tiGo Operations” once reviewed (staff names are never shown to drivers)."],
    [c("reviewed_at"), "timestamp | null", "When Operations confirmed or declined."],
    [c("decline_reason"), "string | null", "Declined requests. Show prominently."],
    [c("queue_position, estimated_available_at"), "int / timestamp | null", "queued."],
    [c("kind"), "string", c("request") + " (reviewed by Operations) or " + c("instant") + " (reserved from the station list)."],
    [c("estimated_charge_minutes"), "int | null", "The driver's estimate."],
    [c("fee_amount, fee_amount_kobo, fee_waived"), "\"500.00\" / int / bool", "Use the kobo field."],
    [c("fee_waiver_reason"), "string | null", c("hire_purchase") + " \u2192 \u201cFree while you're paying off your E-tiGo car\u201d; " + c("tier") + " \u2192 \u201cFree for your tier\u201d."],
    [c("check_in_deadline"), "timestamp | null", "When the held stall is released if charging hasn't started (confirmed or reserved). Use for the countdown."],
    [c("ended_by_operations, end_reason, ended_at"), "bool / string / timestamp", "Operations cancelled or ended it. Show the reason."],
    [c("fee_charged_at, fee_refunded_at"), "timestamp | null", "Receipt lines."],
    [c("free_cancellation_until"), "timestamp | null", "Countdown for “Cancel for free”; null when there is nothing to refund."],
    [c("reserved_at, activated_at, completed_at"), "timestamp | null", "Timeline."],
    [c("vehicle_name, vehicle_plate, vehicle_color"), "string | null", "The driver's car."],
    [c("submitted_at, created_at, updated_at"), "timestamp", ""],
    [c("queue_ahead_count, queue_ahead_description"), "int / string | null", "While queued: drivers ahead, and ready copy: \u201cYou're next in line\u201d, \u201c2 drivers ahead of you\u201d."],
], [37, 19, 44])

story += h2("Screen 6 — At the station: start, finish, cancel")
story += endpoint("POST", "/driver/ev-reservations/{id}/activate", "driver")
story += endpoint("POST", "/driver/ev-reservations/{id}/complete", "driver")
story += endpoint("POST", "/driver/ev-reservations/{id}/cancel", "driver")
story += table([
    ["Action", "Allowed from", "Result", "Refused (422) message"],
    ["Start charging", c("reserved"), c("active"), "Only reserved reservations can be activated."],
    ["Finish", c("active") + ", " + c("reserved"), c("completed") + "; stall passes to the next in line", "Only active or reserved reservations can be completed."],
    ["Cancel", "any open status", c("cancelled") + "; fee refunded if within " + c("free_cancellation_until"), "Cannot cancel a terminal reservation."],
], [16, 18, 36, 30])
story.append(P("Each returns " + c("{message, reservation}") + ". Start accepts an optional " + c("estimated_charge_minutes") +
               " (5\u2013480) that replaces the earlier estimate; ask for it if the driver didn't give one. Finish and Cancel take no body. "
               "All three return 403 for another driver's reservation."))

story += h2("Driver notifications (push + in-app inbox)")
story.append(P("Category " + c("ev_charging") + ". Every push carries " + c("data.type") + ", " + c("data.reservation_id") + " and "
               + c("data.station_name") + "; deep-link to Screen 5 detail."))
story += table([
    ["data.type", "Title", "Sent when", "Example body"],
    [c("ev_charge_request_confirmed"), "Charging Request Confirmed", "Operations confirm",
     "Your charging slot at Lekki Phase 1 Hub is confirmed for today at 2:30 PM. We'll hold a stall from then; check in by 03:00 pm."],
    [c("ev_charge_request_declined"), "Charging Request Declined", "Operations decline, or nobody reviewed it in time",
     "We couldn't book your charging request: no stalls free this afternoon. You can reserve a stall from the station list."],
    [c("ev_reservation_ready"), "EV Reservation Ready / EV Stall Ready", "A stall is held (slot started, or the queue reached them). Has " + c("data.stall_number") + ".",
     "Stall 3 at Lekki Phase 1 Hub is ready for you. Check in by 03:00 pm."],
    [c("ev_reservation_cancelled"), "EV Reservation Cancelled", "Slot or queued turn could not be held, or Operations cancelled it",
     "Your charging reservation at Lekki Phase 1 Hub was cancelled by E-tiGo Operations: Charger 1 is faulty. Your \u20a6500.00 reservation fee has been refunded."],
    [c("ev_reservation_ended"), "Charging Session Ended", "Operations ended a session the driver didn't finish",
     "Your charging session at Lekki Phase 1 Hub was ended by E-tiGo Operations: Car left the bay."],
], [29, 18, 21, 32])
story += h3("Live updates in the driver app")
story.append(P("Subscribe to the driver's private channel " + c("driver.{userId}") + " (already used for ride offers) and listen for "
               + c("ev.reservation.updated") + ". It fires whenever one of the driver's requests or reservations is created or changes status, "
               "queue position, stall, station or slot. Payload: " + c("{reservation_id, deleted, reservation}") + ", where " + c("reservation") +
               " is the same object as Screen 5. Replace the screen's copy with it; no refetch needed."))

# ---------------------------------------------------------------- admin screens
story += h1("A6. Admin dashboard screens (Operations)")

story += h2("Screen A — Charge requests queue (the approval screen)")
story.append(P("The screen the client asked for: drivers who have requested charging, waiting for Operations to confirm or decline."))
story += endpoint("GET", "/admin/ev-charge-requests", "Operations")
story += table([
    ["Query param", "Notes"],
    [c("status"), "Comma list of status values. Default " + c("pending_review,confirmed") + ". Use tabs, e.g. "
                 "Pending | Confirmed | History (" + c("reserved,queued,active,completed,cancelled,expired") + "). Unknown value → 422."],
    [c("station_id"), "Filter by station (dropdown from Screen B)."],
    [c("city_id"), "Filter by the station's city."],
    [c("search"), "Driver first name, last name or phone (max 100)."],
    [c("page, per_page"), "Default 20, max 100."],
], [18, 82])
story.append(P("<b>Response:</b> " + c("charge_requests[]") + " (reservation objects, see Screen 5 table, plus " + c("driver") + " and " + c("reviewed_by") + "), "
               + c("summary: {pending_review, confirmed}") + " (counts for tab badges, ignore filters), and " + c("meta") + ". "
               "Order: pending requests first, oldest first; then confirmed, soonest slot first."))
story += h3("Suggested columns")
story += table([
    ["Column", "Source field(s)"],
    ["Driver", c("driver.first_name") + " + " + c("driver.last_name") + ", " + c("driver.phone") + ", avatar " + c("driver.avatar_url")],
    ["Vehicle", c("vehicle_name") + ", " + c("vehicle_plate") + ", " + c("vehicle_color")],
    ["Battery", c("battery_level") + " % (highlight low levels, e.g. under 20%)"],
    ["Wanted", c("requested_date") + " + " + c("requested_time")],
    ["Station", c("station_name") + " (" + c("station_address") + ")"],
    ["Waiting", "time since " + c("submitted_at")],
    ["Status", c("status.label")],
    ["Slot (confirmed)", c("confirmed_slot_at") + " (local), " + c("check_in_time")],
    ["Reviewed by", c("reviewed_by.name") + ", " + c("reviewed_at") + " (admins only)"],
    ["Notes / decline reason", c("notes") + ", " + c("decline_reason")],
], [22, 78])
story += h3("Action: Confirm")
story += endpoint("POST", "/admin/ev-charge-requests/{id}/confirm", "Operations")
story += table([
    ["Field", "Required", "Notes"],
    [c("confirmed_slot_at"), "yes", "Date + time of the slot. Send ISO 8601 with the Lagos offset, e.g. " + c("2026-10-28T14:30:00+01:00") +
                                   ". Prefill from the driver's requested date and time. At most 5 minutes in the past and 7 days ahead; a slot that has already started holds a stall at once."],
    [c("station_id"), "no", "Move the request to another station. Must be active. Default: the station the driver picked."],
], [20, 12, 68])
story.append(P("Only " + c("pending_review") + " requests can be confirmed. The driver is notified. The response is "
               + c("{message, charge_request, reservation}") + ": the same object under both keys."))
story += h3("Action: Decline")
story += endpoint("POST", "/admin/ev-charge-requests/{id}/decline", "Operations")
story.append(P(c("reason") + " is required (5–500 characters) and <b>is shown to the driver</b>, so label the field accordingly. "
               "Allowed while " + c("pending_review") + ", or " + c("confirmed") + " before the slot starts."))
story += h3("When two admins act at once")
story.append(P("A refused confirm or decline returns 422 " + c("{message, error_code: \"VALIDATION_ERROR\", current_status}") +
               ". Show the message, update the row's status from " + c("current_status") + " and refresh the list."))

story += h2("Screen B — Stations list")
story += endpoint("GET", "/admin/ev-stations?status=&search=&city_id=&page=", "Operations")
story.append(P("Station objects as in the driver app, plus for admins: " + c("availability.queue_length") +
               " (drivers waiting), " + c("city") + ", and " + c("stalls[]") + " with " + c("current_driver") +
               " (" + c("{id, name, phone, vehicle{make, model, colour, plate_number}}") + "). Station " + c("status") + ": "
               + c("active") + " (Active), " + c("inactive") + " (Inactive), " + c("maintenance") + " (Under Maintenance)."))

story += h2("Screen C — Create / edit station")
story += endpoint("POST", "/admin/ev-stations", "Operations")
story += endpoint("PUT", "/admin/ev-stations/{id}", "Operations (send only changed fields)")
story += table([
    ["Field", "Create", "Rules / UI"],
    [c("name"), "required", "max 255"],
    [c("lat, lng"), "required", "Map pin picker."],
    [c("address"), "required", "max 500"],
    [c("city_id"), "optional", "Worked out from the pin when empty; 422 if the pin is outside every active city."],
    [c("total_stalls"), "required", "1–100. Stalls numbered 1..n are created. On edit, increasing adds stalls; decreasing removes the highest-numbered "
                                   "ones only if they are free (else 422 “Stall 5 is in use…”)."],
    [c("connector_types"), "optional", "Multi-select: CCS2, CHAdeMO, Type2, GBT, Tesla."],
    [c("power_output_kw"), "optional", "1–500"],
    [c("pricing_per_kwh"), "optional", "Integer <b>kobo</b>. Let admins type naira and multiply by 100."],
    [c("operating_hours"), "optional", c("{open: \"07:00\", close: \"22:00\"}") + " (24-hour HH:mm, both required if sent)."],
    [c("status"), "optional", c("active | inactive | maintenance") + ". Default active."],
], [20, 12, 68])

story += h2("Screen D — Station detail (stalls and queue)")
story += endpoint("GET", "/admin/ev-stations/{id}", "Operations")
story.append(P("Returns " + c("station") + " (with stalls and their current driver), and " + c("queue[]") +
               ": drivers waiting for a stall, in order. Ignore the top-level " + c("availability") + " (deprecated duplicate of " + c("station.availability") + ")."))
story += table([
    ["queue[] field", "Notes"],
    [c("reservation_id, position"), "Position 1 is next."],
    [c("driver"), c("{id, name, phone, vehicle{make, model, colour, plate_number}}")],
    [c("battery_level"), "Only for drivers who came from a charge request."],
    [c("joined_at, wait_seconds"), "Show “waiting 12 min”."],
    [c("estimated_available_at"), "When a stall is expected to free up, if known."],
], [28, 72])
story.append(P("<b>Stall grid:</b> one tile per stall with " + c("stall_number") + ", status colour (Available, Occupied, Reserved, Out of Service), "
               + c("current_driver") + ", " + c("occupied_since") + " and " + c("estimated_departure_at") + "."))
story += h3("Actions")
story += endpoint("PATCH", "/admin/ev-stations/{id}/status", "body {status: active|inactive|maintenance}")
story += endpoint("PUT", "/admin/ev-stations/{id}/stalls", "body {stalls: [{stall_number, status}]}")
story += endpoint("DELETE", "/admin/ev-stations/{id}", "Operations")
story += bullets([
    "<b>Stalls:</b> set any stall to available, occupied (e.g. a non-E-tiGo car), reserved or out_of_service. A new " + c("stall_number") +
    " adds a stall. A stall holding a driver's reservation can't be changed: 422 “Stall 2 has a driver's reservation. Change it once the driver finishes or cancels.” Show those stalls as locked.",
    "<b>Status:</b> stations that aren't active disappear from the driver app, and confirmed slots there are cancelled when they start.",
    "<b>Delete:</b> refused with 422 while the station has open reservations. Ask for confirmation first.",
])

story += h2("Screen E — Utilisation dashboard")
story += endpoint("GET", "/admin/ev-stations/utilisation?city_id=", "Operations")
story.append(P("Active stations only. " + c("utilisation[]") + ": " + c("station_id, station_name, city_id, total_stalls, availability, occupancy_rate") +
               " (% of stalls occupied or reserved), " + c("estimated_wait_minutes") + " (until the soonest expected departure, or null). "
               + c("summary") + ": " + c("total_stations, total_stalls, total_available, total_occupied, average_occupancy_rate") + "."))

story += h2("Screen F \u2014 All reservations (search, history, cancel, end)")
story.append(P("Every request and reservation, including the reserve-now ones that never reach the approval queue. "
               "Use it for search, history, and for fixing a reservation that is stuck."))
story += endpoint("GET", "/admin/ev-reservations", "Operations")
story += table([
    ["Query param", "Notes"],
    [c("status"), "Comma list of statuses. Default: all."],
    [c("kind"), c("request") + " or " + c("instant") + ". Show as a column or chip too."],
    [c("station_id, city_id, driver_id"), "Narrow the list. " + c("driver_id") + " is the driver's user id (handy from the driver profile)."],
    [c("search"), "Driver name, phone or vehicle plate."],
    [c("from, to"), "Lagos dates, on " + c("reserved_at") + "."],
], [24, 76])
story.append(P("<b>Response:</b> " + c("reservations[]") + " (newest first), " + c("summary") +
               " (open reservations now by status, for KPI tiles: pending_review, confirmed, reserved, queued, active) and " + c("meta") +
               ". Detail: " + c("GET /admin/ev-reservations/{id}") + ". Show " + c("ended_by.name") + " and " + c("end_reason") + " when Operations acted."))
story += h3("Action: Cancel (any open reservation)")
story += endpoint("POST", "/admin/ev-reservations/{id}/cancel", "body {reason, refund_fee}")
story.append(P(c("reason") + " is required (5\u2013500) and shown to the driver. " + c("refund_fee") + " (checkbox, default off) gives the fee back: tick it when it wasn't the driver's fault, e.g. a broken charger. "
               "The stall goes to the next driver in line. The response adds " + c("fee_refunded") + " (true or false)."))
story += h3("Action: End session (reserved or active)")
story += endpoint("POST", "/admin/ev-reservations/{id}/end", "body {reason?, refund_fee?}")
story.append(P("For a driver who drove off without tapping Finish. Marks it completed and frees the stall. The fee is kept unless " + c("refund_fee") + " is ticked (e.g. the charger failed mid-session). Offer it on the station's stall tile too "
               "(the tile's " + c("current_driver") + " links to the reservation through this list, filtered by " + c("station_id") + " and " + c("status=active,reserved") + ")."))
story.append(P("<b>Refused (422):</b> " + c("{message, error_code, current_status}") + ", e.g. \u201cThis reservation has already ended.\u201d Refresh the row."))

story += h2("Screen G \u2014 EV charging settings")
story += endpoint("GET", "/admin/settings/ev-charging", "Operations")
story += endpoint("PUT", "/admin/settings/ev-charging", "send only changed fields")
story += table([
    ["Setting", "Default", "Control"],
    [c("reservation_fee"), "500 (naira)", "Currency input, 0\u2013100,000"],
    [c("reservation_expiry_minutes"), "30", "Minutes a held stall waits for the driver (5\u2013240)"],
    [c("free_cancellation_minutes"), "2", "0\u201360"],
    [c("max_queue_size"), "10", "Drivers per station (0\u2013100)"],
    [c("imminent_departure_minutes"), "15", "A full station queues only if a stall frees within this (0\u2013240)"],
    [c("retry_delay_minutes"), "15", "\u201cTry again in\u201d (1\u2013240)"],
    [c("max_active_reservations_per_driver"), "1", "1\u20135"],
    [c("hire_purchase_fee_waiver"), "on", "Toggle: drivers still paying off an E-tiGo car reserve for free"],
    [c("waive_fee_while_agreement_paused"), "on", "Toggle: a paused agreement still counts as paying"],
], [36, 16, 48])
story.append(P("Changes apply to reservations made or confirmed afterwards; a reservation keeps the fee it was given. "
               "The maintenance settings already have their own screen (Part B, Screen E)."))

story += h2("Live updates (admin web)")
story.append(P("Subscribe to the private channel " + c("admin.ev-stations") + " (Operations and Super Admin) and listen for "
               + c("ev.station.availability_changed") + ". It fires after any stall or reservation change at a station:"))
story += code('''{
  "station_id": "9d4c1f6e-...",
  "deleted": false,
  "status": "active",
  "availability": {"total": 6, "available": 2, "occupied": 3, "reserved": 1, "out_of_service": 0, "queue_length": 1},
  "updated_at": "2026-10-28T13:31:04.000000Z"
}''')
story.append(P("Update counts in place. When the station's detail or the charge-request queue is open, refetch it. "
               + c("deleted: true") + " means the station was removed. The driver app has no websocket for EV; it relies on push notifications and refetching."))
story += callout("note", "City launch readiness includes the gate “Charging stations live for plug-in drivers”, so the city "
                         "checklist screen may link to the stations list.")

# ---------------------------------------------------------------- EV examples
story.append(PageBreak())
story += h1("A7. Example responses")
story += code('''{
  "message": "Charge request submitted successfully.",
  "reservation": {
    "id": "a1f0c2d4-5b6e-4f70-8a91-0b2c3d4e5f60",
    "type": "charging",
    "station_id": "9d4c1f6e-2a3b-4c5d-8e9f-0a1b2c3d4e5f",
    "stall_id": null,
    "status": {"value": "pending_review", "label": "Pending Review"},
    "battery_level": 18,
    "requested_date": "2026-10-28",
    "requested_time": "02:30 pm",
    "notes": "Need about an hour",
    "kind": "request",
    "estimated_charge_minutes": 60,
    "approver_name": null,
    "confirmed_slot": null,
    "check_in_time": null,
    "confirmed_slot_at": null,
    "reviewed_at": null,
    "decline_reason": null,
    "queue_position": null,
    "queue_ahead_count": null,
    "queue_ahead_description": null,
    "estimated_available_at": null,
    "check_in_deadline": null,
    "ended_by_operations": false,
    "end_reason": null,
    "ended_at": null,
    "fee_amount": null,
    "fee_amount_kobo": null,
    "fee_waived": false,
    "fee_waiver_reason": null,
    "fee_charged_at": null,
    "fee_refunded_at": null,
    "free_cancellation_until": null,
    "reserved_at": "2026-10-28T11:02:10.000000Z",
    "activated_at": null,
    "completed_at": null,
    "vehicle_name": "BYD Atto 3",
    "vehicle_plate": "LND-482-KJ",
    "vehicle_color": "White",
    "station_name": "Lekki Phase 1 Hub",
    "station_address": "12 Admiralty Way, Lekki Phase 1, Lagos",
    "assigned_bay": null,
    "station": { "...": "station object" },
    "driver": { "...": "user object" },
    "submitted_at": "2026-10-28T11:02:10.000000Z",
    "updated_at": "2026-10-28T11:02:10.000000Z",
    "created_at": "2026-10-28T11:02:10.000000Z"
  }
}''', "POST /driver/ev-reservations → 201")
story += code('''{
  "charge_requests": [
    {
      "id": "a1f0c2d4-...",
      "status": {"value": "confirmed", "label": "Confirmed"},
      "battery_level": 18,
      "requested_date": "2026-10-28",
      "requested_time": "02:30 pm",
      "confirmed_slot_at": "2026-10-28T13:30:00.000000Z",
      "confirmed_slot": "02:30 pm",
      "check_in_time": "03:00 pm",
      "approver_name": "E-tiGo Operations",
      "reviewed_at": "2026-10-28T11:20:44.000000Z",
      "reviewed_by": {"id": "5e6f...", "name": "Ada Okafor"},
      "fee_amount": "500.00",
      "fee_waived": false,
      "station_name": "Lekki Phase 1 Hub",
      "vehicle_name": "BYD Atto 3",
      "vehicle_plate": "LND-482-KJ",
      "driver": {"id": "7c8d...", "first_name": "Tunde", "last_name": "Bello", "phone": "+2348012345678", "...": "..."},
      "...": "all other reservation fields"
    }
  ],
  "summary": {"pending_review": 4, "confirmed": 2},
  "meta": {"current_page": 1, "last_page": 1, "per_page": 20, "total": 6}
}''', "GET /admin/ev-charge-requests (trimmed)")
story += code('''{
  "message": "Added to queue.",
  "outcome": "queued",
  "reservation": { "status": {"value": "queued", "label": "Queued"}, "queue_position": 2, "...": "..." },
  "queue_position": 2,
  "estimated_available_at": "2026-10-28T13:20:00.000000Z"
}

{
  "message": "All stalls are occupied with no imminent departures. Please try again later.",
  "outcome": "retry",
  "retry_after_minutes": 15,
  "estimated_wait_minutes": 40
}''', "POST /driver/ev-reservations/stations/{id}/reserve → queued, and retry")
story += code('''{
  "station": {
    "id": "9d4c1f6e-...", "name": "Lekki Phase 1 Hub", "city_id": "1b2c...",
    "lat": "6.4474000", "lng": "3.4723000", "address": "12 Admiralty Way, Lekki Phase 1, Lagos",
    "total_stalls": 4, "connector_types": ["CCS2", "Type2"], "power_output_kw": 60,
    "pricing_per_kwh": 15000, "operating_hours": {"open": "07:00", "close": "22:00"},
    "status": {"value": "active", "label": "Active"},
    "availability": {"total": 4, "available": 1, "occupied": 2, "reserved": 1, "out_of_service": 0, "queue_length": 1},
    "city": {"id": "1b2c...", "name": "Lagos", "...": "..."},
    "stalls": [
      {"id": "s1...", "stall_number": 1, "status": {"value": "occupied", "label": "Occupied"},
       "occupied_since": "2026-10-28T12:40:00.000000Z", "estimated_departure_at": "2026-10-28T13:40:00.000000Z",
       "current_driver": {"id": "7c8d...", "name": "Tunde Bello", "phone": "+2348012345678",
                          "vehicle": {"make": "BYD", "model": "Atto 3", "colour": "White", "plate_number": "LND-482-KJ"}},
       "updated_at": "2026-10-28T12:40:00.000000Z"}
    ]
  },
  "availability": { "...": "deprecated, same as station.availability" },
  "queue": [
    {"reservation_id": "q1...", "position": 1,
     "driver": {"id": "8a9b...", "name": "Kemi Ade", "phone": "+2348098765432", "vehicle": {"...": "..."}},
     "battery_level": null, "joined_at": "2026-10-28T13:05:00.000000Z", "wait_seconds": 720,
     "estimated_available_at": "2026-10-28T13:40:00.000000Z"}
  ]
}''', "GET /admin/ev-stations/{id} (trimmed)")

# ================================================================ PART B
story += part("Part B — Vehicle maintenance",
              "Every vehicle has a checklist of service items and legal papers. The system works out what is due, "
              "reminds drivers, and keeps a car off the road when its papers have expired.")

story += h1("B1. What the feature does")
story += bullets([
    "Each vehicle gets a list of <b>items</b>. Service items are due by distance or time; legal papers are due on their expiry date.",
    "Drivers see their car's health, get reminders as items become due, and log work they've had done (with a receipt) for Operations to approve.",
    "Fleet (E-tiGo-owned) cars are maintained by Operations: drivers can't log work on them, and Operations record the work and upload the papers.",
    "When a legal paper has been expired for longer than the grace period, the driver <b>can't go online</b> until a renewed paper is approved. "
    "Overdue service only warns; it never blocks.",
])
story += h2("The items")
story += table([
    ["type", "label (show this)", "Kind", "When it is due (default)"],
    [c("service"), "Routine service", "service record", "Every 5,000 km or 3 months after the last approved service, whichever comes first. Needs an odometer reading."],
    [c("tyres_brakes"), "Tyres & brakes check", "service record", "Every 6 months."],
    [c("battery_health"), "Battery health check", "service record", "Every 12 months. Electric and plug-in hybrid cars only."],
    [c("insurance"), "Insurance", "legal paper", "Expiry date of the approved insurance certificate."],
    [c("roadworthiness"), "Roadworthiness certificate", "legal paper", "Expiry date of the approved certificate."],
    [c("vehicle_licence"), "Vehicle licence", "legal paper", "Expiry date of the approved licence."],
], [17, 22, 15, 46])
story += h2("Item statuses")
story += table([
    ["status", "status_label", "Meaning", "Suggested colour"],
    [c("ok"), "Up to date", "Nothing to do.", "green"],
    [c("due_soon"), "Due soon", "Within 14 days of the due date, or within 500 km of the due reading.", "amber"],
    [c("overdue"), "Overdue", "Service item past its date or distance. Warning only.", "red"],
    [c("expired"), "Expired", "Legal paper past its expiry. Grace period first, then blocks driving.", "red (darker once " + c("blocks_driving") + ")"],
    [c("unknown"), "No record yet", "No approved service date, or no approved paper with an expiry date.", "grey, with a call to action"],
], [14, 16, 50, 20])
story += h2("Expired papers: grace period, then blocked")
story += paper_timeline()
story.append(P("Example with the default 7-day grace: insurance expires on 1 Nov. From 2 Nov the item is " + c("expired") +
               " and its message says “upload the renewed paper by 8 Nov 2026 to keep driving” (" + c("blocks_driving_from: \"2026-11-09\"") +
               "). From 9 Nov " + c("blocks_driving") + " is true, " + c("can_drive") + " is false, and going online is refused."))
story.append(P("<b>When a car becomes blocked:</b> the driver gets a “You Can't Go Online” push, is taken offline (a driver mid-trip "
               "finishes the trip first), and loses the scheduled pickups they had reserved. For fleet cars, Operations get an alert. "
               "<b>When a renewed paper is approved</b>, the block lifts by itself and the driver gets “You Can Go Online Again”."))
story += h2("Odometer")
story += bullets([
    "The driver (or a service record) gives a reading. Between readings the system adds completed trip distance, so " +
    c("estimated_odometer_km") + " keeps moving.",
    "A reading can't be lower than an earlier one or higher than a later one. The 422 message explains which, e.g. "
    "“The reading is lower than the last one (48200 km). Check the number on the dashboard.”",
])
story += h2("Reminders")
story += bullets([
    "Checked every day at 06:00 Lagos time, and whenever a car's status screen is opened.",
    "The driver gets one push per car, listing only the items whose status changed since the last message (no daily repeats).",
    "Operations get one daily digest of fleet items that are overdue or expired.",
])
story += callout("pick", "Intervals, the 14-day / 500 km reminder lead and the 7-day grace period are engineering recommendations (16 Oct 2026). "
                         "The client has not confirmed them. Operations can change them all on the settings screen (Screen E).")

story += h1("B2. Driver app screens")
story += h2("Screen 1 — Vehicle health")
story += endpoint("GET", "/driver/vehicle/status", "driver")
story += table([
    ["Field", "Notes"],
    [c("vehicle"), c("{id, plate_number, make, model, fuel_type, is_fleet, shift_mode, odometer_km, odometer_read_at, estimated_odometer_km}")],
    [c("can_drive"), "false when blocked. Show a full-width red banner with " + c("block_reason") + "."],
    [c("block_reason"), "e.g. “Insurance expired on 1 Nov 2026.”"],
    [c("records_managed_by"), c("driver") + " (own car: show “Log service” buttons) or " + c("operations") + " (fleet car: hide them; show “E-tiGo services this car”)."],
    [c("items[]"), "The checklist. See the item fields below."],
    [c("charging"), "EV block (Part A, Screen 1); null for cars that can't charge."],
    [c("pending_records[]"), "Records the driver submitted that Operations haven't checked yet. Show as “Awaiting review”."],
], [22, 78])
story += h3("Item fields")
story += table([
    ["Field", "Type", "Notes"],
    [c("id, type, label"), "string", "Show " + c("label") + "."],
    [c("is_legal_paper"), "bool", "true → call to action is “Upload renewed paper”; false → “Log service”."],
    [c("status, status_label"), "string", "See item statuses."],
    [c("message"), "string", "Ready-to-show sentence, e.g. “Routine service is due by 12 Dec 2026 or at 53200 km.” Use it as the row subtitle."],
    [c("due_at"), "date | null", "Due or expiry date."],
    [c("days_until_due"), "int | null", "Negative = days past. “in 9 days” / “3 days ago”."],
    [c("due_odometer_km, km_until_due"), "int | null", "Service only. “in 850 km”."],
    [c("last_done_at, last_odometer_km"), "date / int | null", "Last approved service, or when the paper was approved."],
    [c("blocks_driving"), "bool", "This item is keeping the car off the road."],
    [c("blocks_driving_from"), "date | null", "Expired paper still in its grace period: the day driving stops. Show a countdown."],
], [28, 15, 57])

story += h2("Screen 2 — Log maintenance (own car only)")
story += endpoint("POST", "/driver/vehicle/maintenance-records", "driver, multipart, max 10 per minute")
story += table([
    ["Field", "Required", "Rules"],
    [c("item_type"), "yes", c("service | tyres_brakes | battery_health") + " (battery only for plug-in cars). Papers are not logged here."],
    [c("performed_at"), "yes", "Date, not in the future, within the last 2 years."],
    [c("odometer_km"), "for service", "Integer 0–2,000,000. Required for Routine service. Must fit between earlier and later readings."],
    [c("workshop"), "no", "Max 120 characters."],
    [c("notes"), "no", "Max 1000."],
    [c("driver_repair_id"), "no", "Link to one of the driver's repair requests (Part C), e.g. “This was for request RPR-0042”."],
    [c("proof"), "yes", "Receipt or workshop report: jpg, png or pdf, max 10 MB. Camera or file picker."],
], [19, 13, 68])
story.append(P("<b>201:</b> " + c("{message: \"Record submitted. E-tiGo will check it shortly.\", record}") + " with status " + c("pending") +
               ". <b>403</b> on fleet cars: “Fleet cars are serviced and recorded by E-tiGo operations. Contact fleet support.”"))
story += record_review_diagram()

story += h2("Screen 3 — Maintenance history")
story += endpoint("GET", "/driver/vehicle/maintenance-records?page=", "driver, 20 per page, newest first")
story += table([
    ["Record field", "Notes"],
    [c("id, vehicle_id"), ""],
    [c("item_type, item_label"), "Show " + c("item_label") + "."],
    [c("performed_at, odometer_km, workshop, notes"), ""],
    [c("proof_url"), "Signed link to the receipt (image or PDF). Can be null for Operations entries."],
    [c("driver_repair_id"), "Linked repair request, if any."],
    [c("status, status_label"), c("pending") + " Awaiting review, " + c("approved") + " Approved, " + c("rejected") + " Rejected."],
    [c("rejection_reason"), "Show on rejected records, with a “Submit again” button."],
    [c("reviewed_at, created_at"), "timestamps"],
], [36, 64])

story += h2("Screen 4 — Update odometer")
story += endpoint("POST", "/driver/vehicle/odometer", "driver, body {odometer_km}")
story.append(P("Returns " + c("{message: \"Odometer reading saved.\", odometer_km, odometer_read_at}") + ". Prompt for it on the health screen "
               "when " + c("odometer_read_at") + " is old, since the km-based service date depends on it."))

story += h2("Screen 5 — Upload a renewed paper")
story += endpoint("POST", "/driver/documents", "driver, multipart (existing onboarding endpoint)")
story.append(P("Fields: " + c("type") + " (" + c("insurance_certificate | roadworthiness_certificate | vehicle_licence") + "), "
               + c("document") + " (file), " + c("expires_at") + " (date after today; required for these papers). "
               "The paper goes to Operations' existing driver-document review; once approved, the item updates and any block lifts. "
               "Fleet cars get 422: “Your fleet car's papers are managed by E-tiGo operations.”"))

story += h2("Screen 6 — Going online")
story += endpoint("POST", "/driver/toggle-online", "driver")
story += bullets([
    "<b>Blocked:</b> 422 " + c("{message: \"Cannot go online.\", reasons: [...]}") + ". One reason reads e.g. "
    "“Insurance expired on 1 Nov 2026. Upload the renewed paper; you can go online once E-tiGo approves it.” Show the list, with an upload button.",
    "<b>Allowed with a warning:</b> 200 with " + c("vehicle_notice") + " set when papers are expiring soon or in grace. Show it as a dismissible banner.",
])

story += h2("Driver notifications")
story.append(P("Category " + c("compliance") + ". " + c("data.vehicle_id") + " is included where relevant; deep-link to the health screen."))
story += table([
    ["data.type", "Title", "Sent when"],
    [c("vehicle_maintenance_due"), "Vehicle Check Due", "Daily check: items changed to due soon, overdue, expired or still missing. Body joins the items' messages."],
    [c("vehicle_blocked"), "You Can't Go Online", "The grace period ended. Body: block reason + “Upload the renewed paper…”"],
    [c("vehicle_unblocked"), "You Can Go Online Again", "A renewed paper was approved."],
    [c("maintenance_record_reviewed"), "Maintenance Record Checked", "Operations approved or rejected a record (rejection includes the reason)."],
], [28, 24, 48])

story += h1("B3. Admin dashboard screens (Operations)")
story += h2("Screen A — Maintenance overview (what needs attention)")
story += endpoint("GET", "/admin/vehicle-maintenance", "Operations, 30 per page")
story += table([
    ["Query param", "Notes"],
    [c("status"), "Comma list of item statuses. Default " + c("due_soon,overdue,expired") + "."],
    [c("type"), "One item type, e.g. " + c("insurance") + "."],
    [c("fleet_only, blocked_only"), "true/false toggles."],
    [c("search"), "Plate, make or model."],
], [24, 76])
story.append(P("<b>Response:</b> " + c("items[]") + " (item fields from B2 plus " + c("vehicle: {id, plate_number, make, model, is_fleet, is_blocked, driver: {id, name}}") +
               "), " + c("summary") + " and " + c("meta") + ". Sorted expired, overdue, due soon, unknown, then by due date. "
               + c("summary") + " has a count per item status present (" + c("ok, due_soon, overdue, expired, unknown") + "), plus "
               + c("blocked_vehicles") + " and " + c("pending_records") + ". Use them as KPI tiles; a missing status key means 0."))

story += h2("Screen B — Records to review")
story += endpoint("GET", "/admin/vehicle-maintenance/records?status=pending&vehicle_id=", "Operations, oldest first")
story += endpoint("POST", "/admin/vehicle-maintenance/records/{id}/review", "body {action: approve|reject, rejection_reason}")
story.append(P("Records include, for admins, " + c("vehicle: {id, plate_number, is_fleet}") + " and " + c("submitted_by: {id, name, type}") +
               ". Show the proof (" + c("proof_url") + ") next to the details so the reviewer can compare. " + c("rejection_reason") +
               " is required to reject (max 500) and is sent to the driver. 422 if the record was already reviewed, or if its odometer reading conflicts with another one."))

story += h2("Screen C — Vehicle maintenance detail")
story += endpoint("GET", "/admin/vehicles/{vehicle_id}/maintenance", "Operations")
story.append(P("Returns " + c("vehicle") + " (full vehicle incl. " + c("odometer_km, estimated_odometer_km, battery_level_percent, is_blocked, block_reason, is_fleet, shift_mode, fuel_type") + "), "
               + c("items[]") + ", " + c("records[]") + " (latest 50, with submitter) and " + c("documents[]") + " (the car's papers). "
               "Suggested layout: header with plate, driver and block state; checklist; history tabs for records and papers."))
story += h3("Action: record work (fleet cars, or on a driver's behalf)")
story += endpoint("POST", "/admin/vehicles/{vehicle_id}/maintenance-records", "Operations, multipart")
story.append(P("Same fields as the driver form (Screen 2), but " + c("proof") + " is optional and the record is <b>approved at once</b>. "
               "Returns " + c("{message: \"Maintenance recorded.\", record, items}") + ". Use " + c("items") + " to refresh the checklist."))
story += h3("Action: upload a paper")
story += endpoint("POST", "/admin/vehicles/{vehicle_id}/documents", "Operations, multipart")
story.append(P("Fields " + c("type") + ", " + c("document") + ", " + c("expires_at") + " (required for insurance, roadworthiness and vehicle licence). "
               "Approved at once and replaces the car's earlier paper of that type. Returns " + c("{message, document, items}") + "."))

story += h2("Screen D — Unlinked papers")
story += endpoint("GET", "/admin/vehicle-documents/unlinked", "Operations")
story += endpoint("POST", "/admin/vehicle-documents/{document_id}/link", "body {vehicle_id}")
story.append(P("Vehicle papers that couldn't be matched to one car when maintenance launched (the driver had no car, or several). "
               "Each comes with " + c("driver: {id, name}") + ". A small clean-up screen: pick the vehicle, link."))

story += h2("Screen E — Maintenance settings")
story += endpoint("GET", "/admin/settings/vehicle-maintenance", "Operations")
story += endpoint("PUT", "/admin/settings/vehicle-maintenance", "send only changed fields")
story += table([
    ["Setting", "Default", "Rules"],
    [c("enabled"), "true", "Off: items are still tracked and shown, but no reminders or blocks."],
    [c("intervals"), "service 5000 km / 3 mo; tyres_brakes 6 mo; battery_health 12 mo",
     c("{service: {km, months}, ...}") + ". km 500–100,000, months 1–60; each item needs at least one."],
    [c("fuel_type_intervals"), "none", "Overrides per fuel type, e.g. " + c("{electric: {service: {km: 10000, months: 6}}}") + "."],
    [c("due_soon_days"), "14", "0–90"],
    [c("due_soon_km"), "500", "0–5000"],
    [c("legal_grace_days"), "7", "0–60"],
    [c("block_on_expired_papers"), "true", "Turn the block off and keep only the warnings."],
], [24, 30, 46])

story += h1("B4. Example responses")
story += code('''{
  "vehicle": {
    "id": "v1a2...", "plate_number": "LND-482-KJ", "make": "BYD", "model": "Atto 3",
    "fuel_type": "electric", "is_fleet": false, "shift_mode": false,
    "odometer_km": 48200, "odometer_read_at": "2026-10-01T08:00:00+00:00", "estimated_odometer_km": 52350
  },
  "can_drive": true,
  "block_reason": null,
  "records_managed_by": "driver",
  "items": [
    {"id": "i1...", "type": "service", "label": "Routine service", "is_legal_paper": false,
     "status": "due_soon", "status_label": "Due soon", "due_at": "2026-11-20", "days_until_due": 23,
     "due_odometer_km": 53200, "km_until_due": 850, "last_done_at": "2026-08-20", "last_odometer_km": 48200,
     "blocks_driving": false, "blocks_driving_from": null,
     "message": "Routine service is due by 20 Nov 2026 or at 53200 km."},
    {"id": "i2...", "type": "insurance", "label": "Insurance", "is_legal_paper": true,
     "status": "expired", "status_label": "Expired", "due_at": "2026-10-25", "days_until_due": -3,
     "due_odometer_km": null, "km_until_due": null, "last_done_at": "2025-10-26", "last_odometer_km": null,
     "blocks_driving": false, "blocks_driving_from": "2026-11-02",
     "message": "Insurance expired on 25 Oct 2026; upload the renewed paper by 1 Nov 2026 to keep driving."},
    {"id": "i3...", "type": "tyres_brakes", "label": "Tyres & brakes check", "is_legal_paper": false,
     "status": "unknown", "status_label": "No record yet", "due_at": null, "days_until_due": null,
     "...": "...", "message": "Add the date of your last tyres & brakes check."}
  ],
  "charging": {
    "battery_level_percent": 42, "battery_level_at": "2026-10-28T07:15:00+00:00",
    "active_reservation": {"id": "a1f0...", "status": "confirmed",
                           "station": {"id": "9d4c...", "name": "Lekki Phase 1 Hub"}, "confirmed_slot": "02:30 pm"},
    "reservation_fee": {"amount_kobo": 50000, "waived": false, "waiver_reason": null}
  },
  "pending_records": [
    {"id": "r9...", "item_type": "tyres_brakes", "item_label": "Tyres & brakes check", "performed_at": "2026-10-26",
     "status": "pending", "status_label": "Awaiting review", "proof_url": "https://...", "...": "..."}
  ]
}''', "GET /driver/vehicle/status (trimmed)")
story += code('''{
  "items": [
    {"type": "insurance", "label": "Insurance", "status": "expired", "status_label": "Expired",
     "due_at": "2026-10-19", "days_until_due": -9, "blocks_driving": true,
     "message": "Insurance expired on 19 Oct 2026; you can't go online until a renewed paper is approved.",
     "...": "other item fields",
     "vehicle": {"id": "v7...", "plate_number": "KJA-210-XY", "make": "Toyota", "model": "Corolla",
                 "is_fleet": true, "is_blocked": true, "driver": {"id": "d3...", "name": "Musa Ibrahim"}}}
  ],
  "summary": {"ok": 212, "due_soon": 14, "overdue": 6, "expired": 3, "unknown": 41,
              "blocked_vehicles": 2, "pending_records": 5},
  "meta": {"current_page": 1, "last_page": 1, "per_page": 30, "total": 23}
}''', "GET /admin/vehicle-maintenance (trimmed)")

# ================================================================ PART C
story += part("Part C — Repair & service requests",
              "Drivers can already ask E-tiGo for a repair or a maintenance visit from the app. "
              "This is separate from the maintenance checklist in Part B.")
story += h1("C1. Driver side (built)")
story += endpoint("GET", "/driver/repairs", "driver")
story += endpoint("POST", "/driver/repairs", "driver, multipart")
story += endpoint("GET", "/driver/repairs/{id}", "driver")
story += endpoint("POST", "/driver/repairs/{id}/attachments", "driver, multipart {attachment, title}")
story += endpoint("DELETE", "/driver/repairs/{id}/attachments/{attachment_id}", "driver")
story += table([
    ["Form field", "Required", "Rules"],
    [c("type"), "yes", c("repair") + " or " + c("maintenance")],
    [c("title"), "yes", "Max 150, e.g. “Brake noise”."],
    [c("details"), "yes", "Max 2000."],
    [c("preferred_date"), "yes", "Date."],
    [c("preferred_time"), "yes", "Text, max 50."],
    [c("attachment"), "no", "Photo or PDF (jpg, png, webp, pdf), max 10 MB."],
], [20, 12, 68])
story.append(P("Response object: " + c("id, reference_number, type{value,label}, status{value,label}, title, details, preferred_date, preferred_time, summary_note, "
               "vehicle_name, vehicle_plate, vehicle_color, workshop_visit{scheduled_time, instructions, support_phone}, appointment, cancellation_reason, cancelled_by, "
               "progress_steps[{title, subtitle, state}], attachments[], submitted_at") + ". Status values: "
               + c("in_progress, scheduled, completed, cancelled") + ". Step " + c("state") + ": " + c("completed | in_progress | upcoming") + " (draw as a stepper)."))
story += endpoint("POST", "/driver/repairs/{id}/cancel", "driver, body {reason?}")
story.append(P("New: the driver can cancel their own request while it is open (" + c("can_cancel: true") + "). Files E-tiGo adds (" + c("uploaded_by: operations") +
               ") can't be removed by the driver; hide the delete icon on them."))
story += table([
    ["Status", "label", "Driver sees"],
    [c("in_progress"), "In progress", "Request received; E-tiGo is arranging a technician. Can cancel."],
    [c("scheduled"), "Scheduled", "Workshop visit booked: " + c("appointment") + " (date, time, workshop, address, technician, contact phone, instructions). Can cancel."],
    [c("completed"), "Completed", c("completion_summary") + ", and the workshop report in " + c("attachments") + "."],
    [c("cancelled"), "Cancelled", c("cancellation_reason") + "; " + c("cancelled_by") + " is " + c("driver") + " or " + c("operations") + "."],
], [16, 14, 70])
story += table([
    ["data.type", "Title", "Sent when"],
    [c("repair_request_scheduled"), "Workshop Visit Booked", "Operations booked or rebooked the visit. Body has the time and workshop."],
    [c("repair_request_completed"), "Vehicle Service Complete", "Operations completed it. Body has the summary."],
    [c("repair_request_cancelled"), "Service Request Cancelled", "Operations cancelled it. Body has the reason."],
], [30, 24, 46])

story += h1("C2. Admin dashboard screens (Operations)")
story += h2("Screen A \u2014 Repair requests queue")
story += endpoint("GET", "/admin/repair-requests", "Operations")
story += table([
    ["Query param", "Notes"],
    [c("status"), "Comma list. Default " + c("in_progress,scheduled") + " (the open ones). Tabs: New | Booked | Completed | Cancelled."],
    [c("type"), c("repair") + " or " + c("maintenance") + "."],
    [c("search"), "Reference (REP-1041), title, plate, driver name or phone."],
    [c("from, to"), "Submitted between these Lagos dates."],
    [c("vehicle_id"), "One car (link from the vehicle maintenance screen)."],
], [20, 80])
story.append(P("<b>Response:</b> " + c("repairs[]") + ", " + c("summary") + " (count per status, for tab badges) and " + c("meta") +
               ". Order: new requests oldest first, then booked visits soonest first, then the rest. Suggested columns: reference, driver ("
               + c("driver.name, driver.phone") + "), vehicle plate, type, title, preferred date and time, status, booked time ("
               + c("appointment.date, appointment.time") + "), handled by (" + c("handled_by.name") + ")."))
story += h2("Screen B \u2014 Request detail")
story += endpoint("GET", "/admin/repair-requests/{id}", "Operations")
story.append(P("Header: reference, status, driver, vehicle. Body: title and details, preferred time, photos (" + c("attachments") +
               "), the progress stepper, appointment and completion summary. Actions depend on status: open requests offer Book visit, Complete and Cancel; every status offers Add file."))
story += h3("Action: Book (or rebook) the workshop visit")
story += endpoint("POST", "/admin/repair-requests/{id}/schedule", "Operations")
story += table([
    ["Field", "Required", "Notes"],
    [c("scheduled_at"), "yes", "Date + time, ISO 8601 with the Lagos offset. Not in the past. Prefill from the driver's preferred date."],
    [c("workshop_name"), "yes", "Max 150."],
    [c("workshop_address"), "yes", "Max 300."],
    [c("technician_name"), "no", "Max 100. Shown on the \u201cTechnician assigned\u201d step."],
    [c("contact_phone"), "no", "Max 30. Replaces the fleet support number for the driver."],
    [c("instructions"), "no", "Max 1000, e.g. \u201cAsk for Emeka at the front desk.\u201d"],
], [20, 13, 67])
story += h3("Action: Complete")
story += endpoint("POST", "/admin/repair-requests/{id}/complete", "Operations, multipart")
story.append(P(c("summary") + " is required (5\u20132000), shown to the driver: what was done and anything they should know. " + c("report") +
               " is an optional file (jpg, png, webp or pdf, up to 10 MB). Tip: offer \u201cAlso log this on the car's maintenance checklist\u201d, which calls "
               + c("POST /admin/vehicles/{vehicle_id}/maintenance-records") + " with " + c("driver_repair_id") + "."))
story += h3("Action: Cancel")
story += endpoint("POST", "/admin/repair-requests/{id}/cancel", "body {reason}")
story.append(P(c("reason") + " is required (5\u2013500) and shown to the driver."))
story += h3("Action: Add file")
story += endpoint("POST", "/admin/repair-requests/{id}/attachments", "multipart {attachment, title?}")
story.append(P("A quote, photos or a report, at any stage. Refused actions return 422 with " + c("current_status") + ", as on the EV screens."))
story += callout("pick", "The repair statuses, notifications and the rebook rule are engineering picks (28 Oct 2026), not client-confirmed.")

# ================================================================ PART D
story += part("Part D \u2014 What changed in revision 2",
              "The gaps found in revision 1, and how each was closed. Nothing here breaks a screen built on revision 1.")
story += table([
    ["#", "Revision 1 gap", "Now"],
    ["1", "Repair requests had no admin side", "Built: Operations queue, detail, book, complete, cancel, add file; driver can cancel (Part C)."],
    ["2", "Admins couldn't end a driver's EV reservation", "Built: Cancel (with optional fee refund) and End session (Part A, Screen F)."],
    ["3", "No admin list of all EV reservations", "Built: " + c("GET /admin/ev-reservations") + " with filters and search (Screen F)."],
    ["4", "Request form had no \u201chow long\u201d", "Added " + c("estimated_charge_minutes") + " to the request form and to Start charging."],
    ["5", "Queue-ahead fields always null", c("queue_ahead_count") + " and " + c("queue_ahead_description") + " now filled while queued."],
    ["6", "Mixed money units", "Added " + c("fee_amount_kobo") + "; " + c("fee_amount") + " is unchanged."],
    ["7", "Different response keys", "Confirm and decline now return the object under " + c("reservation") + " too; " + c("charge_request") + " kept."],
    ["8", "Display-only times", "Added " + c("check_in_deadline") + " (timestamp)."],
    ["9", "No live updates in the driver app", "Added " + c("ev.reservation.updated") + " on " + c("driver.{userId}") + "."],
    ["10", "\u2014 (new client rule)", "Drivers still paying off an E-tiGo car charge free: " + c("fee_waiver_reason: hire_purchase") + "; " + c("charging.reservation_fee") + " on vehicle status."],
    ["11", "Values only in server config", "EV settings screen (Screen G); " + c("ev_charging") + " and " + c("vehicle_maintenance") + " in " + c("GET /config") + "; refund option on End."],
], [5, 30, 65])
story += h2("Values awaiting client confirmation")
story.append(P("These are engineering defaults. Every one is now a setting Operations can change without a release (EV: Screen G; maintenance: Part B, Screen E), "
               "and the apps read them from " + c("GET /config") + ", so a client decision needs no app or backend change. They have gone to the client in "
               + c("doc/client/EV_MAINTENANCE_REPAIRS_DECISIONS.pdf") + " (EV-1 to EV-7, VM-1 to VM-3, RP-1)."))
story += table([
    ["Value", "Default", "Where it changes"],
    ["Reservation fee", "\u20a6500", "EV settings " + c("reservation_fee")],
    ["Paused hire-purchase agreement counts as paying", "yes", "EV settings " + c("waive_fee_while_agreement_paused")],
    ["Stall hold / check-in", "30 min", "EV settings " + c("reservation_expiry_minutes")],
    ["Free cancellation", "2 min", "EV settings " + c("free_cancellation_minutes")],
    ["Queue size, queue window, retry wait", "10 / 15 min / 15 min", "EV settings"],
    ["Refund when Operations cancel or end", "Operations' choice each time (off by default)", c("refund_fee") + " on Cancel and End"],
    ["Service intervals", "5,000 km or 3 mo; 6 mo; 12 mo", "Maintenance settings " + c("intervals")],
    ["Reminder lead", "14 days / 500 km", "Maintenance settings"],
    ["Grace before blocking", "7 days", "Maintenance settings " + c("legal_grace_days")],
    ["Rebook a workshop visit", "allowed while open", "\u2014 (product rule)"],
], [38, 27, 35])

doc = DocTemplate(OUT, pagesize=A4, leftMargin=MARGIN, rightMargin=MARGIN, topMargin=MARGIN, bottomMargin=18 * mm,
                  title="EV Charging & Vehicle Maintenance — Design and Frontend Guide",
                  author="E-tiGo engineering", subject="Flows, screens, fields and API responses")
frame = Frame(MARGIN, 18 * mm, CONTENT_W, H - MARGIN - 18 * mm, id="f", leftPadding=0, rightPadding=0, topPadding=0, bottomPadding=0)
doc.addPageTemplates([
    PageTemplate(id="cover", frames=[frame], onPage=on_cover),
    PageTemplate(id="body", frames=[frame], onPage=on_page),
])
from reportlab.platypus import NextPageTemplate
story.insert(0, NextPageTemplate("body"))
doc.multiBuild(story)
print("ok", OUT)
