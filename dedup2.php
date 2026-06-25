<?php
$conn = new mysqli("localhost", "root", "", "db_kawasan");
if ($conn->connect_error) die("Koneksi gagal: " . $conn->connect_error);

$res = $conn->query("SELECT * FROM perusahaan");

$seen = [];
$duplicates = [];

while ($row = $res->fetch_assoc()) {
    // Kunci unik: jnskw, kdprov, kdkab, nmkw, nmprsh
    $key = $row['jnskw'] . "|" . $row['kdprov'] . "|" . $row['kdkab'] . "|" . $row['nmkw'] . "|" . $row['nmprsh'];
    
    if (isset($seen[$key])) {
        // Ini adalah duplikat
        $duplicates[] = $row['id'];
    } else {
        $seen[$key] = true;
    }
}

if (count($duplicates) > 0) {
    // Hapus duplikat
    $ids = implode(",", $duplicates);
    $conn->query("DELETE FROM perusahaan WHERE id IN ($ids)");
    echo "Berhasil menghapus " . count($duplicates) . " perusahaan ganda.\n";
} else {
    echo "Tidak ada perusahaan ganda yang ditemukan.\n";
}

$conn->close();
?>
