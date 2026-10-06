import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

// Vitest 只測 packages/ 中不碰 Laravel 與 Vue 的套件，不需要這些外掛。laravel-vite-plugin 在 CI 環境
// 會拒絕啟動開發伺服器（Vitest 也算），所以測試時整組不載入。
const testing = process.env.VITEST !== undefined;

export default defineConfig({
    plugins: lazyPlugins(() =>
        testing
            ? []
            : [
                  laravel({
                      input: [
                          'resources/css/app.css',
                          'resources/js/app.ts',
                          'resources/js/player.ts',
                      ],
                      refresh: true,
                      fonts: [
                          bunny('Instrument Sans', {
                              weights: [400, 500, 600],
                          }),
                      ],
                  }),
                  inertia(),
                  tailwindcss(),
                  vue({
                      template: {
                          transformAssetUrls: {
                              base: null,
                              includeAbsolute: false,
                          },
                      },
                  }),
                  wayfinder({
                      formVariants: true,
                  }),
              ],
    ),
    test: {
        include: ['packages/**/*.test.ts'],
        // 遊戲套件的測試會檢查 CSS 是否都限定在自己的根元素之下
        // （內建的遊戲在 kancil-games、kancil-materials 兩個 submodule 中，多一層目錄）
        css: {
            include: [
                /packages\/games\/(?:kancil-(?:games|materials)\/)?[^/]+\/src\/style\.css/,
            ],
        },
    },
    server: {
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            'packages/schema/src/generated.ts',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            'packages/schema/src/generated.ts',
            'docs/**',
            'CLAUDE.md',
            'packages/games/manifest.json',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
