<?php
include_once 'koneksi.php';
requireRole('penerima'); // Redirect otomatis ke login.php jika bukan penerima

$penerima_id = (int)$_SESSION['user_id'];

// ✅ QUERY YANG DIPERBAIKI - ambil semua data sekaligus
$stmt = dbQuery(
    "SELECT 
        dr.request_id, 
        dr.status AS status_request,
        p.jenis_pakaian,
        u_pemberi.username AS nama_pemberi,
        tp.status_pengantaran, 
        tp.tugas_id,
        tp.metode_pembayaran,
        tp.status_pembayaran,
        tp.ongkos_kirim,
        dr.tanggal_request
     FROM donasi_request dr
     JOIN pakaian p ON dr.pakaian_id = p.pakaian_id
     JOIN users u_pemberi ON p.user_id = u_pemberi.user_id
     LEFT JOIN tugas_pengantaran tp ON dr.request_id = tp.request_id
     WHERE dr.penerima_id = ?
     ORDER BY dr.request_id DESC",
    'i', [$penerima_id]
);
$result = $stmt->get_result();

// Warna badge berdasarkan status pengantaran
function badgeColor($status) {
    return match($status) {
        'Menunggu'              => 'warning text-dark',
        'Driver Ditugaskan'     => 'info text-dark',
        'Menuju Lokasi Pickup'  => 'warning text-dark',
        'Tiba di Lokasi Pickup' => 'success',
        'Barang Diambil'        => 'primary',
        'Dalam Pengiriman'      => 'info text-dark',
        'Tiba di Tujuan'        => 'success',
        'Terkirim'              => 'success',
        default                 => 'secondary',
    };
}

// ✅ FUNGSI untuk menampilkan metode bayar dengan lengkap
function tampilkanMetodeBayar($metode, $status_pembayaran) {
    if (empty($metode)) {
        return '<span class="text-muted">—</span>';
    }
    
    $label = match($metode) {
        'transfer' => '🏦 Transfer Bank',
        'dana'     => '💜 DANA',
        'cod'      => '💰 COD',
        'cash'     => '💵 Tunai',
        default    => $metode
    };
    
    $badgeClass = match($metode) {
        'transfer' => 'bg-primary',
        'dana'     => 'bg-info text-dark',
        'cod'      => 'bg-warning text-dark',
        'cash'     => 'bg-success',
        default    => 'bg-secondary'
    };
    
    $statusHtml = '';
    if ($status_pembayaran === 'pending_verifikasi') {
        $statusHtml = ' <span class="badge bg-warning text-dark">⏳ Verifikasi</span>';
    } elseif ($status_pembayaran === 'lunas' || $status_pembayaran === 'cash_lunas') {
        $statusHtml = ' <span class="badge bg-success">✅ Lunas</span>';
    } elseif ($status_pembayaran === 'belum_dibayar' && $metode === 'cod') {
        $statusHtml = ' <span class="badge bg-info">Bayar saat terima</span>';
    }
    
    return '<span class="badge ' . $badgeClass . '">' . $label . '</span>' . $statusHtml;
}

// ✅ FUNGSI untuk tombol aksi
function tombolAksi($row) {
    $status_req = $row['status_request'] ?? 'Pending';
    $status_driver = $row['status_pengantaran'] ?? '';
    $status_tampil = !empty($status_driver) ? $status_driver : $status_req;
    
    $metode = $row['metode_pembayaran'] ?? '';
    $status_bayar = $row['status_pembayaran'] ?? '';
    $tugas_id = $row['tugas_id'] ?? 0;
    $request_id = $row['request_id'] ?? 0;
    $ongkos_kirim = $row['ongkos_kirim'] ?? 0;
    
    // Kasus 1: Driver sudah tiba
    if ($status_tampil === 'Tiba di Tujuan') {
        return '
            <form action="konfirmasi.terima.php" method="POST" onsubmit="return confirm(\'Apakah barang sudah benar-benar diterima?\')">
                <input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">
                <input type="hidden" name="tugas_id" value="' . $tugas_id . '">
                <input type="hidden" name="request_id" value="' . $request_id . '">
                <button type="submit" name="konfirmasi_terima" class="btn btn-success btn-sm fw-bold">
                    <i class="bi bi-check2-circle me-1"></i>Konfirmasi Diterima
                </button>
            </form>';
    }
    
    // Kasus 2: Sudah selesai
    if ($status_tampil === 'Diterima' || $status_tampil === 'Selesai' || $status_tampil === 'Terkirim') {
        return '<span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Selesai</span>';
    }
    
    // Kasus 3: Belum ada tugas
    if (empty($tugas_id)) {
        return '<button class="btn btn-secondary btn-sm" disabled><i class="bi bi-hourglass-split me-1"></i>Menunggu</button>';
    }
    
    // Kasus 4: COD dan belum lunas
    if ($metode === 'cod' && $status_bayar !== 'lunas' && $status_bayar !== 'cash_lunas') {
        return '
            <div class="d-flex flex-column gap-1">
                <span class="badge bg-warning text-dark">💰 Bayar COD saat driver tiba</span>
                <a href="tracking.penerima.php?id=' . $request_id . '" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-radar me-1"></i>Lacak Driver
                </a>
            </div>';
    }
    
    // Kasus 5: Menunggu verifikasi (transfer/DANA)
    if ($status_bayar === 'pending_verifikasi') {
        return '
            <div class="d-flex flex-column gap-1">
                <span class="badge bg-warning text-dark">⏳ Menunggu Verifikasi Pembayaran</span>
                <a href="tracking.penerima.php?id=' . $request_id . '" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-radar me-1"></i>Lacak Driver
                </a>
            </div>';
    }
    
    // Kasus 6: Belum pilih metode (belum_dibayar dan belum ada metode)
    if ($status_bayar === 'belum_dibayar' && empty($metode) && $ongkos_kirim > 0) {
        return '<a href="pilih.layanan.php?request_id=' . $request_id . '" class="btn btn-warning btn-sm fw-bold">
                    <i class="bi bi-cash-coin me-1"></i>Bayar Ongkir
                </a>';
    }
    
    // Kasus 7: Sudah lunas (transfer/DANA sudah diverifikasi)
    if ($status_bayar === 'lunas' || $status_bayar === 'cash_lunas') {
        return '
            <div class="d-flex flex-column gap-1">
                <span class="badge bg-success">✅ Pembayaran Lunas</span>
                <a href="tracking.penerima.php?id=' . $request_id . '" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-radar me-1"></i>Lacak Driver
                </a>
            </div>';
    }
    
    // Default (misal: sudah pilih metode tapi status_bayar belum_dibayar untuk non-COD)
    if (!empty($metode) && $metode !== 'cod') {
        return '
            <div class="d-flex flex-column gap-1">
                <span class="badge bg-info">Metode: ' . $metode . ' - Menunggu Pembayaran</span>
                <a href="pilih.layanan.php?request_id=' . $request_id . '" class="btn btn-warning btn-sm">
                    <i class="bi bi-cash-coin me-1"></i>Bayar Sekarang
                </a>
            </div>';
    }
    
    return '<a href="tracking.penerima.php?id=' . $request_id . '" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-radar me-1"></i>Lacak Driver
            </a>';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Penerima - KasihSosial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background: #f4f6f9; }

        /* ── Banner notifikasi driver tiba ─────────────── */
        .arrival-banner {
            display: none;
            background: linear-gradient(135deg, #065f46, #0d9488);
            color: #fff; border-radius: 16px;
            padding: 1.1rem 1.4rem;
            margin-bottom: 1.5rem;
            animation: slideDown .5s ease;
            box-shadow: 0 8px 25px rgba(5,150,105,.35);
        }
        .arrival-banner.show { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .arrival-icon {
            font-size: 2.5rem;
            animation: ring 1s ease infinite alternate;
        }
        @keyframes ring {
            from { transform: rotate(-15deg); }
            to   { transform: rotate(15deg); }
        }
        .btn-konfirmasi-cepat {
            background: #fff; color: #065f46;
            border: none; border-radius: 10px;
            padding: .55rem 1.1rem; font-weight: 700;
            font-size: .875rem; cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0,0,0,.15);
            text-decoration: none;
            display: inline-flex; align-items: center; gap: .4rem;
        }
        .btn-konfirmasi-cepat:hover { background: #d1fae5; color: #065f46; }
        .btn-wa-driver {
            background: #25d366; color: #fff;
            border: none; border-radius: 10px;
            padding: .55rem 1.1rem; font-weight: 700;
            font-size: .875rem; cursor: pointer;
            white-space: nowrap; text-decoration: none;
            display: inline-flex; align-items: center; gap: .4rem;
        }
        .btn-wa-driver:hover { background: #128c7e; color: #fff; }

        /* ── Polling countdown ─────────────────────────── */
        .poll-indicator {
            font-size: .7rem; color: #9ca3af;
            display: flex; align-items: center; gap: .3rem;
        }
        .poll-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #10b981;
            animation: blink 1.5s ease infinite;
        }
        @keyframes blink {
            0%,100% { opacity: 1; } 50% { opacity: .2; }
        }

        /* ── Row highlight ketika driver tiba ─────────── */
        tr.row-tiba { background: #f0fdf4 !important; }
        tr.row-tiba td { border-left: 3px solid #10b981; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark px-4">
    <a class="navbar-brand fw-bold" href="index.php">
        <i class="bi bi-heart-fill text-danger me-2"></i>KasihSosial
    </a>
    <div class="ms-auto d-flex align-items-center gap-3">
        <span class="text-white small">
            <i class="bi bi-person-circle me-1"></i><?= e($_SESSION['username']); ?>
        </span>
        <a href="index.php" class="btn btn-sm btn-outline-light">
            <i class="bi bi-grid me-1"></i>Katalog
        </a>
        
        <a href="logout.php" class="btn btn-sm btn-danger"
           onclick="return confirm('Yakin ingin keluar?')">
            <i class="bi bi-box-arrow-right me-1"></i>Logout
        </a>
    </div>
</nav>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-10">

            <?= renderFlash(); ?>

            <!-- ── Banner Driver Tiba (ditampilkan via JS polling) ── -->
            <div class="arrival-banner" id="arrivalBanner">
                <div class="arrival-icon">🔔</div>
                <div class="flex-grow-1">
                    <div class="fw-bold" style="font-size:1rem;">
                        Driver Sudah Tiba di Lokasi Anda!
                    </div>
                    <div style="font-size:.82rem; opacity:.85;" id="arrivalDriverName">
                        Segera konfirmasi bahwa barang sudah Anda terima.
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="#" id="btnKonfirmasiBanner" class="btn-konfirmasi-cepat">
                        <i class="bi bi-check-circle-fill"></i>Konfirmasi Diterima
                    </a>
                    <a href="#" id="btnWaDriver" class="btn-wa-driver" target="_blank"
                       style="display:none;">
                        <i class="bi bi-whatsapp"></i>Hubungi Driver
                    </a>
                </div>
            </div>

            <div class="d-flex align-items-center justify-content-between mb-4">
                <div>
                    <h3 class="fw-bold mb-0">
                        <i class="bi bi-bag-heart me-2 text-primary"></i>Barang yang Saya Minta
                    </h3>
                    <div class="poll-indicator mt-1">
                        <div class="poll-dot"></div>
                        <span>Status diperbarui otomatis · refresh dalam <span id="pollCountdown">15</span>s</span>
                    </div>
                </div>
                <a href="index.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Minta Barang
                </a>
            </div>

            <?php if ($result->num_rows > 0): ?>
                <div class="table-responsive bg-white p-0 shadow-sm rounded overflow-hidden">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Barang</th>
                                <th>Pemberi</th>
                                <th>Status Pengiriman</th>
                                <th>Metode Bayar</th>
                                <th class="text-center pe-4">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): 
                                $status_tampil = !empty($row['status_pengantaran']) ? $row['status_pengantaran'] : $row['status_request'];
                                $is_tiba = ($row['status_request'] === 'Tiba di Tujuan');
                            ?>
                                <tr class="<?= $is_tiba ? 'row-tiba' : ''; ?>"
                                    data-request-id="<?= (int)$row['request_id']; ?>"
                                    data-status="<?= e($row['status_request']); ?>">
                                    
                                    <td class="ps-4">
                                        <strong><?= e($row['jenis_pakaian']); ?></strong>
                                        <small class="d-block text-muted">ID #<?= (int)$row['request_id']; ?></small>
                                    </td>
                                    
                                    <td><?= e($row['nama_pemberi']); ?></td>
                                    
                                    <td>
                                        <span class="badge bg-<?= badgeColor($status_tampil); ?>">
                                            <?= e($status_tampil); ?>
                                        </span>
                                    </td>
                                    
                                    <!-- ✅ KOLOM METODE BAYAR - SEKARANG PASTI MUNCUL -->
                                    <td>
                                        <?= tampilkanMetodeBayar($row['metode_pembayaran'] ?? '', $row['status_pembayaran'] ?? '') ?>
                                    </td>
                                    
                                    <!-- ✅ KOLOM AKSI -->
                                    <td class="text-center pe-4">
                                        <?= tombolAksi($row) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="card border-0 shadow-sm text-center py-5">
                    <div class="card-body">
                        <i class="bi bi-bag-x fs-1 text-muted d-block mb-3"></i>
                        <h5 class="text-muted">Belum ada permintaan barang yang aktif.</h5>
                        <p class="text-muted small">Temukan pakaian yang tersedia di katalog dan ajukan permintaan.</p>
                        <a href="index.php" class="btn btn-primary rounded-pill px-4 mt-2">
                             <i class="bi bi-search me-2"></i>Lihat Katalog
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Polling Status Driver ─────────────────────────────────────────────────────
// Kumpulkan semua request_id yang ada di halaman ini
const allRows = document.querySelectorAll('tr[data-request-id]');
const requestIds = [...new Set([...allRows].map(r => r.dataset.requestId).filter(Boolean))];

// Cari request yang statusnya belum selesai (perlu di-poll)
function getPendingRequests() {
    return [...allRows]
        .filter(r => !['Diterima', 'Selesai', 'Terkirim'].includes(r.dataset.status))
        .map(r => r.dataset.requestId);
}

let pollInterval = null;
let countdown    = 15;
const countEl    = document.getElementById('pollCountdown');

function updateCountdown() {
    if (countEl) countEl.textContent = countdown;
    countdown--;
    if (countdown < 0) {
        countdown = 15;
        pollAll();
    }
}

function pollAll() {
    const pending = getPendingRequests();
    if (pending.length === 0) return;

    pending.forEach(reqId => {
        fetch(`api.cek.status.php?request_id=${reqId}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) return;
                handleStatusUpdate(reqId, data);
            })
            .catch(() => {});
    });
}

function handleStatusUpdate(reqId, data) {
    const row = document.querySelector(`tr[data-request-id="${reqId}"]`);
    if (!row) return;

    const prevStatus = row.dataset.status;
    row.dataset.status = data.status_request;

    if (data.driver_tiba && prevStatus !== 'Tiba di Tujuan') {
        tampilkanBannerTiba(reqId, data);
        row.classList.add('row-tiba');

        const badgeEl = row.querySelector('td:nth-child(3) .badge');
        if (badgeEl) {
            badgeEl.className = 'badge bg-success';
            badgeEl.textContent = 'Tiba di Tujuan';
        }

        const aksiTd = row.querySelector('td:last-child');
        if (aksiTd) {
            aksiTd.innerHTML = `
                <form action="konfirmasi.terima.php" method="POST"
                      onsubmit="return confirm('Apakah barang sudah benar-benar diterima?')">
                    <input type="hidden" name="csrf_token" value="${data.csrf_token || ''}">
                    <input type="hidden" name="tugas_id" value="${data.tugas_id || ''}">
                    <input type="hidden" name="request_id" value="${reqId}">
                    <button type="submit" name="konfirmasi_terima"
                            class="btn btn-success btn-sm fw-bold">
                        <i class="bi bi-check2-circle me-1"></i>Konfirmasi Diterima
                    </button>
                </form>`;
        }
        playBeep();
    }

    if (data.sudah_diterima && prevStatus !== 'Diterima') {
        const aksiTd = row.querySelector('td:last-child');
        if (aksiTd) {
            aksiTd.innerHTML = `<span class="badge bg-success">
                <i class="bi bi-check-circle-fill me-1"></i>Selesai</span>`;
        }
        const banner = document.getElementById('arrivalBanner');
        if (banner) banner.classList.remove('show');
    }
}

function tampilkanBannerTiba(reqId, data) {
    const banner  = document.getElementById('arrivalBanner');
    const nameEl  = document.getElementById('arrivalDriverName');
    const btnKonf = document.getElementById('btnKonfirmasiBanner');
    const btnWa   = document.getElementById('btnWaDriver');

    if (!banner) return;

    if (nameEl && data.nama_driver) {
        nameEl.textContent = `Driver ${data.nama_driver} sudah tiba. Segera konfirmasi penerimaan barang.`;
    }
    if (btnKonf) {
        btnKonf.href = `konfirmasi.terima.php?id=${reqId}`;
    }
    if (btnWa && data.hp_driver_wa) {
        const msg = encodeURIComponent('Halo, saya sudah melihat notifikasi. Saya akan konfirmasi penerimaan barang sekarang.');
        btnWa.href = `https://wa.me/${data.hp_driver_wa}?text=${msg}`;
        btnWa.style.display = 'inline-flex';
    }

    banner.classList.add('show');
    banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function playBeep() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [0, 200, 400].forEach(delay => {
            const osc  = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 880;
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.3, ctx.currentTime + delay / 1000);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + delay / 1000 + 0.3);
            osc.start(ctx.currentTime + delay / 1000);
            osc.stop(ctx.currentTime + delay / 1000 + 0.3);
        });
    } catch(e) {}
}

if (getPendingRequests().length > 0) {
    pollInterval = setInterval(updateCountdown, 1000);
    pollAll();
}

allRows.forEach(row => {
    if (row.dataset.status === 'Tiba di Tujuan') {
        const reqId = row.dataset.requestId;
        fetch(`api.cek.status.php?request_id=${reqId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.error && data.driver_tiba) {
                    tampilkanBannerTiba(reqId, data);
                    row.classList.add('row-tiba');
                }
            }).catch(() => {});
    }
});
</script>
</body>
</html>