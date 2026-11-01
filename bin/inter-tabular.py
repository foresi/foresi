#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Points the digits of an Inter face at the glyphs of its tnum feature, and names it Inter Tabular.

tc-lib-pdf reads the character map only and applies no OpenType features, so this is the one way to
tabular figures. Called by bin/build-pdf-fonts. Needs fontTools. The timestamp of the source stays,
so the same source gives the same bytes.

Usage: inter-tabular.py <source.ttf> <target.ttf>
"""
import sys

from fontTools.ttLib import TTFont

DIGITS = range(0x30, 0x3A)
NAMES = (1, 3, 4, 6, 16)
POSTSCRIPT_NAME = 6


def single_substitutions(font, tag):
    table = font['GSUB'].table
    mapping = {}
    for record in table.FeatureList.FeatureRecord:
        if record.FeatureTag != tag:
            continue
        for index in record.Feature.LookupListIndex:
            for subtable in table.LookupList.Lookup[index].SubTable:
                subtable = getattr(subtable, 'ExtSubTable', subtable)
                if hasattr(subtable, 'mapping'):
                    mapping.update(subtable.mapping)
    return mapping


def main(source, target):
    font = TTFont(source, recalcTimestamp=False)
    tabular = single_substitutions(font, 'tnum')
    for table in font['cmap'].tables:
        if not table.isUnicode():
            continue
        for code in DIGITS:
            glyph = table.cmap.get(code)
            if glyph in tabular:
                table.cmap[code] = tabular[glyph]
    for record in font['name'].names:
        if record.nameID in NAMES:
            family = 'InterTabular' if POSTSCRIPT_NAME == record.nameID else 'Inter Tabular'
            record.string = record.toUnicode().replace('Inter', family)
    font.save(target)
    widths = {font['hmtx'][font.getBestCmap()[code]][0] for code in DIGITS}
    if 1 != len(widths):
        sys.exit('The digits of %s did not become tabular.' % source)


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
