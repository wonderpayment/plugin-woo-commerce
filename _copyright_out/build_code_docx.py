from __future__ import annotations

import os
import re
from dataclasses import dataclass
from pathlib import Path
from zipfile import ZipFile

from docx import Document
from docx.enum.text import WD_LINE_SPACING
from docx.oxml.ns import qn
from docx.shared import Mm, Pt


@dataclass(frozen=True)
class FileLine:
    path: str
    line_no: int
    text: str


EXCLUDE_DIRS = {
    '.git', '.idea',
    'vendor',
    '_copyright_out',
}

INCLUDE_SUFFIXES = {
    # Soft copyright submissions typically expect source code.
    '.php', '.js', '.css',
}


def iter_source_files(root: Path) -> list[Path]:
    files: list[Path] = []
    for p in root.rglob('*'):
        if not p.is_file():
            continue
        rel = p.relative_to(root)
        if any(part in EXCLUDE_DIRS for part in rel.parts):
            continue
        if p.suffix.lower() not in INCLUDE_SUFFIXES:
            continue
        files.append(p)
    # deterministic order
    files.sort(key=lambda x: str(x.relative_to(root)).lower())
    return files


def read_lines(p: Path) -> list[str]:
    # tolerate mixed encodings; WP plugins generally UTF-8
    try:
        return p.read_text(encoding='utf-8').splitlines()
    except UnicodeDecodeError:
        return p.read_text(encoding='utf-8', errors='replace').splitlines()


def build_lines(root: Path) -> list[FileLine]:
    out: list[FileLine] = []
    for f in iter_source_files(root):
        rel = str(f.relative_to(root))
        lines = read_lines(f)
        for idx, line in enumerate(lines, start=1):
            out.append(FileLine(path=rel, line_no=idx, text=line.rstrip('\n')))
    return out


def choose_front_back(
    lines: list[FileLine],
    *,
    full: bool,
    total_target: int = 60,
    front: int = 30,
    back: int = 30,
) -> list[FileLine]:
    if full:
        return lines
    if len(lines) <= total_target:
        return lines
    return lines[:front] + lines[-back:]


def apply_code_style(paragraph) -> None:
    fmt = paragraph.paragraph_format
    fmt.line_spacing_rule = WD_LINE_SPACING.SINGLE
    fmt.space_before = Pt(0)
    fmt.space_after = Pt(0)

    if paragraph.runs:
        for r in paragraph.runs:
            # Keep run fonts unset; we control defaults via style + OOXML defaults
            # to match the sample docx more closely.
            r.font.name = None
            r.font.size = None


def apply_sample_page_setup(doc: Document) -> None:
    # Match the sample's margins and defaults (A4-ish, Word default sizes).
    sec = doc.sections[0]
    # Sample pgSz: w=11906, h=16838 (twips) ~= A4 portrait.
    sec.page_width = Mm(210)
    sec.page_height = Mm(297)
    # In python-docx, margins are Length values; set via twips approximations.
    # Sample: top/bottom 1440 twips (1"), left/right 1800 twips (1.25").
    sec.top_margin = Pt(72)          # 1 inch
    sec.bottom_margin = Pt(72)       # 1 inch
    sec.left_margin = Pt(90)         # 1.25 inch
    sec.right_margin = Pt(90)        # 1.25 inch
    # Sample pgMar header/footer: header=851 twips, footer=992 twips.
    sec.header_distance = Pt(851 / 20)
    sec.footer_distance = Pt(992 / 20)

    normal = doc.styles["Normal"]
    # Sample styles.xml has rPrDefault with:
    # ascii/hAnsi/cs Times New Roman, eastAsia 宋体, size 10.5pt.
    normal.font.name = "Times New Roman"
    # eastAsia font needs OOXML rFonts to be reliable; we also set it on the style.
    normal._element.rPr.rFonts.set(qn("w:eastAsia"), "宋体")
    normal.font.size = Pt(10.5)
    pf = normal.paragraph_format
    pf.space_before = Pt(0)
    pf.space_after = Pt(0)
    pf.line_spacing_rule = WD_LINE_SPACING.SINGLE


def patch_docx_ooxml_like_sample(docx_path: Path) -> None:
    """Post-process DOCX to align a few OOXML defaults with the sample.

    python-docx doesn't expose everything (e.g., docGrid, rPrDefault), so we patch
    them directly in the zipped OOXML to match the sample template more closely.
    """

    with ZipFile(docx_path, "r") as zf:
        entries = {name: zf.read(name) for name in zf.namelist()}

    styles_xml = entries.get("word/styles.xml", b"").decode("utf-8", errors="ignore")
    if styles_xml:
        # Ensure rPrDefault has the same rFonts tuple as the sample.
        target = (
            '<w:rPrDefault><w:rPr>'
            '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" '
            'w:eastAsia="宋体" w:cs="Times New Roman"/>'
            "</w:rPr></w:rPrDefault>"
        )
        if "<w:rPrDefault>" in styles_xml:
            styles_xml = re.sub(r"<w:rPrDefault>.*?</w:rPrDefault>", target, styles_xml, flags=re.S)
        else:
            # Insert into <w:docDefaults> if present.
            if "<w:docDefaults>" in styles_xml:
                styles_xml = styles_xml.replace("<w:docDefaults>", f"<w:docDefaults>{target}", 1)
        entries["word/styles.xml"] = styles_xml.encode("utf-8")

    document_xml = entries.get("word/document.xml", b"").decode("utf-8", errors="ignore")
    if document_xml:
        # Ensure section properties match the sample: pgSz/pgMar/cols/docGrid.
        def normalize_sectpr(sect: str) -> str:
            pg_sz = '<w:pgSz w:w="11906" w:h="16838"/>'
            pg_mar = (
                '<w:pgMar w:top="1440" w:right="1800" w:bottom="1440" w:left="1800" '
                'w:header="851" w:footer="992" w:gutter="0"/>'
            )
            cols = '<w:cols w:space="425" w:num="1"/>'
            docgrid = '<w:docGrid w:type="lines" w:linePitch="312" w:charSpace="0"/>'

            if "<w:pgSz" in sect:
                sect = re.sub(r"<w:pgSz[^>]*/>", pg_sz, sect)
            else:
                sect = sect.replace(">", f">{pg_sz}", 1)

            if "<w:pgMar" in sect:
                sect = re.sub(r"<w:pgMar[^>]*/>", pg_mar, sect)
            else:
                sect = sect.replace("</w:sectPr>", f"{pg_mar}</w:sectPr>", 1)

            cols_match = re.search(r"<w:cols[^>]*/>", sect)
            if cols_match:
                sect = sect[: cols_match.start()] + cols + sect[cols_match.end() :]
            else:
                sect = sect.replace("</w:sectPr>", f"{cols}</w:sectPr>", 1)

            # Recompute match after any earlier edits to keep spans correct.
            docgrid_match = re.search(r"<w:docGrid[^>]*/>", sect)
            if docgrid_match:
                sect = sect[: docgrid_match.start()] + docgrid + sect[docgrid_match.end() :]
            else:
                sect = sect.replace("</w:sectPr>", f"{docgrid}</w:sectPr>", 1)
            return sect

        m = re.search(r"<w:sectPr[\s\S]*?</w:sectPr>", document_xml)
        if m:
            original = m.group(0)
            sect = normalize_sectpr(original)
            if sect != original:
                document_xml = document_xml[: m.start()] + sect + document_xml[m.end() :]
        entries["word/document.xml"] = document_xml.encode("utf-8")

    # Word can also read section defaults from settings; align docGrid there too.
    settings_xml = entries.get("word/settings.xml", b"").decode("utf-8", errors="ignore")
    if settings_xml:
        docgrid = '<w:docGrid w:type="lines" w:linePitch="312" w:charSpace="0"/>'
        if "<w:docGrid" in settings_xml:
            settings_xml = re.sub(r"<w:docGrid[^>]*/>", docgrid, settings_xml)
        else:
            # Insert near end of settings just before closing tag.
            settings_xml = settings_xml.replace("</w:settings>", f"{docgrid}</w:settings>", 1)
        entries["word/settings.xml"] = settings_xml.encode("utf-8")

    tmp_path = docx_path.with_suffix(".tmp.docx")
    with ZipFile(tmp_path, "w") as zf:
        for name, data in entries.items():
            zf.writestr(name, data)
    tmp_path.replace(docx_path)


def main() -> None:
    root = Path(os.environ.get('PROJECT_ROOT', '.')).resolve()
    out_path = Path(os.environ.get('OUT_PATH', 'code.docx')).resolve()
    software_name = os.environ.get('SOFTWARE_NAME', 'Wonder Payment For WooCommerce')
    version = os.environ.get('SOFTWARE_VERSION', '1.0.4')
    full = os.environ.get('FULL_CODE', '0').strip() in {'1', 'true', 'TRUE', 'yes', 'YES'}
    sample_style = os.environ.get('SAMPLE_STYLE', '1').strip() in {'1', 'true', 'TRUE', 'yes', 'YES'}

    all_lines = build_lines(root)
    picked = choose_front_back(all_lines, full=full)

    doc = Document()
    if sample_style:
        apply_sample_page_setup(doc)

    if not sample_style:
        # Legacy mode with title + line numbers.
        title_suffix = '全量' if full else '节选'
        doc.add_paragraph(f'{software_name} 源代码（{title_suffix}）  版本：{version}')
        doc.add_paragraph('')

    for fl in picked:
        if sample_style:
            # Match sample: pure code paragraphs, no file separators or line numbers.
            p = doc.add_paragraph(fl.text, style="Normal")
        else:
            text = f'{fl.line_no:>4}  {fl.text}'
            p = doc.add_paragraph(text)
        apply_code_style(p)

    doc.save(str(out_path))
    if sample_style:
        patch_docx_ooxml_like_sample(out_path)


if __name__ == '__main__':
    main()
