#!/usr/bin/env node
// Packages src/ into dist/bu-qr-generator-<version>.zip
// The src/ folder is renamed to bu-qr-generator/ inside the zip.
//
// Usage:  node scripts/archive.js
//         npm run archive

'use strict';

const fs   = require('fs');
const path = require('path');
const os   = require('os');
const cp   = require('child_process');

// ── Paths ────────────────────────────────────────────────────────────────────

const root       = path.resolve(__dirname, '..');
const srcDir     = path.join(root, 'src');
const pluginFile = path.join(srcDir, 'bu-qr-generator.php');
const distDir    = path.join(root, 'dist');
const pluginSlug = 'bu-qr-generator';

if (!fs.existsSync(srcDir)) {
  console.error(`Error: src/ folder not found at: ${srcDir}`);
  process.exit(1);
}

// ── Read version from plugin header ─────────────────────────────────────────

const header  = fs.readFileSync(pluginFile, 'utf8').split('\n').slice(0, 20).join('\n');
const versionMatch = header.match(/^\s*\*\s*Version:\s*(.+?)\s*$/m);

if (!versionMatch) {
  console.error(`Error: Could not find 'Version:' header in ${pluginFile}`);
  process.exit(1);
}

const version = versionMatch[1].trim();
console.log(`Plugin version : ${version}`);

// ── Check for existing archive ───────────────────────────────────────────────

const zipName = `${pluginSlug}-${version}.zip`;
const zipPath = path.join(distDir, zipName);

if (fs.existsSync(zipPath)) {
  console.error(`Error: Archive already exists for v${version}: ${zipPath}\nBump the version in src/bu-qr-generator.php before archiving.`);
  process.exit(1);
}

// ── Ensure dist/ exists ──────────────────────────────────────────────────────

fs.mkdirSync(distDir, { recursive: true });

// ── Staging area ─────────────────────────────────────────────────────────────
// src/ is copied into staging as bu-qr-generator/ so the zip contains
// bu-qr-generator/<files> (WordPress expects the folder to match the slug).

const stagingRoot   = path.join(os.tmpdir(), `buqr-staging-${version}`);
const stagingPlugin = path.join(stagingRoot, pluginSlug);

if (fs.existsSync(stagingRoot)) {
  fs.rmSync(stagingRoot, { recursive: true, force: true });
}
fs.mkdirSync(stagingPlugin, { recursive: true });

// ── Files to exclude ─────────────────────────────────────────────────────────

const excludedFiles = new Set(['.gitignore', '.gitattributes', '.editorconfig', 'package-lock.json', 'yarn.lock']);
const excludedExts  = new Set(['.log', '.map']);

// ── Copy src/ → staging/bu-qr-generator/ ─────────────────────────────────────

const copyDir = (sourceDir, targetDir) => {
  for (const entry of fs.readdirSync(sourceDir, { withFileTypes: true })) {
    const srcPath  = path.join(sourceDir, entry.name);
    const destPath = path.join(targetDir, entry.name);

    if (entry.isDirectory()) {
      fs.mkdirSync(destPath, { recursive: true });
      copyDir(srcPath, destPath);
      continue;
    }

    if (excludedFiles.has(entry.name))              continue;
    if (excludedExts.has(path.extname(entry.name))) continue;

    fs.copyFileSync(srcPath, destPath);
  }
};

copyDir(srcDir, stagingPlugin);

// ── Create zip ───────────────────────────────────────────────────────────────

console.log(`Creating       : ${zipPath}`);

// Use the system zip command (available on Linux/macOS/WSL).
// On native Windows without WSL, fall back to PowerShell's Compress-Archive.
try {
  cp.execSync(`zip -r "${zipPath}" "${pluginSlug}"`, {
    cwd: stagingRoot,
    stdio: 'inherit',
  });
} catch {
  // Fallback: PowerShell (native Windows)
  cp.execSync(
    `powershell -Command "Compress-Archive -Path '${stagingPlugin}' -DestinationPath '${zipPath}'"`,
    { stdio: 'inherit' }
  );
}

// ── Cleanup staging ──────────────────────────────────────────────────────────

fs.rmSync(stagingRoot, { recursive: true, force: true });

// ── Done ─────────────────────────────────────────────────────────────────────

const sizeKb = (fs.statSync(zipPath).size / 1024).toFixed(1);
console.log(`Done           : ${zipName}  (${sizeKb} KB)`);
