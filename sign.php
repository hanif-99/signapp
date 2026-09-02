<?php

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: admin.php');
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

// DB (PDO)
$pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Create two drafts with QR and insert documents row. QR PNG will be deleted after embedding.
function createSingleDraftWithQrAndCleanup(PDO $pdo, array $config, int $userId) {
    // fetch user
    $u = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $u->execute(['id' => $userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC) ?: ['name'=>'-', 'nipp'=>'-'];

    $storage = rtrim($config['storage'] ?? __DIR__ . '/../storage', '/\\');
    @mkdir($storage . '/drafts', 0755, true);
    @mkdir($storage . '/qrcodes', 0755, true);

    $token = bin2hex(random_bytes(16));
    $timestamp = time();
    $file = $storage . "/drafts/draft_user_{$userId}_{$timestamp}.pdf";
    $qrFile = $storage . "/qrcodes/qr_{$userId}_{$timestamp}.png";

    // build verification URL (ensure base_url is set in config)
    $baseUrl = rtrim($config['base_url'] ?? '', '/\\');
    $verifyUrl = ($baseUrl ? $baseUrl : '') . '/public/validate.php?token=' . $token;

    // generate QR (Endroid)
    $qrCreated = false;
    if (class_exists('\Endroid\QrCode\Builder\Builder') && class_exists('\Endroid\QrCode\Writer\PngWriter')) {
        try {
            $result = \Endroid\QrCode\Builder\Builder::create()
                ->writer(new \Endroid\QrCode\Writer\PngWriter())
                ->data($verifyUrl)
                ->size(220)
                ->margin(8)
                ->build();
            file_put_contents($qrFile, $result->getString());
            $qrCreated = true;
        } catch (Throwable $e) {
            error_log('QR generation failed: ' . $e->getMessage());
            $qrCreated = false;
        }
    }

    // path to the kepala signature block image (the tte/png you provided)
    $sigPihakKesatu = __DIR__ . '/img/tte.png';

    // Ambil data nomor dan hari dari database user
    $nomorDocument = $user['nomor'] ?? '-';
    $hariDocument = $user['hari'] ?? '-';

    // Create Draft A
	
	$pdf = new \FPDF('P','mm','A4');
    $pdf->SetMargins(30,6,0);
    $pdf->AddPage();

    // Helper to print label + wrapped value (fix overflow issues)
    // Perubahan: gunakan MultiCell untuk nilai sehingga bila terlalu panjang akan membungkus ke baris bawah
    function printLabelValue($pdf, $label, $value, $labelWidth = 45, $lineHeight = 6) {
        // Save start X for calculations
        $startX = $pdf->GetX();
        // Print label
        $pdf->Cell($labelWidth, $lineHeight, $label, 0, 0);
        // Colon
        $pdf->Cell(4, $lineHeight, ':', 0, 0);
        // Compute value area width based on page width and right margin
        $xValue = $startX + $labelWidth + 4;
        $pageW = $pdf->GetPageWidth();
        $rightMargin = isset($pdf->rMargin) ? $pdf->rMargin : 10;
        $maxWidth = $pageW - $xValue - $rightMargin;
        if ($maxWidth < 10) $maxWidth = 10;
        // Move to starting X of value
        $pdf->SetX($xValue);
        // Use MultiCell to allow wrapping; use slightly smaller height for lines
        $pdf->MultiCell($maxWidth, max(3, $lineHeight - 1), $value, 0, 'L');
    }

    $pdf->AddFont('Bookman', '', 'bookos.php');
    $pdf->AddFont('Bookman', 'B', 'bookosb.php');
    $pdf->Image("img/cianjur.png", 10, 5, 31, 31,"", "");
    $pdf->SetFont("Arial","",18);
    $pdf->Cell(0,7,"PEMERINTAH KABUPATEN CIANJUR",0,1,"C");
    $pdf->Cell(0,7,"BADAN KEPEGAWAIAN DAN PENGEMBANGAN",0,1,"C");
    $pdf->Cell(0,7,"SUMBER DAYA MANUSIA",0,1,"C");
    $pdf->SetFont("Arial","",11);
    $pdf->Cell(180,6,"Jalan Raya Bandung KM 2 No. 53 - Cianjur 43281 Telp/ Fax. (0253) 255295",0,1,"C");
    $leftText = "bkpsdm.cianjurkab.go.id e-mail : ";
    $emailText = "bkpsdm@cianjurkab.go.id";
    $pdf->SetFont("Arial","",11);
    $wLeft = $pdf->GetStringWidth($leftText);
    $wEmail = $pdf->GetStringWidth($emailText);
    $totalW = $wLeft + $wEmail;
    $pdf->SetX(68);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell($wLeft, 3, $leftText, 0, 0, 'L');
    $pdf->SetTextColor(0,0,255);
    $pdf->SetFont("Arial","",11);
    $pdf->Cell($wEmail, 3, $emailText, 0, 1, 'L');
    $pdf->SetFont("Arial","",11);
    $pdf->SetTextColor(0,0,0);

    $pdf->SetLineWidth(1);
    $pdf->Line(8, $pdf->GetY() + 5, 200, $pdf->GetY() + 5);    
    $pdf->SetMargins(8, 14, 5);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->SetFont("Bookman","B",9);
    $pdf->Ln(10);
    $pdf->Cell(0,7, 'PERJANJIAN KERJA', 0, 1, 'C');
    $pdf->Ln(1);
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Cell(0, 7, 'Nomor: ' . $nomorDocument, 0, 1, 'C');
    $pdf->Ln(1);
    $text_pembuka = "Pada hari ini " . $hariDocument . " yang bertandatangan di bawah ini:";
    $pdf->MultiCell(0, 6, $text_pembuka, 0, 'L');
    $pdf->Ln(1);
    $x_pos = $pdf->GetX();
    $y_pos = $pdf->GetY();
    $jarak_kolom = 30;
	$pdf->SetXY($x_pos, $y_pos);
	$pdf->Cell($jarak_kolom, 5, 'I.     Nama', 0, 0, 'C');
	$pdf->Cell(5, 5, ':', 0, 0, 'C');
	$pdf->MultiCell(0, 5, 'AKOS KOSWARA, S.STP', 0, 'L');
	$y_pos = $pdf->GetY();
	$pdf->SetXY($x_pos, $y_pos);
	$pdf->Cell($jarak_kolom, 5, '           Jabatan', 0, 0, 'C');
	$pdf->Cell(5, 5, ':', 0, 0, 'C');
	$pdf->MultiCell(0, 5, 'KEPALA BADAN KEPEGAWAIAN DAN PENGEMBANGAN SUMBERDAYA MANUSIA', 0, 'L');
	$y_pos = $pdf->GetY();
	$pdf->SetXY($x_pos, $y_pos);
	$pdf->Ln(1);
	$text_pihak1 = "Dalam hal ini bertindak untuk dan atas nama Bupati Cianjur berdasarkan Peraturan Bupati Cianjur Nomor 77 tahun 2020 tentang Perubahan Atas Peraturan Bupati Nomor 36 Tahun 2019 tentang Pendelegasian Sebagian Wewenang Bupati Dibidang Administrasi Kepegawaian Kepada Para Pejabat di Lingkungan Pemerintah Daerah Kabupaten Cianjur, untuk selanjutnya disebut Pihak Kesatu.";
	$pdf->Ln(1);
	$pdf->SetXY($x_pos + 13, $y_pos + 3);
	$pdf->MultiCell(0, 5, $text_pihak1, 0, 'L');
	$pdf->Ln(1);   
    $x_pos = $pdf->GetX();
    $y_pos = $pdf->GetY();
    $pdf->SetXY($x_pos + 6, $y_pos);
    $pdf->Cell(30, 6, 'II.    Nama',0,0);
    $pdf->SetX(54);
    $pdf->Cell(4, 6, ':', 0, 0, 'C');
    $pdf->Cell(0,6, $user['name'] ?? '-',0,1);    
    $x_pos = $pdf->GetX();
    $y_pos = $pdf->GetY();
    $pdf->SetXY($x_pos + 13, $y_pos);
    $pdf->Cell(30,6,'Nomor Induk PPPK',0,0);
    $pdf->SetX(54);
    $pdf->Cell(4,6,':',0,0);
    $pdf->Cell(0,6, $user['nipp'] ?? '-',0,1);    
    $x_pos = $pdf->GetX();
    $y_pos = $pdf->GetY();
    $pdf->SetXY($x_pos + 13, $y_pos);
    $pdf->Cell(30,6,'Tempat / Tgl Lahir',0,0);
    $pdf->SetX(54);
    $pdf->Cell(4,6,':',0,0);
    $pdf->Cell(0,6, ($user['birth_place'] ?? '-') . ' / ' . ($user['birth_date'] ?? '-'),0,1);
    $x_pos = $pdf->GetX();
    $y_pos = $pdf->GetY();
    $jarak_kolom = 35;
    $pdf->SetXY($x_pos + 13, $y_pos);
    $pdf->Cell(30,6,'Pendidikan',0,0);
    $pdf->SetX(54);
    $pdf->Cell(4,6,':',0,0);
    $pdf->Cell(0,6, $user['education'] ?? '-',0,1);
	$x_pos = $pdf->GetX();
	$y_pos = $pdf->GetY();	
	$pdf->SetXY($x_pos + 13, $y_pos);
	$y_start = $pdf->GetY();
	$pdf->Cell(30, 6, 'Alamat', 0, 0);
	$pdf->SetX(54);
	$pdf->Cell(4, 6, ':', 0, 0);
	$pdf->SetX(58);
	$pdf->MultiCell(0, 5, $user['alamat'] ?? '-', 0, 'L');
	$y_end = $pdf->GetY();
	$pdf->SetY($y_end);
    $pdf->Ln(1);
    $text_pihak2 = "             dalam hal ini bertindak untuk dan atas nama diri sendiri, untuk selanjutnya disebut Pihak Kedua.";
    $pdf->MultiCell(0, 5, $text_pihak2, 0, 'L');
    $pdf->Ln(2);
    $text_sepakat = "Pihak Kesatu dan Pihak Kedua sepakat untuk mengikatkan diri satu sama lain dalam Perjanjian Kerja dengan ketentuan sebagaimana dituangkan dalam Pasal-Pasal sebagai berikut:";
    $pdf->MultiCell(0, 5, $text_sepakat, 0, 'J');
    $pdf->Ln(3);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 1', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Masa Perjanjian Kerja, Jabatan, dan Unit Kerja', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_sepakat = "Pihak Kesatu menerima dan mempekerjakan Pihak Kedua sebagai Pegawai Pemerintah dengan Perjanjian Kerja dengan ketentuan sebagai berikut :";
    $pdf->MultiCell(0, 5, $text_sepakat, 0, 'J');
    $pdf->Ln(2);

    // --- GANTI: gunakan helper printLabelValue agar value panjang membungkus, mencegah overflow ---
    printLabelValue($pdf, 'a. Masa Perjanjian Kerja', $user['perjanjian'] ?? '-', 45, 6);
    printLabelValue($pdf, 'b. Jabatan', $user['jabatan'] ?? '-', 45, 6);
    printLabelValue($pdf, 'd. Unit Kerja', $user['unit_kerja'] ?? '-', 45, 6);
    // -------------------------------------------------------------

    $pdf->Ln(2); 
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 2', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Tugas Pekerjaan', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p2_1 = "1)  Pihak Kesatu membuat dan menetapkan tugas pekerjaan yang harus dilaksanakan oleh Pihak Kedua.";
    $pdf->MultiCell(0, 5, $text_p2_1, 0, 'L');
    $text_p2_2 = "2)  Pihak Kedua wajib melaksanakan tugas pekerjaan yang diberikan Pihak Kesatu dengan sebaik-baiknya dan rasa tanggung jawab.";
    $pdf->MultiCell(0, 5, $text_p2_2, 0, 'L');
    $pdf->Ln(2);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 3', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Target Kinerja', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p3_1 = "1)  Pihak Kesatu membuat dan menetapkan target kinerja bagi Pihak Kedua selama masa Perjanjian Kerja.";
    $pdf->MultiCell(0, 5, $text_p3_1, 0, 'L');
    $text_p3_2 = "2)  Pihak Kedua wajib memenuhi target kinerja yang telah ditetapkan oleh Pihak Kedua.";
    $pdf->MultiCell(0, 5, $text_p3_2, 0, 'L');
    $text_p3_3 = "3)  Pihak Kesatu dan Pihak Kedua menandatangani target perjanjian kinerja sesuai peraturan perundang-undangan.";
    $pdf->MultiCell(0, 5, $text_p3_3, 0, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 4', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Hari Kerja dan Jam Kerja', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(3);
    $text_p4 = "Pihak Kedua wajib bekerja sesuai dengan hari kerja dan jam kerja yang berlaku di instansi Pihak Kesatu.";
    $pdf->MultiCell(0, 5, $text_p4, 0, 'L');
    $pdf->Ln(1);
	$pdf->AddPage();
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 5', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Disiplin', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p5_1 = "1)  Pihak Kedua wajib mematuhi semua kewajiban dan larangan";
    $pdf->MultiCell(0, 5, $text_p5_1, 0, 'L');
    $text_p5_2 = "2)  Kewajiban bagi Pihak Kedua sebagaimana dimaksud pada ayat (1) meliputi :";
    $pdf->MultiCell(0, 5, $text_p5_2, 0, 'L');
    $text_a = "a. Setia dan taat pada Pancasila, Undang-Undang Dasar Negara Republik Indonesia Tahun 1945, Negara Kesatuan Republik Indonesia, dan pemerintah yang sah;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Menjaga persatuan dan kesatuan bangsa;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Melaksanakan kebijakan yang dirumuskan pejabat pemerintah yang berwenang;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "d. Menaati ketentuan peraturan perundang-undangan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "e. Melaksanakan tugas kedinasan dengan penuh pengabdian, kejujuran, kesadaran, dan tanggung jawab;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "f.  Menunjukkan integritas dan keteladanan dalam sikap, perilaku, ucapan, dan tindakan kepada setiap orang, baik di dalam maupun di luar kedinasan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "g. Menyimpan rahasia jabatan dan hanya dapat mengemukakan rahasia jabatan sesuai dengan ketentuan peraturan perundang-undangan; ";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "h.  Bersedia ditempatkan di seluruh wilayah Negara Kesatuan Republik Indonesia.";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p5_3 = "3)  Selain memenuhi kewajiban sebagaimana dimaksud dalam Pasal 5 ayat (2), Pihak Kedua wajib :";
    $pdf->MultiCell(0, 5, $text_p5_3, 0, 'L');
    $text_a = "a. Mengutamakan kepentingan negara daripada kepentingan pribadi dan/atau golongan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Mengisi presensi kehadiran sesuai dengan ketentuan yang berlaku;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Masuk kerja dan mentaati ketentuan jam kerja;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "d. Mencapai sasaran kerja pegawai yang di tetapkan berdasarkan ketentuan yang berlaku;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "e. Menerima pemutusan hubungan kerja bila tidak mencapai sasaran kerja;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "f.  Menaati peraturan kedinasan yang ditetapkan oleh pejabat yang berwenang.";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p5_4 = "4)  Larangan bagi Pihak Kedua sebagaimana dimaksud pada ayat (1) meliputi :";
    $pdf->MultiCell(0, 5, $text_p5_4, 0, 'L');
    $text_a = "a. Menyalahgunakan wewenang;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Menjadi perantara untuk mendapatkan keuntungan pribadi dan/atau orang lain dengan menggunakan kewenangan orang lain;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Tanpa izin Pemerintah menjadi pegawai atau bekerja untuk negara lain dan/atau lembaga atau organisasi internasional;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "d. Bekerja pada perusahaan asing, konsultan asing, atau lembaga swadaya masyarakat asing;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "e. Memiliki, menjual, membeli, menggadaikan, menyewakan, atau meminjamkan barang-barang baik bergerak atau tidak bergerak, dokumen atau surat berharga milik negara secara tidak sah;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "f. Tujuan untuk melakukan kegiatan bersama dengan atasan, teman sejawat, bawahan, atau orang lain di dalam maupun di luar lingkungan kerjanya dengan keuntungan pribadi, golongan, atau pihak lain yang secara langsung atau tidak langsung merugikan negara;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "g. Memberikan atau menyanggupi akan memberi sesuatu kepada siapa pun baik secara langsung atau tidak langsung dan dengan dalih apa pun untuk diangkat dalam jabatan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "h. Menerima hadiah atau suatu pemberian apa saja dari siapa pun juga yang berhubungan dengan jabatan dan/atau pekerjaannya;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "i. Bertindak sewenang-wenang terhadap bawahannya;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "j. Melakukan suatu tindakan atau tidak melakukan suatu tindakan yang dapat menghalangi atau mempersulit salah satu pihak yang dilayani sehingga mengakibatkan kerugian bagi yang dilayani;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "k. Menghalangi berjalannya tugas kedinasan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "l. Memberikan dukungan kepada calon Presiden/Wakil Presiden, Dewan Perwakilan Rakyat, Dewan Perwakilan Daerah, atau Dewan Perwakilan Rakyat Daerah dengan cara :";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "1. Ikut serta sebagai pelaksana kampanye;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "2. Menjadi peserta kampanye dengan menggunakan atribut partai atau atribut Aparatur Sipil Negara;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "2. Menjadi peserta kampanye dengan menggunakan atribut partai atau atribut Aparatur Sipil Negara;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "3. Sebagai peserta kampanye dengan mengerahkan Aparatur Sipil Negara lain; dan/atau";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "4. Sebagai peserta kampanye dengan menggunakan fasilitas Negara.";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "m. Memberikan dukungan kepada calon Presiden/Wakil Presiden dengan cara : ";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "1. Membuat keputusan dan/atau tindakan yang menguntungkan atau merugikan salah satu pasangan calon selama masa kampanye; dan/atau";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "2. Mengadakan kegiatan yang mengarah kepada keberpihakan terhadap pasangan calon yang menjadi peserta pemilu sebelum, selama, dan/atau sesudah masa kampanye meliputi pertemuan, ajakan, himbauan, seruan, atau pemberian barang kepada Aparatur Sipil Negara dalam lingkungan unit kerjanya, anggota keluarga, dan masyarakat.";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "n. Memberikan dukungan kepada calon anggota Dewan Perwakilan Daerah atau calon Kepala Daerah/Wakil Kepala Daerah dengan cara memberikan surat dukungan disertai foto kopi Kartu Tanda Penduduk atau Surat Keterangan Tanda Penduduk sesuai peraturan perundang-undangan; dan";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "o. Memberikan dukungan kepada calon Kepala Daerah/Wakil Kepala Daerah, dengan cara :";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "1. Terlibat dalam kegiatan kampanye untuk mendukung calon Kepala Daerah/Wakil Kepala Daerah;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "2. Menggunakan fasilitas yang terkait dengan jabatan dalam kegiatan kampanye;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "3. Membuat keputusan dan/atau tindakan yang menguntungkan atau merugikan salah satu pasangan calon selama masa kampanye; dan/atau";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "4. Mengadakan kegiatan yang mengarah kepada keberpihakan terhadap pasangan calon yang menjadi peserta pemilu sebelum, selama, dan/atau sesudah masa kampanye meliputi pertemuan, ajakan, himbauan, seruan, atau pemberian barang kepada Aparatur Sipil Negara dalam lingkungan kerjanya, anggota keluarga, dan masyarakat.";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p5_5 = "5)  Selain larangan sebagaimana dimaksud pada ayat (4), Pihak Kedua dilarang:";
    $pdf->MultiCell(0, 5, $text_p5_5, 0, 'L');
    $text_a = "a. Mengkonsumsi dan/atau mengedarkan narkotika dan obat-obatan terlarang;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Melakukan perkataan maupun perbuatan yang dapat merugikan pribadi dan negara;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Mengajukan permohonan pindah tugas selama masa berlaku perjanjian kerja;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "d. Mengikuti organisasi atau kegiatan yang mengarah pada tindakan radikalisme dan terorisme;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p5_5 = "6)  Pihak Kedua yang tidak mematuhi kewajiban dan/atau melanggar larangan sebagaimana dimaksud pada ayat (2), ayat (3), ayat (4), dan ayat (5) diberikan sanksi berupa :";
    $pdf->MultiCell(0, 5, $text_p5_5, 0, 'L');
    $text_a = "a. Sanksi ringan berupa :";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "1. Teguran lisan;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "2. Teguran tertulis; atau";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "3. Pernyataan tidak puas secara tertulis.";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Sanksi sedang berupa :";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "1. Penundaan kenaikan gaji berkala selama 1 (satu) tahun;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Sanksi berat berupa :";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "1. Pemutusan hubungan Perjanjian Kerja dengan hormat;";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "2. Pemutusan hubungan Perjanjian Kerja dengan hormat tidak atas permintaan sendiri; atau";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "3. Pemutusan hubungan Perjanjian Kerja tidak dengan hormat.";
    $pdf->SetX($pdf->GetX() + 10); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 6', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Gaji', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
	$text_p6_1 = "1) Pihak Kedua berhak mendapat gaji dan tunjangan sesuai dengan ketentuan peraturan perundang-undangan.";
	$pdf->MultiCell(0, 5, $text_p6_1, 0, 'L');    
	$gaji = isset($user['gaji']) && $user['gaji'] !== '' ? $user['gaji'] : '-';
	$gol = isset($user['gol']) && $user['gol'] !== '' ? $user['gol'] : '-';
	$pdf->Cell(0, 5, '2) Pihak Kedua berhak menerima gaji dalam golongan ' . $gol . ' sebesar Rp' . $gaji, 0, 1);
    $text_p6_3 = "3) Pembayaran gaji sebagaimana dimaksud pada ayat (1) dilakukan sejak Pihak Kedua melaksanakan tugas yang dibuktikan dengan surat pernyataan melaksanakan tugas dari pimpinan unit kerja penempatan Pihak Kedua.";
    $pdf->MultiCell(0, 5, $text_p6_3, 0, 'L');
    $text_p6_4 = "4) Apabila Pihak Kedua yang melaksanakan tugas pada tanggal hari kerja pertama bulan berkenaan, gaji sebagaimana dimaksud pada ayat (2) dibayarkan mulai bulan berkenaan.";
    $pdf->MultiCell(0, 5, $text_p6_4, 0, 'L');
    $text_p6_5 = "5) Apabila Pihak Kedua yang melaksanakan tugas pada tanggal hari kerja kedua dan seterusnya pada bulan berkenaan, gaji sebagaimana dimaksud pada ayat (2) dan ayat (3) dibayarkan mulai bulan berikutnya.";
    $pdf->MultiCell(0, 5, $text_p6_5, 0, 'L');
    $text_p6_6 = "6) Pembayaran gaji Pihak Kedua dilaksanakan sesuai dengan ketentuan peraturan perundang-undangan";
    $pdf->MultiCell(0, 5, $text_p6_6, 0, 'L');
    $text_p6_7 = "7) Penerimaan gaji sebagaimana dimaksud pada ayat (2) dan ayat (3), dapat dilakukan pemotongan pada saat pembayaran, sesuai ketentuan peraturan perundang-undangan.";
    $pdf->MultiCell(0, 5, $text_p6_7, 0, 'L');
    $pdf->Ln(2);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 7', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Cuti', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p7_1 = "1) Pihak Kedua berhak mendapat cuti tahunan, cuti sakit, cuti melahirkan dan cuti bersama selama masa perjanjian Kerja.";
    $pdf->MultiCell(0, 5, $text_p7_1, 0, 'L');
    $text_p7_2 = "2) Cuti sebagaimana dimaksud pada ayat (1) dilaksanakan sesuai dengan ketentuan peraturan perundang-undangan.";
    $pdf->MultiCell(0, 5, $text_p7_2, 0, 'L');
    $pdf->Ln(3);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->AddPage();
    $pdf->Cell(0, 3, 'Pasal 8', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Pengembangan Kompetensi', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p8_1 = "1) Pihak Kesatu memberikan pengembangan kompetensi kepada Pihak Kedua untuk mendukung pelaksanaan tugas  selama masa Perjanjian Kerja dengan memperhatikan hasil penilaian kinerja Pihak Kedua.";
    $pdf->MultiCell(0, 5, $text_p8_1, 0, 'L');
    $text_p8_2 = "2) Pelaksanaan pengembangan kompetensi sebagaimana dimaksud pada ayat (1) dilaksanakan sesuai dengan peraturan perundang-undangan.";
    $pdf->MultiCell(0, 5, $text_p8_2, 0, 'L');
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 9', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Penghargaan', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p9_1 = "1) Pihak Kesatu memberikan penghargaan kepada Pihak Kedua berupa : ";
    $pdf->MultiCell(0, 5, $text_p9_1, 0, 'L');
    $text_a = "a. Tanda kehormatan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Kesempatan prioritas untuk pengembangan kompetensi; dan/atau";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Kesempatan menghadiri acara resmi dan/atau acara kenegaraan.";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p9_2 = "2) Pemberian penghargaan kepada Pihak Kedua sebagaimana dimaksud pada ayat (1) huruf a dilaksanakan sesuai dengan ketentuan peraturan perundang-undangan.";
    $pdf->MultiCell(0, 5, $text_p9_2, 0, 'L');
    $text_p9_3 = "3) Pemberian penghargaan kepada Pihak Kedua sebagaimana dimaksud pada ayat (1) huruf b diberikan kepada Pihak Kedua apabila mempunyai penilaian kinerja yang paling baik.";
    $pdf->MultiCell(0, 5, $text_p9_3, 0, 'L');
    $text_p9_4 = "4) Pemberian penghargaan kepada Pihak Kedua sebagaimana dimaksud pada ayat (1) huruf c diberikan kepada Pihak Kedua setelah mendapatkan pertimbangan dari Tim Penilai Kinerja Pegawai Pemerintah dengan Perjanjian Kerja yang ada pada Pihak Kesatu.";
    $pdf->MultiCell(0, 5, $text_p9_4, 0, 'L');
    $pdf->Ln(2);	
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 10', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Perlindungan', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p10_1 = "1) Pihak Kesatu wajib memberikan perlindungan bagi Pihak Kedua berupa :";
    $pdf->MultiCell(0, 5, $text_p10_1, 0, 'L');
    $text_a = "a. Jaminan hari tua;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Jaminan kesehatan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Jaminan keecelakaan kerja;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "d. Jaminan kematian; dan";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
	$text_a = "e. Bantuan hukum.";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p10_2 = "2) Perlindungan sebagaimana dimaksud pada ayat (1) huruf a, huruf b, huruf c, dan huruf d dilakukan dengan mengikutsertakan Pihak Kedua dalam program sistem jaminan sosial nasional.";
    $pdf->MultiCell(0, 5, $text_p10_2, 0, 'L');
    $text_p10_3 = "3) Perlindungan sebagaimana dimaksud pada ayat (1) huruf e diberikan kepada Pihak Kedua dalam perkara yang dihadapi di pengadilan terkait pelaksanaan tugas.";
    $pdf->MultiCell(0, 5, $text_p10_3, 0, 'L');
    $text_p10_4 = "4) Pemberian perlindungan kepada Pihak Kedua sebagaimana dimaksud pada ayat (1) dilaksanakan sesuai dengan ketentuan peraturan perundang-undangan.";
    $pdf->MultiCell(0, 5, $text_p10_4, 0, 'L');
    $pdf->Ln(2);
    $pdf->AddPage();
    $pdf->SetMargins(6, 14, 3);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 11', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Pemutusan Hubungan Perjanjian Kerja', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_par = "Pihak Kesatu dan Pihak Kedua dapat melakukan pemutusan hubungan Perjanjian Kerja dengan ketentuan sebagai berikut :";
    $pdf->MultiCell(0, 5, $text_par, 0, 'L');
    $text_p11_1 = "1) Pemutusan hubungan Perjanjian Kerja dengan hormat dilakukan apabila : ";
    $pdf->MultiCell(0, 5, $text_p11_1, 0, 'L');
    $text_a = "a. Jangka waktu Perjanjian Kerja berakhir;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Pihak Kedua meninggal dunia;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Pihak Kedua mengajukan permohonan berhenti sebagai Pegawai Pemerintah dengan Perjanjian Kerja; atau";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "d. Terjadi perampingan organisasi atau kebijakan pemerintah yang mengakibatkan pengurangan Pegawai Pemerintah dengan Perjanjian Kerja pada Pihak Kesatu.";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p11_2 = "2) Pemutusan hubungan Perjanjian Kerja dengan hormat tidak atas permintaan sendiri dilakukan apabila:";
    $pdf->MultiCell(0, 5, $text_p11_2, 0, 'L');
    $text_a = "a. Pihak Kedua dihukum penjara berdasarkan putusan pengadilan yang telah memiliki kekuatan hukum tetap karena melakukan tindak pidana penjara paling singkat 2 (dua) tahun dan tindak pidana dilakukan dengan tidak berencana; ";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Pihak Kedua melakukan pelanggaran kewajiban dan/atau larangan sebagaimana yang dimaksud dalam Pasal 5; atau";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Pihak Kedua tidak dapat memenuhi target kinerja yang telah disepakati sesuai dengan Perjanjian Kerja.";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_p11_3 = "3) Pemutusan hubungan Perjanjian Kerja tidak dengan hormat dilakukan apabila;";
    $pdf->MultiCell(0, 5, $text_p11_3, 0, 'L');
    $text_a = "a. Melakukan penyelewengan terhadap Pancasila dan/atau Undang-Undang Dasar Negara Republik Indonesia Tahun 1945;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "b. Dihukum penjara atau kurungan berdasarkan putusan pengadilan yang telah memiliki kekuatan hukum tetap karena melakukan tindak pidana kejahatan jabatan atau tindak pidana yang ada hubungannya dengan jabatan;";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "c. Menjadi anggota dan/atau pengurus partai politik; atau";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $text_a = "d. Dihukum penjara berdasarkan putusan pengadilan yang telah memiliki kekuatan hukum tetap karena melakukan tindak pidana yang diancam pidana penjara paling singkat 2 (dua) tahun atau lebih dan tindak pidana tersebut dilakukan dengan berencana.";
    $pdf->SetX($pdf->GetX() + 5); 
    $pdf->MultiCell(0, 5, $text_a, 0, 'L');
    $pdf->Ln(1);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 12', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Penyelesaian Perselisihan', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_par = "Apabila dalam pelaksanaan Perjanjian Kerja ini terjadi perselisihan, maka Pihak Kesatu dan Pihak Kedua sepakat menyelesaikan perselisihan tersebut sesuai dengan ketentuan peraturan perundang-undangan.";
    $pdf->MultiCell(0, 5, $text_par, 0, 'L');        
    $pdf->Ln(3);
    $pdf->SetFont('Bookman', 'B', 9);
    $pdf->Cell(0, 3, 'Pasal 13', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Lain-lain', 0, 1, 'C');
    $pdf->SetFont('Bookman', '', 9);
    $pdf->Ln(2);
    $text_p13_1 = "1) Pihak Kedua bersedia melaksanakan seluruh ketentuan yang telah diatur dalam peraturan kedinasan dan peraturan lainnya yang berlaku di Pihak Kesatu.";
    $pdf->MultiCell(0, 5, $text_p13_1, 0, 'L');        
    $text_p13_2 = "2) Pihak Kedua wajib menyimpan dan menjaga kerahasiaan baik dokumen maupun informasi milik Pihak Kesatu sesuai dengan ketentuan peraturan perundang-undangan. ";
    $pdf->MultiCell(0, 5, $text_p13_2, 0, 'L');        
    $text_p13_3 = "3) Pihak Kesatu dapat memperpanjang masa Perjanjian Kerja yang dilaksanakan sesuai dengan peraturan perundang-undangan. ";
    $pdf->MultiCell(0, 5, $text_p13_3, 0, 'L');        
    $pdf->Ln(4);
    $text_par = "Demikian Perjanjian Kerja ini dibuat dalam rangkap 2 (dua) oleh Pihak Kesatu dan Pihak Kedua dalam keadaan sehat dan sadar seta tanpa pengaruh ataupun paksaan dari pihak mana pun, masing-masing bermeterai cukup dan mempunyai kekuatan hukum yang sama.";
    $pdf->MultiCell(0, 5, $text_par, 0, 'L');        
    $pdf->Ln(1);

    // --- Pihak Kesatu & Pihak Kedua area (draft A) ---
    $y_position = 217;
    $pdf->SetXY(39, $y_position);
    $pdf->Cell(0, 14, 'Pihak Kesatu', 0, 1, 'L');

    // insert kepala signature block image (if exists) to left
    if (file_exists($sigPihakKesatu)) {
        $imgX = 6;
        $imgY = $y_position + 15;
        $imgW = 90; // width in mm, adjust to taste
        // let FPDF keep aspect ratio if height not provided
        $pdf->Image($sigPihakKesatu, $imgX, $imgY, $imgW, 0, 'PNG');
        // move cursor below image so following content doesn't overlap
        $pdf->SetY($imgY + 36);
    } else {
        // fallback to original textual name if image missing
        $pdf->Ln(23); 
        $pdf->SetX(30);
        $pdf->Cell(0, 12, 'AKOS KOSWARA, S.STP', 0, 1, 'L');
    }

    $x_right_col = 110;
    $pdf->SetXY($x_right_col + 38, $y_position); 
    $pdf->Cell(0, 14, 'Pihak Kedua', 0, 1, 'L');

    // prepare name and font
    $name = trim($user['name'] ?? '-');
    $pdf->SetFont('Bookman','',9);

    // position of the "Pihak Kedua" label used above in the code
    $labelX = $x_right_col + 38;
    $labelText = 'Pihak Kedua';

    // calculate center X of the label
    $labelWidth = $pdf->GetStringWidth($labelText);
    $centerX = $labelX + ($labelWidth / 2);

    // measure name width
    $nameWidth = $pdf->GetStringWidth($name);

    // ensure name won't overflow the page — reduce font size if necessary
    $pageW = $pdf->GetPageWidth();
    $rightMargin = isset($pdf->rMargin) ? $pdf->rMargin : 10;
    $leftMargin = isset($pdf->lMargin) ? $pdf->lMargin : 10;

    $fs = 9; // starting font size
    while ($nameWidth > ($pageW - 2 * max($leftMargin, $rightMargin)) && $fs > 6) {
        $fs -= 0.5;
        $pdf->SetFont('Bookman','',$fs);
        $nameWidth = $pdf->GetStringWidth($name);
    }
    // ensure final font is set
    $pdf->SetFont('Bookman','',$fs);

    // compute final X to center the name under the label but keep within margins
    $finalX = $centerX - ($nameWidth / 2);
    if ($finalX < $leftMargin) $finalX = $leftMargin;
    if ($finalX + $nameWidth > $pageW - $rightMargin) $finalX = $pageW - $rightMargin - $nameWidth;

    $pdf->SetXY($finalX, $y_position + 33);
    $pdf->Cell($nameWidth, 20, $name, 0, 1, 'C');

    if ($qrCreated && file_exists($qrFile)) {
        $pageW = $pdf->GetPageWidth();
        $pageH = $pdf->GetPageHeight();
        $qrW = 28; $qrH = 28;
        $x = $pageW - $qrW - 2;
        $y = $pageH - $qrH - 2;
        $pdf->Image($qrFile, $x, $y, $qrW, $qrH, 'PNG');
        // Note: token text intentionally omitted (per request)
    }

    $pdf->Output('F', $file);

    // Insert into documents table
    $ins = $pdo->prepare("INSERT INTO documents (user_id, filename, draft_path, token, created_at, approval_status) VALUES (:uid, :fname, :dpath, :token, NOW(), 'pending')");
    $ins->execute([
		'uid' => $userId,
		'fname' => basename($file),
		'dpath' => $file,      // Single path
		'token' => $token
	]);

    // Remove QR PNG after embedding
    if ($qrCreated && file_exists($qrFile)) {
        @unlink($qrFile);
    }

    return (int)$pdo->lastInsertId();
}

// Load or create document
try {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE user_id = :uid ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        $docId = createSingleDraftWithQrAndCleanup($pdo, $config, (int)$_SESSION['user_id']);
        $stmt->execute(['uid' => $_SESSION['user_id']]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
    exit;
}

// If already signed -> redirect to complete.php
if (!empty($doc['signed_at'])) {
    header('Location: complete.php');
    exit;
}

$viewerUrl = 'file.php?id=' . intval($doc['id']) . '&type=draft';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>SignApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8f9fb; font-family:system-ui, -apple-system, "Segoe UI", Roboto, Arial; color:#222; }
    .container { max-width:1200px; }
    .page-title { margin:18px 0; display:flex; justify-content:space-between; align-items:center; }
    .card-frm { background:#fff; border-radius:10px; padding:18px; box-shadow:0 6px 18px rgba(16,24,40,0.05); }
    #pdf-canvas { width:100%; border-radius:6px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,0.04); display:block; }
    .signature-wrapper { border-radius:10px; padding:16px; background:#fff; border:1px solid #e9ecef; }
    .small-muted { color:#6c757d; }
    .btn-brand { background:#0d6efd; color:#fff; border:none; }
    .controls { display:flex; gap:8px; align-items:center; }
    .page-indicator { border-radius:6px; padding:6px 10px; background:#fff; border:1px solid #e9ecef; }
  </style>
</head>
<body>
  <div class="container py-3">
    <div class="page-title">
      <div>
        <h4 class="mb-0">DOKUMEN KONTRAK</h4>
          <div class="small-muted">PEGAWAI PEMERINTAH DENGAN PERJANJIAN KERJA (PPPK)</div>
      </div>
      <div><a href="logout.php" class="btn btn-outline-secondary btn-sm">Logout</a></div>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card-frm">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
              <h6 class="mb-0">Preview</h6>
            <div class="small-muted" style="color: red;">Silahkan baca dokumen terlebih dahulu sebelum tanda tangan.</div>
            </div>
            <div class="controls">
              <button id="prevPage" class="btn btn-sm btn-outline-secondary">Prev</button>
              <button id="nextPage" class="btn btn-sm btn-outline-secondary">Next</button>
              <div class="page-indicator ms-2">Page: <span id="pageNum">1</span> / <span id="pageCount">--</span></div>
            </div>
          </div>

          <div id="pdfWrapper" style="padding:18px; background:#fff; border:1px solid #f1f1f1;">
            <canvas id="pdf-canvas"></canvas>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card-frm signature-wrapper">
          <h6 class="mb-2">Ambil Tanda Tangan Anda</h6>
          <div id="sig-area" style="height:220px; border-radius:6px; border:1px solid #eef2f5;">
            <canvas id="sig-canvas" style="width:100%; height:100%"></canvas>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button id="clearBtn" class="btn btn-outline-secondary btn-sm">Hapus</button>
            <button id="saveBtn" class="btn btn-brand btn-sm">Simpan Dokumen</button>
          </div>

          <div id="msg" class="mt-3 small-muted">
            Tips : gunakan mouse atau layar sentuh. Setelah melakukan tanda tangan, klik simpan.
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.9.179/pdf.min.js"></script>
  <script src="vendor/js/signature_pad.umd.min.js"></script>

  <script>
  PDFJS = window['pdfjs-dist/build/pdf'] || window.pdfjsLib || null;
  PDFJS.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.9.179/pdf.worker.min.js';
  const pdfUrl = <?= json_encode($viewerUrl) ?>;
  const pdfCanvas = document.getElementById('pdf-canvas');
  const pdfCtx = pdfCanvas.getContext('2d');
  let pdfDoc = null, pageNum = 1, scaleBase = 1, zoom = 1.0, pageRendering=false, pagePending=null;

  function deviceRatio(){ return Math.max(window.devicePixelRatio||1,1); }

  async function renderPage(num){
    pageRendering = true;
    const page = await pdfDoc.getPage(num);
    const wrapper = document.getElementById('pdfWrapper');
    const available = wrapper.clientWidth - 36;
    const v1 = page.getViewport({scale:1});
    scaleBase = available / v1.width;
    const effective = scaleBase * zoom;
    const viewport = page.getViewport({scale: effective});
    const ratio = deviceRatio();
    pdfCanvas.width = Math.round(viewport.width * ratio);
    pdfCanvas.height = Math.round(viewport.height * ratio);
    pdfCanvas.style.width = Math.round(viewport.width) + 'px';
    pdfCanvas.style.height = Math.round(viewport.height) + 'px';
    pdfCtx.setTransform(ratio,0,0,ratio,0,0);
    await page.render({canvasContext: pdfCtx, viewport: viewport}).promise;
    document.getElementById('pageNum').textContent = num;
    document.getElementById('pageCount').textContent = pdfDoc.numPages;
    pageRendering = false;
    if (pagePending !== null) { const p = pagePending; pagePending = null; renderPage(p); }
  }
  function queueRender(num){ if (pageRendering) pagePending = num; else renderPage(num); }

  document.addEventListener('DOMContentLoaded', function(){
    if (pdfUrl) {
      PDFJS.getDocument(pdfUrl).promise.then(function(pdf_){
        pdfDoc = pdf_;
        document.getElementById('pageCount').textContent = pdfDoc.numPages;
        renderPage(pageNum);
      }).catch(function(e){
        console.error('PDF load error', e);
        document.getElementById('msg').textContent = 'Gagal memuat dokumen.';
      });
    }

    document.getElementById('prevPage').addEventListener('click', function(){ if (pageNum<=1) return; pageNum--; queueRender(pageNum); });
    document.getElementById('nextPage').addEventListener('click', function(){ if (!pdfDoc || pageNum>=pdfDoc.numPages) return; pageNum++; queueRender(pageNum); });

    // signature pad
    const sigCanvas = document.getElementById('sig-canvas');
    function resizeSig(c){ const ratio = deviceRatio(); const w = c.offsetWidth || c.clientWidth || 400; const h = c.offsetHeight || c.clientHeight || 220; c.width = Math.round(w*ratio); c.height = Math.round(h*ratio); c.getContext('2d').setTransform(ratio,0,0,ratio,0,0); c.getContext('2d').fillStyle='#fff'; c.getContext('2d').fillRect(0,0,w,h); }
    resizeSig(sigCanvas);
    const signaturePad = new SignaturePad(sigCanvas, { backgroundColor: 'rgba(0,0,0,0)', penColor: 'black' });

    document.getElementById('clearBtn').addEventListener('click', function(){ signaturePad.clear(); document.getElementById('msg').textContent = 'Tips: gunakan mouse atau layar sentuh.'; });

    document.getElementById('saveBtn').addEventListener('click', async function(){
      const btn = this;
      const msgEl = document.getElementById('msg');
      msgEl.textContent = '';
      if (!signaturePad || signaturePad.isEmpty()) { msgEl.innerHTML = '<span class="text-danger">Silakan gambar tanda tangan terlebih dahulu.</span>'; return; }

      const canvasRect = pdfCanvas.getBoundingClientRect();
      const defaultW = Math.max(90, canvasRect.width * 0.25);
      const defaultH = Math.max(34, defaultW * 0.35);
      const leftRatio = (canvasRect.width - defaultW - 24) / canvasRect.width;
      const topRatio = (canvasRect.height - defaultH - 24) / canvasRect.height;

      const payload = {
        sig: signaturePad.toDataURL('image/png'),
        pos: {
          leftRatio: Math.max(0, Math.min(1, leftRatio)),
          topRatio: Math.max(0, Math.min(1, topRatio)),
          widthRatio: Math.max(0.01, Math.min(1, defaultW / canvasRect.width)),
          heightRatio: Math.max(0.01, Math.min(1, defaultH / canvasRect.height)),
          page: pageNum
        }
      };

      btn.disabled = true;
      const oldText = btn.textContent;
      btn.textContent = 'Menyimpan...';

      try {
        const resp = await fetch('save_signature.php', {
          method: 'POST',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify(payload)
        });
        const j = await resp.json();
        if (!resp.ok || !j.success) {
          msgEl.innerHTML = '<span class="text-danger">Gagal menyimpan: ' + (j.error || resp.statusText) + '</span>';
          btn.disabled = false;
          btn.textContent = oldText;
          return;
        }

        msgEl.innerHTML = '<span class="text-success">Tanda tangan tersimpan.</span>';
        setTimeout(function(){ window.location.href = 'complete.php'; }, 700);
      } catch (err) {
        console.error(err);
        msgEl.innerHTML = '<span class="text-danger">Kesalahan jaringan saat menyimpan.</span>';
        btn.disabled = false; btn.textContent = oldText;
      }
    });

    window.addEventListener('resize', function(){ resizeSig(sigCanvas); });
  });
  </script>
</body>
</html>