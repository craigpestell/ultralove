// Builds the site's critical ("above the fold") CSS from dev and checks it. See README.md.
//
// 1. Opens an SSH tunnel to dev's nginx and loads real dev pages in headless Chrome, answering every
//    dev.ultralove.ca request through the tunnel (dev sits behind Cloudflare Access).
// 2. For each page type, logged out and in, at desktop / tablet / phone sizes, with JavaScript off (what the
//    first paint looks like) and on (classes scripts add), keeps every CSS rule that matches an element in
//    the first screen - rules whose @media doesn't apply at that size are skipped.
// 3. Merges the kept rules in the stylesheet's own order, adds the @font-face and @keyframes they use, and
//    minifies without reordering.
// 4. Verifies: renders each page with only the critical CSS and with the full CSS and compares the first
//    screen pixel by pixel. Only if every page is within the threshold is the theme's critical.css written.
//
// Usage: node generate.mjs [--no-verify] [--pages=/,/shop/]    Env: CHROME, DEV_SSH_HOST, DIFF_LIMIT
import { spawn, execFileSync } from 'node:child_process';
import http from 'node:http';
import net from 'node:net';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import puppeteer from 'puppeteer-core';
import postcss from 'postcss';
import { minify } from 'csso';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(HERE, '../../wp-content/themes/martfury-child/css/critical.css');
const WORK = path.join(HERE, 'work');
const SITE = 'dev.ultralove.ca';
const SSH_HOST = process.env.DEV_SSH_HOST || 'ultralove-dev-app';
const DIFF_LIMIT = Number(process.env.DIFF_LIMIT || 0.5); // % of first-screen pixels allowed to differ
const SIZE_LIMIT = 150 * 1024; // refuse to inline more than this (raw bytes)
const CONCURRENCY = 3;
const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')).map(([k, v]) => [k, v ?? true]));

// Every page type Autoptimize optimizes (cart, checkout and my account it leaves alone).
const PAGES = args.pages ? String(args.pages).split(',') : [
	'/', '/shop/', '/shop/page/2/', '/product-category/vibrators-massagers/', '/product-brand/california-exotics/',
	'/product/w637756-womanizer-beauty-lilac/', '/sale-products/', '/brands/', '/wishlist/', '/ordering-info/',
	'/contact-us/', '/about/', '/become-a-retailer/', '/?s=vibrator&post_type=product', '/no-such-page-404/',
];
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
const UA_M = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36';
// Martfury sends different markup to phones (user agent), so each size uses the matching user agent.
const VIEWPORTS = [
	{ name: 'desktop', width: 1440, height: 900, ua: UA_D },
	{ name: 'wide', width: 1920, height: 1080, ua: UA_D },
	{ name: 'tablet', width: 820, height: 1180, ua: UA_M, mobile: true },
	{ name: 'phone', width: 390, height: 844, ua: UA_M, mobile: true },
];
// Asks 34-critical-css.php for the page as it is without critical CSS (full stylesheet, render-blocking).
const PLAIN = 'ul_critical_css=off';

const CHROME = process.env.CHROME || ['/usr/bin/google-chrome', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p));

// ---------------------------------------------------------------- dev access

function ssh(cmd) {
	return execFileSync('ssh', [SSH_HOST, cmd], { encoding: 'utf8' }).trim();
}

async function openTunnel() {
	const port = await new Promise((res) => { const s = net.createServer().listen(0, () => { const p = s.address().port; s.close(() => res(p)); }); });
	const proc = spawn('ssh', ['-N', '-o', 'ExitOnForwardFailure=yes', '-L', `${port}:127.0.0.1:80`, SSH_HOST], { stdio: 'ignore' });
	for (let i = 0; i < 50; i++) {
		const ok = await new Promise((res) => { const c = net.connect(port, '127.0.0.1', () => { c.end(); res(true); }).on('error', () => res(false)); });
		if (ok) return { port, close: () => proc.kill() };
		await new Promise((r) => setTimeout(r, 200));
	}
	proc.kill();
	throw new Error('SSH tunnel to dev did not come up');
}

// A logged-in cookie for a customer (what signed-in shoppers see - no admin bar), made on the server and
// valid for 2 hours, like ops/css/collect-html.sh does for an administrator.
function loginCookie() {
	const wp = 'cd /var/www/html && sudo -u www-data wp --skip-themes';
	const id = ssh(`${wp} user list --role=customer --field=ID --number=1 2>/dev/null | head -1`);
	const name = ssh(`${wp} eval 'echo LOGGED_IN_COOKIE;' 2>/dev/null | tail -1`);
	const value = ssh(`${wp} eval "echo wp_generate_auth_cookie( ${id}, time() + 7200, 'logged_in' );" 2>/dev/null | tail -1`);
	if (!name || !value) throw new Error('Could not make a login cookie on dev');
	return `${name}=${value}`;
}

// Fetches a dev URL through the tunnel as if it had come in over HTTPS through Cloudflare.
function devFetch(port, url, { method = 'GET', headers = {}, body, cookie } = {}) {
	const u = new URL(url);
	const h = { ...headers, host: SITE, 'x-forwarded-proto': 'https' };
	delete h['accept-encoding'];
	if (cookie) h.cookie = [h.cookie, cookie].filter(Boolean).join('; ');
	return new Promise((res, rej) => {
		const req = http.request({ host: '127.0.0.1', port, method, path: u.pathname + u.search, headers: h }, (r) => {
			const chunks = [];
			r.on('data', (c) => chunks.push(c));
			r.on('end', () => res({ status: r.statusCode, headers: r.headers, body: Buffer.concat(chunks) }));
		});
		req.on('error', rej);
		// The shop page through the tunnel from GitHub's runners can take over a minute.
		req.setTimeout(120000, () => req.destroy(new Error('timeout ' + url)));
		if (body) req.write(body);
		req.end();
	});
}

// ---------------------------------------------------------------- CSS model

const DYNAMIC = /::?(before|after|first-line|first-letter|placeholder|selection|marker|backdrop|file-selector-button|-webkit-[a-z-]+|-moz-[a-z-]+|-ms-[a-z-]+)(\([^)]*\))?|:(hover|focus|focus-within|focus-visible|active|visited|link|target)\b/g;

// Parsed stylesheet: rules in order, each with a key that is the same wherever the rule appears.
const cssCache = new Map();
function model(cssText) {
	if (cssCache.has(cssText)) return cssCache.get(cssText);
	const root = postcss.parse(cssText);
	const rules = [], fontFaces = [], keyframes = [];
	const seen = new Map();
	root.walk((node) => {
		if (node.type === 'atrule' && node.name === 'font-face') { fontFaces.push(node); return; }
		if (node.type === 'atrule' && /keyframes$/.test(node.name)) { keyframes.push(node); return; }
		if (node.type !== 'rule' || node.parent?.type === 'atrule' && /keyframes$/.test(node.parent.name)) return;
		const wrappers = [];
		for (let p = node.parent; p && p.type !== 'root'; p = p.parent) wrappers.unshift(p.type === 'atrule' ? `@${p.name} ${p.params}` : '');
		const body = node.nodes.map(String).join(';');
		const base = wrappers.join('{') + '|' + node.selector + '{' + body;
		const n = (seen.get(base) || 0) + 1;
		seen.set(base, n);
		rules.push({ key: base + '#' + n, wrappers, mediaRaw: wrappers.filter((w) => w.startsWith('@media')).map((w) => w.slice(7)), selectors: node.selectors, body, node });
	});
	const m = { rules, fontFaces, keyframes };
	cssCache.set(cssText, m);
	return m;
}

// ---------------------------------------------------------------- per page

// The CSS a page uses, in cascade order: its stylesheets and <style> blocks as served.
async function pageCss(page, port, cookie) {
	const sheets = await page.evaluate(() => [...document.querySelectorAll('link[rel="stylesheet"], style')]
		.filter((e) => !e.closest('noscript'))
		.map((e) => (e.tagName === 'LINK' ? { href: e.href, media: e.media || 'all' } : { text: e.textContent, media: e.media || 'all' })));
	let css = '';
	for (const s of sheets) {
		let text = s.text;
		if (s.href) {
			if (!s.href.includes(SITE)) continue; // Only the site's own CSS is inlined.
			text = (await devFetch(port, s.href, { cookie })).body.toString('utf8');
		}
		text = text.replace(/@charset[^;]+;/gi, '');
		css += (s.media && s.media !== 'all' ? `@media ${s.media}{${text}}` : text) + '\n';
	}
	return css;
}

// Which rules (and which of their selectors) match something in the first screen.
async function aboveFold(page, m) {
	const payload = m.rules.map((r) => ({ media: r.mediaRaw, sels: r.selectors.map((s) => s.replace(DYNAMIC, '').trim() || null) }));
	return page.evaluate((rules) => {
		const fold = innerHeight;
		const cache = new Map();
		// An element counts if it's drawn in the first screen. One that isn't drawn counts only if it's the top
		// of a hidden subtree (display:none) whose parent is in the first screen - the rule that hides it is
		// needed; nothing inside it is drawn, so rules for its contents aren't.
		const boxed = (e) => e.getClientRects().length > 0 || getComputedStyle(e).display === 'contents';
		const visible = (el) => {
			if (el.getClientRects().length) return el.getBoundingClientRect().top < fold;
			const parent = el.parentElement;
			if (!parent) return true;
			if (!boxed(parent)) return false;
			return parent.getBoundingClientRect().top < fold;
		};
		const hit = (sel) => {
			if (cache.has(sel)) return cache.get(sel);
			let res = false;
			try {
				const els = document.querySelectorAll(sel);
				for (let i = 0; i < els.length && i < 200; i++) if (visible(els[i])) { res = true; break; }
			} catch (e) { res = false; }
			cache.set(sel, res);
			return res;
		};
		const out = [];
		rules.forEach((r, i) => {
			if (r.media.length && !r.media.every((q) => matchMedia(q).matches)) return;
			const hits = [];
			r.sels.forEach((s, j) => { if (s && hit(s)) hits.push(j); });
			if (hits.length) out.push([i, hits]);
		});
		return out;
	}, payload);
}

async function newPage(browser, port, vp, { cookie, js, transform }) {
	const page = await browser.newPage();
	await page.setUserAgent(vp.ua);
	await page.setViewport({ width: vp.width, height: vp.height, isMobile: !!vp.mobile, hasTouch: !!vp.mobile, deviceScaleFactor: 1 });
	await page.setJavaScriptEnabled(js);
	await page.setRequestInterception(true);
	page.on('request', async (req) => {
		const url = req.url();
		if (!url.startsWith(`https://${SITE}/`) && !url.startsWith(`http://${SITE}/`)) return req.continue();
		try {
			const r = await devFetch(port, url, { method: req.method(), headers: req.headers(), body: req.postData(), cookie });
			const headers = {};
			for (const [k, v] of Object.entries(r.headers)) if (!['content-length', 'content-encoding', 'transfer-encoding', 'connection', 'set-cookie'].includes(k)) headers[k] = Array.isArray(v) ? v.join(', ') : v;
			let body = r.body;
			if (transform) body = transform(url, req.resourceType(), body, headers);
			await req.respond({ status: r.status, headers, body });
		} catch (e) {
			req.abort().catch(() => {});
		}
	});
	return page;
}

const withParam = (p, q) => p + (p.includes('?') ? '&' : '?') + q;

async function collect(browser, port, job) {
	const page = await newPage(browser, port, job.vp, job);
	try {
		await page.goto(`https://${SITE}${withParam(job.path, PLAIN)}`, { waitUntil: 'networkidle2', timeout: 180000 });
		if (job.js) await new Promise((r) => setTimeout(r, 1500));
		const css = await pageCss(page, port, job.cookie);
		const m = model(css);
		return { m, hits: await aboveFold(page, m) };
	} finally {
		await page.close();
	}
}

// ---------------------------------------------------------------- build

function build(results) {
	// One order for every rule seen: start from the first stylesheet, insert rules only another page has
	// right after the rule they follow there.
	const order = [], pos = new Map();
	const byKey = new Map();
	for (const { m } of results) {
		let last = -1;
		for (const r of m.rules) {
			if (pos.has(r.key)) { last = pos.get(r.key); continue; }
			order.splice(last + 1, 0, r.key);
			for (let i = last + 1; i < order.length; i++) pos.set(order[i], i);
			last = last + 1;
			byKey.set(r.key, r);
		}
	}
	const kept = new Map(); // key -> Set of selector indexes
	for (const { m, hits } of results) for (const [i, sels] of hits) {
		const k = m.rules[i].key;
		if (!kept.has(k)) kept.set(k, new Set());
		sels.forEach((s) => kept.get(k).add(s));
	}

	// Rules in order, grouped back into their @media / @supports wrappers.
	let css = '', open = [];
	const fonts = new Set(), anims = new Set();
	for (const k of order) {
		if (!kept.has(k)) continue;
		const r = byKey.get(k);
		const sels = r.selectors.filter((_, j) => kept.get(k).has(j));
		let i = 0;
		while (i < open.length && i < r.wrappers.length && open[i] === r.wrappers[i]) i++;
		css += '}'.repeat(open.length - i);
		for (const w of r.wrappers.slice(i)) css += w + '{';
		open = r.wrappers.slice();
		css += sels.join(',') + '{' + r.body + '}';
		for (const d of r.node.nodes || []) {
			if (d.type !== 'decl') continue;
			if (/^font(-family)?$/.test(d.prop)) d.value.split(',').forEach((f) => fonts.add(f.replace(/!important|["']/g, '').trim().split(/\s+/).slice(d.prop === 'font' ? -1 : 0).join(' ').toLowerCase()));
			if (/^animation(-name)?$/.test(d.prop)) d.value.split(/[\s,]+/).forEach((a) => anims.add(a));
		}
	}
	css += '}'.repeat(open.length);

	// @font-face and @keyframes the kept rules use (from the first stylesheet that has them).
	const faces = new Map(), frames = new Map();
	for (const { m } of results) {
		for (const f of m.fontFaces) {
			const fam = String(f.nodes.find((d) => d.prop === 'font-family')?.value || '').replace(/["']/g, '').trim().toLowerCase();
			const key = f.toString();
			// Fonts embedded as data: URIs (Dashicons' is 43 KB) are too big to inline; they come with the full CSS.
			if (fonts.has(fam) && !faces.has(key) && !/url\(\s*["']?data:/.test(key)) faces.set(key, f.toString());
		}
		for (const k of m.keyframes) if (anims.has(k.params.trim()) && !frames.has(k.params)) frames.set(k.params, k.toString());
	}
	let out = [...faces.values()].join('') + css + [...frames.values()].join('');
	// Root-relative asset URLs, so the same file works on dev and production.
	out = out.replace(/(https?:)?\/\/dev\.ultralove\.ca\//g, '/');
	return { css: minify(out, { restructure: false }).css, stats: { rules: kept.size, of: order.length, faces: faces.size, keyframes: frames.size } };
}

// ---------------------------------------------------------------- verify

const FREEZE = '<style>*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}</style>';

async function shot(browser, port, job, critical, html) {
	const transform = (url, type, body, headers) => {
		const ct = String(headers['content-type'] || '');
		if (type === 'document' && ct.includes('text/html')) {
			let h = html;
			h = h.replace('</head>', (critical !== null ? `<style id="ul-critical-check">${critical}</style>` : '') + FREEZE + '</head>');
			return Buffer.from(h);
		}
		if (critical !== null && type === 'stylesheet' && ct.includes('text/css')) return Buffer.from('');
		return body;
	};
	const page = await newPage(browser, port, job.vp, { ...job, js: false, transform });
	try {
		await page.goto(`https://${SITE}${withParam(job.path, PLAIN)}`, { waitUntil: 'networkidle0', timeout: 180000 });
		// Only the critical CSS (and the animation freeze) stays; <noscript> styles too, since they're what a
		// no-JavaScript render needs in place of what scripts do (WooCommerce's shows the product gallery).
		if (critical !== null) await page.evaluate(() => document.querySelectorAll('style:not(#ul-critical-check)').forEach((s) => { if (!s.textContent.includes('animation:none!important') && !s.closest('noscript')) s.remove(); }));
		// Both renders wait for every image in the first screen, so an image still loading in one of them
		// doesn't count as a CSS difference. Polled from here: page timers don't run with JavaScript off.
		for (let t = 0; t < 80; t++) {
			const pending = await page.evaluate(() => [...document.images].filter((i) => {
				const r = i.getBoundingClientRect();
				return r.width && r.height && r.top < innerHeight && r.bottom > 0 && r.left < innerWidth && r.right > 0 && !(i.complete && i.naturalWidth);
			}).length);
			if (!pending) break;
			await new Promise((r) => setTimeout(r, 250));
		}
		await Promise.race([
			page.evaluate(() => Promise.all([...document.images].filter((i) => i.getBoundingClientRect().top < innerHeight).map((i) => i.decode().catch(() => {})))),
			new Promise((r) => setTimeout(r, 10000)),
		]);
		// A loaded image can still be decoding (a large banner shows only its blurred backdrop for a moment), so
		// the screenshot is taken once the screen stops changing.
		let prev = null;
		for (let t = 0; t < 12; t++) {
			await new Promise((r) => setTimeout(r, 400));
			const cur = Buffer.from(await page.screenshot({ type: 'png' }));
			if (prev && cur.equals(prev)) break;
			prev = cur;
		}
		return PNG.sync.read(prev);
	} finally {
		await page.close();
	}
}

async function verify(chrome, port, jobs, critical) {
	const rows = [];
	await pool(jobs, async (job) => {
		// Both renders get the same HTML: some listings (Sale Products) come back in a different order each time.
		const url = `https://${SITE}${withParam(job.path, PLAIN)}`;
		const [a, b] = await retry(async () => {
			const html = (await devFetch(port, url, { headers: { 'user-agent': job.vp.ua }, cookie: job.cookie })).body.toString('utf8');
			return [await shot(await chrome(), port, job, null, html), await shot(await chrome(), port, job, critical, html)];
		});
		const { width, height } = a;
		const diff = new PNG({ width, height });
		const n = pixelmatch(a.data, b.data, diff.data, width, height, { threshold: 0.15 });
		const pct = (100 * n) / (width * height);
		const name = job.name.replace(/[^a-z0-9]+/gi, '-');
		if (pct > 0.05) {
			fs.writeFileSync(path.join(WORK, `${name}-full.png`), PNG.sync.write(a));
			fs.writeFileSync(path.join(WORK, `${name}-critical.png`), PNG.sync.write(b));
			fs.writeFileSync(path.join(WORK, `${name}-diff.png`), PNG.sync.write(diff));
		}
		rows.push({ name: job.name, pct });
	});
	return rows.sort((x, y) => y.pct - x.pct);
}

// ---------------------------------------------------------------- main

// One page render can fail on its own (Chrome dropping the connection, a slow response); try it again.
async function retry(fn, tries = 3) {
	for (let i = 1; ; i++) {
		try {
			return await fn();
		} catch (e) {
			if (i >= tries) throw e;
			await new Promise((r) => setTimeout(r, 2000));
		}
	}
}

async function pool(items, fn) {
	let i = 0;
	await Promise.all(Array.from({ length: CONCURRENCY }, async () => { while (i < items.length) await fn(items[i++]); }));
}

async function main() {
	if (!CHROME) throw new Error('Chrome not found; set CHROME');
	fs.rmSync(WORK, { recursive: true, force: true });
	fs.mkdirSync(WORK, { recursive: true });
	const tunnel = await openTunnel();
	const cookie = loginCookie();
	// Chrome is restarted if it goes away (it occasionally drops the connection mid-render).
	let browser = null, launching = null;
	const chrome = async () => {
		if (browser && browser.connected) return browser;
		launching ??= puppeteer.launch({ executablePath: CHROME, headless: true, args: ['--no-sandbox', '--hide-scrollbars'] })
			.then((b) => { browser = b; return b; })
			.finally(() => { launching = null; });
		return launching;
	};
	try {
		const jobs = [];
		for (const p of PAGES) for (const vp of VIEWPORTS) for (const who of ['out', 'in']) jobs.push({ path: p, vp, cookie: who === 'in' ? cookie : null, name: `${who} ${vp.name} ${p}` });

		const results = [];
		let done = 0;
		await pool(jobs.flatMap((j) => [{ ...j, js: false }, { ...j, js: true }]), async (job) => {
			try {
				results.push(await retry(async () => collect(await chrome(), tunnel.port, job)));
			} catch (e) {
				throw new Error(`${job.name} (js ${job.js ? 'on' : 'off'}): ${e.message}`);
			}
			if (++done % 20 === 0) console.log(`collected ${done}/${jobs.length * 2}`);
		});

		const { css, stats } = build(results);
		console.log(`critical CSS: ${stats.rules} of ${stats.of} rules, ${stats.faces} @font-face, ${stats.keyframes} @keyframes, ${Math.round(css.length / 1024)} KB`);
		fs.writeFileSync(path.join(WORK, 'critical.css'), css);
		if (css.length > SIZE_LIMIT) throw new Error(`critical CSS is ${Math.round(css.length / 1024)} KB, over the ${SIZE_LIMIT / 1024} KB limit`);

		if (!args['no-verify']) {
			const rows = await verify(chrome, tunnel.port, jobs, css);
			const report = rows.map((r) => `${r.pct.toFixed(2).padStart(6)}%  ${r.name}`).join('\n');
			fs.writeFileSync(path.join(WORK, 'verify.txt'), report + '\n');
			console.log('first-screen difference, critical CSS only vs full CSS (worst first):\n' + rows.slice(0, 12).map((r) => `${r.pct.toFixed(2).padStart(6)}%  ${r.name}`).join('\n'));
			const bad = rows.filter((r) => r.pct > DIFF_LIMIT);
			if (bad.length) throw new Error(`${bad.length} page(s) differ by more than ${DIFF_LIMIT}% - see ops/critical-css/work/*-diff.png; critical.css not updated`);
		}

		const header = '/* Generated by ops/critical-css/generate.mjs from dev - do not edit; see ops/critical-css/README.md. */\n';
		fs.writeFileSync(OUT, header + css + '\n');
		console.log('wrote', path.relative(process.cwd(), OUT));
	} finally {
		if (browser) await browser.close().catch(() => {});
		tunnel.close();
	}
}

main().catch((e) => { console.error(e.message); process.exit(1); });
