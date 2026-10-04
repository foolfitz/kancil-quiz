import type { Plugin } from 'vite';
import { defineConfig } from 'vite';

// 獨立播放器（docs/SPEC.md O-02）建置成單一個 HTML 檔：程式、樣式、字型全部內嵌，
// 放在任何靜態主機上，或下載後直接用瀏覽器開啟（file://）都能用。輸出到 public/standalone.html。
// 建置：npm run build:standalone（npm run build 會一併執行）。

// <script src> 以 file:// 開啟時會被瀏覽器擋下，所以把 JS 與 CSS 內嵌到 HTML 中
function inlineIntoHtml(): Plugin {
    return {
        name: 'kancil-standalone-inline',
        enforce: 'post',
        generateBundle(_options, bundle) {
            const page = bundle['index.html'];
            if (page?.type !== 'asset') {
                return;
            }
            let html = String(page.source);
            for (const [name, file] of Object.entries(bundle)) {
                const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                if (file.type === 'chunk') {
                    const tag = new RegExp(
                        `<script[^>]*src="[^"]*${escaped}"[^>]*></script>`,
                    );
                    // 程式中的 </script 會提早結束標籤，改寫成等價的 <\/script
                    const code = file.code.replace(/<\/script/gi, '<\\/script');
                    html = html.replace(
                        tag,
                        () => `<script type="module">${code}</script>`,
                    );
                    delete bundle[name];
                } else if (name.endsWith('.css')) {
                    const tag = new RegExp(
                        `<link[^>]*href="[^"]*${escaped}"[^>]*>`,
                    );
                    html = html.replace(
                        tag,
                        () => `<style>${String(file.source)}</style>`,
                    );
                    delete bundle[name];
                }
            }
            page.source = html;
            page.fileName = 'standalone.html';
        },
    };
}

export default defineConfig({
    base: './',
    plugins: [inlineIntoHtml()],
    build: {
        outDir: '../../../public',
        // 輸出目錄是 Laravel 的 public，只寫入 standalone.html，不清空其他檔案
        emptyOutDir: false,
        copyPublicDir: false,
        assetsInlineLimit: Number.MAX_SAFE_INTEGER,
        cssCodeSplit: false,
        modulePreload: false,
    },
});
