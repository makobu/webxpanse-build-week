#!/usr/bin/env node

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
const output = process.env.CRM_PERF_AUDIT_OUTPUT
    || path.join(root, 'tmp', `performance-audit-${timestamp}.json`);
const playwrightCli = require.resolve('@playwright/test/cli');

fs.mkdirSync(path.dirname(output), { recursive: true });

const result = spawnSync(process.execPath, [
    playwrightCli,
    'test',
    'tests/performance/high-impact-pages.performance.spec.js',
    '--project=chromium',
    '--workers=1'
], {
    cwd: root,
    env: {
        ...process.env,
        CRM_PERF_AUDIT: '1',
        CRM_PERF_AUDIT_OUTPUT: output
    },
    stdio: 'inherit'
});

if (result.error) {
    throw result.error;
}

if (result.status !== 0) {
    process.exit(result.status || 1);
}

console.log(`Performance audit written to ${output}`);
