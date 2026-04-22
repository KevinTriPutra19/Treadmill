<?php
require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/schema.php';

ensure_app_schema($conn);

$state = get_device_state($conn);
$activeUid = $state['active_uid'] ?? null;
$photoMode = (int) ($state['photo_mode'] ?? 2);
$photoInterval = (int) ($state['photo_interval_sec'] ?? 2);
$tapCount = (int) ($state['tap_count'] ?? 0);
$deviceStatus = $state['status'] ?? 'idle';
$isLoggedIn = $activeUid !== null;

$memberName = 'Belum Login';
if ($isLoggedIn) {
	$member = get_member_by_uid($conn, $activeUid);
	$memberName = $member['full_name'] ?? ('User ' . substr((string) $activeUid, 0, 6));
}

$row = null;

$session_id = $row ? (int) $row['id'] : 0;
$tv = $row && $row['time_value'] ? htmlspecialchars($row['time_value']) : '0:00';
$dist = $row && $row['distance'] ? htmlspecialchars($row['distance']) : '0 km';
$img = $row && $row['image_path'] ? $row['image_path'] : null;
$raw = $row && $row['raw_ocr'] ? htmlspecialchars($row['raw_ocr']) : '—';
$edited = $row ? (int) $row['edited'] : 0;
$tts_raw = $row ? $row['timestamp_api'] : null;
$currentDurationMinutes = parse_duration_minutes($row['time_value'] ?? null);
$currentDistanceKm = parse_distance_km($row['distance'] ?? null);
$currentCalories = (int) ($row['calories'] ?? 0);
if ($currentCalories <= 0) {
	$currentCalories = estimate_calories($currentDurationMinutes, $currentDistanceKm);
}

$bulan_indo = [
	1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
	5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
	9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

if ($tts_raw) {
	$dt = new DateTime($tts_raw);
	$tgl = $dt->format('d') . ' ' . $bulan_indo[(int) $dt->format('m')] . ' ' . $dt->format('Y');
	$jam = $dt->format('H:i') . ' WIB';
} else {
	$tgl = '—';
	$jam = '—';
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Treadmill Monitoring</title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="assets/js/main.js" defer></script>
</head>
<body>

<div class="header">
	<div class="header-content">
		<div class="logo-section">
			<div class="logo-icon">
				<i class="fas fa-running"></i>
			</div>
			<div class="logo-text">
				<h1>Treadmill Monitoring</h1>
				<p class="tagline">Dashboard dan Riwayat Sesi</p>
			</div>
		</div>
		<div class="header-actions">
			<a class="nav-pill is-active" href="index.php"><i class="fas fa-house"></i><span>Dashboard</span></a>
			<a class="nav-pill" href="riwayat.php"><i class="fas fa-clipboard-list"></i><span>Riwayat</span></a>
		</div>
	</div>
</div>

<div class="container">
	<div class="control-card">
		<div>
			<h2>Mode Capture Tombol</h2>
			<p class="control-note">Silakan sesuaikan posisi kamera pada panel preview terlebih dahulu, kemudian tekan tombol secara bertahap: tekan pertama untuk masuk mode foto, tekan kedua untuk mengambil foto waktu, dan tekan ketiga untuk mengambil foto jarak sekaligus memulai proses OCR.</p>
		</div>
		<div class="tap-flow">
			<span>Tap 1: Login</span>
			<span>Tap 2: Logout</span>
			<span>Button 1: Mode Foto</span>
			<span>Button 2: Foto Waktu</span>
			<span>Button 3: Foto Jarak + OCR</span>
		</div>
	</div>

	<?php if (!$isLoggedIn): ?>
	<div class="login-empty-card">
		<div class="login-empty-icon"><i class="fas fa-id-card"></i></div>
		<h2>Belum Ada User Login</h2>
		<p>Silakan tap RFID untuk login. Setelah login, data user dan riwayat berdasarkan ID akan tampil otomatis.</p>
		<div class="tap-flow">
			<span>Tap 1: Login</span>
			<span>Tap 2: Logout</span>
			<span>Button: Trigger Kamera 2x</span>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($isLoggedIn): ?>
	<div class="active-user-section">
		<div class="section-title">
			<div class="title-left">
				<i class="fas fa-user-check"></i>
				<h2>Pengguna Aktif</h2>
			</div>
			<div class="status-inline">Tap: <?= $tapCount ?> | Status: <?= htmlspecialchars($deviceStatus) ?></div>
		</div>

		<div class="user-card-container">
			<div class="user-profile-card">
				<div class="profile-header">
					<div class="avatar">
						<i class="fas fa-user"></i>
					</div>
					<div class="profile-info">
						<h3><?= htmlspecialchars($memberName) ?></h3>
						<p class="user-id"><i class="fas fa-id-card"></i> UID: <?= htmlspecialchars((string) $activeUid) ?></p>
					</div>
					<div class="status-badge active">
						<i class="fas fa-circle"></i> Active
					</div>
				</div>

				<div class="activity-stats">
					<div class="stat-item">
						<div class="stat-icon time">
							<i class="fas fa-stopwatch"></i>
						</div>
						<div class="stat-info">
							<span class="stat-label">Waktu Treadmill</span>
							<span class="stat-value" id="timer-value"><?= $tv ?></span>
						</div>
						<button class="btn-edit" onclick="openEdit('waktu')" title="Edit Waktu" <?= $session_id <= 0 ? 'disabled' : '' ?>>
							<i class="fas fa-pen"></i>
						</button>
					</div>

					<div class="stat-item">
						<div class="stat-icon calories">
							<i class="fas fa-fire-alt"></i>
						</div>
						<div class="stat-info">
							<span class="stat-label">Kalori Terbakar</span>
							<span class="stat-value" id="calories-value"><?= number_format($currentCalories, 0, ',', '.') ?> kcal</span>
						</div>
					</div>

					<div class="stat-item">
						<div class="stat-icon distance">
							<i class="fas fa-route"></i>
						</div>
						<div class="stat-info">
							<span class="stat-label">Jarak Tempuh</span>
							<span class="stat-value" id="distance-value"><?= $dist ?></span>
						</div>
						<button class="btn-edit" onclick="openEdit('jarak')" title="Edit Jarak" <?= $session_id <= 0 ? 'disabled' : '' ?>>
							<i class="fas fa-pen"></i>
						</button>
					</div>
				</div>

				<div class="quick-save-row">
					<button id="save-history-btn" class="btn-save-history"><i class="fas fa-floppy-disk"></i> Simpan ke Riwayat</button>
					<span id="save-history-msg" class="save-history-msg"></span>
				</div>

				<div class="session-info">
					<div class="info-item">
						<i class="far fa-calendar-alt"></i>
						<span id="timer-date"><?= $tgl ?></span>
					</div>
					<div class="info-item">
						<i class="far fa-clock"></i>
						<span id="timer-time">Mulai: <?= $jam ?></span>
					</div>
					<?php if ($edited): ?>
					<div class="info-item edited-badge">
						<i class="fas fa-pen"></i>
						<span>Diedit manual</span>
					</div>
					<?php endif; ?>
				</div>

				<div class="ocr-section">
					<div class="ocr-header">
						<div class="ocr-title">
							<i class="fas fa-camera-retro"></i>
							<span>Preview Kamera dan Hasil OCR</span>
						</div>
						<div class="ocr-status-wrap">
							<div id="ocr-loading" class="ocr-loading" data-state="idle">
								<span class="spinner"></span>
								<span id="ocr-loading-text">Menunggu OCR...</span>
							</div>
							<div class="ocr-raw" id="ocr-raw" title="Hasil OCR mentah"><?= $raw ?></div>
						</div>
					</div>

					<div class="capture-steps">
						<div class="step-item"><strong>1</strong><span>Posisikan kamera ke display treadmill</span></div>
						<div class="step-item"><strong>2</strong><span>Tekan tombol sampai foto waktu dan jarak selesai</span></div>
						<div class="step-item"><strong>3</strong><span>Nilai OCR otomatis muncul di dashboard</span></div>
					</div>

					<div class="ocr-summary-row">
						<div class="ocr-summary-box">
							<label>Waktu OCR</label>
							<strong id="ocr-time-inline"><?= $tv ?></strong>
						</div>
						<div class="ocr-summary-box">
							<label>Jarak OCR</label>
							<strong id="ocr-distance-inline"><?= $dist ?></strong>
						</div>
					</div>

					<div class="capture-grid">
						<div class="ocr-image-card">
							<span class="ocr-image-label">Foto 1 · Waktu</span>
							<img id="live-img-time" src="uploads/latest_time.jpg" alt="Preview waktu" onerror="this.parentElement.classList.add('no-image')">
							<div class="capture-caption">Gunakan foto ini untuk deteksi waktu treadmill.</div>
						</div>
						<div class="ocr-image-card">
							<span class="ocr-image-label">Foto 2 · Jarak Tempuh</span>
							<img id="live-img-distance" src="uploads/latest_distance.jpg" alt="Preview jarak" onerror="this.parentElement.classList.add('no-image')">
							<div class="capture-caption">Gunakan foto ini untuk deteksi jarak tempuh.</div>
						</div>
					</div>

					<details class="ocr-debug">
						<summary>Debug OCR (opsional)</summary>
						<div class="ocr-images ocr-debug-grid">
							<div class="ocr-image-card">
								<span class="ocr-image-label">Time · Original</span>
								<img id="ocr-time-original" src="uploads/debug/time_00_original.jpg" alt="Time Original" onerror="this.parentElement.classList.add('no-image')">
							</div>
							<div class="ocr-image-card">
								<span class="ocr-image-label">Time · Cropped</span>
								<img id="ocr-time-cropped" src="uploads/debug/time_01_cropped.jpg" alt="Time Cropped" onerror="this.parentElement.classList.add('no-image')">
							</div>
							<div class="ocr-image-card">
								<span class="ocr-image-label">Time · Processed</span>
								<img id="ocr-time-processed" src="uploads/debug/time_02_processed.jpg" alt="Time Processed" onerror="this.parentElement.classList.add('no-image')">
							</div>
							<div class="ocr-image-card">
								<span class="ocr-image-label">Distance · Original</span>
								<img id="ocr-distance-original" src="uploads/debug/distance_00_original.jpg" alt="Distance Original" onerror="this.parentElement.classList.add('no-image')">
							</div>
							<div class="ocr-image-card">
								<span class="ocr-image-label">Distance · Cropped</span>
								<img id="ocr-distance-cropped" src="uploads/debug/distance_01_cropped.jpg" alt="Distance Cropped" onerror="this.parentElement.classList.add('no-image')">
							</div>
							<div class="ocr-image-card">
								<span class="ocr-image-label">Distance · Processed</span>
								<img id="ocr-distance-processed" src="uploads/debug/distance_02_processed.jpg" alt="Distance Processed" onerror="this.parentElement.classList.add('no-image')">
							</div>
						</div>
					</details>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>

<input type="hidden" id="session-id" value="<?= $session_id ?>">
<input type="hidden" id="session-edited" value="<?= $edited ?>">

<div class="edit-overlay" id="edit-overlay" onclick="closeEdit()"></div>
<div class="edit-modal" id="edit-modal">
	<div class="edit-modal-header">
		<h3 id="edit-modal-title">Edit</h3>
		<button class="edit-modal-close" onclick="closeEdit()">
			<i class="fas fa-times"></i>
		</button>
	</div>
	<div class="edit-modal-body">
		<label class="edit-label" id="edit-label">Nilai</label>
		<input type="text" class="edit-input" id="edit-input" placeholder="Masukkan nilai baru">
	</div>
	<div class="edit-modal-footer">
		<button class="btn-cancel" onclick="closeEdit()">Batal</button>
		<button class="btn-save" onclick="saveEdit()"><i class="fas fa-check"></i> Simpan</button>
	</div>
</div>

<div class="footer">
	<p>&copy; 2026 Telkom Indonesia. All Rights Reserved.</p>
</div>

<script>
var editTarget = null;
var isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
var currentUid = <?= json_encode($activeUid) ?>;

function openEdit(field) {
  editTarget = field;
  var modal = document.getElementById('edit-modal');
  var overlay = document.getElementById('edit-overlay');
  var title = document.getElementById('edit-modal-title');
  var label = document.getElementById('edit-label');
  var input = document.getElementById('edit-input');

  if (field === 'waktu') {
	title.textContent = 'Edit Waktu Treadmill';
	label.textContent = 'Waktu (contoh: 0:38)';
	input.value = document.getElementById('timer-value').textContent.trim();
	input.placeholder = '0:38';
  } else {
	title.textContent = 'Edit Jarak Tempuh';
	label.textContent = 'Jarak (contoh: 4.2 km)';
	input.value = document.getElementById('distance-value').textContent.trim();
	input.placeholder = '4.2 km';
  }

  overlay.classList.add('active');
  modal.classList.add('active');
  input.focus();
}

function closeEdit() {
  document.getElementById('edit-modal').classList.remove('active');
  document.getElementById('edit-overlay').classList.remove('active');
  editTarget = null;
}

function saveEdit() {
  var val = document.getElementById('edit-input').value.trim();
  if (!val) return;

  if (editTarget === 'waktu') {
	hasManualTimeEdit = true;
  } else if (editTarget === 'jarak') {
	hasManualDistanceEdit = true;
  }

  var sessionId = document.getElementById('session-id').value;
  if (!sessionId || Number(sessionId) <= 0) {
    if (editTarget === 'waktu') {
	  document.getElementById('timer-value').textContent = val;
	  document.getElementById('ocr-time-inline').textContent = val;
	} else {
	  document.getElementById('distance-value').textContent = val;
	  document.getElementById('ocr-distance-inline').textContent = val;
	}
	updateCaloriesDisplay();
	setEditButtonsDisabled(false);
	closeEdit();
	return;
  }
  var dbField = (editTarget === 'waktu') ? 'time_value' : 'distance';

	fetch('endpoints/update.php', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json' },
	body: JSON.stringify({ id: parseInt(sessionId, 10), field: dbField, value: val })
  })
  .then(function(r) { return r.json(); })
  .then(function(res) {
	if (res.status === 'ok') {
	  if (editTarget === 'waktu') {
		document.getElementById('timer-value').textContent = val;
		document.getElementById('ocr-time-inline').textContent = val;
	  } else {
		document.getElementById('distance-value').textContent = val;
		document.getElementById('ocr-distance-inline').textContent = val;
	  }
	  if (typeof res.calories !== 'undefined') {
		document.getElementById('calories-value').textContent = String(res.calories) + ' kcal';
	  } else {
		updateCaloriesDisplay();
	  }
	  document.getElementById('session-edited').value = '1';
	  closeEdit();
	} else {
	  alert('Gagal menyimpan: ' + (res.message || 'Error'));
	}
  })
  .catch(function() {
	alert('Gagal menghubungi server');
  });
}

function watchDeviceState() {
	fetch('endpoints/device_state.php')
		.then(function(r) { return r.json(); })
		.then(function(state) {
			if (state.status !== 'ok') return;
			var changed = (Boolean(state.is_logged_in) !== isLoggedIn) || (state.active_uid !== currentUid);
			if (changed) {
				location.reload();
			}
		})
		.catch(function() {});
}

document.getElementById('edit-input').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') saveEdit();
  if (e.key === 'Escape') closeEdit();
});

var bulanIndo = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
var treadmillBase = 'uploads/';
var latestOcrTimestamp = null;
var lastImageToken = '';
var lastAutoSavedTimestamp = null;
var hasManualTimeEdit = false;
var hasManualDistanceEdit = false;
var previousProcessingState = null;

function setEditButtonsDisabled(disabled) {
	var buttons = document.querySelectorAll('.btn-edit');
	buttons.forEach(function(btn) {
		btn.disabled = disabled;
	});
}

function updateCardImage(id, src) {
	var el = document.getElementById(id);
	if (!el) return;
	var card = el.parentElement;
	if (card) card.classList.remove('no-image');
	el.src = src;
}

function clearCardImage(id) {
	var el = document.getElementById(id);
	if (!el) return;
	var card = el.parentElement;
	if (card) card.classList.add('no-image');
	el.removeAttribute('src');
}

function resetDashboardForNewCapture() {
	document.getElementById('timer-value').textContent = '0:00';
	document.getElementById('distance-value').textContent = '0 km';
	document.getElementById('ocr-time-inline').textContent = '0:00';
	document.getElementById('ocr-distance-inline').textContent = '0 km';
	document.getElementById('calories-value').textContent = '0 kcal';
	document.getElementById('ocr-raw').textContent = '—';
	document.getElementById('timer-date').textContent = '—';
	document.getElementById('timer-time').textContent = 'Mulai: —';

	var loadingEl = document.getElementById('ocr-loading');
	var loadingTextEl = document.getElementById('ocr-loading-text');
	if (loadingEl) loadingEl.dataset.state = 'idle';
	if (loadingTextEl) loadingTextEl.textContent = 'Menunggu foto baru';

	clearCardImage('live-img-time');
	clearCardImage('live-img-distance');
	clearCardImage('ocr-time-original');
	clearCardImage('ocr-time-cropped');
	clearCardImage('ocr-time-processed');
	clearCardImage('ocr-distance-original');
	clearCardImage('ocr-distance-cropped');
	clearCardImage('ocr-distance-processed');

	latestOcrTimestamp = null;
	lastImageToken = '';
	lastAutoSavedTimestamp = null;
	previousProcessingState = null;
	hasManualTimeEdit = false;
	hasManualDistanceEdit = false;
	document.getElementById('session-id').value = '0';
	document.getElementById('session-edited').value = '0';
	setEditButtonsDisabled(true);
}

function parseProcessingFlag(value) {
	if (value === true || value === 1 || value === '1') return true;
	if (typeof value === 'string') {
		var v = value.trim().toLowerCase();
		return v === 'true' || v === 'yes' || v === 'on';
	}
	return false;
}

function parseDurationMinutes(value) {
	if (!value) return 0;
	var text = String(value).trim();
	var hms = text.match(/^(?:(\d+):)?(\d{1,2}):(\d{2})$/);
	if (hms) {
		var h = hms[1] ? parseInt(hms[1], 10) : 0;
		var m = parseInt(hms[2], 10);
		var s = parseInt(hms[3], 10);
		return (h * 60) + m + (s / 60);
	}
	var num = parseFloat(text.replace(',', '.'));
	return isNaN(num) ? 0 : num;
}

function parseDistanceKm(value) {
	if (!value) return 0;
	var text = String(value).trim();
	var match = text.match(/(\d+(?:[\.,]\d+)?)/);
	if (!match) return 0;
	var num = parseFloat(match[1].replace(',', '.'));
	return isNaN(num) ? 0 : num;
}

function calculateCalories(durationMinutes, distanceKm) {
	var timeCalories = durationMinutes > 0 ? durationMinutes * 8 : 0;
	var distanceCalories = distanceKm > 0 ? distanceKm * 60 : 0;
	if (timeCalories > 0 && distanceCalories > 0) {
		return Math.round((timeCalories + distanceCalories) / 2);
	}
	if (distanceCalories > 0) return Math.round(distanceCalories);
	if (timeCalories > 0) return Math.round(timeCalories);
	return 0;
}

function updateCaloriesDisplay() {
	var timeVal = document.getElementById('timer-value');
	var distVal = document.getElementById('distance-value');
	var caloriesEl = document.getElementById('calories-value');
	if (!timeVal || !distVal || !caloriesEl) return;
	var minutes = parseDurationMinutes(timeVal.textContent || '0:00');
	var distance = parseDistanceKm(distVal.textContent || '0 km');
	var calories = calculateCalories(minutes, distance);
	caloriesEl.textContent = calories + ' kcal';
}

function refreshPreviewImages() {
	if (!isLoggedIn) return;
}

function formatTimestamp(ts) {
  if (!ts) return { tanggal: '—', jam: '—' };
  var d = new Date(ts.replace(' ', 'T'));
  if (isNaN(d)) return { tanggal: '—', jam: '—' };
  var tgl = d.getDate() + ' ' + bulanIndo[d.getMonth() + 1] + ' ' + d.getFullYear();
  var h = String(d.getHours()).padStart(2, '0');
  var m = String(d.getMinutes()).padStart(2, '0');
  return { tanggal: tgl, jam: 'Mulai: ' + h + ':' + m + ' WIB' };
}

function updateTimer() {
	if (!isLoggedIn) {
	  return;
	}

	fetch('endpoints/ocr_preview.php')
	.then(function(r) { return r.json(); })
	.then(function(data) {
	  var loadingEl = document.getElementById('ocr-loading');
	  var loadingTextEl = document.getElementById('ocr-loading-text');
	  var isProcessing = parseProcessingFlag(data.processing);
	  var justFinishedProcessing = (previousProcessingState === true && isProcessing === false);
	  if (loadingEl) {
		loadingEl.hidden = false;
		loadingEl.dataset.state = isProcessing ? 'processing' : 'idle';
	  }
	  if (loadingTextEl) {
		if (data.processing_message) {
			loadingTextEl.textContent = data.processing_message;
		} else {
			loadingTextEl.textContent = isProcessing ? 'OCR sedang diproses...' : 'OCR selesai';
		}
	  }

	  if (data.status === 'ok' || data.status === 'empty') {
		var hasNewResult = Boolean(data.timestamp) && data.timestamp !== latestOcrTimestamp;
		if (hasNewResult) {
		  latestOcrTimestamp = data.timestamp;
		  hasManualTimeEdit = false;
		  hasManualDistanceEdit = false;
		  document.getElementById('ocr-time-inline').textContent = data.time_value || '0:00';
		  document.getElementById('ocr-distance-inline').textContent = data.distance || '0 km';
		  document.getElementById('timer-value').textContent = data.time_value || '0:00';
		  document.getElementById('distance-value').textContent = data.distance || '0 km';
		  updateCaloriesDisplay();
		}

		if (!latestOcrTimestamp && data.status === 'empty') {
		  document.getElementById('ocr-time-inline').textContent = '0:00';
		  document.getElementById('ocr-distance-inline').textContent = '0 km';
		  document.getElementById('timer-value').textContent = '0:00';
		  document.getElementById('distance-value').textContent = '0 km';
		  updateCaloriesDisplay();
		}

		if (data.raw_ocr) {
		  document.getElementById('ocr-raw').textContent = data.raw_ocr;
		} else if (isProcessing) {
		  document.getElementById('ocr-raw').textContent = data.processing_message || 'OCR sedang diproses...';
		}

		if (data.timestamp) {
		  var fmt = formatTimestamp(data.timestamp);
		  document.getElementById('timer-date').textContent = fmt.tanggal;
		  document.getElementById('timer-time').textContent = fmt.jam;
		}

		if (justFinishedProcessing && data.status === 'ok' && data.timestamp && data.time_value !== 'ERROR' && data.timestamp !== lastAutoSavedTimestamp) {
		  lastAutoSavedTimestamp = data.timestamp;
		  saveToHistory(true);
		}

		var token = String(data.timestamp || '') + '|' + String(data.processing_updated_at || '') + '|' + String(data.processing ? '1' : '0');
		if (token !== lastImageToken) {
		  lastImageToken = token;
		  var cacheBust = '?t=' + Date.now();
		  updateCardImage('live-img-time', treadmillBase + 'latest_time.jpg' + cacheBust);
		  updateCardImage('live-img-distance', treadmillBase + 'latest_distance.jpg' + cacheBust);
		  updateCardImage('ocr-time-original', treadmillBase + 'debug/time_00_original.jpg' + cacheBust);
		  updateCardImage('ocr-time-cropped', treadmillBase + 'debug/time_01_cropped.jpg' + cacheBust);
		  updateCardImage('ocr-time-processed', treadmillBase + 'debug/time_02_processed.jpg' + cacheBust);
		  updateCardImage('ocr-distance-original', treadmillBase + 'debug/distance_00_original.jpg' + cacheBust);
		  updateCardImage('ocr-distance-cropped', treadmillBase + 'debug/distance_01_cropped.jpg' + cacheBust);
		  updateCardImage('ocr-distance-processed', treadmillBase + 'debug/distance_02_processed.jpg' + cacheBust);
		}

		previousProcessingState = isProcessing;
	  }
	})
	.catch(function() {
	  var timer = document.getElementById('timer-value');
	  if (timer) timer.textContent = 'Offline';
	});
}

function saveToHistory(isAuto) {
	var btn = document.getElementById('save-history-btn');
	var msg = document.getElementById('save-history-msg');
	if (!btn || !msg) return;
	isAuto = (typeof isAuto === 'boolean') ? isAuto : false;

	btn.disabled = true;
	msg.textContent = isAuto ? 'Menyimpan otomatis...' : 'Menyimpan...';

	var payload = {
		time_value: document.getElementById('timer-value').textContent.trim(),
		distance: document.getElementById('distance-value').textContent.trim(),
		raw_ocr: document.getElementById('ocr-raw').textContent.trim(),
		timestamp_api: latestOcrTimestamp,
		time_edited: hasManualTimeEdit,
		distance_edited: hasManualDistanceEdit,
		save_source: isAuto ? 'auto' : 'manual'
	};

	fetch('endpoints/save_session.php', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(payload)
	})
	.then(function(r) { return r.json(); })
	.then(function(res) {
		if (res.status === 'ok') {
			msg.textContent = isAuto ? 'OCR selesai, otomatis tersimpan ke riwayat.' : 'Tersimpan ke riwayat.';
			document.getElementById('session-id').value = res.id || 0;
			if (typeof res.calories !== 'undefined') {
				document.getElementById('calories-value').textContent = String(res.calories) + ' kcal';
			}
			setEditButtonsDisabled(false);

			if (!isAuto) {
				fetch('endpoints/reset_capture_cache.php', {
					method: 'POST'
				})
				.then(function(r) { return r.json(); })
				.then(function(resetRes) {
					if (resetRes.status === 'ok') {
						resetDashboardForNewCapture();
						msg.textContent = 'Tersimpan. Dashboard direset, siap mulai lagi.';
					} else {
						msg.textContent = 'Data tersimpan, tapi reset dashboard gagal.';
					}
				})
				.catch(function() {
					msg.textContent = 'Data tersimpan, tapi reset dashboard gagal.';
				});
			}
		} else {
			msg.textContent = res.message || 'Gagal menyimpan.';
		}
	})
	.catch(function() {
		msg.textContent = 'Server tidak merespons.';
	})
	.finally(function() {
		btn.disabled = false;
	});
}

var saveHistoryBtn = document.getElementById('save-history-btn');
if (saveHistoryBtn) {
	saveHistoryBtn.addEventListener('click', function() { saveToHistory(false); });
}

setEditButtonsDisabled(Number(document.getElementById('session-id').value || '0') <= 0);
updateCaloriesDisplay();

updateTimer();
setInterval(updateTimer, 2000);
setInterval(watchDeviceState, 2000);
</script>

</body>
</html>
