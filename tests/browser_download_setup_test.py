import importlib.util
import pathlib
import tempfile

source = pathlib.Path(__file__).resolve().parents[1]/'.github/scripts/browser-download-setup.py'
spec = importlib.util.spec_from_file_location('download_setup', source)
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)
with tempfile.TemporaryDirectory() as directory:
    root = pathlib.Path(directory)
    mirror = root/'apt/apt-mirrors.txt'
    mirror.parent.mkdir()
    mirror.write_text('http://azure.archive.ubuntu.com/ubuntu\nhttps://security.ubuntu.com/ubuntu\n')
    sources = root/'apt/sources.list.d/ubuntu.sources'
    sources.parent.mkdir()
    sources.write_text('URIs: https://archive.ubuntu.com/ubuntu/\nSuites: noble noble-updates noble-security\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n')
    third_party = root/'apt/sources.list.d/vendor.list'
    third_party.write_text('deb https://packages.microsoft.com/repos/code stable main\n')
    setup.configure(root)
    assert mirror.read_text() == (setup.MIRROR + '\n') * 2
    assert 'ubuntu.com' not in sources.read_text()
    assert 'Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg' in sources.read_text()
    assert 'Suites: noble noble-updates noble-security' in sources.read_text()
    assert third_party.read_text() == 'deb https://packages.microsoft.com/repos/code stable main\n'
    assert 'Timeout "20"' in (root/'apt/apt.conf.d/99-transtrade-download-timeouts').read_text()
print('PASS independent mirror replaces Ubuntu hosts; signing keys, suites and third-party sources preserved')
