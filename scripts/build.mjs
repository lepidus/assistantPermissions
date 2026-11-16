import {copyFile, mkdir} from 'node:fs/promises';
const root = new URL('../', import.meta.url);
await mkdir(new URL('public/build/', root), {recursive: true});
await copyFile(new URL('resources/js/assistantPermissions.js', root), new URL('public/build/assistantPermissions.js', root));
