<?php
// setup_db.php — Import dari master-sampel-stpu-tw2-290626.csv
// Kompatibel dengan PHP 7.2+ dan shared hosting

// Tingkatkan batas waktu & memori untuk import data besar
@ini_set('max_execution_time', 300);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/config.php';

// Coba koneksi tanpa DB dulu (untuk CREATE DATABASE)
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    die('Koneksi gagal: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// Buat database — skip jika tidak punya privilege (hosting)
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
$result = $conn->query($sql);
if ($result === TRUE) {
    echo "Database " . DB_NAME . " siap.<br>\n";
} else {
    // Di hosting, database sudah ada — lanjut saja
    echo "Info: Database sudah ada atau tidak perlu dibuat (privilege terbatas). Melanjutkan...<br>\n";
}

// Sambungkan ke DB yang sudah ada
if (!$conn->select_db(DB_NAME)) {
    die("Gagal terhubung ke database '" . DB_NAME . "': " . $conn->error . "<br>\nPastikan database sudah dibuat di cPanel/phpMyAdmin.");
}

echo "Terhubung ke database: " . DB_NAME . "<br>\n";

// Drop dan buat ulang tabel dengan skema baru
$conn->query("DROP TABLE IF EXISTS perusahaan");

$sql = "CREATE TABLE perusahaan (
    id              INT(11) AUTO_INCREMENT PRIMARY KEY,
    kdprov          VARCHAR(10),
    nmprov          VARCHAR(100),
    kdkab           VARCHAR(10),
    nmkab           VARCHAR(100),
    kdprovkab       VARCHAR(10),
    jnskw           VARCHAR(10),
    nmkw            VARCHAR(255),
    nmprsh          VARCHAR(255),
    alamat          TEXT,
    idstpu          VARCHAR(50),
    nmkorespondensi VARCHAR(255),
    nohp            VARCHAR(50),
    email           VARCHAR(255),
    jarusaha        VARCHAR(255),
    kbli            VARCHAR(255),
    INDEX idx_kawasan (jnskw, kdprovkab),
    INDEX idx_prov    (kdprov),
    INDEX idx_kab     (kdprovkab),
    INDEX idx_idstpu  (idstpu)
)";

if ($conn->query($sql) === TRUE) {
    echo "Tabel perusahaan dibuat ulang.<br>\n";
} else {
    die("Error creating table: " . $conn->error);
}

// --- Import CSV ---
$csvFile = __DIR__ . '/master-sampel-stpu-tw2-290626.csv';
if (!file_exists($csvFile)) {
    die("File CSV tidak ditemukan di: $csvFile<br>\nPastikan file sudah di-upload ke folder yang sama dengan setup_db.php.");
}

// Baca master kode provinsi dari tabel referensi yang sudah ada
$provMap = [
    '11' => 'ACEH',
    '12' => 'SUMATERA UTARA',
    '13' => 'SUMATERA BARAT',
    '14' => 'RIAU',
    '15' => 'JAMBI',
    '16' => 'SUMATERA SELATAN',
    '17' => 'BENGKULU',
    '18' => 'LAMPUNG',
    '19' => 'KEPULAUAN BANGKA BELITUNG',
    '21' => 'KEPULAUAN RIAU',
    '31' => 'DKI JAKARTA',
    '32' => 'JAWA BARAT',
    '33' => 'JAWA TENGAH',
    '34' => 'DI YOGYAKARTA',
    '35' => 'JAWA TIMUR',
    '36' => 'BANTEN',
    '51' => 'BALI',
    '52' => 'NUSA TENGGARA BARAT',
    '53' => 'NUSA TENGGARA TIMUR',
    '61' => 'KALIMANTAN BARAT',
    '62' => 'KALIMANTAN TENGAH',
    '63' => 'KALIMANTAN SELATAN',
    '64' => 'KALIMANTAN TIMUR',
    '65' => 'KALIMANTAN UTARA',
    '71' => 'SULAWESI UTARA',
    '72' => 'SULAWESI TENGAH',
    '73' => 'SULAWESI SELATAN',
    '74' => 'SULAWESI TENGGARA',
    '75' => 'GORONTALO',
    '76' => 'SULAWESI BARAT',
    '81' => 'MALUKU',
    '82' => 'MALUKU UTARA',
    '91' => 'PAPUA BARAT',
    '92' => 'PAPUA',
    '93' => 'PAPUA SELATAN',
    '94' => 'PAPUA TENGAH',
    '95' => 'PAPUA PEGUNUNGAN',
    '96' => 'PAPUA BARAT DAYA',
];

$file = fopen($csvFile, 'r');
if ($file === FALSE) {
    die("Gagal membuka file CSV.<br>\n");
}

// Deteksi BOM UTF-8
$bom = fread($file, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($file);
}

// Baris pertama = label deskriptif, lewati
fgetcsv($file, 0, ';');

// Baris kedua = nama kolom teknis
$header = fgetcsv($file, 0, ';');
// Normalkan nama kolom (trim + lowercase) — kompatibel PHP 7.2+
$header = array_map(function($h) { return strtolower(trim($h)); }, $header);

// Mapping index
$idx = array_flip($header);

$stmt = $conn->prepare(
    "INSERT INTO perusahaan
        (kdprov, nmprov, kdkab, nmkab, kdprovkab, jnskw, nmkw, nmprsh, alamat,
         idstpu, nmkorespondensi, nohp, email, jarusaha, kbli)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    die("Gagal menyiapkan statement: " . $conn->error);
}

$count   = 0;
$skipped = 0;

while (($data = fgetcsv($file, 0, ';')) !== FALSE) {
    if (count($data) < 5) { $skipped++; continue; }

    $kdprov          = trim($data[$idx['prov']]               ?? '');
    $kdkab           = trim($data[$idx['kab']]                ?? '');
    $nmprsh          = trim($data[$idx['b1r101']]             ?? '');
    $alamat          = trim($data[$idx['b1r102']]             ?? '');
    $kdprovkab       = trim($data[$idx['b1r103_value']]       ?? '');
    $lblkab          = trim($data[$idx['b1r103_label']]       ?? '');
    $kawasan_raw     = trim($data[$idx['b1r108_label']]       ?? '');
    $nama_kawasan    = trim($data[$idx['b1r109_label']]       ?? '');

    // Fallback: jika b1r108_label kosong, pakai kolom jenis_kawasan
    if ($kawasan_raw === '') {
        $kawasan_raw = trim($data[$idx['jenis_kawasan']] ?? '');
    }
    // Fallback nama kawasan: jika b1r109_label kosong, pakai b1r109_value
    if ($nama_kawasan === '' && isset($idx['b1r109_value'])) {
        $nama_kawasan = trim($data[$idx['b1r109_value']] ?? '');
    }
    $idstpu          = trim($data[$idx['idstpu']]             ?? '');
    $nmkorespondensi = trim($data[$idx['b1r105']]             ?? '');
    $nohp            = trim($data[$idx['b1r104']]             ?? '');
    $email           = trim($data[$idx['b1r106']]             ?? '');
    $jarusaha        = trim($data[$idx['b2r202_label']]       ?? '');
    $kbli            = trim($data[$idx['b2r204_kbli_label']]  ?? '');

    if ($nmprsh === '') { $skipped++; continue; }

    // Mapping jnskw — kompatibel PHP 7.2+ (tanpa str_contains)
    $kawasan_lower = strtolower($kawasan_raw);
    if (strpos($kawasan_lower, 'industri') !== FALSE) {
        $jnskw = 'KI';
    } elseif (strpos($kawasan_lower, 'ekonomi khusus') !== FALSE || strpos($kawasan_lower, 'kek') !== FALSE) {
        $jnskw = 'KEK';
    } else {
        $jnskw = strtoupper(substr($kawasan_raw, 0, 10));
    }

    // Nama provinsi dari kode
    $nmprov = isset($provMap[$kdprov]) ? $provMap[$kdprov] : '';

    // Nama kab dari lblkab: "[08] ACEH BESAR" → "ACEH BESAR"
    $nmkab = '';
    if ($lblkab !== '') {
        $pos = strpos($lblkab, '] ');
        $nmkab = ($pos !== FALSE) ? substr($lblkab, $pos + 2) : $lblkab;
        $nmkab = strtoupper(trim($nmkab));
    }

    // Jika kdprovkab kosong → grup "Lainnya"
    if ($kdprovkab === '') {
        $kdprovkab = null;
        if ($nmkab === '') $nmkab = 'Lainnya';
    }

    // nmkw: pakai nama_kawasan; jika kosong → "Lainnya"
    $nmkw = ($nama_kawasan !== '') ? $nama_kawasan : 'Lainnya';

    $stmt->bind_param(
        "sssssssssssssss",
        $kdprov, $nmprov, $kdkab, $nmkab, $kdprovkab,
        $jnskw, $nmkw, $nmprsh, $alamat,
        $idstpu, $nmkorespondensi, $nohp, $email, $jarusaha, $kbli
    );

    if ($stmt->execute()) {
        $count++;
    } else {
        echo "Warning baris " . ($count + $skipped + 3) . ": " . $stmt->error . "<br>\n";
        $skipped++;
    }
}

fclose($file);

echo "<br><strong>Selesai!</strong><br>\n";
echo "Berhasil diimpor: <strong>$count</strong> perusahaan.<br>\n";
echo "Dilewati/error: <strong>$skipped</strong> baris.<br>\n";

$conn->close();
?>
