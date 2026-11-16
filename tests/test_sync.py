import importlib.util
import json
from pathlib import Path
import unittest
spec=importlib.util.spec_from_file_location('sync',Path(__file__).resolve().parents[1]/'server/sync.py')
sync=importlib.util.module_from_spec(spec);spec.loader.exec_module(sync)
DATA=json.dumps({'rows':[{'id':1}],'details':{'1':{'id':1}}}).encode()
CONFIG={'FTP_HOST':'example.test','FTP_USER':'user','FTP_PASSWORD':'secret','FTP_REMOTE_DIR':'/private'}
class FTP:
 def __init__(self,context,timeout):self.files={'snapshot.json':b'old'};self.encrypted=False;self.bad=False;self.refuse=False
 def connect(self,*a):pass
 def login(self,*a):pass
 def prot_p(self):self.encrypted=True
 def set_pasv(self,v):pass
 def cwd(self,v):pass
 def storbinary(self,cmd,f):assert self.encrypted;self.files[cmd[5:]]=f.read()
 def retrbinary(self,cmd,cb):cb(b'corrupted' if self.bad else self.files[cmd[5:]])
 def rename(self,a,b):
  if self.refuse:raise RuntimeError('rename refused')
  self.files[b]=self.files.pop(a)
 def delete(self,a):del self.files[a]
 def close(self):pass
class Tests(unittest.TestCase):
 def test_upload_verifies_and_replaces(self):
  f=FTP(None,0);sync.upload(DATA,CONFIG,lambda **kw:f);self.assertEqual(f.files,{'snapshot.json':DATA});self.assertTrue(f.encrypted)
 def test_corruption_preserves_old(self):
  f=FTP(None,0);f.bad=True
  with self.assertRaises(ValueError):sync.upload(DATA,CONFIG,lambda **kw:f)
  self.assertEqual(f.files,{'snapshot.json':b'old'})
 def test_rename_failure_preserves_old(self):
  f=FTP(None,0);f.refuse=True
  with self.assertRaises(RuntimeError):sync.upload(DATA,CONFIG,lambda **kw:f)
  self.assertEqual(f.files,{'snapshot.json':b'old'})
 def test_invalid_snapshot_rejected(self):
  with self.assertRaises(ValueError):sync.validate_snapshot(b'{"rows":[],"details":{}}')
 def test_config_line_injection_rejected(self):
  with self.assertRaises(ValueError):sync.upload(DATA,dict(CONFIG,FTP_REMOTE_DIR='x\r\nDELE snapshot.json'))
if __name__=='__main__':unittest.main()
