#!/usr/bin/env node

/**
 * Simple build script to compile the Vue frontend
 * Run with: node scripts/build.js
 */

const { spawn } = require('child_process');
const path = require('path');

const clientDir = path.join(__dirname, '..', 'client');

console.log('Building frontend...');
console.log(`Working directory: ${clientDir}\n`);

const build = spawn('npm', ['run', 'build-only'], {
  cwd: clientDir,
  stdio: 'inherit',
  shell: true
});

build.on('close', (code) => {
  if (code === 0) {
    console.log('\n✓ Frontend built successfully!');
    console.log('\nNext steps:');
    console.log('1. Copy dist folder to nginx container:');
    console.log('   docker cp client/dist/. smeta_web:/usr/share/nginx/html/');
    process.exit(0);
  } else {
    console.error(`\n✗ Build failed with exit code ${code}`);
    process.exit(code);
  }
});
