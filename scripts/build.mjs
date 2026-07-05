import { cp, mkdir, readFile, rm, stat } from 'node:fs/promises';
import { basename, join, relative, resolve, sep } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const output = join(root, 'build', 'leadforms-go');
const ignored = (await readFile(join(root, '.distignore'), 'utf8')).split(/\r?\n/).map(v => v.trim()).filter(Boolean);
const patternRegex = ignored.filter((pattern) => pattern.includes('*')).map((pattern) => new RegExp(`^${pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replaceAll('*', '.*')}$`, 'i'));
const ignoredNames = new Set(ignored.filter((pattern) => !pattern.includes('*')));
const isIgnored = (source) => {
  const parts = relative(root, source).split(sep).filter(Boolean);
  return parts.some((part) => ignoredNames.has(part) || patternRegex.some((pattern) => pattern.test(part)));
};
await rm(join(root, 'build'), { recursive: true, force: true });
await mkdir(output, { recursive: true });
for (const name of ['assets', 'includes', 'languages', 'leadforms-go.php', 'uninstall.php', 'readme.md', 'CHANGELOG.md', 'ROADMAP.md']) {
  const source = join(root, name);
  try { await stat(source); } catch { continue; }
  if (!isIgnored(source)) await cp(source, join(output, basename(source)), { recursive: true, filter: (item) => !isIgnored(item) });
}
console.log(`Release directory: ${output}`);
