"""Exercise the public sign-out route against PHP's local web server."""
from pathlib import Path
import socket
import subprocess
import time
import unittest
from urllib import error, request


ROOT = Path(__file__).resolve().parents[2]


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, *_args):
        return None


class LogoutHttpTest(unittest.TestCase):
    def test_anonymous_sign_out_clears_cookie_and_redirects(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(ROOT)],
                                  stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        try:
            opener = request.build_opener(NoRedirect())
            for _ in range(50):
                try:
                    response = opener.open(f'http://127.0.0.1:{port}/logout.php?reason=inactive', timeout=1)
                except error.HTTPError as exc:
                    response = exc
                except error.URLError:
                    time.sleep(.1)
                    continue
                with response:
                    self.assertEqual(response.status, 303)
                    self.assertEqual(response.headers['Location'], '/login.php?expired=inactive')
                    self.assertIn('TRANSTRADE_SESSION=', response.headers.get('Set-Cookie', ''))
                    self.assertIn('no-store', response.headers.get('Cache-Control', ''))
                    return
            self.fail('PHP sign-out server did not respond.')
        finally:
            server.terminate()
            try:
                server.communicate(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.communicate()


if __name__ == '__main__':
    unittest.main()
