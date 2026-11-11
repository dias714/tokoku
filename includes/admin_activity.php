<?php
function recordAdminActivity(
    PDO $pdo,
    string $activity,
    string $description,
    ?int $referenceId = null
): void {
    $actor = trim((string) ($_SESSION['name'] ?? 'Admin'));
    $stmt = $pdo->prepare(
        'INSERT INTO tbl_log (aktivitas, keterangan, waktu, id_referensi)
         VALUES (:aktivitas, :keterangan, NOW(), :id_referensi)'
    );
    $stmt->execute([
        'aktivitas' => $activity,
        'keterangan' => $actor . ' — ' . $description,
        'id_referensi' => $referenceId,
    ]);
}
