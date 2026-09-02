<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

// decode JSON body
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_input']);
    exit;
}
$sigDataUrl = $body['sig'] ?? null;
if (!$sigDataUrl) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_signature']);
    exit;
}

// Validate and decode data URL
if (!preg_match('#^data:image/(png|jpeg|jpg);base64,(.*)$#i', $sigDataUrl, $m)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_dataurl']);
    exit;
}
$sigBin = base64_decode($m[2]);
if ($sigBin === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_base64']);
    exit;
}

try {
    // DB connection
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // fetch user data untuk mendapatkan username
    $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = :uid");
    $userStmt->execute(['uid' => $_SESSION['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new Exception('User not found');
    
    $username = $user['username'];

    // fetch latest draft for this user
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) throw new Exception('Draft document not found');

    $draftPath = $doc['draft_path'] ?? '';
    if (!file_exists($draftPath)) throw new Exception('Draft file missing on server');

    $storage = rtrim($config['storage'] ?? __DIR__ . '/../storage', '/\\');
    @mkdir($storage . '/signed', 0755, true);
    $tmpDir = $storage . '/tmp';
    @mkdir($tmpDir, 0700, true);

    // save signature PNG temporary
    $tmpPng = $tmpDir . '/sig_' . $_SESSION['user_id'] . '_' . time() . '.png';
    file_put_contents($tmpPng, $sigBin);

    if (!class_exists('\setasign\Fpdi\Fpdi')) throw new Exception('FPDI not installed');
    if (!class_exists('ZipArchive')) throw new Exception('ZipArchive not available');

    // Fixed signature placement configuration (mm)
    $SIG_WIDTH_MM  = 40;
    $SIG_HEIGHT_MM = 30;
    $QR_WIDTH_MM   = 30;
    $RIGHT_MARGIN_MM = 0;
    $BOTTOM_MARGIN_MM = 39;
    $SPACE_BETWEEN_SIG_AND_QR = 1;

    // helper: apply signature to PDF
    function applySignatureFixed($draftPath, $outPath, $sigPng, $cfg) {
        $pdf = new \setasign\Fpdi\Fpdi();
        $pageCount = $pdf->setSourceFile($draftPath);
        for ($p = 1; $p <= $pageCount; $p++) {
            $tpl = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tpl);
            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            // place signature on the last page only
            if ($p == $pageCount) {
                $pageW = $size['width'];
                $pageH = $size['height'];

                $sigW = (float)$cfg['SIG_WIDTH_MM'];
                $sigH = (float)$cfg['SIG_HEIGHT_MM'];
                $qrW  = (float)$cfg['QR_WIDTH_MM'];
                $rightM = (float)$cfg['RIGHT_MARGIN_MM'];
                $bottomM = (float)$cfg['BOTTOM_MARGIN_MM'];
                $space = (float)$cfg['SPACE_BETWEEN_SIG_AND_QR'];

                $x = $pageW - $rightM - $qrW - $space - $sigW;
                if ($x < 10) $x = max(10, $pageW - $sigW - $rightM - $qrW - $space);
                $y = $pageH - $sigH - $bottomM;
                if ($y < 10) $y = max(10, $pageH - $sigH - $bottomM);

                // Insert signature image
                $pdf->Image($sigPng, $x, $y, $sigW, $sigH, 'PNG');
            }
        }
        $pdf->Output('F', $outPath);
    }

    $signedDir = $storage . '/signed';
    @mkdir($signedDir, 0755, true);
    
    // Generate signed PDF dengan nama berdasarkan username
    $signedPath = $signedDir . '/' . $username . '.pdf';

    // apply signature to draft
    $cfg = [
        'SIG_WIDTH_MM' => $SIG_WIDTH_MM,
        'SIG_HEIGHT_MM' => $SIG_HEIGHT_MM,
        'QR_WIDTH_MM' => $QR_WIDTH_MM,
        'RIGHT_MARGIN_MM' => $RIGHT_MARGIN_MM,
        'BOTTOM_MARGIN_MM' => $BOTTOM_MARGIN_MM,
        'SPACE_BETWEEN_SIG_AND_QR' => $SPACE_BETWEEN_SIG_AND_QR
    ];
    applySignatureFixed($draftPath, $signedPath, $tmpPng, $cfg);

    // update documents record: set signed path, set signed_at (TANPA ZIP)
    $upd = $pdo->prepare("UPDATE documents SET signed_path = :path, signed_at = NOW(), approval_status = 'pending' WHERE id = :id");
    $upd->execute(['path' => $signedPath, 'id' => $doc['id']]);

    // cleanup tmp png
    @unlink($tmpPng);

    $baseUrl = rtrim($config['base_url'] ?? '', '/\\');
    $downloadEndpoint = $baseUrl . '/public/download_signed.php?id=' . urlencode($doc['id']);

    echo json_encode(['success' => true, 'id' => (int)$doc['id'], 'file' => $downloadEndpoint, 'message' => 'Signed file created. Waiting admin approval to download.']);
    exit;

} catch (Exception $e) {
    error_log('save_signature error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}