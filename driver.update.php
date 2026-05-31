<?php

include_once 'koneksi.php';
requireRole('driver');

// Hanya terima POST dengan tombol update_status
if (!isset($_POST['update_status'])) {
    header("Location: driver.dashboard.php"); exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────────
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    flash('error', 'Token keamanan tidak valid.');
    header("Location: driver.dashboard.php"); exit;
}

$tugas_id     = validateId($_POST['tugas_id']  ?? 0);
$status_input = validateEnum($_POST['status_baru'] ?? '', [
    'Menunggu',
    'Driver Ditugaskan',
    'Menuju Lokasi Pickup',     
    'Tiba di Lokasi Pickup',    
    'Barang Diambil',
    'Dalam Pengiriman',        
    'Tiba di Tujuan',
    'Terkirim',                
]);
$driver_id = (int)$_SESSION['user_id'];

if (!$tugas_id || !$status_input) {
    flash('error', 'Data tidak valid.');
    header("Location: driver.dashboard.php"); exit;
}

// ── Pastikan tugas milik driver ini ──────────────────────────────────────────
$cek = dbQuery(
    "SELECT tp.tugas_id, tp.request_id, tp.status_pengantaran
     FROM tugas_pengantaran tp
     WHERE tp.tugas_id = ? AND tp.driver_id = ?",
    'ii', [$tugas_id, $driver_id]
)->get_result()->fetch_assoc();

if (!$cek) {
    flash('error', 'Tugas tidak ditemukan atau bukan milik Anda.');
    header("Location: driver.dashboard.php"); exit;
}

$request_id      = (int)$cek['request_id'];
$status_sekarang = $cek['status_pengantaran'];

// ── Tentukan status yang akan disimpan ───────────────────────────────────────
if ($status_input === 'Tiba di Tujuan') {
    $status_simpan = 'Tiba di Tujuan';
} elseif ($status_input === 'Terkirim') {
    $status_simpan = 'Terkirim';
} else {
    $status_simpan = $status_input;
}

// ── Update tugas_pengantaran ──────────────────────────────────────────────────
$stmt = dbQuery(
    "UPDATE tugas_pengantaran
     SET status_pengantaran = ?, updated_at = NOW()
     WHERE tugas_id = ? AND driver_id = ?",
    'sii', [$status_simpan, $tugas_id, $driver_id]
);

if ($stmt->affected_rows > 0) {

    if ($status_input === 'Tiba di Tujuan') {
        // Update donasi_request agar penerima bisa konfirmasi
        dbQuery(
            "UPDATE donasi_request SET status = 'Tiba di Tujuan' WHERE request_id = ?",
            'i', [$request_id]
        );
        flash('success', 'Barang telah tiba di tujuan! Menunggu konfirmasi penerima.');

    } elseif ($status_input === 'Terkirim') {
        // Update donasi_request menjadi Diterima
        dbQuery(
            "UPDATE donasi_request SET status = 'Diterima' WHERE request_id = ?",
            'i', [$request_id]
        );
        // Update status_pembayaran jika belum
        dbQuery(
            "UPDATE tugas_pengantaran SET status_pembayaran = 'cash_lunas', status_pengantaran = 'Selesai'
             WHERE tugas_id = ? AND status_pembayaran = 'belum_dibayar'",
            'i', [$tugas_id]
        );
        flash('success', 'Tugas selesai! Terima kasih telah mengantarkan donasi.');

    } elseif ($status_input === 'Tiba di Lokasi Pickup') {
        flash('success', 'Anda telah tiba di lokasi penjemputan. Ambil barang dan lanjutkan perjalanan.');

    } elseif ($status_input === 'Barang Diambil') {
        flash('success', 'Barang sudah diambil. Segera antar ke penerima.');

    } elseif ($status_input === 'Dalam Pengiriman') {
        flash('success', 'Anda dalam perjalanan menuju penerima. Hati-hati di jalan!');

    } else {
        flash('success', 'Status diperbarui menjadi: ' . $status_input);
    }

} else {
    flash('info', 'Status tidak berubah (sudah ' . $status_sekarang . ').');
}

header("Location: driver.dashboard.php");
exit;