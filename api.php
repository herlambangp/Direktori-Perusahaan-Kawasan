<?php
// api.php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config.php';
$conn = db_connect();

// Helper: cek apakah request ini dari admin yang sudah login
function require_admin(): void {
    if (empty($_SESSION['is_admin'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Silakan login sebagai admin terlebih dahulu.']);
        exit;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Cek dari JSON body jika kosong
$inputJSON = file_get_contents('php://input');
$input = [];
if (!empty($inputJSON)) {
    $decoded = json_decode($inputJSON, TRUE);
    if (is_array($decoded)) {
        $input = $decoded;
        if (isset($input['action'])) $action = $input['action'];
    }
}

// =============================================================
// ACTION: get_auth_status — kembalikan status sesi login
// =============================================================
if ($action === 'get_auth_status') {
    echo json_encode([
        'status'   => 'success',
        'is_admin' => !empty($_SESSION['is_admin']),
        'username' => $_SESSION['username'] ?? null,
        'nama'     => $_SESSION['nama']     ?? null,
    ]);
    exit;

// =============================================================
// ACTION: get_summary
// =============================================================
} elseif ($action === 'get_summary') {
    $resKEK = $conn->query("SELECT COUNT(DISTINCT nmkw) as kawasan_count, COUNT(*) as perusahaan_count FROM perusahaan WHERE jnskw = 'KEK'");
    $rowKEK = $resKEK->fetch_assoc();

    $resKI = $conn->query("SELECT COUNT(DISTINCT nmkw) as kawasan_count, COUNT(*) as perusahaan_count FROM perusahaan WHERE jnskw = 'KI'");
    $rowKI = $resKI->fetch_assoc();

    echo json_encode([
        "status" => "success",
        "data"   => [
            "KEK" => ["kawasan" => (int)$rowKEK['kawasan_count'], "perusahaan" => (int)$rowKEK['perusahaan_count']],
            "KI"  => ["kawasan" => (int)$rowKI['kawasan_count'],  "perusahaan" => (int)$rowKI['perusahaan_count']]
        ]
    ]);
    exit;

// =============================================================
// ACTION: get_map_data
// Mengembalikan data per kabupaten/kota untuk overlay peta
// =============================================================
} elseif ($action === 'get_map_data') {
    $jnskw = $_GET['jnskw'] ?? 'KI';

    $stmt = $conn->prepare(
        "SELECT kdprovkab, nmkab, nmkw, COUNT(*) as jml
         FROM perusahaan
         WHERE jnskw = ? AND kdprovkab IS NOT NULL AND kdprovkab != ''
         GROUP BY kdprovkab, nmkab, nmkw
         ORDER BY kdprovkab, nmkw"
    );
    $stmt->bind_param("s", $jnskw);
    $stmt->execute();
    $result = $stmt->get_result();

    // Agregasi per kabupaten: { kdprovkab => { nmkab, kawasanList[], total } }
    $kabMap = [];
    while ($row = $result->fetch_assoc()) {
        $kd = $row['kdprovkab'];
        if (!isset($kabMap[$kd])) {
            $kabMap[$kd] = [
                'kdprovkab' => $kd,
                'nmkab'     => $row['nmkab'],
                'kawasan'   => [],
                'total'     => 0
            ];
        }
        $kabMap[$kd]['kawasan'][] = [
            'nama' => $row['nmkw'],
            'jml'  => (int)$row['jml']
        ];
        $kabMap[$kd]['total'] += (int)$row['jml'];
    }

    echo json_encode([
        "status" => "success",
        "data"   => array_values($kabMap)
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;

// =============================================================
// ACTION: get_tree
// Pohon direktori: Prov → Kab → Kawasan → Perusahaan
// =============================================================
} elseif ($action === 'get_tree') {
    $jnskw = $_GET['jnskw'] ?? 'KEK';

    $stmt = $conn->prepare(
        "SELECT id, kdprov, nmprov, kdkab, nmkab, kdprovkab, nmkw, nmprsh, alamat,
                idstpu, nmkorespondensi, nohp, email, jarusaha, kbli
         FROM perusahaan
         WHERE jnskw = ?
         ORDER BY nmprov, nmkab, nmkw, nmprsh"
    );
    $stmt->bind_param("s", $jnskw);
    $stmt->execute();
    $result = $stmt->get_result();

    $tree    = [];   // prov → kab → kawasan → perusahaan
    $lainnya = [];   // kawasan → perusahaan (tanpa kdprovkab)

    while ($row = $result->fetch_assoc()) {
        $nmkw    = ($row['nmkw'] && trim($row['nmkw']) !== '') ? $row['nmkw'] : 'Lainnya';
        $company = [
            "id"              => (int)$row['id'],
            "name"            => $row['nmprsh'],
            "alamat"          => $row['alamat']          ?? '',
            "idstpu"          => $row['idstpu']          ?? '',
            "nmkorespondensi" => $row['nmkorespondensi'] ?? '',
            "nohp"            => $row['nohp']            ?? '',
            "email"           => $row['email']           ?? '',
            "jarusaha"        => $row['jarusaha']        ?? '',
            "kbli"            => $row['kbli']            ?? '',
            "type"            => "perusahaan"
        ];

        // ── Baris TANPA kdprovkab → node Lainnya tunggal ──────────────
        if (empty($row['kdprovkab'])) {
            $kawId = md5('LAINNYA_' . $nmkw);
            if (!isset($lainnya[$kawId])) {
                $lainnya[$kawId] = [
                    "id"       => $kawId,
                    "name"     => $nmkw,
                    "type"     => "kawasan",
                    "children" => []
                ];
            }
            $lainnya[$kawId]["children"][] = $company;
            continue;
        }

        // ── Baris DENGAN kdprovkab → pohon Prov > Kab > Kawasan ───────
        $kdprov    = $row['kdprov'] ?: '00';
        $nmprov    = $row['nmprov'] ?: 'Tidak Diketahui';
        $kdkab     = $row['kdkab']  ?: '00';
        $nmkab     = $row['nmkab']  ?: 'Lainnya';
        $kdprovkab = $row['kdprovkab'];
        $provKey   = $kdprov;
        $kabKey    = $kdprov . '_' . $kdkab . '_' . strtolower($nmkab);

        if (!isset($tree[$provKey])) {
            $tree[$provKey] = [
                "id"       => $kdprov,
                "name"     => strtoupper($nmprov),
                "type"     => "prov",
                "children" => []
            ];
        }
        if (!isset($tree[$provKey]["children"][$kabKey])) {
            $tree[$provKey]["children"][$kabKey] = [
                "id"        => $kabKey,
                "kdprov"    => $kdprov,
                "kdkab"     => $kdkab,
                "kdprovkab" => $kdprovkab,
                "name"      => strtoupper($nmkab),
                "type"      => "kab",
                "children"  => []
            ];
        }
        $kawId = md5($kabKey . "_" . $nmkw);
        if (!isset($tree[$provKey]["children"][$kabKey]["children"][$kawId])) {
            $tree[$provKey]["children"][$kabKey]["children"][$kawId] = [
                "id"       => $kawId,
                "name"     => $nmkw,
                "type"     => "kawasan",
                "children" => []
            ];
        }
        $tree[$provKey]["children"][$kabKey]["children"][$kawId]["children"][] = $company;
    }

    // ── Konversi ke indexed array ──────────────────────────────────────
    $finalTree = [];
    foreach ($tree as $prov) {
        $prov["children"] = array_values($prov["children"]);
        foreach ($prov["children"] as &$kab) {
            $kab["children"] = array_values($kab["children"]);
        }
        unset($kab);
        $finalTree[] = $prov;
    }

    // ── Tambahkan node Lainnya di akhir (jika ada) ────────────────────
    if (!empty($lainnya)) {
        $lainnyaChildren = array_values($lainnya);
        $totalLainnya = array_sum(array_map(fn($k) => count($k['children']), $lainnyaChildren));
        $finalTree[] = [
            "id"       => "LAINNYA",
            "name"     => "LAINNYA",
            "type"     => "prov",
            "children" => [
                [
                    "id"        => "LAINNYA_KAB",
                    "kdprov"    => "",
                    "kdkab"     => "",
                    "kdprovkab" => "",
                    "name"      => "Belum Terpetakan",
                    "type"      => "kab",
                    "children"  => $lainnyaChildren
                ]
            ]
        ];
    }

    $json = json_encode(
        ["status" => "success", "data" => $finalTree],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
    echo ($json === false)
        ? json_encode(["status" => "error", "message" => "JSON Error: " . json_last_error_msg()])
        : $json;
    exit;


// =============================================================
// ACTION: move_company
// =============================================================
} elseif ($action === 'move_company') {
    require_admin();

    $company_id  = $input['company_id']  ?? null;
    $target_type = $input['target_type'] ?? null;
    $new_nmkw    = $input['new_nmkw']    ?? '';
    $new_kdkab   = $input['new_kdkab']   ?? '';
    $new_nmkab   = $input['new_nmkab']   ?? '';
    $new_kdprov  = $input['new_kdprov']  ?? '';
    $new_nmprov  = $input['new_nmprov']  ?? '';
    $new_kdprovkab = $input['new_kdprovkab'] ?? null;

    if (!$company_id) {
        echo json_encode(["status" => "error", "message" => "Company ID is missing"]);
        exit;
    }

    if ($target_type === 'kawasan') {
        $stmt = $conn->prepare("UPDATE perusahaan SET nmkw=?, kdkab=?, nmkab=?, kdprov=?, nmprov=?, kdprovkab=? WHERE id=?");
        $stmt->bind_param("ssssssi", $new_nmkw, $new_kdkab, $new_nmkab, $new_kdprov, $new_nmprov, $new_kdprovkab, $company_id);
    } elseif ($target_type === 'kab') {
        $default_kw = "Lainnya";
        $stmt = $conn->prepare("UPDATE perusahaan SET nmkw=?, kdkab=?, nmkab=?, kdprov=?, nmprov=?, kdprovkab=? WHERE id=?");
        $stmt->bind_param("ssssssi", $default_kw, $new_kdkab, $new_nmkab, $new_kdprov, $new_nmprov, $new_kdprovkab, $company_id);
    } elseif ($target_type === 'prov') {
        $default_kw    = "Lainnya";
        $default_kab   = "00";
        $default_nmkab = "Lainnya";
        $null_kdprovkab = null;
        $stmt = $conn->prepare("UPDATE perusahaan SET nmkw=?, kdkab=?, nmkab=?, kdprov=?, nmprov=?, kdprovkab=? WHERE id=?");
        $stmt->bind_param("ssssssi", $default_kw, $default_kab, $default_nmkab, $new_kdprov, $new_nmprov, $null_kdprovkab, $company_id);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid target type"]);
        exit;
    }

    echo $stmt->execute()
        ? json_encode(["status" => "success", "message" => "Company moved successfully"])
        : json_encode(["status" => "error",   "message" => "Failed to update database"]);
    exit;

// =============================================================
// ACTION: get_options  (untuk modal tambah data)
// =============================================================
} elseif ($action === 'get_options') {
    $jnskw = $conn->real_escape_string($_GET['jnskw'] ?? '');

    $provs = [];
    $res = $conn->query("SELECT DISTINCT kdprov, nmprov FROM perusahaan WHERE jnskw='$jnskw' AND kdprov IS NOT NULL AND kdprov != '' ORDER BY nmprov");
    while ($r = $res->fetch_assoc()) $provs[] = $r;

    $kabs = [];
    $res = $conn->query("SELECT DISTINCT kdprov, kdkab, nmkab, kdprovkab FROM perusahaan WHERE jnskw='$jnskw' AND kdkab IS NOT NULL AND kdkab != '' ORDER BY nmkab");
    while ($r = $res->fetch_assoc()) $kabs[] = $r;

    $kws = [];
    $res = $conn->query("SELECT DISTINCT kdprov, kdkab, nmkw FROM perusahaan WHERE jnskw='$jnskw' AND nmkw IS NOT NULL AND nmkw != '' ORDER BY nmkw");
    while ($r = $res->fetch_assoc()) $kws[] = $r;

    echo json_encode(
        ["status" => "success", "data" => ["provs" => $provs, "kabs" => $kabs, "kws" => $kws]],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;

// =============================================================
// ACTION: add_company
// =============================================================
} elseif ($action === 'add_company') {
    require_admin();

    $jnskw  = $input['jnskw']  ?? '';
    $kdprov = $input['kdprov'] ?? '';
    $nmprov = $input['nmprov'] ?? '';
    $kdkab  = $input['kdkab']  ?? '';
    $nmkab  = $input['nmkab']  ?? '';
    $kdprovkab = $input['kdprovkab'] ?? null;
    $nmkw   = $input['nmkw']   ?? '';
    $nmprsh = $input['nmprsh'] ?? '';
    $alamat = $input['alamat'] ?? '';

    // Field baru
    $idstpu          = $input['idstpu']          ?? '';
    $nmkorespondensi = $input['nmkorespondensi'] ?? '';
    $nohp            = $input['nohp']            ?? '';
    $email           = $input['email']           ?? '';
    $jarusaha        = $input['jarusaha']        ?? '';
    $kbli            = $input['kbli']            ?? '';

    if (!$jnskw || !$kdprov || !$kdkab || !$nmkw || !$nmprsh) {
        echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO perusahaan
            (jnskw, kdprov, nmprov, kdkab, nmkab, kdprovkab, nmkw, nmprsh, alamat,
             idstpu, nmkorespondensi, nohp, email, jarusaha, kbli)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "sssssssssssssss",
        $jnskw, $kdprov, $nmprov, $kdkab, $nmkab, $kdprovkab, $nmkw, $nmprsh, $alamat,
        $idstpu, $nmkorespondensi, $nohp, $email, $jarusaha, $kbli
    );

    echo $stmt->execute()
        ? json_encode(["status" => "success", "message" => "Data berhasil ditambahkan"])
        : json_encode(["status" => "error",   "message" => "Gagal menyimpan data"]);
    exit;

} else {
    echo json_encode(["status" => "error", "message" => "Invalid action: $action"]);
}

$conn->close();
?>
