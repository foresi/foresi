# Fonts of the PDF documents

All faces are Inter 4.1 by The Inter Project Authors, under the SIL Open Font License 1.1 (`OFL.txt`), which names no reserved font name. They are stored in the format of tc-lib-pdf-font and embedded as subsets in each document.

| Family | Style | Files | Source |
| --- | --- | --- | --- |
| `intertnum` | regular | `intertnum.*` | `Inter-Regular.ttf`, modified |
| `intertnum` | bold | `intertnumb.*` | `Inter-SemiBold.ttf`, modified |
| `interdisplay` | regular | `interdisplay.*` | `InterDisplay-SemiBold.ttf`, unchanged |

Run `bin/build-pdf-fonts <Inter-4.1.zip>` to make them again; it checks the release and the faces by SHA-256 and gives the same files every time. The tabular step, `bin/inter-tabular.py`, needs Python 3 with fontTools.

The modification: tc-lib-pdf applies no OpenType features, so `tnum` cannot be turned on. In `intertnum` the character map of the digits 0 to 9 points at the glyphs of the `tnum` feature instead, and the font is named Inter Tabular. Nothing else differs.
