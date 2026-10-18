<?php
declare(strict_types=1);

/** Read protected archive metadata without creating directories or changing jobs. */
function sa_job(string $root, string $id): array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('Invalid archive reference.');
    $dir = $root . '/' . $id;
    if (is_link($dir) || is_link($dir . '/job.json')) throw new RuntimeException('Invalid archive storage.');
    if (!is_file($dir . '/job.json')) throw new OutOfBoundsException('Shipment archive not found.');
    $job = json_decode((string)file_get_contents($dir . '/job.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job) || ($job['id'] ?? '') !== $id || ($job['type'] ?? '') !== 'shipment_archive'
        || !is_array($job['files'] ?? null)) throw new RuntimeException('Shipment archive metadata is damaged.');
    return $job;
}

/** Only the native merged shipment pack is exposed; never arbitrary job files. */
function sa_pack(array $job): ?array {
    $found = null;
    foreach ($job['files'] as $file) {
        if (!is_array($file) || ($file['folder'] ?? '') !== '' || ($file['name'] ?? '') !== 'Master Shipment Documents.pdf') continue;
        $stored = (string)($file['storedName'] ?? '');
        if ($found !== null || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}$/D', $stored)
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($file['sha256'] ?? '')) || (int)($file['size'] ?? 0) <= 0)
            throw new RuntimeException('Shipment archive file metadata is damaged.');
        $found = $file;
    }
    return $found;
}

function sa_documents(string $root): array {
    if (!is_dir($root)) return [];
    if (is_link($root)) throw new RuntimeException('Invalid archive storage.');
    $out = [];
    foreach (glob($root . '/*/job.json') ?: [] as $path) {
        $id = basename(dirname($path));
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) continue;
        $job = sa_job($root, $id);
        $file = sa_pack($job);
        if (!$file) continue;
        // Preserve revisions and identify these as archived packs, not current invoices.
        $date = (string)($job['createdAt'] ?? '');
        $display = 'date unavailable';
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $date)) {
            try {$display = (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('d-m-Y H:i').' PKT';}
            catch (Exception $e) { /* Keep the original timestamp and label its missing display. */ }
        }
        $out[] = ['id'=>'archive:'.$id, 'contractRef'=>(string)($job['contract'] ?? ''),
            'lotRef'=>(string)($job['lot'] ?? ''), 'category'=>'shipment-archive',
            'name'=>'Archived shipment document pack · '.$display, 'mime'=>'application/pdf',
            'size'=>(int)$file['size'], 'uploadedAt'=>$date, 'source'=>'Protected shipment archive',
            'downloadUrl'=>'/api/shipment_archive_file.php?id='.$id];
    }
    usort($out, static fn($a,$b)=>strcmp($b['uploadedAt'],$a['uploadedAt']) ?: strcmp($b['id'],$a['id']));
    return $out;
}
