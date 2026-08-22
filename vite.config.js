import { defineConfig } from 'vite';
import laravel, { refreshPaths } from 'laravel-vite-plugin';
import { viteStaticCopy } from 'vite-plugin-static-copy';

export default defineConfig({
    build: {
        manifest: true,
        rtl: true,
        outDir: 'public/build/',
        cssCodeSplit: true,
        buildDirectory: '',
        sourcemap: false,
        rollupOptions: {
            output: {
                assetFileNames: 'assets/[name]-[hash][extname]',
                chunkFileNames: 'assets/[name]-[hash].js',
                entryFileNames: 'assets/[name]-[hash].js',
            },
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/scss/tailwind.scss',
                'resources/scss/icons.scss',
                'resources/css/app.css',
                'resources/css/app-shell.css',
                'resources/js/app.js',
            ],
            refresh: [
                ...refreshPaths,
                'app/Livewire/**',
            ],
        }),
        viteStaticCopy({
            targets: [
                {
                    src: 'resources/images/error-img.png',
                    dest: 'images',
                },
                {
                    src: 'resources/images/logo-sm.svg',
                    dest: 'images',
                },
                {
                    src: 'resources/images/users/avatar-{1,2,3}.jpg',
                    dest: 'images/users',
                },
                {
                    src: 'resources/js/pages/login.init.js',
                    dest: 'js/pages',
                },
                {
                    src: 'node_modules/@popperjs/core/dist/umd/popper.min.js',
                    dest: 'libs/@popperjs/core/umd',
                },
                {
                    src: 'node_modules/feather-icons/dist/feather.min.js',
                    dest: 'libs/feather-icons',
                },
                {
                    src: 'node_modules/metismenujs/dist/metismenujs.min.js',
                    dest: 'libs/metismenujs',
                },
                {
                    src: 'node_modules/simplebar/dist/simplebar.min.js',
                    dest: 'libs/simplebar',
                },
                {
                    src: 'node_modules/apexcharts/dist/apexcharts.min.js',
                    dest: 'libs/apexcharts',
                },
                {
                    src: 'node_modules/apexcharts/dist/apexcharts.css',
                    dest: 'libs/apexcharts',
                },
                {
                    src: 'node_modules/swiper/swiper-bundle.min.js',
                    dest: 'libs/swiper',
                },
                {
                    src: 'node_modules/swiper/swiper-bundle.min.css',
                    dest: 'libs/swiper',
                },
            ],
        }),
    ],
    resolve: {
        alias: {
            '@fonts': '/resources/fonts',
        },
    },
});
