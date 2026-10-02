// Copy node SVG icons into dist/ alongside the compiled .js, because tsc only
// emits JavaScript. n8n loads the icon referenced by `icon: 'file:wadesk.svg'`
// from the same folder as the node file.
const fs = require('fs');
const path = require('path');

function copySvgs(srcDir, outDir) {
    if (!fs.existsSync(srcDir)) return;
    for (const entry of fs.readdirSync(srcDir, { withFileTypes: true })) {
        const src = path.join(srcDir, entry.name);
        const out = path.join(outDir, entry.name);
        if (entry.isDirectory()) {
            copySvgs(src, out);
        } else if (entry.name.endsWith('.svg') || entry.name.endsWith('.png')) {
            fs.mkdirSync(path.dirname(out), { recursive: true });
            fs.copyFileSync(src, out);
        }
    }
}

copySvgs(path.join(__dirname, 'nodes'), path.join(__dirname, 'dist', 'nodes'));
copySvgs(path.join(__dirname, 'credentials'), path.join(__dirname, 'dist', 'credentials'));
console.log('Icons copied to dist/.');
