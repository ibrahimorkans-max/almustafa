// Builds public/css/millnexa/screens.css from resources/css/screens.css.  Run: npm run build:screens
//
// It scans our own views and PHP for Tailwind classes and emits:
//   - every class Filament's prebuilt stylesheet lacks, and
//   - every variant class (sm:, lg:, hover:, dark: …) we use, even if Filament has it,
// so within our file a base class always comes before its responsive overrides. Base classes that
// Filament already has are left to Filament, so Filament's own components look exactly as before.
import { compile, optimize } from '@tailwindcss/node';
import { Scanner } from '@tailwindcss/oxide';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const input = resolve(root, 'resources/css/screens.css');
const output = resolve(root, 'public/css/millnexa/screens.css');
const filamentCss = readFileSync(resolve(root, 'public/css/filament/filament/app.css'), 'utf8');

const scanner = new Scanner({
    sources: [
        { base: resolve(root, 'resources/views'), pattern: '**/*.blade.php', negated: false },
        { base: resolve(root, 'resources/views'), pattern: 'welcome.blade.php', negated: true },
        { base: resolve(root, 'app'), pattern: '**/*.php', negated: false },
    ],
});
const found = scanner.scan();

const selector = (c) => '.' + c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const inFilament = (c) => ['{', ':', ',', ' ', '>', '.', '[', ')'].some((end) => filamentCss.includes(selector(c) + end));
const candidates = found.filter((c) => c.includes(':') || !inFilament(c));

const compiler = await compile(readFileSync(input, 'utf8'), {
    base: dirname(input),
    onDependency: () => {},
});
const css = optimize(compiler.build(candidates), { minify: true }).code;

mkdirSync(dirname(output), { recursive: true });
writeFileSync(output, '/* Built by resources/css/build-screens.mjs — do not edit. */\n' + css);
console.log(`${candidates.length} classes → ${output} (${(css.length / 1024).toFixed(1)} KB)`);
