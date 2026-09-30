"""Render collected dev pages with the original vs trimmed Martfury CSS and diff the screenshots."""
import os, re, subprocess, sys
from PIL import Image, ImageChops

T = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'work')
REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
CH = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
ORIG = os.path.join(REPO, 'wp-content/themes/martfury/style.css')
TRIM = os.path.join(REPO, 'wp-content/themes/martfury-child/css/martfury-trimmed.css')
os.makedirs(os.path.join(T, 'cmp'), exist_ok=True)

pages = {
    'd': ['in-d-1', 'in-d-2', 'in-d-3', 'in-d-4', 'in-d-5', 'in-d-6', 'in-d-7', 'in-d-8', 'in-d-9', 'in-d-10', 'in-d-12',
          'in-d-13', 'in-d-14', 'in-d-15', 'in-d-16', 'in-d-17', 'in-d-19', 'in-d-20', 'in-d-21',
          'out-d-1', 'out-d-2', 'out-d-5', 'out-d-13', 'out-d-14'],
    'm': ['in-m-1', 'in-m-2', 'in-m-5', 'in-m-13', 'in-m-14', 'in-m-15', 'out-m-1', 'out-m-2', 'out-m-5', 'out-m-13'],
}
size = {'d': '1366,3200', 'm': '412,3600'}

def render(name, css, tag):
    h = open(os.path.join(T, 'html', name + '.html'), errors='ignore').read()
    h = h.replace('dev.ultralove.ca', 'ultralove.ca')
    h, n = re.subn(r"(<link[^>]*id=['\"]martfury-css['\"][^>]*href=['\"])[^'\"]*(['\"])", r'\1file://' + css + r'\2', h)
    # Freeze motion so the two renders are comparable.
    h = h.replace('</head>', '<style>*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}</style></head>', 1)
    f = os.path.join(T, 'cmp', f'{name}-{tag}.html')
    open(f, 'w').write(h)
    png = f[:-5] + '.png'
    subprocess.run([CH, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--allow-file-access-from-files',
                    f'--window-size={size[name.split("-")[1]]}', '--virtual-time-budget=7000',
                    f'--screenshot={png}', 'file://' + f], capture_output=True, timeout=120)
    return png, n

results = []
for kind, names in pages.items():
    for name in names:
        a, n1 = render(name, ORIG, 'orig')
        b, n2 = render(name, TRIM, 'trim')
        A, B = Image.open(a).convert('RGB'), Image.open(b).convert('RGB')
        d = ImageChops.difference(A, B)
        bbox = d.getbbox()
        px = sum(1 for p in d.getdata() if max(p) > 24) if bbox else 0
        pct = 100 * px / (A.size[0] * A.size[1])
        results.append((pct, name, bbox, n1 and n2))
        if bbox:
            # Side-by-side of the differing region for review.
            x0, y0, x1, y1 = bbox
            pad = 40
            box = (max(0, x0 - pad), max(0, y0 - pad), min(A.size[0], x1 + pad), min(A.size[1], min(y1, y0 + 1400) + pad))
            ca, cb = A.crop(box), B.crop(box)
            sbs = Image.new('RGB', (ca.size[0] * 2 + 10, ca.size[1]), 'red')
            sbs.paste(ca, (0, 0)); sbs.paste(cb, (ca.size[0] + 10, 0))
            sbs.save(os.path.join(T, 'cmp', f'diff-{name}.png'))

for pct, name, bbox, ok in sorted(results, reverse=True):
    print(f'{pct:6.2f}%  {name:10} css-swapped={bool(ok)}  diff-box={bbox}')
