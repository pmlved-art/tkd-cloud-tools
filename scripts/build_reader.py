#!/usr/bin/env python3
"""Build the reviewed 0.2.0 candidate from explicit source files; no secrets."""
import hashlib
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
VERSION = '0.2.0'


def main():
    files = {'tkd-cloud-reader/' + n: ROOT / 'wordpress/tkd-cloud-reader' / n
             for n in ('tkd-cloud-reader.php', 'read-extensions.php', 'uninstall.php', 'README.md')}
    files.update({'tkd-cloud-reader/tests/' + n: ROOT / 'tests/php' / n
                  for n in ('test-reader.php', 'test-read-extensions.php', 'test-manifest-bounds.php', 'fixtures/tkd-classes.php')})
    target = ROOT / ('artifacts/tkd-cloud-reader-' + VERSION + '-candidate.zip')
    target.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, source in sorted(files.items()):
            item = zipfile.ZipInfo(name, (2026, 11, 17, 0, 0, 0))
            item.compress_type = zipfile.ZIP_DEFLATED
            item.external_attr = 0o100644 << 16
            archive.writestr(item, source.read_bytes())
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(files)
        for name, source in files.items():
            assert archive.read(name) == source.read_bytes()
    sources = list(files.values()) + [ROOT / n for n in ('scripts/reader.py', 'scripts/read_contracts.py', 'scripts/build_reader.py', 'tests/test_read_extensions.py')] + [target]
    manifest = '\n'.join(hashlib.sha256(p.read_bytes()).hexdigest() + '  ' + str(p.relative_to(ROOT)) for p in sources) + '\n'
    (ROOT / ('artifacts/SHA256SUMS-' + VERSION)).write_text(manifest)
    print(target.name, hashlib.sha256(target.read_bytes()).hexdigest(), target.stat().st_size)


if __name__ == '__main__':
    main()
