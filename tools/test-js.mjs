import {spawnSync} from 'node:child_process';
import {readdirSync, mkdirSync, writeFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {resolve} from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const production = readdirSync(resolve(root, 'src/com_nicode_form_studio/media/js')).filter(name => name.endsWith('.js'));
for (const name of production) {
  const syntax = spawnSync(process.execPath, ['--check', resolve(root, 'src/com_nicode_form_studio/media/js', name)], {cwd:root, encoding:'utf8'});
  if (syntax.status !== 0) { process.stderr.write(syntax.stderr || `Syntax check failed: ${name}\n`); process.exit(1); }
}
const files = readdirSync(resolve(root, 'tests/js')).filter(name => name.endsWith('.test.mjs')).sort().map(name => resolve(root, 'tests/js', name));
const run = spawnSync(process.execPath, ['--test', '--test-reporter=tap', ...files], {cwd:root, encoding:'utf8'});
process.stdout.write(run.stdout || ''); process.stderr.write(run.stderr || '');
const count = name => Number(run.stdout?.match(new RegExp(`^# ${name} (\\d+)$`, 'm'))?.[1] ?? NaN);
const report = {timestamp:new Date().toISOString(), node:process.version, tests:count('tests'), passed:count('pass'), failures:count('fail'), success:run.status === 0 && Number.isFinite(count('tests'))};
mkdirSync(resolve(root, 'build'), {recursive:true});
writeFileSync(resolve(root, 'build/js-test-results.json'), JSON.stringify(report, null, 2) + '\n');
process.exitCode = report.success ? 0 : 1;
