"""Use signed official Ubuntu packages with bounded network waits on QA runners."""
import pathlib, sys
root = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else pathlib.Path('/etc')
changed = 0
for relative in ['apt/apt-mirrors.txt', 'apt/sources.list', 'apt/sources.list.d/ubuntu.sources']:
    path = root / relative
    if not path.is_file():
        continue
    before = path.read_text()
    after = before.replace('http://azure.archive.ubuntu.com/ubuntu', 'https://archive.ubuntu.com/ubuntu').replace('https://azure.archive.ubuntu.com/ubuntu', 'https://archive.ubuntu.com/ubuntu')
    if after != before:
        path.write_text(after)
        changed += 1
config = root / 'apt/apt.conf.d/99-transtrade-download-timeouts'
config.parent.mkdir(parents=True, exist_ok=True)
config.write_text('Acquire::Retries "1";\nAcquire::http::Timeout "20";\nAcquire::https::Timeout "20";\n')
print(f'Bounded APT downloads configured; {changed} Azure mirror files switched to the official Ubuntu archive. Package-signature verification is unchanged.')
