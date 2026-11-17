"""Fixed-host authenticated GET PDF download; no content/credentials in logs."""
import hashlib
import json
import os
from pathlib import Path
import re
import ssl
from urllib.error import HTTPError, URLError
from urllib.request import build_opener, ProxyHandler, HTTPSHandler, Request, getproxies, proxy_bypass
from scripts.preflight import NoRedirect

BASE = 'https://tkd-ukraine.com.ua:443/wp-json/tkd-cloud/v1/'
MAX_PDF = 10 * 1024 * 1024


def download_pdf(number, document_id, output=None):
    if not isinstance(number, str) or not re.fullmatch(r'TKD-[A-Za-z0-9-]{1,60}', number) or type(document_id) is not int or not 0 < document_id < 10**18:
        return {'result': 'invalid_document'}
    auth = os.environ.get('TKD_CLOUD_AUTHORIZATION', '')
    if not auth or len(auth) > 8192 or any(ord(c) < 32 or ord(c) > 126 for c in auth):
        return {'result': 'secret_missing_or_invalid'}
    if not getproxies().get('https') or proxy_bypass('tkd-ukraine.com.ua:443'):
        return {'result': 'managed_proxy_required'}
    try:
        opener = build_opener(ProxyHandler(), NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        request = Request(BASE + 'orders/' + number + '/documents/' + str(document_id) + '/pdf', headers={'Authorization': auth, 'Accept': 'application/pdf', 'User-Agent': 'TKD-Cloud-PDF/0.4.3'}, method='GET')
        with opener.open(request, timeout=45) as response:
            if response.code != 200:
                return {'result': 'http_error', 'http_status': response.code}
            if response.headers.get('Content-Type', '').split(';')[0].strip().lower() != 'application/pdf' or 'no-store' not in response.headers.get('Cache-Control', '').lower():
                return {'result': 'pdf_contract_mismatch'}
            raw = response.read(MAX_PDF + 1)
            if not raw.startswith(b'%PDF-') or len(raw) > MAX_PDF or len(raw) < 8:
                return {'result': 'pdf_contract_mismatch'}
        path = Path(output) if output is not None else Path('exports') / ('tkd-document-' + str(document_id) + '.pdf')
        if path.suffix.lower() != '.pdf':
            return {'result': 'invalid_output_path'}
        path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, 'wb') as stream:
                stream.write(raw)
                stream.flush()
                os.fsync(stream.fileno())
        except OSError:
            path.unlink(missing_ok=True)
            return {'result': 'local_pdf_write_failed'}
        return {'result': 'pdf_downloaded', 'http_status': 200, 'path': str(path), 'sha256': hashlib.sha256(raw).hexdigest()}
    except FileExistsError:
        return {'result': 'output_exists_no_overwrite'}
    except HTTPError as error:
        code = error.code
        error.close()
        return {'result': 'redirect_not_followed' if 300 <= code < 400 else 'http_error', 'http_status': code}
    except (URLError, OSError, TimeoutError, ValueError):
        return {'result': 'pdf_download_failed'}


def main():
    import argparse
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--number', required=True)
    parser.add_argument('--document-id', required=True, type=int)
    parser.add_argument('--output')
    args = parser.parse_args()
    result = download_pdf(args.number, args.document_id, args.output)
    print(json.dumps(result))
    return 0 if result['result'] == 'pdf_downloaded' else 1


if __name__ == '__main__':
    raise SystemExit(main())
