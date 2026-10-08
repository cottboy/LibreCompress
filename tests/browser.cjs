const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs/promises');
const { chromium } = require('playwright');

async function main() {
    const base = new URL(process.argv[2]);
    const output = path.resolve(process.argv[3]);
    assert.match(base.pathname, /\/libre-compress-tests-[A-Za-z0-9]+\/$/);
    await fs.mkdir(output, { recursive: true });
    const browser = await chromium.launch({ headless: true, channel: process.env.LC_BROWSER_CHANNEL });
    let checks = 0;
    try {
        for (const [name, viewport] of [
            ['desktop', { width: 1440, height: 1000 }],
            ['mobile', { width: 390, height: 844 }],
        ]) {
            const page = await browser.newPage({ viewport });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(new URL('browser.html', base).href);
            await page.waitForFunction(() => [...document.images].every(image => image.complete && image.naturalWidth > 0));
            const images = await page.locator('img').evaluateAll(items => items.map(image => {
                const canvas = document.createElement('canvas');
                canvas.width = image.naturalWidth;
                canvas.height = image.naturalHeight;
                const context = canvas.getContext('2d');
                context.drawImage(image, 0, 0);
                const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
                return { src: image.currentSrc, colors: new Set(pixels).size };
            }));
            assert.equal(images.length, 4);
            for (let index = 0; index < images.length; index++) {
                assert.ok(images[index].colors > 10, '图片必须有实际像素内容');
                assert.ok(images[index].src.endsWith(['.webp', '.png', '.avif', '.png'][index]));
                checks += 2;
            }
            await page.screenshot({ path: path.join(output, `fallback-${name}.png`), fullPage: true });
            for (const tab of ['general', 'tools']) {
                await page.goto(new URL(`settings-${tab}.html`, base).href);
                const controls = page.locator('input[name^="libre_compress_tools["]');
                if (tab === 'tools') {
                    const speedKeys = ['pngquant_speed', 'oxipng_level', 'webp_method', 'webp_lossless_level', 'gif2webp_method', 'avif_speed', 'gifsicle_level'];
                    for (const key of speedKeys) {
                        const input = page.locator(`input[name="libre_compress_tools[${key}]"]`);
                        assert.equal(await input.count(), 1);
                        const value = await input.evaluate(element => {
                            element.value = element.min;
                            element.dispatchEvent(new Event('input', { bubbles: true }));
                            return { value: element.value, output: element.nextElementSibling.value, rect: element.getBoundingClientRect().toJSON() };
                        });
                        assert.equal(value.value, value.output);
                        assert.ok(value.rect.width > 0 && value.rect.x >= 0 && value.rect.right <= viewport.width);
                        checks += 3;
                    }
                    assert.ok(await controls.count() >= 7);
                    const multipass = page.locator('input[name="libre_compress_tools[svg_multipass]"]');
                    await multipass.uncheck();
                    assert.equal(await multipass.isChecked(), false);
                    await multipass.check();
                    assert.equal(await multipass.isChecked(), true);
                    checks += 2;
                    await page.locator('h3').filter({ hasText: '压缩速度' }).scrollIntoViewIfNeeded();
                } else {
                    const checkbox = page.locator('input[name="libre_compress_general[original_fallback]"]');
                    await checkbox.uncheck();
                    assert.equal(await checkbox.isChecked(), false);
                    await checkbox.check();
                    assert.equal(await checkbox.isChecked(), true);
                    checks += 2;
                    await checkbox.scrollIntoViewIfNeeded();
                }
                await page.screenshot({ path: path.join(output, `settings-${tab}-${name}.png`) });
            }
            assert.deepEqual(errors, []);
            checks++;
            await page.close();
        }
        console.log(`通过 ${checks} 项浏览器断言，截图位于 ${output}`);
    } finally {
        await browser.close();
    }
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
