# Fonts of the PDF documents

All faces are Inter 4.1 by The Inter Project Authors, under the SIL Open Font License 1.1 (`OFL.txt`), which names no reserved font name. They are stored in the format of tc-lib-pdf-font and embedded as subsets in each document.

| Family | Style | Files | Source |
| --- | --- | --- | --- |
| `intertnum` | regular | `intertnum.*` | `Inter-Regular.ttf`, modified |
| `intertnum` | bold | `intertnumb.*` | `Inter-SemiBold.ttf`, modified |
| `interdisplay` | regular | `interdisplay.*` | `InterDisplay-SemiBold.ttf`, unchanged |

The modification: tc-lib-pdf applies no OpenType features, so `tnum` cannot be turned on. In `intertnum` the character map of the digits 0 to 9 points at the glyphs of the `tnum` feature instead, and the font is named Inter Tabular. Nothing else differs.
