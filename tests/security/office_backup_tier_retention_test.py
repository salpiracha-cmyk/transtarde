import hashlib, json, tempfile, unittest, zipfile
from pathlib import Path
from unittest.mock import patch
from office_backup_agent_test import agent


def backup(root, folder, stamp, created, payload=b'encrypted-fixture'):
    target = root / folder
    target.mkdir(exist_ok=True)
    temp = target / 'fixture.tmp'
    with zipfile.ZipFile(temp, 'w') as out:
        out.writestr('Encrypted/000001.bin', payload)
        out.writestr('backup-manifest.json', json.dumps({'application':'Transtrade', 'type':'full_recovery', 'createdAt':created, 'encryption':{'algorithm':'AES-256-GCM'}, 'encryptedEntries':{'System_Recovery/private/auth.json':{'stored':'Encrypted/000001.bin'}}}))
    digest = agent.sha256_file(temp)
    path = target / ('TRANSTRADE_FULL_' + stamp + '_' + digest[:12] + '.zip')
    temp.replace(path)
    return path, digest


class TierRetentionTest(unittest.TestCase):
    def test_weekly_replaces_only_older_daily(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            old, _ = backup(root,'Daily','2026-10-09_1800','2026-10-09T13:00:00Z')
            current, _ = backup(root,'Daily','2026-10-19_1800','2026-10-19T13:00:00Z')
            newer, _ = backup(root,'Daily','2026-10-20_1800','2026-10-20T10:00:00Z')
            weekly, digest = backup(root,'Weekly','2026-W43_1800','2026-10-19T13:00:00Z')
            result = agent.apply_tier_retention(root, [('weekly',weekly,digest)])
            self.assertEqual(result['removed'],1)
            self.assertFalse(old.exists())
            self.assertTrue(current.exists() and newer.exists() and weekly.exists())
    def test_monthly_replaces_older_weekly_and_daily_preserves_monthlies(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            old_daily, _ = backup(root,'Daily','2026-09-29_1800','2026-09-29T13:00:00Z')
            old_weekly, _ = backup(root,'Weekly','2026-W39_1800','2026-09-21T13:00:00Z')
            prior_month, _ = backup(root,'Monthly','2026-09_1800','2026-09-01T13:00:00Z')
            monthly, digest = backup(root,'Monthly','2026-10_1800','2026-10-01T13:00:00Z')
            current, _ = backup(root,'Daily','2026-10-01_1800','2026-10-01T13:00:00Z')
            result = agent.apply_tier_retention(root, [('monthly',monthly,digest)])
            self.assertEqual(result['removed'],2)
            self.assertFalse(old_daily.exists() or old_weekly.exists())
            self.assertTrue(prior_month.exists() and monthly.exists() and current.exists())
    def test_unverified_missing_or_changed_survivors_never_prune(self):
        for reason in ('wrong_digest','missing','changed'):
            with self.subTest(reason=reason), tempfile.TemporaryDirectory() as d:
                root = Path(d)
                old, _ = backup(root,'Daily','2026-10-09_1800','2026-10-09T13:00:00Z')
                weekly, digest = backup(root,'Weekly','2026-W43_1800','2026-10-19T13:00:00Z')
                if reason == 'wrong_digest': digest = '0'*64
                if reason == 'missing': weekly.unlink()
                if reason == 'changed': weekly.write_bytes(b'broken')
                self.assertEqual(agent.apply_tier_retention(root,[('weekly',weekly,digest)])['removed'],0)
                self.assertTrue(old.exists())
    def test_unknown_or_unreadable_and_links_are_held(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            old, _=backup(root,'Daily','2026-10-09_1800','2026-10-09T13:00:00Z')
            old.write_bytes(b'broken')
            unknown=root/'Daily'/'unknown.zip';unknown.write_bytes(b'keep')
            weekly,digest=backup(root,'Weekly','2026-W43_1800','2026-10-19T13:00:00Z')
            result=agent.apply_tier_retention(root,[('weekly',weekly,digest)])
            self.assertEqual(result['removed'],0)
            self.assertEqual(result['held'],2)
            self.assertTrue(old.exists() and unknown.exists())
    def test_saved_full_checksum_bootstraps_existing_weekly(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            old,_=backup(root,'Daily','2026-10-09_1800','2026-10-09T13:00:00Z')
            weekly,digest=backup(root,'Weekly','2026-W43_1800','2026-10-19T13:00:00Z')
            result=agent.apply_tier_retention(root,verified_state={'sha256':digest})
            self.assertEqual(result['removed'],1)
            self.assertFalse(old.exists())
            newer,_=backup(root,'Daily','2026-10-20_1800','2026-10-20T10:00:00Z')
            self.assertEqual(agent.apply_tier_retention(root)['removed'],0)
            self.assertTrue(newer.exists())
    def test_linked_tier_directory_refuses_cleanup(self):
        with tempfile.TemporaryDirectory() as d, tempfile.TemporaryDirectory() as outside:
            root=Path(d)
            try:(root/'Daily').symlink_to(Path(outside),target_is_directory=True)
            except OSError:self.skipTest('Directory symlinks are unavailable on this runner.')
            with self.assertRaises(RuntimeError):agent.apply_tier_retention(root)

    def test_survivor_change_stops_cleanup(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            old,_=backup(root,'Daily','2026-10-09_1800','2026-10-09T13:00:00Z')
            weekly,digest=backup(root,'Weekly','2026-W43_1800','2026-10-19T13:00:00Z')
            actual=agent.sha256_file
            count=0
            def checksum(path):
                nonlocal count
                if path==weekly:
                    count+=1
                    if count>=3:return '0'*64
                return actual(path)
            with patch.object(agent,'sha256_file',side_effect=checksum):
                with self.assertRaises(RuntimeError):agent.apply_tier_retention(root,[('weekly',weekly,digest)])
            self.assertTrue(old.exists())

if __name__=='__main__':unittest.main()
