// GitHub Pages has no server-side routing: serve the app for any unknown path so deep links (/medishelf-sync/cabinet) work.
import { copyFileSync, writeFileSync } from 'node:fs';
copyFileSync('dist/index.html', 'dist/404.html');
writeFileSync('dist/.nojekyll', '');
console.log('postbuild: 404.html and .nojekyll written');
