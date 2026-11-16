"""Build the upload ZIP using only the Python standard library."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
root = Path(__file__).resolve().parent.parent
with ZipFile(root / 'little-steps-php-bg.zip', 'w', ZIP_DEFLATED) as archive:
    for folder in ('public_html', 'little-steps-private', 'tests'):
        for path in sorted((root / folder).rglob('*')):
            if not path.is_file():
                continue
            if path.parent.name == 'data' and path.name != 'snapshot.json':
                continue
            archive.write(path, path.relative_to(root))
    archive.write(root / 'README-BG.md', 'README-BG.md')
print('Created little-steps-php-bg.zip')
