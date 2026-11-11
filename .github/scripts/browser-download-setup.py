"""Use signed official Ubuntu packages with bounded network waits on QA runners."""
import pathlib
import subprocess
import sys


def archive_available():
    # Bound DNS as well as the HTTP connection; urlopen's timeout alone does not
    # bound the system resolver. Never replace the runner mirror blindly.
    probe = "import urllib.request; urllib.request.urlopen('https://archive.ubuntu.com/ubuntu/dists/noble/InRelease', timeout=5).close()"
    try:
        return subprocess.run([sys.executable, '-c', probe], timeout=8,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
    except subprocess.TimeoutExpired:
        return False


def configure(root, replace_azure):
    changed = 0
    for relative in ['apt/apt-mirrors.txt', 'apt/sources.list', 'apt/sources.list.d/ubuntu.sources']:
        path = root / relative
        if not path.is_file() or not replace_azure:
            continue
        before = path.read_text()
        after = before.replace('http://azure.archive.ubuntu.com/ubuntu', 'https://archive.ubuntu.com/ubuntu').replace('https://azure.archive.ubuntu.com/ubuntu', 'https://archive.ubuntu.com/ubuntu')
        if after != before:
            path.write_text(after)
            changed += 1
    config = root / 'apt/apt.conf.d/99-transtrade-download-timeouts'
    config.parent.mkdir(parents=True, exist_ok=True)
    config.write_text('Acquire::Retries "1";\nAcquire::http::Timeout "20";\nAcquire::https::Timeout "20";\n')
    print(f'Bounded APT downloads configured; {changed} mirror files changed. ' +
          ('Ubuntu archive probe passed.' if replace_azure else 'Ubuntu archive unavailable; preserving runner mirrors.') +
          ' Package-signature verification is unchanged.')


if __name__ == '__main__':
    configure(pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else pathlib.Path('/etc'), archive_available())
