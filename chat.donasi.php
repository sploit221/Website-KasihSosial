<?php

include_once 'koneksi.php';

if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}
requireLogin();

$my_id      = (int)$_SESSION['user_id'];
$pakaian_id  = (int)($_GET['pakaian_id'] ?? 0);
$penerima_id = (int)($_GET['penerima_id'] ?? 0);

if (!$pakaian_id || !$penerima_id) {
    flash('error', 'Akses tidak sah.');
    header("Location: index.php"); exit;
}

// Validasi ketat: hanya izinkan pemilik barang ATAU user yang sudah mengajukan request
$stmt_p = dbQuery(
    "SELECT p.user_id, p.foto_pakaian, p.jenis_pakaian, p.ukuran, u.username AS nama_pemilik 
     FROM pakaian p 
     JOIN users u ON p.user_id = u.user_id 
     WHERE p.pakaian_id = ?", 
    'i', [$pakaian_id]
);
$pakaian = $stmt_p->get_result()->fetch_assoc();

if (!$pakaian) {
    flash('error', 'Barang tidak ditemukan.');
    header("Location: index.php"); exit;
}

$owner_id = (int)$pakaian['user_id'];

$cek_otoritas = dbQuery(
    "SELECT 1 FROM donasi_request WHERE pakaian_id = ? AND (penerima_id = ? OR ? = ?) LIMIT 1",
    'iiii', [$pakaian_id, $penerima_id, $my_id, $owner_id]
);

if ($cek_otoritas->get_result()->num_rows === 0 && $my_id !== $owner_id) {
    flash('error', 'Anda tidak memiliki otoritas untuk mengakses percakapan ini.');
    header("Location: index.php"); exit;
}

// Menentukan target lawan bicara (Logika Aman)
$target_lawan_bicara = ($my_id === $owner_id) ? $penerima_id : $owner_id;

// ── PROSES KIRIM PESAN (DENGAN CSRF & SANITASI) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kirim'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die("Security Breach Detected: Invalid Token.");
    }

    $isi_pesan = htmlspecialchars(strip_tags(trim($_POST['pesan'] ?? '')), ENT_QUOTES, 'UTF-8');
    
    if (!empty($isi_pesan)) {
        dbQuery(
            "INSERT INTO chat_donasi (pakaian_id, pengirim_id, penerima_pesan_id, isi_pesan, tanggal_pesan) VALUES (?, ?, ?, ?, NOW())",
            'iiis',
            [$pakaian_id, $my_id, $target_lawan_bicara, $isi_pesan]
        );
        header("Location: chat.donasi.php?pakaian_id=$pakaian_id&penerima_id=$penerima_id&sent=1");
        exit;
    }
}

// ── AMBIL RIWAYAT CHAT (DENGAN FILTER USER) ───────────────────────────────
$riwayat_query = dbQuery(
    "SELECT cd.*, u.username AS nama_pengirim 
     FROM chat_donasi cd
     JOIN users u ON cd.pengirim_id = u.user_id
     WHERE cd.pakaian_id = ? 
     AND (
       (cd.pengirim_id = ? AND cd.penerima_pesan_id = ?) OR 
       (cd.pengirim_id = ? AND cd.penerima_pesan_id = ?)
     )
     ORDER BY cd.tanggal_pesan ASC",
    'iiiii',
    [$pakaian_id, $my_id, $target_lawan_bicara, $target_lawan_bicara, $my_id]
);
$riwayat = $riwayat_query->get_result(); // Sekarang $riwayat sudah memiliki data
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Chat Donasi — KasihSosial</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --coral: #e85d4a;
      --dark:  #1a1a2e;
    }
    body { background: #f0f4f8; font-family: 'Plus Jakarta Sans', sans-serif; }

    .chat-wrapper {
      max-width: 700px; margin: 0 auto;
      height: 100dvh; display: flex; flex-direction: column;
    }

    /* Header */
    .chat-header {
      background: var(--dark);
      padding: .85rem 1.25rem;
      color: #fff;
      display: flex; align-items: center; gap: .85rem;
      flex-shrink: 0;
    }
    .chat-header .back-btn {
      color: rgba(255,255,255,.7); text-decoration: none; font-size: 1.2rem;
      transition: color .2s;
    }
    .chat-header .back-btn:hover { color: #fff; }
    .chat-header .item-thumb {
      width: 42px; height: 42px; border-radius: 10px;
      object-fit: cover; border: 2px solid rgba(255,255,255,.2);
      flex-shrink: 0;
    }
    .chat-header .item-name { font-weight: 700; font-size: .95rem; line-height: 1.2; }
    .chat-header .item-sub  { font-size: .72rem; opacity: .65; }

    /* Messages area */
    .chat-messages {
      flex: 1; overflow-y: auto;
      padding: 1rem 1.25rem;
      display: flex; flex-direction: column; gap: .75rem;
      scroll-behavior: smooth;
    }

    /* Bubbles */
    .msg-wrap { display: flex; flex-direction: column; max-width: 78%; }
    .msg-wrap.me { align-self: flex-end; align-items: flex-end; }
    .msg-wrap.them { align-self: flex-start; align-items: flex-start; }

    .bubble {
      padding: .65rem 1rem;
      border-radius: 18px;
      font-size: .875rem;
      line-height: 1.55;
      word-break: break-word;
      position: relative;
    }
    .bubble.me {
      background: linear-gradient(135deg, var(--coral), #f4845f);
      color: #fff;
      border-bottom-right-radius: 4px;
    }
    .bubble.them {
      background: #fff;
      color: var(--dark);
      border: 1px solid #e5e7eb;
      border-bottom-left-radius: 4px;
      box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }
    .msg-meta {
      font-size: .68rem; margin-top: 3px; opacity: .65;
    }
    .msg-meta.me { color: var(--dark); }

    /* Date divider */
    .date-divider {
      text-align: center; font-size: .72rem;
      color: #9ca3af; margin: .5rem 0;
      display: flex; align-items: center; gap: .75rem;
    }
    .date-divider::before, .date-divider::after {
      content: ''; flex: 1; height: 1px; background: #e5e7eb;
    }

    /* Empty state */
    .chat-empty {
      margin: auto; text-align: center; color: #9ca3af;
      padding: 2rem;
    }
    .chat-empty i { font-size: 3rem; display: block; margin-bottom: .75rem; }

    /* Input area */
    .chat-input {
      background: #fff;
      padding: .85rem 1.25rem;
      border-top: 1px solid #e5e7eb;
      flex-shrink: 0;
    }
    .chat-input .input-group {
      border: 1.5px solid #e5e7eb;
      border-radius: 14px;
      overflow: hidden;
      transition: border-color .2s;
    }
    .chat-input .input-group:focus-within {
      border-color: var(--coral);
      box-shadow: 0 0 0 3px rgba(232,93,74,.1);
    }
    .chat-input textarea {
      border: none; resize: none;
      font-size: .875rem; padding: .65rem 1rem;
      line-height: 1.5; max-height: 120px;
      font-family: 'Plus Jakarta Sans', sans-serif;
    }
    .chat-input textarea:focus { box-shadow: none; outline: none; }
    .chat-input .send-btn {
      background: linear-gradient(135deg, var(--coral), #f4845f);
      border: none; color: #fff;
      padding: 0 1.1rem; font-size: 1rem;
      transition: opacity .2s;
    }
    .chat-input .send-btn:hover { opacity: .85; }
    .char-count { font-size: .7rem; color: #9ca3af; }
  </style>
</head>
<body>

<div class="chat-wrapper">

  <!-- Header -->
  <div class="chat-header">
    <a href="index.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <?php if ($pakaian): ?>
      <img src="uploads/<?= e($pakaian['foto_pakaian']); ?>"
           class="item-thumb"
           onerror="this.src='https://placehold.co/42x42?text=?'"
           alt="<?= e($pakaian['jenis_pakaian']); ?>">
      <div>
        <div class="item-name"><?= e($pakaian['jenis_pakaian']); ?> &mdash; <?= e($pakaian['ukuran'] ?? ''); ?></div>
        <div class="item-sub"><i class="bi bi-person-fill me-1"></i><?= e($pakaian['nama_pemilik']); ?></div>
      </div>
    <?php else: ?>
      <div class="item-name">Percakapan Donasi</div>
    <?php endif; ?>
  </div>

  <?php if (!empty($error)): ?>
  <div class="alert alert-danger m-2 py-2 small rounded-3 border-0">
    <i class="bi bi-exclamation-triangle me-1"></i><?= e($error); ?>
  </div>
<?php endif; ?>

<?php if (isset($_GET['sent'])): ?>
  <div class="alert alert-success m-2 py-2 small rounded-3 border-0" id="sent-alert">
    <i class="bi bi-check-circle me-1"></i>Pesan terkirim!
  </div>
<?php endif; ?>

  <!-- Messages -->
  <div class="chat-messages" id="chatMessages">
    <?php if ($riwayat->num_rows > 0):
      $prev_date = '';
      while ($chat = $riwayat->fetch_assoc()):
        $is_me    = ($chat['pengirim_id'] == $my_id);
        $tanggal  = isset($chat['tanggal_pesan']) ? date('d M Y', strtotime($chat['tanggal_pesan'])) : '';
        $waktu    = isset($chat['tanggal_pesan']) ? date('H:i', strtotime($chat['tanggal_pesan'])) : '';
    ?>
      <?php if ($tanggal !== $prev_date): ?>
        <div class="date-divider"><?= $tanggal; ?></div>
        <?php $prev_date = $tanggal; ?>
      <?php endif; ?>

      <div class="msg-wrap <?= $is_me ? 'me' : 'them'; ?>">
        <div class="bubble <?= $is_me ? 'me' : 'them'; ?>">
          <?= e($chat['isi_pesan']); ?>
        </div>
        <div class="msg-meta <?= $is_me ? 'me' : ''; ?>">
          <?= $is_me ? 'Anda' : e($chat['nama_pengirim']); ?> · <?= $waktu; ?>
        </div>
      </div>
    <?php endwhile; else: ?>
      <div class="chat-empty">
        <i class="bi bi-chat-heart"></i>
        <p class="fw-semibold mb-1">Belum ada pesan</p>
        <small>Mulai percakapan untuk tanya detail barang atau atur jadwal ambil.</small>
      </div>
    <?php endif; ?>
  </div>

  <!-- Input -->
  <div class="chat-input">
    <form method="POST" action="" id="chatForm">
      <?= csrfField(); ?>
      <div class="input-group">
        <textarea name="isi_pesan" id="msgInput" class="form-control"
                  placeholder="Tulis pesan…" rows="1" maxlength="1000" required
                  oninput="autoResize(this); updateCount(this)"></textarea>
        <button type="submit" name="kirim" class="send-btn" title="Kirim">
          <i class="bi bi-send-fill"></i>
        </button>
      </div>
      <div class="d-flex justify-content-between mt-1">
        <small class="text-muted" style="font-size:.72rem;">
          Tanyakan detail atau buat janji penjemputan
        </small>
        <small class="char-count"><span id="charCount">0</span>/1000</small>
      </div>
    </form>
  </div>

</div><!-- /chat-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Scroll ke bawah otomatis
  const chatEl = document.getElementById('chatMessages');
  if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;

  // Auto-resize textarea
  function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }

  // Character counter
  function updateCount(el) {
    document.getElementById('charCount').textContent = el.value.length;
  }

  // Kirim dengan Ctrl+Enter
  document.getElementById('msgInput')?.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      document.getElementById('chatForm').submit();
    }
  });

  // Auto dismiss sent alert
  const sentAlert = document.getElementById('sent-alert');
  if (sentAlert) setTimeout(() => sentAlert.remove(), 3000);
</script>
</body>
</html>