#!/usr/bin/env python3
"""Build only the byte-exact reviewed production gateway."""
import hashlib
import json
from pathlib import Path
import zipfile
ROOT = Path(__file__).resolve().parents[1]
VERSION = '0.4.2'
FILES = ('tkd-cloud-reader.php','read-extensions.php','operations.php','operator-role.php','native-contracts.php','document-contracts.php','reservation-contracts.php','document-operations.php','document-pdf.php','document-pins.php','document-field-pins.php','document-state-keys.php','delivery-creation-lock.php','uninstall.php','README.md')
def main():
    reviewed = json.loads((ROOT / 'review/release-0.4.2.json').read_text())
    assert reviewed['source_review_complete'] is True
    files = {'tkd-cloud-reader/' + n: ROOT / 'wordpress/tkd-cloud-reader' / n for n in FILES}
    hashes = {n: hashlib.sha256(p.read_bytes()).hexdigest() for n,p in files.items()}
    assert hashes == reviewed['production_files'], 'Source changed after review'
    assert 'Version: 0.4.2' in files['tkd-cloud-reader/tkd-cloud-reader.php'].read_text()
    target = ROOT / ('artifacts/tkd-cloud-reader-' + VERSION + '.zip')
    target.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, source in sorted(files.items()):
            info = zipfile.ZipInfo(name, (2026,11,17,0,0,0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, source.read_bytes())
    with zipfile.ZipFile(target) as archive:
        assert archive.testzip() is None
        assert set(archive.namelist()) == set(files)
        for name, source in files.items():
            assert archive.read(name) == source.read_bytes()
    digest = hashlib.sha256(target.read_bytes()).hexdigest()
    target.with_suffix('.zip.sha256').write_text(digest + '  ' + target.name + '\n')
    print(target.name, digest, target.stat().st_size)
if __name__ == '__main__':
    main()
