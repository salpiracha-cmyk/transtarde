<?php
declare(strict_types=1);

// One-time deployment bridge: copy the legacy recovery password hash from
// source code into the private data directory without loading the application.
// Loading auth_store.php here is unsafe because its dependencies and runtime
// state may differ while a release is being staged.

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: migrate_recovery_hash.php SOURCE TARGET\n");
    exit(64);
}

$sourcePath = $argv[1];
$targetPath = $argv[2];
$source = @file_get_contents($sourcePath);
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "Existing recovery configuration could not be read safely.\n");
    exit(1);
}

$tokens = token_get_all($source);
$foundName = false;
$legacyHash = '';
foreach ($tokens as $token) {
    if (!is_array($token)) {
        continue;
    }
    if (!$foundName && $token[0] === T_STRING && $token[1] === 'TT_ADMIN_RECOVERY_HASH') {
        $foundName = true;
        continue;
    }
    if ($foundName && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
        $literal = $token[1];
        $quote = $literal[0] ?? '';
        if (($quote === "'" || $quote === '"') && substr($literal, -1) === $quote) {
            $legacyHash = substr($literal, 1, -1);
            if ($quote === '"') {
                $legacyHash = stripcslashes($legacyHash);
            } else {
                $legacyHash = str_replace(["\\\\", "\\'"], ["\\", "'"], $legacyHash);
            }
        }
        break;
    }
}

$sourceHasRecoveryConstant = $foundName;
if (!$sourceHasRecoveryConstant) {
    fwrite(STDERR, "No legacy recovery constant is present; recovery remains disabled until owner rotation.\n");
    exit(3);
}

$info = $legacyHash !== '' ? password_get_info($legacyHash) : ['algoName' => 'unknown'];
if (($info['algoName'] ?? 'unknown') === 'unknown') {
    fwrite(STDERR, "Existing recovery hash could not be migrated safely.\n");
    exit(1);
}

$targetDir = dirname($targetPath);
if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "Private recovery directory could not be created.\n");
    exit(1);
}
@chmod($targetDir, 0700);

$temporaryPath = $targetPath . '.tmp-' . bin2hex(random_bytes(8));
if (file_put_contents($temporaryPath, $legacyHash . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Private recovery hash could not be written.\n");
    exit(1);
}
@chmod($temporaryPath, 0600);
if (!rename($temporaryPath, $targetPath)) {
    @unlink($temporaryPath);
    fwrite(STDERR, "Private recovery hash could not be installed atomically.\n");
    exit(1);
}
@chmod($targetPath, 0600);

fwrite(STDOUT, "Recovery hash migrated to private storage.\n");
