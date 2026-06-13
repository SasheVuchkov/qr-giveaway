#!/usr/bin/env node
// Composes print-ready page SVGs by placing up to 35 prod-code cards
// on the qr-code-page.svg background template in a 5 × 7 grid.
//
// Page template: print/templates/qr-code-page.svg  (330 × 487 mm, 1 unit = 1 mm)
// Cards source:  qr-dist/prod-codes/*.svg
// Output:        qr-dist/pages/page-01.svg, page-02.svg, …
//
// Grid parameters (all in mm):
//   Origin X: 12.6    Spacing X: 61    Cols: 5
//   Origin Y: 30      Spacing Y: 61    Rows: 7
//   Slot:     55 × 55 mm               Total: 35 cards per page
//
// Usage:
//   node scripts/compose-page.js
//   npm run compose-page

'use strict';

const fs   = require('fs');
const path = require('path');

// ── Paths ─────────────────────────────────────────────────────────────────────

const root      = path.resolve(__dirname, '..');
const pagePath  = path.join(root, 'print', 'templates', 'qr-code-page.svg');
const prodDir   = path.join(root, 'qr-dist', 'prod-codes');
const outputDir = path.join(root, 'qr-dist', 'pages');

// ── Grid constants ────────────────────────────────────────────────────────────

const CARD_W    = 45.961082;   // prod-code viewBox width  (mm)
const CARD_H    = 50.772491;   // prod-code viewBox height (mm)

const CELL_SIZE = 55;          // target slot dimension (mm)
const ORIGIN_X  = 12.6;        // left edge of first slot (mm)
const ORIGIN_Y  = 30;          // top  edge of first slot (mm)
const SPACING_X = 61;          // column pitch (mm)  = cell + 6 mm gap
const SPACING_Y = 61;          // row    pitch (mm)  = cell + 6 mm gap
const COLS      = 5;
const ROWS      = 7;
const MAX_CARDS = COLS * ROWS; // 35

// Uniform scale so the card height fills the 55 mm slot.
// Width becomes 45.961082 × scale ≈ 49.78 mm → centred in the 55 mm slot.
const CARD_SCALE = CELL_SIZE / CARD_H;                           // ≈ 1.08312
const X_OFFSET   = (CELL_SIZE - CARD_W * CARD_SCALE) / 2;       // ≈ 2.61 mm (centring)

// ── SVG helpers ───────────────────────────────────────────────────────────────

/**
 * Return the content that sits between <svg ...> and </svg>.
 * Handles files that start with an <?xml …?> declaration.
 */
const svgInner = (str) => {
  const svgStart   = str.indexOf('<svg');
  const openEnd    = str.indexOf('>', svgStart) + 1;
  const closeStart = str.lastIndexOf('</svg>');
  return closeStart === -1 ? str.slice(openEnd) : str.slice(openEnd, closeStart);
};

/**
 * Strip Inkscape/Sodipodi editor-only elements from SVG content.
 * These elements reference namespaces (inkscape:, sodipodi:) that are declared
 * on the <svg> root and become undefined once the root is stripped.
 * They carry no visual data and are never needed for rendering or print.
 */
const stripEditorElements = (str) => {
  // Remove <sodipodi:namedview …/> (self-closing)
  let out = str.replace(/<sodipodi:namedview\b[^>]*\/>/gs, '');
  // Remove <sodipodi:namedview …> … </sodipodi:namedview> (block form)
  out = out.replace(/<sodipodi:namedview\b[\s\S]*?<\/sodipodi:namedview>/g, '');
  // Remove <inkscape:…/> self-closing elements (e.g. inkscape:grid)
  out = out.replace(/<inkscape:[a-z]+\b[^>]*\/>/gi, '');
  // Remove <inkscape:…> … </inkscape:…> block elements
  out = out.replace(/<inkscape:[a-z]+\b[\s\S]*?<\/inkscape:[a-z]+>/gi, '');
  // Strip inkscape:* and sodipodi:* attributes from any remaining elements
  // e.g. inkscape:label="…"  inkscape:groupmode="…"  sodipodi:nodetypes="…"
  out = out.replace(/\s+(?:inkscape|sodipodi):[a-z-]+=(?:"[^"]*"|'[^']*')/gi, '');
  return out;
};

// ── Validate inputs ───────────────────────────────────────────────────────────

if (!fs.existsSync(pagePath)) {
  console.error(`Error: page template not found at:\n  ${pagePath}`);
  process.exit(1);
}

if (!fs.existsSync(prodDir)) {
  console.error(`Error: prod-codes directory not found at:\n  ${prodDir}`);
  process.exit(1);
}

const prodFiles = fs.readdirSync(prodDir)
  .filter((f) => f.endsWith('.svg'))
  .sort();

if (prodFiles.length === 0) {
  console.error(`Error: no .svg files found in ${prodDir}`);
  process.exit(1);
}

fs.mkdirSync(outputDir, { recursive: true });

// ── Parse page template once ──────────────────────────────────────────────────

console.log('Parsing page template…');
const pageSvg        = fs.readFileSync(pagePath, 'utf8');
const pageBackground = stripEditorElements(svgInner(pageSvg)).trim();
console.log('  ✓  qr-code-page.svg\n');

// ── Chunk prod files into pages ───────────────────────────────────────────────

const batches = [];
for (let i = 0; i < prodFiles.length; i += MAX_CARDS) {
  batches.push(prodFiles.slice(i, i + MAX_CARDS));
}

console.log(`Found ${prodFiles.length} prod-code(s) → ${batches.length} page(s)`);

// ── Compose pages ─────────────────────────────────────────────────────────────

let successPages = 0;
let errorCount   = 0;

for (let bIdx = 0; bIdx < batches.length; bIdx++) {
  const batch    = batches[bIdx];
  const pageNum  = String(bIdx + 1).padStart(2, '0');
  const outPath  = path.join(outputDir, `page-${pageNum}.svg`);

  try {
    const cardGroups = batch.map((filename, idx) => {
      const col = idx % COLS;
      const row = Math.floor(idx / COLS);
      const tx  = (ORIGIN_X + col * SPACING_X + X_OFFSET).toFixed(6);
      const ty  = (ORIGIN_Y + row * SPACING_Y).toFixed(6);
      const sc  = CARD_SCALE.toFixed(8);

      const cardSvg = fs.readFileSync(path.join(prodDir, filename), 'utf8');
      const inner   = svgInner(cardSvg).trim();

      return [
        `<!-- card ${String(idx + 1).padStart(2, '0')}  col=${col} row=${row}  ${filename} -->`,
        `<g transform="translate(${tx},${ty}) scale(${sc})">`,
        inner,
        `</g>`,
      ].join('\n');
    });

    const svg = [
      `<svg xmlns="http://www.w3.org/2000/svg"`,
      `     width="330mm" height="487mm"`,
      `     viewBox="0 0 330 487">`,
      ``,
      `<!-- ── layer 0: page background ───────────────────────────────────── -->`,
      pageBackground,
      ``,
      `<!-- ── layer 1: prod-code cards ────────────────────────────────────── -->`,
      cardGroups.join('\n\n'),
      ``,
      `</svg>`,
    ].join('\n');

    fs.writeFileSync(outPath, svg, 'utf8');

    const mb = (fs.statSync(outPath).size / 1024 / 1024).toFixed(1);
    console.log(`  ✓  page-${pageNum}.svg  (${mb} MB, ${batch.length} cards)`);
    successPages++;
  } catch (err) {
    console.error(`  ✗  page-${pageNum}.svg: ${err.message}`);
    errorCount++;
  }
}

console.log(`\nDone: ${successPages} page(s) written, ${errorCount} failed`);
console.log(`Output: ${outputDir}`);
