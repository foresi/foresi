# Fonts of the PDF documents

The Inter faces are Inter 4.1 by The Inter Project Authors, under the SIL Open Font License 1.1 (`OFL.txt`), which names no reserved font name. The Gelasio faces are Gelasio 1.008 by The Gelasio Project Authors, under the SIL Open Font License 1.1 (`OFL-Gelasio.txt`), which names no reserved font name either. They are stored in the format of tc-lib-pdf-font and embedded as subsets in each document; Gelasio only in a document whose markup names it.

| Family | Style | Files | Source |
| --- | --- | --- | --- |
| `intertnum` | regular | `intertnum.*` | `Inter-Regular.ttf`, modified |
| `intertnum` | bold | `intertnumb.*` | `Inter-SemiBold.ttf`, modified |
| `interdisplay` | regular | `interdisplay.*` | `InterDisplay-SemiBold.ttf`, unchanged |
| `gelasio` | regular | `gelasio.*` | `Gelasio-Regular.ttf`, unchanged |
| `gelasio` | bold | `gelasiob.*` | `Gelasio-Bold.ttf`, unchanged |

Run `bin/build-pdf-fonts inter <Inter-4.1.zip>` or `bin/build-pdf-fonts gelasio <directory>` to make them again; it checks the sources by SHA-256 and gives the same files every time. The Gelasio faces are the static TTFs in `fonts/ttf/` of [SorkinType/Gelasio at 7ab20e7](https://github.com/SorkinType/Gelasio/tree/7ab20e7e5c42791e603b9ee3201a0b49849cfdb2/fonts/ttf). The tabular step, `bin/inter-tabular.py`, needs Python 3 with fontTools.

The modification: tc-lib-pdf applies no OpenType features, so `tnum` cannot be turned on. In `intertnum` the character map of the digits 0 to 9 points at the glyphs of the `tnum` feature instead, and the font is named Inter Tabular. Nothing else differs.
