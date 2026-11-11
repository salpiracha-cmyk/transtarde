import importlib.util
import pathlib
import subprocess
import tempfile
from unittest.mock import patch

source=pathlib.Path(__file__).resolve().parents[1]/'.github/scripts/browser-download-setup.py'
spec=importlib.util.spec_from_file_location('download_setup',source)
setup=importlib.util.module_from_spec(spec);spec.loader.exec_module(setup)
with tempfile.TemporaryDirectory() as directory:
    root=pathlib.Path(directory);mirror=root/'apt/apt-mirrors.txt';mirror.parent.mkdir()
    original='http://azure.archive.ubuntu.com/ubuntu\nhttps://security.ubuntu.com/ubuntu\n'
    mirror.write_text(original);setup.configure(root,False);assert mirror.read_text()==original
    setup.configure(root,True);assert mirror.read_text()==original.replace('http://azure.archive.ubuntu.com/ubuntu','https://archive.ubuntu.com/ubuntu')
    assert 'Timeout "20"' in (root/'apt/apt.conf.d/99-transtrade-download-timeouts').read_text()
with patch.object(setup.subprocess,'run',side_effect=subprocess.TimeoutExpired('probe',8)):
    assert not setup.archive_available()
print('PASS unreachable replacement preserves runner mirrors; reachable replacement and bounded DNS probe')
