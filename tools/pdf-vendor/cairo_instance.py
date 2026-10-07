"""Create static Cairo instances (Regular 400, Bold 700) from the variable font for mPDF.

Besides instancing (wght=400/700, slnt=0) it makes two compatibility changes:
 1. Adds a narrow no-break space (U+202F) glyph. Cairo lacks it, but the app uses it
    as the thousands separator for Arabic-Indic digits.
 2. mPDF's OpenType engine refuses lookups that use GDEF mark filtering sets
    ("contains MarkGlyphSets - Not tested yet"). Cairo uses them in two mark-to-mark
    lookups (marks above / marks below). Each filtering set is converted to an
    equivalent GDEF mark attachment class, which mPDF supports. This keeps the exact
    same behaviour as long as the sets are disjoint and no attachment classes exist,
    which the script asserts.
 3. Cairo's required-ligature feature (rlig, always applied by mPDF) contains ~48 designer
    ligatures whose glyphs have no Unicode code point (e.g. seen+noon, sheen+yeh-hamza).
    mPDF maps such glyphs to Private Use Area codes, so words containing them could not be
    copied or searched in the PDF. Those rules are removed; the letters then use the normal
    joined initial/medial/final forms. Ligatures with a Unicode mapping (lam-alef, etc.) stay.

Usage: python3 -I cairo_instance.py <Cairo-VF.ttf> <out_dir>"""
import sys
from fontTools.ttLib import TTFont
from fontTools.ttLib.tables import otTables
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.varLib import instancer

NNBSP_WIDTH = 160  # units per em = 1000; the normal space is 220
USE_MARK_FILTERING_SET = 0x0010


def filtering_sets_to_attachment_classes(font):
    gdef = font["GDEF"].table
    sets_def = getattr(gdef, "MarkGlyphSetsDef", None)
    lookups = [lk for tag in ("GSUB", "GPOS") if tag in font for lk in font[tag].table.LookupList.Lookup]
    users = [lk for lk in lookups if lk.LookupFlag & USE_MARK_FILTERING_SET]
    if not users:
        return 0
    assert sets_def is not None, "lookup uses a filtering set but GDEF has none"
    assert getattr(gdef, "MarkAttachClassDef", None) is None, "font already has mark attachment classes"
    assert not any(lk.LookupFlag & 0xFF00 for lk in lookups), "font already uses mark attachment types"
    used = sorted({lk.MarkFilteringSet for lk in users})
    assert len(used) <= 255
    class_of_set = {}
    class_defs = {}
    for n, set_index in enumerate(used, start=1):
        glyphs = sets_def.Coverage[set_index].glyphs
        for g in glyphs:
            assert g not in class_defs, f"glyph {g} is in two filtering sets"
            class_defs[g] = n
        class_of_set[set_index] = n
    cd = otTables.MarkAttachClassDef()
    cd.classDefs = class_defs
    gdef.MarkAttachClassDef = cd
    for lk in users:
        lk.LookupFlag = (lk.LookupFlag & 0x00FF & ~USE_MARK_FILTERING_SET) | (class_of_set[lk.MarkFilteringSet] << 8)
        del lk.MarkFilteringSet
    gdef.MarkGlyphSetsDef = None
    if getattr(gdef, "VarStore", None) is None:
        gdef.Version = 0x00010000
    return len(users)


def drop_unmapped_required_ligatures(font):
    gsub = font["GSUB"].table
    mapped = set(font.getBestCmap().values())
    rlig = {li for fr in gsub.FeatureList.FeatureRecord if fr.FeatureTag == "rlig" for li in fr.Feature.LookupListIndex}
    removed = 0
    emptied = set()
    for li in sorted(rlig):
        lookup = gsub.LookupList.Lookup[li]
        kept_subtables = []
        for wrapper in lookup.SubTable:
            st = wrapper.ExtSubTable if wrapper.LookupType == 7 else wrapper
            if st.LookupType == 4:
                for first in list(st.ligatures):
                    keep = [lig for lig in st.ligatures[first] if lig.LigGlyph in mapped]
                    removed += len(st.ligatures[first]) - len(keep)
                    if keep:
                        st.ligatures[first] = keep
                    else:
                        del st.ligatures[first]
                if not st.ligatures:
                    continue  # mPDF cannot parse an empty ligature subtable
            kept_subtables.append(wrapper)
        lookup.SubTable = kept_subtables
        lookup.SubTableCount = len(kept_subtables)
        if not kept_subtables:
            emptied.add(li)
    # A lookup left without subtables is detached from every feature (indices stay unchanged)
    for fr in gsub.FeatureList.FeatureRecord:
        idx = [li for li in fr.Feature.LookupListIndex if li not in emptied]
        fr.Feature.LookupListIndex = idx
        fr.Feature.LookupCount = len(idx)
    return removed

src, out = sys.argv[1], sys.argv[2]
for weight, style in ((400, "Regular"), (700, "Bold")):
    vf = TTFont(src)
    inst = instancer.instantiateVariableFont(vf, {"wght": weight, "slnt": 0}, updateFontNames=True)
    name = inst["name"]
    for rec in list(name.names):
        if rec.nameID in (1, 16):
            rec.string = "Cairo"
        elif rec.nameID in (2, 17):
            rec.string = style
        elif rec.nameID == 4:
            rec.string = "Cairo " + style
        elif rec.nameID == 6:
            rec.string = "Cairo-" + style
    inst["OS/2"].usWeightClass = weight
    fs = inst["OS/2"].fsSelection
    fs &= ~((1 << 0) | (1 << 5) | (1 << 6))
    fs |= (1 << 5) if style == "Bold" else (1 << 6)
    inst["OS/2"].fsSelection = fs
    inst["head"].macStyle = 1 if style == "Bold" else 0

    cmap = inst.getBestCmap()
    if 0x202F not in cmap:
        gname = "uni202F"
        order = inst.getGlyphOrder()
        order.append(gname)
        inst.setGlyphOrder(order)
        inst["glyf"][gname] = TTGlyphPen(None).glyph()
        inst["hmtx"].metrics[gname] = (NNBSP_WIDTH, 0)
        for table in inst["cmap"].tables:
            if table.isUnicode():
                table.cmap[0x202F] = gname

    converted = filtering_sets_to_attachment_classes(inst)
    dropped = drop_unmapped_required_ligatures(inst)

    path = f"{out}/Cairo-{style}.ttf"
    inst.save(path)
    print("wrote", path, f"({converted} lookups converted to mark attachment classes, "
          f"{dropped} unmapped rlig ligatures removed)")
