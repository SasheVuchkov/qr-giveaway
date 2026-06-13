#!/usr/bin/env node
// Composes finished, print-safe flat-vector SVGs by merging three layers:
//
//   Layer 0  print/templates/qr-code-bg.svg   — card background
//   Layer 1  qr-dist/codes/<slug>.svg          — generated QR code
//   Layer 2  print/templates/qr-code-logo.svg  — logo artwork overlay
//
// Output is a single flat SVG with NO nested <image> tags — all elements
// are inlined as real <rect> / <path> nodes.  Suitable for any SVG→PDF
// pipeline (Inkscape CLI, cairosvg, Puppeteer, etc.) at any print resolution.
//
// Usage:
//   node scripts/compose-qr.js
//   npm run compose-qr

'use strict';

const fs   = require('fs');
const path = require('path');

// ── Paths ─────────────────────────────────────────────────────────────────────

const root      = path.resolve(__dirname, '..');
const bgPath    = path.join(root, 'print', 'templates', 'qr-code-bg.svg');
const logoPath  = path.join(root, 'print', 'templates', 'qr-code-logo.svg');
const codesDir  = path.join(root, 'qr-dist', 'codes');
const outputDir = path.join(root, 'qr-dist', 'prod-codes');

// ── Coordinate constants (derived from qr-code-bg.svg) ───────────────────────

// Canvas size — matches bg.svg viewBox
const CANVAS_W = '45.961082';
const CANVAS_H = '50.772491';

// bg.svg layer1 world-space offset (translate on <g id="layer1">)
// All bg elements and logo artwork share this coordinate system
const BG_TRANSLATE = 'translate(-78.021167,-32.51963)';

// QR code slot in bg canvas (mm) — derived from bg.svg's hidden g596:
//   matrix(0.07224607,0,0,0.07224607, 86.715364, 45.107022) + layer1 translate
//   tx = 86.715364 − 78.021167 = 8.694197 mm
//   ty = 45.107022 − 32.519630 = 12.587392 mm
//   slot size = 400 × 0.07224607 = 28.898 mm
//   our QR canvas = 330 units  →  scale = 28.898 / 330
const QR_TX    = 6.8;
const QR_TY    = 10.2;
const QR_SCALE = (400 * 0.07224607) / 400;   // ≈ 0.087570

// Logo scale — applied around the centre of rect598 (world coordinates).
//   rect598: x=97.808403, y=56.058754, w=6.8536611, h=6.9949727
//   centre  = (97.808403 + 6.8536611/2, 56.058754 + 6.9949727/2)
//           = (101.235234, 59.556240)
const LOGO_SCALE = 0.78;
const LOGO_CX    = 98.908403 + 6.8536611 / 2;   // 101.235234
const LOGO_CY    = 56.058754 + 6.9949727 / 2;   // 59.556240

// ── SVG string helpers ────────────────────────────────────────────────────────

/**
 * Find the index of the next true <g ...> opening tag at or after `from`.
 * Guards against false positives inside attribute values.
 */
const nextGroupOpen = (str, from) => {
  let i = from;
  while (i < str.length) {
    const idx = str.indexOf('<g', i);
    if (idx === -1) return -1;
    const c = str[idx + 2];
    if (c === ' ' || c === '\n' || c === '\r' || c === '\t' || c === '>') return idx;
    i = idx + 2;
  }
  return -1;
};

/**
 * Starting from `afterOpenEnd` (the position immediately after a <g> opening
 * tag's closing '>'), walk forward and return the position just past the
 * matching </g>.
 */
const findGroupEnd = (str, afterOpenEnd) => {
  let depth = 1;
  let pos   = afterOpenEnd;
  const CLOSE = '</g>';

  while (depth > 0 && pos < str.length) {
    const nOpen  = nextGroupOpen(str, pos);
    const nClose = str.indexOf(CLOSE, pos);

    if (nClose === -1) break; // malformed SVG

    if (nOpen !== -1 && nOpen < nClose) {
      depth++;
      pos = str.indexOf('>', nOpen) + 1; // skip past the nested <g...>
    } else {
      depth--;
      pos = nClose + CLOSE.length;
    }
  }

  return pos;
};

/**
 * Extract the inner content of <g id="..."> ... </g>.
 */
const getGroupInner = (str, id) => {
  const markerIdx = str.indexOf(`id="${id}"`);
  if (markerIdx === -1) return '';
  const gStart  = str.lastIndexOf('<g', markerIdx);
  const openEnd = str.indexOf('>', gStart) + 1;
  const closeEnd = findGroupEnd(str, openEnd);
  return str.slice(openEnd, closeEnd - '</g>'.length);
};

/**
 * Remove <g id="..."> ... </g> and return the remaining string.
 */
const removeGroup = (str, id) => {
  const markerIdx = str.indexOf(`id="${id}"`);
  if (markerIdx === -1) return str;
  const gStart  = str.lastIndexOf('<g', markerIdx);
  const openEnd = str.indexOf('>', gStart) + 1;
  const closeEnd = findGroupEnd(str, openEnd);
  return str.slice(0, gStart) + str.slice(closeEnd);
};

/**
 * Extract a single element (self-closing or block) by its id attribute.
 */
const getElementById = (str, id) => {
  const markerIdx = str.indexOf(`id="${id}"`);
  if (markerIdx === -1) return '';
  const start  = str.lastIndexOf('<', markerIdx);
  const gtIdx  = str.indexOf('>', start);
  const elem   = str.slice(start, gtIdx + 1);
  if (elem.endsWith('/>')) return elem;                        // self-closing
  const tagName  = (elem.match(/^<([\w:-]+)/) || [])[1] || '';
  const closeTag = `</${tagName}>`;
  const closeIdx = str.indexOf(closeTag, gtIdx);
  return closeIdx === -1 ? elem : str.slice(start, closeIdx + closeTag.length);
};

/**
 * Return the content between the <svg> opening tag and </svg>.
 */
const svgInner = (str) => {
  const openEnd    = str.indexOf('>') + 1;
  const closeStart = str.lastIndexOf('</svg>');
  return closeStart === -1 ? str.slice(openEnd) : str.slice(openEnd, closeStart);
};

// ── Build the composed flat SVG ───────────────────────────────────────────────

const buildFlatSVG = ({ bgLayer1, qrInner, logoRect, logoPath: logoPathEl }) => {
  const scale = QR_SCALE.toFixed(8);
  const tx    = QR_TX.toFixed(6);
  const ty    = QR_TY.toFixed(6);

  return [
    `<svg xmlns="http://www.w3.org/2000/svg"`,
    `     width="${CANVAS_W}mm" height="${CANVAS_H}mm"`,
    `     viewBox="0 0 ${CANVAS_W} ${CANVAS_H}">`,
    ``,
    `<!-- layer 0: card background -->`,
    `<g transform="${BG_TRANSLATE}">`,
    bgLayer1,
    `</g>`,
    ``,
    `<!-- layer 1: QR code -->`,
    `<g transform="translate(${tx},${ty}) scale(${scale})">`,
    qrInner,
    `</g>`,
    ``,
    `<!-- layer 2: logo artwork (scaled ${LOGO_SCALE * 100}% around logo centre) -->`,
    `<g transform="${BG_TRANSLATE}">`,
    `<g transform="translate(${LOGO_CX},${LOGO_CY}) scale(${LOGO_SCALE}) translate(${-LOGO_CX},${-LOGO_CY})">`,
    logoRect,
    logoPathEl,
    `</g>`,
    `</g>`,
    ``,
    `</svg>`,
  ].join('\n');
};

// ── Validate inputs ───────────────────────────────────────────────────────────

for (const [label, p] of [['bg template', bgPath], ['logo template', logoPath], ['codes dir', codesDir]]) {
  if (!fs.existsSync(p)) {
    console.error(`Error: ${label} not found at: ${p}`);
    process.exit(1);
  }
}

const codeFiles = fs.readdirSync(codesDir).filter((f) => f.endsWith('.svg'));

if (codeFiles.length === 0) {
  console.error(`Error: No .svg files found in ${codesDir}`);
  process.exit(1);
}

// ── Parse shared templates once ───────────────────────────────────────────────

console.log('Parsing shared templates…');

const bgSvg      = fs.readFileSync(bgPath, 'utf8');
const bgLayer1   = [
  // g596  — hidden QR placeholder (not needed in composed output)
  // g912  — full-page vertical cut lines  (page-level, must not repeat per card)
  // g911  — full-page horizontal cut lines (page-level, must not repeat per card)
  // g872  — left-margin white strip       (page-level)
  // g898  — horizontal row separators     (page-level)
  'g596', 'g912', 'g911', 'g872', 'g898',
].reduce((svg, id) => removeGroup(svg, id), getGroupInner(bgSvg, 'layer1')).trim();

const logoSvg    = fs.readFileSync(logoPath, 'utf8');
const logoRect   = getElementById(logoSvg, 'rect598').trim();
const logoPathEl = getElementById(logoSvg, 'path599').trim();

if (!bgLayer1)   { console.error('Error: could not extract bg layer1');  process.exit(1); }
if (!logoRect)   { console.error('Error: could not extract rect598');    process.exit(1); }
if (!logoPathEl) { console.error('Error: could not extract path599');    process.exit(1); }

console.log('  ✓  qr-code-bg.svg');
console.log('  ✓  qr-code-logo.svg');

// ── Ensure output directory ───────────────────────────────────────────────────

fs.mkdirSync(outputDir, { recursive: true });

// ── Compose ───────────────────────────────────────────────────────────────────

console.log(`\nComposing ${codeFiles.length} QR code(s)…`);

let successCount = 0;
let errorCount   = 0;

for (const filename of codeFiles) {
  const codePath = path.join(codesDir, filename);
  const outPath  = path.join(outputDir, filename);

  try {
    const qrSvg    = fs.readFileSync(codePath, 'utf8');
    const qrInner  = svgInner(qrSvg).trim();
    const output   = buildFlatSVG({ bgLayer1, qrInner, logoRect, logoPath: logoPathEl });

    fs.writeFileSync(outPath, output, 'utf8');

    const kb = (fs.statSync(outPath).size / 1024).toFixed(1);
    console.log(`  ✓  ${filename}  (${kb} KB)`);
    successCount++;
  } catch (err) {
    console.error(`  ✗  ${filename}: ${err.message}`);
    errorCount++;
  }
}

console.log(`\nDone: ${successCount} composed, ${errorCount} failed`);
console.log(`Output: ${outputDir}`);
