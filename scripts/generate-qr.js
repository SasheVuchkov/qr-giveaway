#!/usr/bin/env node
// Generates styled SVG QR codes for every URL listed in qr-dist/urls.txt
// and writes them to qr-dist/codes/<slug>.svg
//
// Style:
//   - Near-black (#0a0a0b) modules and finder-pattern outer frames
//   - Golden (#f5b82e) inner dot of all three finder patterns
//   - White (#ffffff) background
//
// Usage:
//   node scripts/generate-qr.js
//   npm run generate-qr
//
// URL list format (qr-dist/urls.txt):
//   - One URL per line
//   - Lines starting with '#' are treated as comments and skipped
//   - Blank lines are skipped

'use strict';

const fs      = require('fs');
const path    = require('path');
const QRCode  = require('qrcode');

// ── Paths ────────────────────────────────────────────────────────────────────

const root      = path.resolve(__dirname, '..');
const urlsFile  = path.join(root, 'qr-dist', 'urls.txt');
const outputDir = path.join(root, 'qr-dist', 'codes');

// ── Style constants ───────────────────────────────────────────────────────────

const MODULE_SIZE   = 10;    // px per module
const MARGIN        = 2;     // modules of quiet zone
const DOT_COLOR     = '#0a0a0b';
const CORNER_COLOR  = '#f5b82e';  // inner 3×3 of each finder pattern
const BG_COLOR      = '#ffffff';

// Logo placeholder: white square centered in the QR code.
// Error correction is set to H (30 % recovery), so up to ~20 % coverage
// is safe. LOGO_RATIO controls what fraction of the QR area the square takes.
const LOGO_RATIO    = 0.20;

// ── SVG builder ───────────────────────────────────────────────────────────────

/**
 * Returns true when (row, col) falls inside the 3×3 dark center of one of
 * the three finder patterns (top-left, top-right, bottom-left).
 */
const isFinderInnerDot = (row, col, size) => {
  const finderCenters = [
    { r: 2, c: 2 },              // top-left
    { r: 2, c: size - 5 },       // top-right
    { r: size - 5, c: 2 },       // bottom-left
  ];

  return finderCenters.some(
    ({ r, c }) => row >= r && row <= r + 2 && col >= c && col <= c + 2,
  );
};

/**
 * Builds a styled SVG string from a QR module matrix.
 * @param {Uint8Array} modules - flat row-major matrix (1 = dark, 0 = light)
 * @param {number}     size    - number of modules per side
 */
const buildSVG = (modules, size) => {
  const px    = MODULE_SIZE;
  const m     = MARGIN * px;
  const total = size * px + m * 2;

  // Logo placeholder: a white square centered in the QR code.
  // Round to the nearest module boundary so the gap looks clean.
  const logoModules  = Math.round(size * LOGO_RATIO);
  const logoOffset   = Math.round((size - logoModules) / 2);
  const logoPx       = logoModules * px;
  const logoX        = m + logoOffset * px;
  const logoY        = m + logoOffset * px;

  const isLogoArea = (row, col) =>
    row >= logoOffset &&
    row < logoOffset + logoModules &&
    col >= logoOffset &&
    col < logoOffset + logoModules;

  const rects = [];

  for (let row = 0; row < size; row++) {
    for (let col = 0; col < size; col++) {
      if (!modules[row * size + col]) continue;
      if (isLogoArea(row, col)) continue;

      const x     = m + col * px;
      const y     = m + row * px;
      const color = isFinderInnerDot(row, col, size) ? CORNER_COLOR : DOT_COLOR;

      rects.push(`<rect x="${x}" y="${y}" width="${px}" height="${px}" fill="${color}"/>`);
    }
  }

  // White logo placeholder drawn on top so the background stays clean
  // even when adjacent modules are dark.
  const logoRect = `<rect x="${logoX}" y="${logoY}" width="${logoPx}" height="${logoPx}" fill="${BG_COLOR}"/>`;

  return [
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${total} ${total}" width="${total}" height="${total}">`,
    `<rect width="${total}" height="${total}" fill="${BG_COLOR}"/>`,
    ...rects,
    logoRect,
    '</svg>',
  ].join('\n');
};

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Converts a URL into a safe filename slug.
 * e.g. "https://example.com/page-one" → "example.com_page-one"
 */
const urlToSlug = (url) => {
  try {
    const parsed = new URL(url);
    const host   = parsed.hostname.replace(/^www\./, '');
    const slug   = (parsed.pathname + parsed.search)
      .replace(/^\//, '')
      .replace(/[^a-zA-Z0-9._-]/g, '_')
      .replace(/_+/g, '_')
      .replace(/^_|_$/g, '');

    return slug ? `${host}_${slug}` : host;
  } catch {
    return url.replace(/[^a-zA-Z0-9._-]/g, '_').replace(/_+/g, '_');
  }
};

/**
 * Returns a deduplicated filename: if slug already exists in the seen set,
 * appends _2, _3, … until it is unique.
 */
const uniqueFilename = (slug, seen) => {
  let candidate = slug;
  let counter   = 2;

  while (seen.has(candidate)) {
    candidate = `${slug}_${counter}`;
    counter++;
  }

  seen.add(candidate);
  return candidate;
};

// ── Read & parse urls.txt ────────────────────────────────────────────────────

if (!fs.existsSync(urlsFile)) {
  console.error(`Error: URL list not found at: ${urlsFile}`);
  console.error('Create the file and add one URL per line.');
  process.exit(1);
}

const rawLines = fs.readFileSync(urlsFile, 'utf8').split('\n');
const urls = rawLines
  .map((line) => line.trim())
  .filter((line) => line && !line.startsWith('#'));

if (urls.length === 0) {
  console.error('Error: No URLs found in urls.txt');
  process.exit(1);
}

console.log(`Found ${urls.length} URL(s) in urls.txt`);

// ── Ensure output directory exists ───────────────────────────────────────────

fs.mkdirSync(outputDir, { recursive: true });

// ── Generate QR codes ─────────────────────────────────────────────────────────

const seenSlugs  = new Set();
let successCount = 0;
let errorCount   = 0;

const generateAll = async () => {
  for (const url of urls) {
    const slug     = urlToSlug(url);
    const filename = uniqueFilename(slug, seenSlugs) + '.svg';
    const outPath  = path.join(outputDir, filename);

    try {
      const qr              = QRCode.create(url, { errorCorrectionLevel: 'H' });
      const { data, size }  = qr.modules;
      const svgString       = buildSVG(data, size);

      fs.writeFileSync(outPath, svgString, 'utf8');
      console.log(`  ✓  ${filename}`);
      successCount++;
    } catch (err) {
      console.error(`  ✗  ${url}  →  ${err.message}`);
      errorCount++;
    }
  }

  console.log(`\nDone: ${successCount} generated, ${errorCount} failed`);
  console.log(`Output: ${outputDir}`);
};

generateAll();
