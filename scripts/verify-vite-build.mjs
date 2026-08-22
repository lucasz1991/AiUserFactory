import { readdir, readFile, stat } from 'node:fs/promises';
import { basename, relative, resolve } from 'node:path';

const MEBIBYTE = 1024 * 1024;
const buildRoot = resolve('public/build');
const manifestPath = resolve(buildRoot, 'manifest.json');
const maxTotalBytes = 20 * MEBIBYTE;
const maxFileCount = 100;
const maxJavaScriptBytes = 2 * MEBIBYTE;
const maxCssBytes = 1.25 * MEBIBYTE;

const requiredEntries = [
    'resources/css/app-shell.css',
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/scss/icons.scss',
    'resources/scss/tailwind.scss',
];

const requiredLegacyAssets = [
    'images/error-img.png',
    'images/logo-sm.svg',
    'images/users/avatar-1.jpg',
    'images/users/avatar-2.jpg',
    'images/users/avatar-3.jpg',
    'js/pages/login.init.js',
    'libs/@popperjs/core/umd/popper.min.js',
    'libs/apexcharts/apexcharts.css',
    'libs/apexcharts/apexcharts.min.js',
    'libs/feather-icons/feather.min.js',
    'libs/metismenujs/metismenujs.min.js',
    'libs/simplebar/simplebar.min.js',
    'libs/swiper/swiper-bundle.min.css',
    'libs/swiper/swiper-bundle.min.js',
];

async function collectFiles(directory) {
    const entries = await readdir(directory, { withFileTypes: true });
    const files = [];

    for (const entry of entries) {
        const absolutePath = resolve(directory, entry.name);

        if (entry.isDirectory()) {
            files.push(...await collectFiles(absolutePath));
        } else if (entry.isFile()) {
            files.push(absolutePath);
        }
    }

    return files;
}

function fail(message) {
    throw new Error(`Vite build verification failed: ${message}`);
}

const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
const files = await collectFiles(buildRoot);
const fileSizes = new Map();

for (const file of files) {
    fileSizes.set(relative(buildRoot, file).replaceAll('\\', '/'), (await stat(file)).size);
}

const totalBytes = [...fileSizes.values()].reduce((total, bytes) => total + bytes, 0);
const sourceMaps = [...fileSizes.keys()].filter((file) => file.endsWith('.map'));

if (files.length > maxFileCount) {
    fail(`${files.length} files exceed the ${maxFileCount}-file budget.`);
}

if (totalBytes > maxTotalBytes) {
    fail(`${(totalBytes / MEBIBYTE).toFixed(2)} MiB exceed the ${(maxTotalBytes / MEBIBYTE).toFixed(0)} MiB budget.`);
}

if (sourceMaps.length > 0) {
    fail(`production source maps are present: ${sourceMaps.join(', ')}`);
}

for (const entry of requiredEntries) {
    if (!manifest[entry]) {
        fail(`manifest entry is missing: ${entry}`);
    }
}

const emittedManifestAssets = new Set();

for (const item of Object.values(manifest)) {
    if (item.file) {
        emittedManifestAssets.add(item.file);
    }

    for (const cssFile of item.css ?? []) {
        emittedManifestAssets.add(cssFile);
    }
}

for (const asset of emittedManifestAssets) {
    if (!fileSizes.has(asset)) {
        fail(`manifest references a missing file: ${asset}`);
    }

    if (!/-[A-Za-z0-9_-]{8,}\.[^.]+$/.test(basename(asset))) {
        fail(`manifest asset has no content hash: ${asset}`);
    }
}

for (const asset of requiredLegacyAssets) {
    if (!fileSizes.has(asset)) {
        fail(`required legacy asset is missing: ${asset}`);
    }
}

for (const [file, bytes] of fileSizes) {
    if (file.endsWith('.js') && bytes > maxJavaScriptBytes) {
        fail(`${file} exceeds the ${(maxJavaScriptBytes / MEBIBYTE).toFixed(0)} MiB JavaScript budget.`);
    }

    if (file.endsWith('.css') && bytes > maxCssBytes) {
        fail(`${file} exceeds the ${(maxCssBytes / MEBIBYTE).toFixed(2)} MiB CSS budget.`);
    }
}

console.log(`Vite build verified: ${files.length} files, ${(totalBytes / MEBIBYTE).toFixed(2)} MiB, 0 source maps, hashed manifest assets.`);
