import { execFileSync } from 'node:child_process';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

const virtualId = 'virtual:i18n';
const resolvedVirtualId = '\0virtual:i18n';

function langJson() {
    return execFileSync('php', ['scripts/export-lang.php'], {
        cwd: process.cwd(),
        encoding: 'utf8',
    });
}

function i18nFromLang() {
    return {
        name: 'i18n-from-lang',
        resolveId(id) {
            if (id === virtualId) {
                return resolvedVirtualId;
            }
        },
        load(id) {
            if (id !== resolvedVirtualId) {
                return null;
            }

            return `export default ${langJson()}`;
        },
        generateBundle() {
            this.emitFile({
                type: 'asset',
                fileName: 'lang.json',
                source: langJson(),
            });
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Manrope', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('Barlow Condensed', {
                    weights: [500, 600],
                }),
                bunny('Cairo', {
                    weights: [400, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
        i18nFromLang(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
