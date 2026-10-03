import zipfile, re, sys

path = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\huawei\Downloads\ALLEN.docx"
out = sys.argv[2] if len(sys.argv) > 2 else path.rsplit(".", 1)[0] + "_extracted.txt"

with zipfile.ZipFile(path) as z:
    xml = z.read("word/document.xml").decode("utf-8", errors="replace")

# Insert newlines at paragraph and break boundaries, tabs at tabs
xml = re.sub(r"<w:tab[^>]*/>", "\t", xml)
xml = re.sub(r"<w:br[^>]*/>", "\n", xml)
xml = re.sub(r"</w:p>", "\n", xml)
# Table row/cell boundaries
xml = re.sub(r"</w:tc>", " | ", xml)
xml = re.sub(r"</w:tr>", "\n", xml)
# Strip remaining tags
text = re.sub(r"<[^>]+>", "", xml)
# Unescape entities
text = (text.replace("&amp;", "&").replace("&lt;", "<").replace("&gt;", ">")
            .replace("&quot;", '"').replace("&apos;", "'"))
# Collapse excess blank lines
text = re.sub(r"\n{3,}", "\n\n", text)

with open(out, "w", encoding="utf-8") as f:
    f.write(text)

print(text[:3000])
print("-----CHARS:", len(text))
