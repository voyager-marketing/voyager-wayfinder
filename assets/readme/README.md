# README art

The banner at the top of the repository README and the image GitHub shows when the repository is shared.

| File | What it is |
|---|---|
| `banner-dark.svg`, `banner-light.svg` | The README banner, picked by the reader's color scheme. The route draws in once on load and stays still when the reader asks for reduced motion. |
| `social-preview.svg` | The 1280x640 share image, as SVG. |
| `social-preview.png` | The same image as PNG, for Settings > General > Social preview. |
| `build.py` | Writes the three SVGs. |

The route is the answer find gives on the Playground demo site for the text "Need a plumber today?" on the home page: the front page route, the page template, the page content, then the synced pattern where that text is edited.

Every word is outlined to a path, so the images load no font and look the same everywhere. The faces are Space Grotesk and JetBrains Mono, both SIL Open Font License, in the colors of Voyager's design system. To change the art, edit `build.py`, then:

```
python3 build.py SpaceGrotesk.woff2 JetBrainsMono.woff2 .
npx playwright screenshot --viewport-size=1280,640 "file://$PWD/social-preview.svg" social-preview.png
```

`build.py` needs `fonttools`, `brotli` and `uharfbuzz`. None of these files ships in the plugin ZIP.
