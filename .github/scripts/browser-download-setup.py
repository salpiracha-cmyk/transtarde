"""Use an independent Ubuntu mirror without changing APT trust verification."""
import pathlib
import re
import sys

MIRROR = 'https://mirrors.mit.edu/ubuntu'


def configure(root):
    changed = 0
    paths = [root / 'apt/apt-mirrors.txt', root / 'apt/sources.list']
    source_dir = root / 'apt/sources.list.d'
    if source_dir.is_dir():
        paths += list(source_dir.glob('*.sources')) + list(source_dir.glob('*.list'))
    for path in paths:
        if not path.is_file():
            continue
        before = path.read_text()
        after = re.sub(r'https?://(?:[a-z0-9.-]+\.)?ubuntu\.com/ubuntu/?', MIRROR, before)
        if after != before:
            path.write_text(after)
            changed += 1
    config = root / 'apt/apt.conf.d/99-transtrade-download-timeouts'
    config.parent.mkdir(parents=True, exist_ok=True)
    config.write_text('Acquire::Retries "1";\nAcquire::http::Timeout "20";\nAcquire::https::Timeout "20";\n')
    print(f'Configured independent MIT mirror in {changed} APT files. Package-signature verification is unchanged.')


if __name__ == '__main__':
    configure(pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else pathlib.Path('/etc'))
