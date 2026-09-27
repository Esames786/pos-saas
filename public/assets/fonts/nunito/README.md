# Nunito (self-hosted, latin, variable weight)

Vendored once on 2026-09-27 for owner decision A3 (Edge next release, workstream W-C): the Online POS and the
Branch Server must render Nunito with no Internet / CDN dependency.

| Item | Value |
|---|---|
| Family | Nunito (designers: Vernon Adams, Cyreal, Jacques Le Bailly) |
| File | `nunito-latin-wght-v32.woff2` - variable font, `wght` axis, latin subset only |
| Downloaded from | `https://fonts.gstatic.com/s/nunito/v32/XRXV3I6Li01BKofINeaB.woff2` (the URL the Google Fonts API css2 returned for `family=Nunito:wght@300;400;500;600;700`, latin block, for every one of the five weights) |
| Google Fonts version | gstatic `v32` |
| Upstream source | https://github.com/googlefonts/nunito (google/fonts `ofl/nunito/METADATA.pb` source commit `8c6a9bb9732545b9ed53f29ec5e1ab0ff53c4e6f`) |
| SHA-256 | `ba344451eab25b217a165363b1982048a5e5830a0daf36577973955a04cac793` |
| Size | 39,128 bytes |
| Licence | SIL Open Font License 1.1 - `OFL.txt` (copied from google/fonts `ofl/nunito/OFL.txt`) |

Consumed by `public/assets/css/fonts-local.css` (five `@font-face` rules, weights 300/400/500/600/700,
`font-display: swap`, all pointing at this one variable file - exactly what Google served). The OFL permits bundling
and redistribution with software; the font is not sold by itself and the licence text travels with it.
Characters outside the latin block (latin-ext, Cyrillic, Vietnamese) fall back to the next family in the stack.
