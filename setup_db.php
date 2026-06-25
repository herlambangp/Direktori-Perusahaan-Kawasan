<?php
// setup_db.php — Import dari master-sampel-stpu-tw2.csv

require_once __DIR__ . '/config.php';
$conn = db_connect_no_db();

// Buat database
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sql) === TRUE) {
    echo "Database " . DB_NAME . " siap.<br>\n";
} else {
    die("Error creating database: " . $conn->error);
}

$conn->select_db(DB_NAME);

// Drop dan buat ulang tabel dengan skema baru
$conn->query("DROP TABLE IF EXISTS perusahaan");

$sql = "CREATE TABLE perusahaan (
    id          INT(11) AUTO_INCREMENT PRIMARY KEY,
    kdprov      VARCHAR(10),
    nmprov      VARCHAR(100),
    kdkab       VARCHAR(10),
    nmkab       VARCHAR(100),
    kdprovkab   VARCHAR(10),
    jnskw       VARCHAR(10),
    nmkw        VARCHAR(255),
    nmprsh      VARCHAR(255),
    alamat      TEXT,
    INDEX idx_kawasan (jnskw, kdprovkab),
    INDEX idx_prov    (kdprov),
    INDEX idx_kab     (kdprovkab)
)";

if ($conn->query($sql) === TRUE) {
    echo "Tabel perusahaan dibuat ulang.<br>\n";
} else {
    die("Error creating table: " . $conn->error);
}

// --- Import CSV ---
$csvFile = 'master-sampel-stpu-tw2.csv';
if (!file_exists($csvFile)) {
    die("File $csvFile tidak ditemukan.<br>\n");
}

// Baca master kode provinsi dari tabel referensi yang sudah ada
// (dipakai untuk melengkapi nmprov dari kdprov)
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

// Baca header
$header = fgetcsv($file, 0, ';');
// Normalkan nama kolom (trim + lowercase)
$header = array_map(fn($h) => strtolower(trim($h)), $header);

// Mapping index
$idx = array_flip($header);

$stmt = $conn->prepare(
    "INSERT INTO perusahaan (kdprov, nmprov, kdkab, nmkab, kdprovkab, jnskw, nmkw, nmprsh, alamat)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$count   = 0;
$skipped = 0;

while (($data = fgetcsv($file, 0, ';')) !== FALSE) {
    if (count($data) < 5) { $skipped++; continue; }

    $kdprov       = trim($data[$idx['kdprov']] ?? '');
    $kdkab        = trim($data[$idx['kdkab']] ?? '');
    $nmprsh       = trim($data[$idx['nama_perusahaan']] ?? '');
    $alamat       = trim($data[$idx['alamat']] ?? '');
    $kdprovkab    = trim($data[$idx['kdprovkab']] ?? '');
    $lblkab       = trim($data[$idx['lblkab']] ?? '');
    $kawasan_raw  = trim($data[$idx['kawasan']] ?? '');
    $nama_kawasan = trim($data[$idx['nama_kawasan']] ?? '');

    if ($nmprsh === '') { $skipped++; continue; }

    // Mapping jnskw
    if ($kawasan_raw === 'Kawasan Industri') {
        $jnskw = 'KI';
    } elseif ($kawasan_raw === 'Kawasan Ekonomi Khusus') {
        $jnskw = 'KEK';
    } else {
        $jnskw = strtoupper(substr($kawasan_raw, 0, 10));
    }

    // Nama provinsi dari kode
    $nmprov = $provMap[$kdprov] ?? '';

    // Nama kab dari lblkab: "[08] ACEH BESAR" → "ACEH BESAR"
    $nmkab = '';
    if ($lblkab !== '') {
        // ambil setelah "] "
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
        "sssssssss",
        $kdprov, $nmprov, $kdkab, $nmkab, $kdprovkab,
        $jnskw, $nmkw, $nmprsh, $alamat
    );

    if ($stmt->execute()) {
        $count++;
    } else {
        echo "Warning baris " . ($count + $skipped + 2) . ": " . $stmt->error . "<br>\n";
        $skipped++;
    }
}

fclose($file);

echo "<br><strong>Selesai!</strong><br>\n";
echo "Berhasil diimpor: <strong>$count</strong> perusahaan.<br>\n";
echo "Dilewati/error: <strong>$skipped</strong> baris.<br>\n";

$conn->close();
?>
