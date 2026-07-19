from pathlib import Path
import textwrap


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docs" / "USER_JOURNEY_FLOW.md"
OUTPUT = ROOT / "docs" / "USER_JOURNEY_FLOW.pdf"


def escape_pdf_text(value: str) -> str:
    return value.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def markdown_to_lines(markdown: str) -> list[str]:
    lines: list[str] = []
    for raw in markdown.splitlines():
        line = raw.rstrip()
        if line.startswith("# "):
            lines.append("")
            lines.append(line[2:].strip().upper())
            lines.append("")
            continue
        if line.startswith("## "):
            lines.append("")
            lines.append(line[3:].strip())
            continue
        if line.startswith("- "):
            wrapped = textwrap.wrap(line[2:].strip(), width=88)
            for index, part in enumerate(wrapped):
                prefix = "- " if index == 0 else "  "
                lines.append(prefix + part)
            continue
        if line == "":
            lines.append("")
            continue
        wrapped = textwrap.wrap(line, width=90) or [""]
        lines.extend(wrapped)
    return lines


def paginate(lines: list[str], page_line_limit: int = 44) -> list[list[str]]:
    pages: list[list[str]] = []
    current: list[str] = []
    for line in lines:
        if len(current) >= page_line_limit:
            pages.append(current)
            current = []
        current.append(line)
    if current:
        pages.append(current)
    return pages


def build_page_content(page_lines: list[str], page_no: int, total_pages: int) -> str:
    commands: list[str] = ["BT", "/F1 12 Tf", "50 790 Td", "14 TL"]
    title = "CRM User Journey Flow"
    commands.append(f"({escape_pdf_text(title)}) Tj")
    commands.append("T*")
    commands.append("/F1 9 Tf")
    commands.append(f"(Page {page_no} of {total_pages}) Tj")
    commands.append("T*")
    commands.append("T*")
    commands.append("/F1 11 Tf")
    for line in page_lines:
        commands.append(f"({escape_pdf_text(line)}) Tj")
        commands.append("T*")
    commands.append("ET")
    return "\n".join(commands)


def write_pdf(pages: list[list[str]], output_path: Path) -> None:
    objects: list[bytes] = []

    def add_object(payload: str) -> int:
        objects.append(payload.encode("latin-1", "replace"))
        return len(objects)

    font_id = add_object("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")

    page_ids: list[int] = []
    content_ids: list[int] = []
    total_pages = len(pages)
    for idx, page in enumerate(pages, start=1):
        content = build_page_content(page, idx, total_pages)
        content_id = add_object(f"<< /Length {len(content.encode('latin-1', 'replace'))} >>\nstream\n{content}\nendstream")
        content_ids.append(content_id)
        page_ids.append(0)

    kids_refs = []
    pages_id_placeholder = add_object("<< /Type /Pages /Kids [] /Count 0 >>")
    for idx, content_id in enumerate(content_ids):
        page_obj = (
            f"<< /Type /Page /Parent {pages_id_placeholder} 0 R "
            f"/MediaBox [0 0 612 792] "
            f"/Resources << /Font << /F1 {font_id} 0 R >> >> "
            f"/Contents {content_id} 0 R >>"
        )
        page_ids[idx] = add_object(page_obj)
        kids_refs.append(f"{page_ids[idx]} 0 R")

    objects[pages_id_placeholder - 1] = (
        f"<< /Type /Pages /Kids [{' '.join(kids_refs)}] /Count {len(page_ids)} >>".encode("latin-1")
    )

    catalog_id = add_object(f"<< /Type /Catalog /Pages {pages_id_placeholder} 0 R >>")

    pdf = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]
    for obj_id, obj in enumerate(objects, start=1):
        offsets.append(len(pdf))
        pdf.extend(f"{obj_id} 0 obj\n".encode("latin-1"))
        pdf.extend(obj)
        pdf.extend(b"\nendobj\n")

    xref_offset = len(pdf)
    pdf.extend(f"xref\n0 {len(objects) + 1}\n".encode("latin-1"))
    pdf.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        pdf.extend(f"{offset:010d} 00000 n \n".encode("latin-1"))
    pdf.extend(
        (
            f"trailer\n<< /Size {len(objects) + 1} /Root {catalog_id} 0 R >>\n"
            f"startxref\n{xref_offset}\n%%EOF\n"
        ).encode("latin-1")
    )

    output_path.write_bytes(pdf)


def main() -> None:
    markdown = SOURCE.read_text(encoding="utf-8")
    lines = markdown_to_lines(markdown)
    pages = paginate(lines)
    write_pdf(pages, OUTPUT)
    print(str(OUTPUT))


if __name__ == "__main__":
    main()
