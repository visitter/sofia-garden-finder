#!/usr/bin/env python3
"""Collect with the existing PHP backend and publish atomically over explicit FTPS."""
import argparse
import ftplib
import hashlib
import io
import json
import logging
import os
from pathlib import Path
import ssl
import subprocess
import sys
import time
import uuid

ROOT = Path(__file__).resolve().parent.parent
LOG = logging.getLogger('little-steps')

def validate_snapshot(data):
    snapshot = json.loads(data)
    if not snapshot.get('rows') or not isinstance(snapshot.get('details'), dict):
        raise ValueError('Invalid snapshot')
    ids = [row['id'] for row in snapshot['rows']]
    if len(ids) != len(set(ids)):
        raise ValueError('Duplicate listing IDs')
    # Unavailable new listings may have no detail; the frontend labels these unknown.
    for key, detail in snapshot['details'].items():
        if str(detail['id']) != str(key):
            raise ValueError('Mismatched listing ID')
    return snapshot

def upload(data, config, factory=ftplib.FTP_TLS):
    validate_snapshot(data)
    host, user, password, directory = (config[k] for k in ('FTP_HOST','FTP_USER','FTP_PASSWORD','FTP_REMOTE_DIR'))
    if any('\r' in x or '\n' in x for x in (host,user,password,directory)):
        raise ValueError('Invalid FTPS setting')
    context = ssl.create_default_context()
    ftp = factory(context=context, timeout=60)
    temporary = '.snapshot-' + uuid.uuid4().hex + '.json'
    staged = False
    try:
        ftp.connect(host, int(config.get('FTP_PORT','21')))
        ftp.login(user, password)  # FTP_TLS negotiates TLS before credentials.
        ftp.prot_p()              # Encrypt data transfers as well as login.
        ftp.set_pasv(True)
        ftp.cwd(directory)
        staged = True
        ftp.storbinary('STOR ' + temporary, io.BytesIO(data))
        digest = hashlib.sha256()
        ftp.retrbinary('RETR ' + temporary, digest.update)
        if digest.digest() != hashlib.sha256(data).digest():
            raise ValueError('Uploaded file verification failed')
        # Never delete the current file first. Failure leaves the previous snapshot intact.
        ftp.rename(temporary, 'snapshot.json')
        staged = False
        LOG.info('FTPS snapshot published and transfer checksum verified')
    finally:
        if staged:
            try: ftp.delete(temporary)
            except Exception: pass
        ftp.close()

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--upload-only', action='store_true', help='Publish existing local snapshot without collection')
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    config = dict(os.environ)
    for key in ('FTP_HOST','FTP_USER','FTP_PASSWORD','FTP_REMOTE_DIR'):
        if not config.get(key):
            LOG.error('Missing configuration: %s', key)
            return 1
    data_dir = Path(config.get('LITTLE_STEPS_DATA_DIR', '/var/lib/little-steps'))
    if not args.upload_only:
        # Keep runtime state outside the Git checkout.
        env = dict(config, LITTLE_STEPS_DATA_DIR=str(data_dir))
        data_dir.mkdir(parents=True, exist_ok=True)
        subprocess.run([config.get('PHP_BIN','/usr/bin/php'), str(ROOT/'server/collect.php')], env=env, check=True, timeout=7200)
    data = (data_dir/'snapshot.json').read_bytes()
    snapshot = validate_snapshot(data)
    LOG.info('Publishing %s listings; %s failed source checks retained/marked unavailable', len(snapshot['rows']), len(snapshot.get('refresh',{}).get('failedIds',[])))
    for attempt in range(3):
        try:
            upload(data, config)
            return 0
        except Exception as exc:
            # Do not log credentials, protocol transcripts, or raw server replies.
            LOG.error('FTPS attempt %s failed (%s)', attempt+1, type(exc).__name__)
            if attempt < 2: time.sleep(30)
    return 1

if __name__ == '__main__':
    try: sys.exit(main())
    except Exception as exc:
        LOG.error('Sync failed (%s). Check connectivity, PHP, and configured paths.', type(exc).__name__)
        sys.exit(1)
