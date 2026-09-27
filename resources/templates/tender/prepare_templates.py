#!/usr/bin/env python3
r"""
Builds the tender document templates in this folder from the user's real,
executed documents (Section 19.3). Run once per new source document:

    python3 prepare_templates.py <source-dir>

What it does, per document:
  * removes every embedded signature / company seal / notary stamp picture
    (the sources are signed originals — a template must never carry them),
    keeping the letterhead and any text boxes, lines and page decoration;
  * swaps each variable value for a ${placeholder}, merging the text runs a
    value is split across and keeping the first run's formatting;
  * leaves every other word, the layout and the legal wording untouched.

The source files themselves are NOT committed (they carry real signatures).
Fills happen at runtime in App\Services\Tender\TenderDocumentService.
"""
import re, sys, zipfile, os

SRC = {
    'power_of_attorney': 'Power of Attorney For Tender no G () .docx',
    'bid_securing_declaration': 'Framework Securing Declaration For Tender no G.docx',
    'performance_securing_declaration': 'PERFORMANCE SECURING DECLARATION - UDOM 728.docx',
    'acceptance_letter': 'Acceptance Letter - UDOM # TR63-2025-2026-728.docx',
}

# Pictures to strip, by media file (what they are, from a visual check).
STRIP = {
    'power_of_attorney': {'media/image2.png',   # company seal
                          'media/image3.png', 'media/image4.png', 'media/image40.png', 'media/image8.png',  # signatures
                          'media/image5.png', 'media/image50.png', 'media/image6.png', 'media/image7.png',  # notary stamps
                          'media/image9.png'},  # handwritten witness name
    'bid_securing_declaration': {'media/image2.png'},          # company seal
    'performance_securing_declaration': {'media/image1.png'},  # signature
    'acceptance_letter': {'media/image1.png'},                 # signature
}

# (old text, placeholder, minimum expected occurrences)
REPLACE = {
    'power_of_attorney': [
        ('126 of 17th day of September 2026', '${resolution_no} of ${resolution_date_dayof}', 1),
        ('18th day of September 2026', '${date_dayof}', 6),
        ('Plot No. 58/29B, Mwinyijuma Road, Mwananyamala Komakoma, Kinondoni District, with Post Office Box 14118 Dar es Salaam',
         'with Post Office Box ${company_po_box} ${company_city}', 1),
        ('Trust House, 2nd Floor,', '${company_physical_address},', 1),
        ('Plot number 334/43, House number KJM-MWG 334, Bamaga, Kijitonyama, Kinondoni District, Dar es Salaam', '${attorney_address}', 1),
        ('MOSES DEOGRATIAS KIDUDUYE', '${attorney_name_upper}', 3),
        ('HYPERMED HEALTHCARE LIMITED', '${company_name_upper}', 5),
        ('52/054/2026/2027/G/19', '${tender_number}', 2),
        ('SUPPLY OF MEDICAL EQUIPMENTS AND MACHINES FOR NJOMBE REGIONAL REFFERAL HOSPITAL', '${tender_title_upper}', 1),
    ],
    'bid_securing_declaration': [
        ('18th September 2026', '${date_short}', 1),
        ('FA/2026/2027/10816/52/019/G/02', '${tender_number}', 1),
        ('Moses Deogratias Kiduduye', '${signatory_name}', 1),
        ('capacity of Managing Director', 'capacity of ${signatory_position}', 1),
        ('18th day of September, 2026', '${date_dayof_comma}', 1),
        ('Hypermed Healthcare Limited', '${company_name}', 2),
    ],
    'performance_securing_declaration': [
        ('day of 17th  of October 2025', 'day of ${date_of}', 1),
        ('17th  October 2025', '${date_short}', 1),
        ('TR63/2025/2026/G/728', '${contract_number}', 1),
        ('Moses Kiduduye', '${signatory_name}', 1),
        ('capacity of Managing Director', 'capacity of ${signatory_position}', 1),
        ('HYPERMED HEALTHCARE LIMITED', '${company_name_upper}', 1),
    ],
    'acceptance_letter': [
        ('HH/BMH-HQ/11/2025/013', '${our_ref}', 1),
        ('17th November 2025', '${date_short}', 1),
        ('TR63/2025/2026/G/728', '${tender_number}', 1),
        ('FA/2025/2026/4/TR137/G/64/1/1', '${entity_ref}', 1),
        ('13-11-2025', '${entity_ref_date}', 1),
        ('SUPPLY OF REAGENTS FOR HB ELETROPHORESIS H8-ANALYZER', '${tender_title_upper}', 1),
        ('TZS. 7,752,000.00 (Seven Million Seven Hundred Fifty-Two Thousand.) (VAT Exclusive)',
         '${currency}. ${value} (${value_words}.) (VAT ${vat_basis})', 1),
        ('HYPERMED HEALTHCARE LTD', '${company_short_upper}', 1),
        ('Moses Kiduduye', '${signatory_name}', 1),
        ('Managing Director', '${signatory_position}', 1),
    ],
}

# Addressee blocks: the first paragraph becomes ${entity_address} (filled
# with line breaks at runtime); the following ones are removed.
ADDRESS = {
    'bid_securing_declaration': ['MEDICAL OFFICER INCHARGE,', 'LIGULA REGIONAL REFFERAL HOSPITAL (MTWARA),', 'P.O.BOX  520,', 'CHUNO, MTWARA.'],
    'performance_securing_declaration': ['Vice Chancellor', 'University of Dodoma,', 'P.O Box 259,', 'DODOMATANZANIA'],
    'acceptance_letter': ['Executive Director,', 'THE BENJAMIN MKAPA HOSPITAL,', 'P.O Box 11088,', 'DODOMA TANZANIA'],
}

T_RE = re.compile(r'(<w:t(?: [^>]*)?>)([^<]*)(</w:t>)')
P_RE = re.compile(r'<w:p[ >].*?</w:p>', re.S)


def strip_pictures(xml, rels, strip):
    ids = {rid for rid, target in rels.items() if target in strip}
    if not ids:
        return xml, 0
    alt = '|'.join(ids)
    n = 0
    # Whole drawing/VML containers that only hold one of these pictures.
    def whole(m):
        nonlocal n
        s = m.group(0)
        if re.search(r'<w:t[ >]', s):
            return s
        if re.search(r'r:(?:embed|id)="(%s)"' % alt, s):
            n += 1
            return ''
        return s
    xml = re.sub(r'<mc:AlternateContent>(?:(?!<mc:AlternateContent>).)*?</mc:AlternateContent>', whole, xml, flags=re.S)
    xml = re.sub(r'<w:drawing>.*?</w:drawing>|<w:pict>.*?</w:pict>', whole, xml, flags=re.S)
    # Pictures inside groups that also hold text boxes: drop just the picture.
    before = len(xml)
    xml = re.sub(r'<pic:pic\b(?:(?!</pic:pic>).)*?r:embed="(%s)".*?</pic:pic>' % alt, '', xml, flags=re.S)
    xml = re.sub(r'<wpg:grpSp>(?:(?!</wpg:grpSp>).)*?</wpg:grpSp>', lambda m: m.group(0) if '<w:t' in m.group(0) or 'pic:pic' in m.group(0) or 'wps:' in m.group(0) else '', xml, flags=re.S)
    xml = re.sub(r'<v:shape\b[^>]*>(?:(?!</v:shape>).)*?<v:imagedata [^>]*r:id="(%s)"[^>]*/>.*?</v:shape>' % alt, '', xml, flags=re.S)
    xml = re.sub(r'<v:shape\b[^>]*>\s*<v:imagedata [^>]*r:id="(%s)"[^>]*/>\s*</v:shape>' % alt, '', xml, flags=re.S)
    if len(xml) != before:
        n += 1
    left = re.findall(r'r:(?:embed|id)="(%s)"' % alt, xml)
    assert not left, f'pictures still referenced: {left}'
    return xml, n


def replace_in_paragraph(p, old, new):
    """Replace every occurrence of `old` in the paragraph's visible text."""
    count = 0
    while True:
        ts = list(T_RE.finditer(p))
        text = ''.join(m.group(2) for m in ts)
        i = text.find(old)
        if i < 0:
            return p, count
        j = i + len(old)
        pos, pieces = 0, []
        for m in ts:
            a, b = pos, pos + len(m.group(2))
            pieces.append((m, a, b))
            pos = b
        out, last = [], 0
        placed = False
        for m, a, b in pieces:
            out.append(p[last:m.start()])
            last = m.end()
            t = m.group(2)
            if b <= i or a >= j:
                out.append(m.group(0))
                continue
            keep_l = t[:max(0, i - a)]
            keep_r = t[max(0, j - a):] if b > j else ''
            mid = new if not placed else ''
            placed = True
            open_tag = m.group(1)
            if 'xml:space' not in open_tag:
                open_tag = '<w:t xml:space="preserve">'
            out.append(open_tag + keep_l + mid + keep_r + m.group(3))
        out.append(p[last:])
        p = ''.join(out)
        count += 1


def replace_all(xml, old, new):
    total = 0
    def one(m):
        nonlocal total
        p, c = replace_in_paragraph(m.group(0), old, new)
        total += c
        return p
    return P_RE.sub(one, xml), total


def ptext(p):
    return ''.join(m.group(2) for m in T_RE.finditer(p)).strip()


def address_block(xml, lines):
    paras = list(P_RE.finditer(xml))
    for k in range(len(paras)):
        if ptext(paras[k].group(0)) == lines[0]:
            span = paras[k:k + len(lines)]
            got = [ptext(s.group(0)) for s in span]
            # tolerate a line split into two paragraphs (e.g. DODOMA / TANZANIA)
            assert [g.replace(' ', '') for g in got] == [l.replace(' ', '') for l in lines], (got, lines)
            first, _ = replace_in_paragraph(span[0].group(0), lines[0], '${entity_address}')
            # A justified paragraph stretches every line that ends in a line
            # break, so the multi-line address must be left-aligned.
            first = first.replace('<w:jc w:val="both"/>', '<w:jc w:val="left"/>')
            return xml[:span[0].start()] + first + xml[span[-1].end():]
    raise AssertionError(f'address block not found: {lines[0]}')


def rels_of(z, part):
    name = part.replace('word/', 'word/_rels/') + '.rels'
    if name not in z.namelist():
        return {}
    r = z.read(name).decode()
    return {m.group(1): m.group(2) for m in re.finditer(r'<Relationship [^>]*?Id="([^"]+)"[^>]*?Target="([^"]+)"', r)} | \
           {m.group(2): m.group(1) for m in re.finditer(r'<Relationship [^>]*?Target="([^"]+)"[^>]*?Id="([^"]+)"', r)}


def build(src_dir, key, out_dir):
    z = zipfile.ZipFile(os.path.join(src_dir, SRC[key]))
    parts = [n for n in z.namelist() if re.match(r'word/(document|header\d*|footer\d*)\.xml$', n)]
    new_parts = {}
    for part in parts:
        xml = z.read(part).decode('utf-8')
        xml, _ = strip_pictures(xml, rels_of(z, part), STRIP[key])
        if part == 'word/document.xml' and key in ADDRESS:
            xml = address_block(xml, ADDRESS[key])
        new_parts[part] = xml
    if key == 'acceptance_letter':
        # The source pushes "Date:" right with spaces; the filled reference
        # and date run a few characters longer, so trim that padding.
        x = new_parts['word/document.xml']
        pad = '<w:t xml:space="preserve">                         </w:t>'
        assert x.count(pad) == 1
        new_parts['word/document.xml'] = x.replace(pad, '<w:t xml:space="preserve">                </w:t>')
    for old, new, minimum in REPLACE[key]:
        total = 0
        for part in parts:
            new_parts[part], c = replace_all(new_parts[part], old, new)
            total += c
        assert total >= minimum, f'{key}: "{old}" replaced {total}x, expected >= {minimum}'
        print(f'  {key}: {total}x {new}')
    out = os.path.join(out_dir, f'{key}.docx')
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as w:
        for item in z.infolist():
            name = item.filename
            if name.startswith('word/media/') and name.replace('word/', '') in STRIP[key]:
                continue  # drop the picture file itself too
            data = new_parts[name].encode('utf-8') if name in new_parts else z.read(name)
            w.writestr(item, data)
    return out


if __name__ == '__main__':
    src = sys.argv[1] if len(sys.argv) > 1 else os.path.expanduser('~/Downloads')
    here = os.path.dirname(os.path.abspath(__file__))
    for key in SRC:
        print(build(src, key, here))
