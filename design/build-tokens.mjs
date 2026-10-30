import { readFile, writeFile } from 'node:fs/promises';

const source = new URL('./tokens.json', import.meta.url);
const target = new URL('../apps/hub/resources/css/tokens.css', import.meta.url);
const tokens = JSON.parse(await readFile(source, 'utf8'));
const declarations = Object.entries(tokens).flatMap(([group, values]) =>
    Object.entries(values).map(([name, value]) => `    --${group}-${name}: ${value};`),
);
const css = `/* Generated from design/tokens.json. Run node design/build-tokens.mjs. */\n:root {\n${declarations.join('\n')}\n    color-scheme: dark;\n}\n`;

if (process.argv.includes('--check')) {
    if (await readFile(target, 'utf8') !== css) {
        throw new Error('Design tokens are stale. Run node design/build-tokens.mjs.');
    }
} else {
    await writeFile(target, css);
}
