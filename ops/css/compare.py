"""Render collected dev pages with the original vs trimmed stylesheets and diff the screenshots."""
import glob, os, re, subprocess
from PIL import Image, ImageChops

HERE = os.path.dirname(os.path.abspath(__file__))
T = os.path.join(HERE, 'work')
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
CH = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
THEMES = os.path.join(REPO, 'wp-content/themes')
CHILD_CSS = os.path.join(THEMES, 'martfury-child/css')
os.makedirs(os.path.join(T, 'cmp'), exist_ok=True)

# Stylesheet handle -> (original, trimmed copy). The originals of vendor plugins come from production.
wc = sorted(glob.glob(os.path.join(CHILD_CSS, 'woocommerce-*-trimmed.css')))
TARGETS = {
    'martfury-css': ('file://' + os.path.join(THEMES, 'martfury/style.css'), os.path.join(CHILD_CSS, 'martfury-trimmed.css')),
    'ionicons-css': ('file://' + os.path.join(THEMES, 'martfury/css/ionicons.min.css'), os.path.join(CHILD_CSS, 'ionicons-trimmed.css')),
    'eleganticons-css': ('file://' + os.path.join(THEMES, 'martfury/css/eleganticons.min.css'), os.path.join(CHILD_CSS, 'eleganticons-trimmed.css')),
    'font-awesome-css': ('file://' + os.path.join(THEMES, 'martfury/css/font-awesome.min.css'), os.path.join(CHILD_CSS, 'font-awesome-trimmed.css')),
}
if wc:
    TARGETS['woocommerce-general-css'] = ('https://ultralove.ca/wp-content/plugins/woocommerce/assets/css/woocommerce.css', wc[-1])

# Trimmed copies are rendered from work/cmp/, with root-relative URLs (vendor fonts) pointed at production.
LOCAL = {}
for handle, (_, trimmed) in TARGETS.items():
    css = open(trimmed).read()
    css = re.sub(r'url\((["\']?)/(?!/)', r'url(\1https://ultralove.ca/', css)
    css = css.replace('url(../../martfury/', 'url(file://' + THEMES + '/martfury/')
    css = css.replace('url("../../martfury/', 'url("file://' + THEMES + '/martfury/')
    p = os.path.join(T, 'cmp', 'trim-' + os.path.basename(trimmed))
    open(p, 'w').write(css)
    LOCAL[handle] = 'file://' + p

pages = {
    'd': ['in-d-1', 'in-d-2', 'in-d-3', 'in-d-4', 'in-d-5', 'in-d-6', 'in-d-7', 'in-d-8', 'in-d-9', 'in-d-10', 'in-d-12',
          'in-d-13', 'in-d-14', 'in-d-15', 'in-d-16', 'in-d-17', 'in-d-19', 'in-d-20', 'in-d-21',
          'out-d-1', 'out-d-2', 'out-d-5', 'out-d-13', 'out-d-14'],
    'm': ['in-m-1', 'in-m-2', 'in-m-5', 'in-m-13', 'in-m-14', 'in-m-15', 'out-m-1', 'out-m-2', 'out-m-5', 'out-m-13'],
}
size = {'d': '1366,3200', 'm': '412,3600'}

def render(name, tag):
    h = open(os.path.join(T, 'html', name + '.html'), errors='ignore').read()
    h = h.replace('dev.ultralove.ca', 'ultralove.ca')
    swapped = 0
    for handle, (orig, _) in TARGETS.items():
        href = orig if tag == 'orig' else LOCAL[handle]
        h, n = re.subn(r"(<link[^>]*id=['\"]" + re.escape(handle) + r"['\"][^>]*href=['\"])[^'\"]*(['\"])", lambda m: m.group(1) + href + m.group(2), h)
        swapped += n
    # Freeze motion so the two renders are comparable.
    h = h.replace('</head>', '<style>*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}</style></head>', 1)
    f = os.path.join(T, 'cmp', f'{name}-{tag}.html')
    open(f, 'w').write(h)
    png = f[:-5] + '.png'
    subprocess.run([CH, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--allow-file-access-from-files',
                    f'--window-size={size[name.split("-")[1]]}', '--virtual-time-budget=7000',
                    f'--screenshot={png}', 'file://' + f], capture_output=True, timeout=120)
    return png, swapped

results = []
for kind, names in pages.items():
    for name in names:
        a, n1 = render(name, 'orig')
        b, n2 = render(name, 'trim')
        A, B = Image.open(a).convert('RGB'), Image.open(b).convert('RGB')
        d = ImageChops.difference(A, B)
        bbox = d.getbbox()
        px = sum(1 for p in d.getdata() if max(p) > 24) if bbox else 0
        pct = 100 * px / (A.size[0] * A.size[1])
        results.append((pct, name, bbox, n2))
        if bbox:
            # Side-by-side of the differing region for review.
            x0, y0, x1, y1 = bbox
            pad = 40
            box = (max(0, x0 - pad), max(0, y0 - pad), min(A.size[0], x1 + pad), min(A.size[1], min(y1, y0 + 1400) + pad))
            ca, cb = A.crop(box), B.crop(box)
            sbs = Image.new('RGB', (ca.size[0] * 2 + 10, ca.size[1]), 'red')
            sbs.paste(ca, (0, 0)); sbs.paste(cb, (ca.size[0] + 10, 0))
            sbs.save(os.path.join(T, 'cmp', f'diff-{name}.png'))

for pct, name, bbox, n in sorted(results, reverse=True):
    print(f'{pct:6.2f}%  {name:10} stylesheets-swapped={n}/{len(TARGETS)}  diff-box={bbox}')
