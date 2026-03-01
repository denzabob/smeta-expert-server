#!/usr/bin/env node

/**
 * Local-only helper to compile the Vue frontend during development.
 * This must not be used as a production deploy step on VPS.
 */

const { spawn } = require('child_process');
const path = require('path');

const clientDir = path.join(__dirname, '..', 'client');

console.log('Building frontend (local workstation only)...');
console.log(`Working directory: ${clientDir}\n`);

const build = spawn('npm', ['run', 'build-only'], {
  cwd: clientDir,
  stdio: 'inherit',
  shell: true
});

build.on('close', (code) => {
  if (code === 0) {
    console.log('\n✓ Frontend built successfully!');
    console.log('\nDo not copy build artifacts into the VPS checkout.');
    console.log('Deploy flow is: local changes -> git push -> VPS git pull --ff-only -> docker compose up -d --build.');
    process.exit(0);
  } else {
    console.error(`\n✗ Build failed with exit code ${code}`);
    process.exit(code);
  }
});
