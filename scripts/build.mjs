import { cp, mkdir, readFile, rm, stat } from 'node:fs/promises';
import { basename, dirname, join, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const output = join(root, 'build', 'leadforms-go');
const ignored = (await readFile(join(root, '.distignore'), 'utf8')).split(/\r?\n/).map(v => v.trim()).filter(Boolean);
await rm(join(root, 'build'), { recursive: true, force: true });
await mkdir(output, { recursive: true });
for (const name of ['assets', 'includes', 'languages', 'leadforms-go.php', 'uninstall.php', 'readme.md', 'CHANGELOG.md', 'ROADMAP.md']) {
  const source = join(root, name);
  try { await stat(source); } catch { continue; }
  if (!ignored.includes(name)) await cp(source, join(output, basename(source)), { recursive: true });
}
console.log(`Release directory: ${output}`);
