import {copyFile, mkdir} from 'node:fs/promises';
const root = new URL('../', import.meta.url);
await mkdir(new URL('public/build/', root), {recursive: true});
await copyFile(new URL('resources/js/editorialAdvisor.js', root), new URL('public/build/editorialAdvisor.js', root));
