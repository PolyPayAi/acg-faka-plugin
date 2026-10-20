"""Exercise store extraction, update safety and config sanitization in isolation."""
import importlib.util
import json
import tempfile
from pathlib import Path
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('package', ROOT / 'scripts/package.py')
package = importlib.util.module_from_spec(spec)
spec.loader.exec_module(package)

with tempfile.TemporaryDirectory() as work:
    work = Path(work)
    # Build from a temporary source tree containing a deliberately poisoned config.
    import shutil
    source = work / 'source'
    for path in ('app', 'skills'):
        shutil.copytree(ROOT / path, source / path)
    for name in ('README.md', 'README.zh.md', 'LICENSE', 'MARKETPLACE.md'):
        shutil.copyfile(ROOT / name, source / name)
    secret = 'DO_NOT_SHIP_FIXTURE_KEY'
    for base in (package.PAY, package.CALLBACK):
        (source / base / 'Config/Config.php').write_text("<?php return ['api_key'=>'" + secret + "'];")
        (source / base / 'runtime.log').write_text(secret)
    package.ROOT = source
    manifest = package.build(work / 'dist')
    assert len(manifest['packages']) == 6
    host = work / 'host'
    destinations = {1: host / package.PAY, 0: host / package.CALLBACK}
    for item in manifest['packages']:
        with ZipFile(work / 'dist' / item['file']) as archive:
            assert all(secret.encode() not in archive.read(n) for n in archive.namelist())
            assert 'skills/polypay-acg-faka/SKILL.md' in archive.namelist()
            assert all(not n.startswith('/') and '..' not in Path(n).parts for n in archive.namelist())
            if item['type'] is None:
                assert 'app/Pay/PolyPay/Impl/Pay.php' in archive.namelist()
                assert 'app/Plugin/PolyPayCallback/Controller/Notification.php' in archive.namelist()
                continue
            assert 'Config/Info.php' in archive.namelist()
            for lang in ('zh-cn', 'zh-tw', 'en', 'ja'):
                name = 'Lang/' + lang + '.json'
                assert name in archive.namelist()
                pack = json.loads(archive.read(name))
                assert pack and all(isinstance(value, str) and value.strip() for value in pack.values())
                if item['type'] == 1:
                    assert {'API 密钥', '商户编号', '每 1 CNY 折合 USD', '私有存储目录（绝对路径）', '强制 HTTPS'} <= set(pack)
            assert not any(n.startswith('app/') for n in archive.namelist())
            target = destinations[item['type']]
            if item['mode'] == 'install':
                archive.extractall(target)
                assert (target / 'Config/Config.php').is_file()
                (target / 'Config/Config.php').write_text(secret)
            else:
                assert 'Config/Config.php' not in archive.namelist()
                archive.extractall(target)
                assert (target / 'Config/Config.php').read_text() == secret
    assert (destinations[1] / 'Impl/Pay.php').is_file()
    assert (destinations[0] / 'Controller/Notification.php').is_file()
    assert json.loads((work / 'dist/manifest.json').read_text()) == manifest
print('PASS: store roots, both modules, config sanitization, update preservation, no private state')
