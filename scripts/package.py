"""Build manual and marketplace install/update archives without local state."""
import hashlib
import json
import re
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

ROOT = Path(__file__).resolve().parent.parent
PAY = Path('app/Pay/PolyPay')
CALLBACK = Path('app/Plugin/PolyPayCallback')


def source_files(base):
    """Select code and licenses; configuration is generated separately."""
    names = (
        ['Config/Info.php', 'Config/Submit.php', 'Gateway.php', 'Support.php', 'HostUrl.php', 'Impl/Pay.php', 'Impl/Signature.php']
        if base == PAY else ['Config/Info.php', 'Config/Submit.php', 'Controller/Notification.php']
    )
    candidates = [ROOT / base / name for name in names]
    candidates += [ROOT / base / 'Lang' / (lang + '.json') for lang in ('zh-cn', 'zh-tw', 'en', 'ja')]
    if base == PAY:
        vendor = ROOT / base / 'Vendor/polypay'
        candidates += [vendor / 'autoload.php', vendor / 'LICENSE']
        candidates += sorted((vendor / 'src').rglob('*.php'))
    files = []
    for item in candidates:
        if item.is_symlink():
            raise ValueError('Symlinks are not package inputs: ' + str(item))
        if not item.is_file():
            raise ValueError('Missing package source: ' + str(item))
        files.append(item.relative_to(ROOT))
    return files


def build(output_dir=None):
    """Emit six archives and an upload manifest with SHA-256 checksums."""
    version = re.search(r"'version' => '([0-9.]+)'", (ROOT / PAY / 'Config/Info.php').read_text())[1]
    companion = (ROOT / CALLBACK / 'Config/Info.php').read_text()
    if f"Plugin::VERSION => '{version}'" not in companion:
        raise ValueError('Payment and callback versions must match')
    output_dir = Path(output_dir) if output_dir else ROOT / 'dist'
    output_dir.mkdir(parents=True, exist_ok=True)
    manifest = {'version': version, 'packages': []}
    for mode in ('install', 'update'):
        for label, base, kind, key in (
            ('manual', None, None, None),
            ('store-payment', PAY, 1, 'PolyPay'),
            ('store-callback', CALLBACK, 0, 'PolyPayCallback'),
        ):
            entries = {}
            for directory in ([PAY, CALLBACK] if base is None else [base]):
                for path in source_files(directory):
                    name = path.as_posix() if base is None else path.relative_to(base).as_posix()
                    entries[name] = (ROOT / path).read_bytes()
                if mode == 'install':
                    name = (directory / 'Config/Config.php').as_posix() if base is None else 'Config/Config.php'
                    entries[name] = b"<?php\nreturn ['STATUS' => 0];\n" if directory == CALLBACK else b"<?php\nreturn [];\n"
            for name in ('LICENSE', 'README.md', 'README.zh.md', 'MARKETPLACE.md'):
                entries[name] = (ROOT / name).read_bytes()
            output = output_dir / f'acg-faka-polypay-{version}-{label}-{mode}.zip'
            with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
                for name, data in sorted(entries.items()):
                    archive.writestr(name, data)
            manifest['packages'].append({
                'file': output.name, 'mode': mode, 'type': kind, 'plugin_key': key,
                'sha256': hashlib.sha256(output.read_bytes()).hexdigest(),
            })
    (output_dir / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    return manifest


if __name__ == '__main__':
    for package in build()['packages']:
        print(package['file'])
