#!/usr/bin/env node
// Exports every SVG page in qr-dist/pages/ to a single multi-page PDF.
//
// Strategy: each SVG is rendered in its own headless-Chrome tab so that
// clip-path / filter IDs defined in separate SVG <defs> blocks can never
// conflict with each other.  The per-tab PDFs are then merged with pdf-lib.
//
// Page size: 330 × 487 mm  •  zero margins
//
// Usage:
//   node scripts/export-pdf.js
//   npm run export-pdf

'use strict';

const fs   = require('fs');
const path = require('path');

const puppeteer           = require('puppeteer');
const { PDFDocument }     = require('pdf-lib');

// ── Paths ─────────────────────────────────────────────────────────────────────

const root      = path.resolve(__dirname, '..');
const pagesDir  = path.join(root, 'qr-dist', 'pages');
const outputDir = path.join(root, 'qr-dist');
const outputPdf = path.join(outputDir, 'print-sheet.pdf');

// ── Page dimensions ───────────────────────────────────────────────────────────

const PAGE_W = '330mm';
const PAGE_H = '487mm';

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Wrap a raw SVG string in a minimal HTML shell that:
 *   - sets the @page size to exactly 330 × 487 mm with zero margins
 *   - makes the SVG fill the viewport completely
 */
const buildHtml = (svgContent) => `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    @page { size: ${PAGE_W} ${PAGE_H}; margin: 0; }
    html, body {
      margin: 0; padding: 0;
      width: ${PAGE_W}; height: ${PAGE_H};
      overflow: hidden;
    }
    svg { display: block; width: 100%; height: 100%; }
  </style>
</head>
<body>${svgContent}</body>
</html>`;

// ── Main ──────────────────────────────────────────────────────────────────────

const main = async () => {
  // Collect & sort SVG pages
  if (!fs.existsSync(pagesDir)) {
    console.error(`Error: pages directory not found:\n  ${pagesDir}`);
    console.error('Run  npm run compose-page  first.');
    process.exit(1);
  }

  const svgFiles = fs.readdirSync(pagesDir)
    .filter((f) => f.endsWith('.svg'))
    .sort();

  if (svgFiles.length === 0) {
    console.error(`Error: no .svg files found in ${pagesDir}`);
    process.exit(1);
  }

  console.log(`Found ${svgFiles.length} page(s) — launching browser…\n`);

  const browser = await puppeteer.launch({
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });

  const merged = await PDFDocument.create();

  try {
    for (let i = 0; i < svgFiles.length; i++) {
      const filename  = svgFiles[i];
      const svgPath   = path.join(pagesDir, filename);
      const svgContent = fs.readFileSync(svgPath, 'utf8');

      const tab = await browser.newPage();

      // Silence console noise from the page
      tab.on('console', () => {});
      tab.on('pageerror', () => {});

      await tab.setContent(buildHtml(svgContent), { waitUntil: 'domcontentloaded' });

      const pdfBuf = await tab.pdf({
        width:           PAGE_W,
        height:          PAGE_H,
        printBackground: true,
        margin:          { top: '0', right: '0', bottom: '0', left: '0' },
      });

      await tab.close();

      // Merge into the combined document
      const srcDoc      = await PDFDocument.load(pdfBuf);
      const [srcPage]   = await merged.copyPages(srcDoc, [0]);
      merged.addPage(srcPage);

      const kb = (pdfBuf.length / 1024).toFixed(0);
      console.log(`  ✓  ${filename}  (${kb} KB)`);
    }
  } finally {
    await browser.close();
  }

  fs.mkdirSync(outputDir, { recursive: true });

  const pdfBytes = await merged.save();
  fs.writeFileSync(outputPdf, Buffer.from(pdfBytes));

  const mb = (fs.statSync(outputPdf).size / 1024 / 1024).toFixed(1);
  console.log(`\nDone: print-sheet.pdf  (${mb} MB, ${svgFiles.length} page(s))`);
  console.log(`Output: ${outputPdf}`);
};

main().catch((err) => {
  console.error('\nFatal error:', err.message);
  process.exit(1);
});
