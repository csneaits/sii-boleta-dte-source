const fs = require('fs');
const path = require('path');
const glob = require('glob');
const Terser = require('terser');
const CleanCSS = require('clean-css');

const root = path.resolve(__dirname, '..');
const assetsDir = path.join(root, 'Presentation', 'assets');

function minifyJs(file) {
  const code = fs.readFileSync(file, 'utf8');
  const result = Terser.minify(code, { compress: true, mangle: true });
  if (result.error) throw result.error;
  const out = file.replace(/\.js$/, '.min.js');
  fs.writeFileSync(out, result.code, 'utf8');
  console.log('minified', file, '->', out);
}

function minifyCss(file) {
  const code = fs.readFileSync(file, 'utf8');
  const result = new CleanCSS().minify(code);
  if (result.errors && result.errors.length) throw new Error(result.errors.join('\n'));
  const out = file.replace(/\.css$/, '.min.css');
  fs.writeFileSync(out, result.styles, 'utf8');
  console.log('minified', file, '->', out);
}

function run() {
  if (!fs.existsSync(assetsDir)) {
    console.log('No assets dir found at', assetsDir);
    return;
  }

  const jsFiles = glob.sync('**/*.js', { cwd: assetsDir, nodir: true }).map(f => path.join(assetsDir, f));
  const cssFiles = glob.sync('**/*.css', { cwd: assetsDir, nodir: true }).map(f => path.join(assetsDir, f));

  jsFiles.forEach(minifyJs);
  cssFiles.forEach(minifyCss);
}

run();
