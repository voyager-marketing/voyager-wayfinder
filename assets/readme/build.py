"""Writes the README banners and the social preview as SVG, with every word outlined, so nothing loads a font.

    python3 build.py <Space Grotesk variable .woff2 or .ttf> <JetBrains Mono variable .woff2 or .ttf> <out dir>

Needs fonttools, brotli (for .woff2) and uharfbuzz. Both fonts are SIL Open Font License. social-preview.png is
social-preview.svg rendered at 1280x640 in Chromium (see README.md here).
"""
import functools
import io
import sys

import uharfbuzz as hb
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

FONTS = {}

@functools.lru_cache(None)
def load(name, weight):
    tt = TTFont(FONTS[name])
    inst = instancer.instantiateVariableFont(tt, {'wght': weight})
    inst.flavor = None
    buf = io.BytesIO(); inst.save(buf); data = buf.getvalue()
    face = hb.Face(data); font = hb.Font(face)
    return inst, font, inst['head'].unitsPerEm

def outline(text, name='grotesk', weight=400, size=16, x=0.0, y=0.0, tracking=0.0, anchor='start'):
    """Path data for `text` with its baseline at y. tracking is in em. Returns (d, width)."""
    tt, font, upem = load(name, weight)
    buf = hb.Buffer(); buf.add_str(text); buf.guess_segment_properties()
    hb.shape(font, buf, {'kern': True, 'liga': True})
    gs = tt.getGlyphSet(); order = tt.getGlyphOrder()
    scale = size / upem
    total = sum(p.x_advance for p in buf.glyph_positions) * scale + tracking * size * max(len(buf.glyph_infos) - 1, 0)
    if anchor == 'end': x -= total
    elif anchor == 'middle': x -= total / 2
    pen = SVGPathPen(gs, ntos=lambda v: ('%.1f' % v).rstrip('0').rstrip('.'))
    cx = 0.0
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        g = order[info.codepoint]
        tp = TransformPen(pen, (scale, 0, 0, -scale, x + (cx + pos.x_offset) * scale, y - pos.y_offset * scale))
        gs[g].draw(tp)
        cx += pos.x_advance + tracking * upem
    return pen.getCommands(), total



THEMES = {
    'dark': dict(bg='#08071A', edge='#272248', ink='#F4F2FA', muted='#938EAE', soft='#C7C3DA',
                 route='#E6317A', ring='#A78BFA', station='#08071A', track='#272248'),
    'light': dict(bg='#F4F2FA', edge='#DCD8EA', ink='#08071A', muted='#625E7C', soft='#3A3656',
                  route='#9B1750', ring='#7238DD', station='#F4F2FA', track='#DCD8EA'),
}

# The real answer find gives on the demo site for the text "Need a plumber today?" on "/", in chain order from the
# route to the match (the plugin's own output, read from a local Playground run of blueprint.json).
STATIONS = [
    ('route:front-page', 'Settings > Reading'),
    ('tpl:page', 'Site Editor'),
    ('pt:page', 'Page content: Home'),
]
TERMINAL = ('pat:cta-banner', 'Edit here: post.php?post=4', 'Synced pattern, one edit changes 2 pages')


def text(d, fill, extra=''):
    return f'<path d="{d}" fill="{fill}"{extra}/>'


def route_svg(t, x0, x1, y, scale, delay=0.3):
    """The route from what you see to where it is edited, left to right, with one 45 degree climb to the match."""
    s = scale
    r = 9 * s
    climb = 46 * s
    gap = (x1 - x0 - climb) / 4
    xs = [x0 + gap * i for i in range(4)]
    tx = xs[-1] + gap + climb
    ty = y - climb
    bend = xs[-1] + gap
    d = f'M{x0:.1f} {y:.1f} H{bend:.1f} L{tx:.1f} {ty:.1f}'
    length = (bend - x0) + climb * 1.41421356
    parts = []
    parts.append(f'<path d="M{x0:.1f} {y:.1f} H{bend:.1f} L{tx:.1f} {ty:.1f}" fill="none" stroke="{t["track"]}" stroke-width="{10*s:.1f}" stroke-linecap="round" stroke-linejoin="round"/>')
    parts.append(f'<path class="route" d="{d}" fill="none" stroke="{t["route"]}" stroke-width="{6*s:.1f}" stroke-linecap="round" stroke-linejoin="round" style="stroke-dasharray:{length:.1f};--len:{length:.1f}px"/>')

    # The start: what a person sees on the page.
    see, w = outline('“Need a plumber today?” on /', 'grotesk', 500, 17 * s, x0, y - 26 * s)
    parts.append(f'<g class="stop" style="--at:{delay:.2f}s">'
                 f'<circle cx="{x0:.1f}" cy="{y:.1f}" r="{r*1.1:.1f}" fill="{t["route"]}"/>'
                 + text(see, t['ink']) + '</g>')

    # The chain, each a station with its id and where it is edited.
    for i, (ident, where) in enumerate(STATIONS):
        cx = x0 + gap * (i + 1)
        at = delay + 1.8 * ((cx - x0) / length)
        idd, _ = outline(ident, 'mono', 400, 16 * s, cx, y + 37 * s, anchor='middle')
        wd, _ = outline(where, 'grotesk', 400, 15 * s, cx, y + 60 * s, anchor='middle')
        parts.append(f'<g class="stop" style="--at:{at:.2f}s">'
                     f'<circle cx="{cx:.1f}" cy="{y:.1f}" r="{r:.1f}" fill="{t["station"]}" stroke="{t["ring"]}" stroke-width="{3.5*s:.1f}"/>'
                     + text(idd, t['soft']) + text(wd, t['muted']) + '</g>')

    # The match: where it is edited.
    ident, edit, why = TERMINAL
    at = delay + 1.8
    lx = tx + r * 1.25
    idd, iw = outline(ident, 'mono', 500, 17 * s, lx, ty - 76 * s, anchor='end')
    ed, _ = outline(edit, 'grotesk', 600, 16 * s, lx, ty - 52 * s, anchor='end')
    wy, _ = outline(why, 'grotesk', 400, 14 * s, lx, ty - 31 * s, anchor='end')
    parts.append(f'<g class="stop end" style="--at:{at:.2f}s">'
                 f'<circle class="halo" cx="{tx:.1f}" cy="{ty:.1f}" r="{r*2.1:.1f}" fill="none" stroke="{t["ring"]}" stroke-width="{2.5*s:.1f}"/>'
                 f'<circle cx="{tx:.1f}" cy="{ty:.1f}" r="{r*1.25:.1f}" fill="{t["route"]}"/>'
                 + text(idd, t['ink']) + text(ed, t['route']) + text(wy, t['muted']) + '</g>')
    return '\n  '.join(parts)


STYLE = """<style>
  .route { animation: draw 1.8s cubic-bezier(.45,0,.2,1) .3s both; }
  .stop { animation: show .35s ease-out var(--at) both; }
  .halo { transform-box: fill-box; transform-origin: center; animation: ping 1.2s ease-out 2.2s 2 both; }
  @keyframes draw { from { stroke-dashoffset: var(--len); } to { stroke-dashoffset: 0; } }
  @keyframes show { from { opacity: 0; } to { opacity: 1; } }
  @keyframes ping { 0% { transform: scale(.6); opacity: 0; } 40% { opacity: 1; } 100% { transform: scale(1); opacity: 1; } }
  @media (prefers-reduced-motion: reduce) { .route, .stop, .halo { animation: none; } }
</style>"""


def banner(mode):
    t = THEMES[mode]
    W, H = 1280, 400
    word, ww = outline('Wayfinder', 'grotesk', 600, 92, 72, 150, tracking=-0.025)
    tag, _ = outline('Where each thing on a WordPress site is edited.', 'grotesk', 400, 26, 75, 198)
    by, bw = outline('by Voyager', 'grotesk', 500, 20, 1208, 98, anchor='end')
    return f'''<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}" role="img" aria-labelledby="t">
  <title id="t">Voyager Wayfinder: where each thing on a WordPress site is edited</title>
  {STYLE}
  <rect x="0.5" y="0.5" width="{W-1}" height="{H-1}" rx="18" fill="{t["bg"]}" stroke="{t["edge"]}"/>
  {text(word, t["ink"])}
  {text(tag, t["muted"])}
  {text(by, t["ring"])}
  {route_svg(t, 92, 1188, 318, 1.0)}
</svg>
'''


def social():
    t = THEMES['dark']
    W, H = 1280, 640
    word, _ = outline('Wayfinder', 'grotesk', 600, 132, 88, 210, tracking=-0.025)
    tag1, _ = outline('Where each thing on a WordPress site is edited,', 'grotesk', 400, 34, 92, 270)
    tag2, _ = outline('for the people who edit it and the AI agents that help.', 'grotesk', 400, 34, 92, 314)
    repo, _ = outline('github.com/voyager-marketing/voyager-wayfinder', 'mono', 400, 20, 92, 606)
    built, _ = outline('Built by Voyager', 'grotesk', 600, 22, 1188, 606, anchor='end')
    return f'''<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}" role="img" aria-labelledby="t">
  <title id="t">Voyager Wayfinder: where each thing on a WordPress site is edited</title>
  <rect width="{W}" height="{H}" fill="{t["bg"]}"/>
  <rect x="0" y="0" width="{W}" height="10" fill="{t["route"]}"/>
  {text(word, t["ink"])}
  {text(tag1, t["muted"])}
  {text(tag2, t["muted"])}
  {route_svg(t, 108, 1172, 478, 1.15)}
  <line x1="92" y1="564" x2="1188" y2="564" stroke="{t["edge"]}" stroke-width="2"/>
  {text(repo, t["soft"])}
  {text(built, t["ring"])}
</svg>
'''


if __name__ == '__main__':
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    FONTS['grotesk'], FONTS['mono'], out = sys.argv[1:]
    for name, svg in (('banner-dark', banner('dark')), ('banner-light', banner('light')), ('social-preview', social())):
        with open(f'{out}/{name}.svg', 'w', encoding='utf-8') as f:
            f.write(svg)
