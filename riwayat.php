<?php
require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/schema.php';

ensure_app_schema($conn);

$state = get_device_state($conn);
$activeUid = $state['active_uid'] ?? null;
$isLoggedIn = $activeUid !== null;
$memberName = null;
if ($isLoggedIn) {
	$member = get_member_by_uid($conn, $activeUid);
	$memberName = $member['full_name'] ?? ('User ' . substr((string) $activeUid, 0, 6));
}

$sessions = [];
$totals = [
	'duration' => 0.0,
	'distance' => 0.0,
	'calories' => 0,
	'count' => 0,
];


if ($isLoggedIn) {
	$stmt = $conn->prepare("SELECT id, time_value, distance, calories, timestamp_api, edited, is_time_edited, is_distance_edited, image_distance_path, image_path FROM treadmill_sessions WHERE rfid_uid = ? ORDER BY id DESC");
	$stmt->bind_param('s', $activeUid);
	$stmt->execute();
	$result = $stmt->get_result();

	while ($row = $result->fetch_assoc()) {
		$minutes = parse_duration_minutes($row['time_value'] ?? null);
		$distanceKm = parse_distance_km($row['distance'] ?? null);
		$storedCalories = (int) ($row['calories'] ?? 0);
		$calories = $storedCalories > 0 ? $storedCalories : estimate_calories($minutes, $distanceKm);

		$totals['duration'] += $minutes;
		$totals['distance'] += $distanceKm;
		$totals['calories'] += $calories;
		$totals['count']++;

		$timestamp = $row['timestamp_api'] ?? null;
		$dateLabel = '—';
		$timeLabel = '—';
		if ($timestamp) {
			$dt = new DateTime($timestamp);
			$dateLabel = $dt->format('d-m-Y');
			$timeLabel = $dt->format('H:i');
		}

		$sessions[] = [
			'id' => (int) $row['id'],
			'date' => $dateLabel,
			'time' => $timeLabel,
			'duration' => format_duration_readable($minutes),
			'duration_raw' => $row['time_value'] ?? '0:00',
			'calories' => number_format($calories, 0, ',', '.'),
			'distance' => $distanceKm > 0 ? number_format($distanceKm, 1, ',', '.') . ' km' : '—',
			'distance_raw' => $row['distance'] ?? '0 km',
			'edited' => (int) $row['edited'],
			'is_time_edited' => (int) ($row['is_time_edited'] ?? 0),
			'is_distance_edited' => (int) ($row['is_distance_edited'] ?? 0),
			'image_distance_path' => $row['image_distance_path'] ?? null,
			'image_path' => $row['image_path'] ?? null,
		];
	}

	$stmt->close();
}

$conn->close();

function history_badge_class($edited)
{
	return $edited ? 'badge-edited' : 'badge-live';
}
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
<body class="history-page">

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
			<a class="nav-pill" href="index.php"><i class="fas fa-house"></i><span>Dashboard</span></a>
			<a class="nav-pill is-active" href="riwayat.php"><i class="fas fa-clipboard-list"></i><span>Riwayat</span></a>
		</div>
	</div>
</div>

<div class="container">
	<?php if ($isLoggedIn): ?>
	<div class="history-user-banner">
		<div><strong><?= htmlspecialchars($memberName) ?></strong></div>
		<div>UID: <?= htmlspecialchars((string) $activeUid) ?></div>
	</div>
	<?php else: ?>
	<div class="login-empty-card">
		<div class="login-empty-icon"><i class="fas fa-id-card"></i></div>
		<h2>Belum Ada User Login</h2>
		<p>Halaman riwayat akan menampilkan data berdasarkan RFID user yang sedang login.</p>
	</div>
	<?php endif; ?>

	<?php if ($isLoggedIn): ?>
	<div class="summary-grid">
		<div class="summary-card">
			<span class="summary-label">Total Durasi</span>
			<div class="summary-value"><?= htmlspecialchars(format_duration_readable($totals['duration'])) ?></div>
			<p>Akumulasi semua sesi latihan</p>
		</div>
		<div class="summary-card">
			<span class="summary-label">Kalori Terbakar</span>
			<div class="summary-value"><?= number_format($totals['calories'], 0, ',', '.') ?></div>
			<p>Estimasi total seluruh sesi</p>
		</div>
		<div class="summary-card">
			<span class="summary-label">Total Jarak</span>
			<div class="summary-value"><?= number_format($totals['distance'], 1, ',', '.') ?></div>
			<p>Dalam satuan kilometer</p>
		</div>
	</div>

	<div class="history-card">
		<div class="section-title">
			<div class="title-left">
				<i class="fas fa-history"></i>
				<h2>Daftar Riwayat</h2>
			</div>
		</div>

		<div class="table-wrap">
			<table class="history-table">
				<thead>
					<tr>
						<th>Tanggal</th>
						<th>Jam</th>
						<th>Durasi</th>
						<th>Kalori</th>
						<th>Jarak</th>
						<th>Status</th>
						<th>Aksi</th>
					</tr>
				</thead>
				<tbody>
					<?php if (count($sessions) > 0): ?>
						<?php foreach ($sessions as $session): ?>
							<tr data-session-id="<?= (int) $session['id'] ?>">
								<td data-label="Tanggal"><?= htmlspecialchars($session['date']) ?></td>
								<td data-label="Jam"><?= htmlspecialchars($session['time']) ?></td>
								<td data-label="Durasi" class="cell-duration"><?= htmlspecialchars($session['duration']) ?></td>
								<td data-label="Kalori" class="cell-calories"><?= htmlspecialchars($session['calories']) ?> kcal</td>
								<td data-label="Jarak" class="cell-distance"><?= htmlspecialchars($session['distance']) ?></td>
								<td data-label="Status" class="cell-status">
									<?php
									$label = 'Live';
									if (!empty($session['edited'])) {
										$parts = [];
										if (!empty($session['is_time_edited'])) {
											$parts[] = 'Waktu';
										}
										if (!empty($session['is_distance_edited'])) {
											$parts[] = 'Jarak';
										}
										$label = count($parts) > 0 ? ('Manual (' . implode(', ', $parts) . ')') : 'Manual';
									}
									?>
									<span class="status-chip <?= history_badge_class($session['edited']) ?>"><?= htmlspecialchars($label) ?></span>
								</td>
								<td data-label="Aksi" class="actions-cell">
									<?php
									$hasImage = !empty($session['image_distance_path']) || !empty($session['image_path']);
									?>
									<div class="history-actions">
										<button type="button" class="action-btn action-view" data-action="view"
											data-id="<?= (int) $session['id'] ?>"
											data-image-distance="<?= htmlspecialchars((string) ($session['image_distance_path'] ?? '')) ?>"
											data-image="<?= htmlspecialchars((string) ($session['image_path'] ?? '')) ?>"
											<?= $hasImage ? '' : 'disabled' ?>
											>
											<i class="fas fa-image"></i><span>Lihat</span>
										</button>
										<button type="button" class="action-btn action-edit" data-action="edit"
											data-id="<?= (int) $session['id'] ?>"
											data-time="<?= htmlspecialchars((string) ($session['duration_raw'] ?? '0:00')) ?>"
											data-distance="<?= htmlspecialchars((string) ($session['distance_raw'] ?? '0 km')) ?>">
											<i class="fas fa-pen"></i><span>Edit</span>
										</button>
										<button type="button" class="action-btn action-delete" data-action="delete"
											data-id="<?= (int) $session['id'] ?>">
											<i class="fas fa-trash"></i><span>Hapus</span>
										</button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr class="is-empty">
							<td colspan="7">
								<div class="empty-state">
									<i class="fas fa-inbox"></i>
									<p>Belum ada data riwayat.</p>
								</div>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div class="history-note">
		Kalori dihitung sebagai estimasi dari data sesi yang tersimpan. Jika data jarak ada, estimasi memakai jarak; jika tidak, estimasi memakai durasi.
	</div>
	<?php endif; ?>
</div>

<div class="modal-overlay" id="history-modal-overlay"></div>

<div class="history-modal" id="history-edit-modal" role="dialog" aria-modal="true" aria-labelledby="history-edit-title">
	<div class="modal-header">
		<h3 class="modal-title" id="history-edit-title">Edit Riwayat</h3>
		<button type="button" class="modal-close" data-close="modal"><i class="fas fa-times"></i></button>
	</div>
	<div class="modal-body">
		<input type="hidden" id="history-edit-id" value="0">
		<div class="modal-field">
			<label for="history-edit-time">Waktu (contoh: 0:38)</label>
			<input type="text" id="history-edit-time" placeholder="0:38">
		</div>
		<div class="modal-field">
			<label for="history-edit-distance">Jarak (contoh: 4.2 km)</label>
			<input type="text" id="history-edit-distance" placeholder="4.2 km">
		</div>
	</div>
	<div class="modal-footer">
		<button type="button" class="btn-cancel" data-close="modal">Batal</button>
		<button type="button" class="btn-save" id="history-edit-save"><i class="fas fa-check"></i> Simpan</button>
	</div>
</div>

<div class="history-modal" id="history-delete-modal" role="dialog" aria-modal="true" aria-labelledby="history-delete-title">
	<div class="modal-header">
		<h3 class="modal-title" id="history-delete-title">Hapus Riwayat</h3>
		<button type="button" class="modal-close" data-close="modal"><i class="fas fa-times"></i></button>
	</div>
	<div class="modal-body">
		<p class="modal-text">Data riwayat yang dihapus tidak bisa dikembalikan. Yakin ingin menghapus?</p>
		<input type="hidden" id="history-delete-id" value="0">
	</div>
	<div class="modal-footer">
		<button type="button" class="btn-cancel" data-close="modal">Batal</button>
		<button type="button" class="btn-danger" id="history-delete-confirm"><i class="fas fa-trash"></i> Hapus</button>
	</div>
</div>

<div class="history-modal history-modal-wide" id="history-image-modal" role="dialog" aria-modal="true" aria-labelledby="history-image-title">
	<div class="modal-header">
		<h3 class="modal-title" id="history-image-title">Preview Gambar Sesi</h3>
		<button type="button" class="modal-close" data-close="modal"><i class="fas fa-times"></i></button>
	</div>
	<div class="modal-body">
		<div class="history-image-grid">
			<div class="history-image-card" id="history-image-distance-card">
				<span>Foto Jarak</span>
				<img id="history-image-distance" src="" alt="Foto jarak" loading="lazy">
			</div>
			<div class="history-image-card" id="history-image-generic-card">
				<span>Foto OCR</span>
				<img id="history-image-generic" src="" alt="Foto OCR" loading="lazy">
			</div>
		</div>
	</div>
</div>

<script>
var historyIsLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
var historyUid = <?= json_encode($activeUid) ?>;

var historyOverlay = document.getElementById('history-modal-overlay');
var editModal = document.getElementById('history-edit-modal');
var deleteModal = document.getElementById('history-delete-modal');
var imageModal = document.getElementById('history-image-modal');

function openHistoryModal(modal) {
	historyOverlay.classList.add('active');
	modal.classList.add('active');
}

function closeHistoryModals() {
	historyOverlay.classList.remove('active');
	[editModal, deleteModal, imageModal].forEach(function(modal) {
		modal.classList.remove('active');
	});
}

if (historyOverlay) {
	historyOverlay.addEventListener('click', closeHistoryModals);
}

document.querySelectorAll('[data-close="modal"]').forEach(function(btn) {
	btn.addEventListener('click', closeHistoryModals);
});

function resolveHistoryImage(path) {
	if (!path) {
		return '';
	}
	if (/^https?:\/\//i.test(path)) {
		return path;
	}
	return 'uploads/' + path.replace(/^\/+/, '');
}

function setImageCard(cardId, imgId, src) {
	var card = document.getElementById(cardId);
	var img = document.getElementById(imgId);
	if (!card || !img) {
		return;
	}
	if (src) {
		img.src = src;
		card.classList.remove('no-image');
	} else {
		img.removeAttribute('src');
		card.classList.add('no-image');
	}
}

var historyTable = document.querySelector('.history-table');
if (historyTable) {
	historyTable.addEventListener('click', function(event) {
		var button = event.target.closest('button[data-action]');
		if (!button) {
			return;
		}
		var action = button.getAttribute('data-action');
		var row = button.closest('tr');
		var sessionId = button.getAttribute('data-id') || (row ? row.getAttribute('data-session-id') : '0');

		if (action === 'edit') {
			document.getElementById('history-edit-id').value = sessionId;
			document.getElementById('history-edit-time').value = button.getAttribute('data-time') || '';
			document.getElementById('history-edit-distance').value = button.getAttribute('data-distance') || '';
			openHistoryModal(editModal);
			return;
		}

		if (action === 'delete') {
			document.getElementById('history-delete-id').value = sessionId;
			openHistoryModal(deleteModal);
			return;
		}

		if (action === 'view') {
			setImageCard('history-image-distance-card', 'history-image-distance', resolveHistoryImage(button.getAttribute('data-image-distance')));
			setImageCard('history-image-generic-card', 'history-image-generic', resolveHistoryImage(button.getAttribute('data-image')));
			openHistoryModal(imageModal);
		}
	});
}

var historyEditSave = document.getElementById('history-edit-save');
if (historyEditSave) {
	historyEditSave.addEventListener('click', function() {
		var sessionId = Number(document.getElementById('history-edit-id').value || 0);
		var timeValue = document.getElementById('history-edit-time').value.trim();
		var distanceValue = document.getElementById('history-edit-distance').value.trim();

		if (!sessionId || !timeValue || !distanceValue) {
			alert('Pastikan waktu dan jarak terisi.');
			return;
		}

		fetch('endpoints/update_session.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ id: sessionId, time_value: timeValue, distance: distanceValue })
		})
		.then(function(r) { return r.json(); })
		.then(function(res) {
			if (res.status !== 'ok') {
				alert('Gagal menyimpan: ' + (res.message || 'Error'));
				return;
			}
			var row = document.querySelector('tr[data-session-id="' + sessionId + '"]');
			if (row) {
				var durationCell = row.querySelector('.cell-duration');
				var distanceCell = row.querySelector('.cell-distance');
				var caloriesCell = row.querySelector('.cell-calories');
				var statusCell = row.querySelector('.cell-status');

				if (durationCell && res.duration_label) {
					durationCell.textContent = res.duration_label;
				}
				if (distanceCell && res.distance_label) {
					distanceCell.textContent = res.distance_label;
				}
				if (caloriesCell && res.calories_label) {
					caloriesCell.textContent = res.calories_label + ' kcal';
				}

				var editButton = row.querySelector('button[data-action="edit"]');
				if (editButton) {
					editButton.setAttribute('data-time', res.time_value || timeValue);
					editButton.setAttribute('data-distance', res.distance || distanceValue);
				}

				if (statusCell) {
					var parts = [];
					if (res.is_time_edited) {
						parts.push('Waktu');
					}
					if (res.is_distance_edited) {
						parts.push('Jarak');
					}
					var label = parts.length ? ('Manual (' + parts.join(', ') + ')') : 'Manual';
					statusCell.innerHTML = '<span class="status-chip badge-edited">' + label + '</span>';
				}
			}

			closeHistoryModals();
		})
		.catch(function() {
			alert('Gagal menghubungi server');
		});
	});
}

var deleteConfirm = document.getElementById('history-delete-confirm');
if (deleteConfirm) {
	deleteConfirm.addEventListener('click', function() {
		var sessionId = Number(document.getElementById('history-delete-id').value || 0);
		if (!sessionId) {
			return;
		}
		fetch('endpoints/delete_session.php', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ id: sessionId })
		})
		.then(function(r) { return r.json(); })
		.then(function(res) {
			if (res.status !== 'ok') {
				alert('Gagal menghapus: ' + (res.message || 'Error'));
				return;
			}
			var row = document.querySelector('tr[data-session-id="' + sessionId + '"]');
			if (row && row.parentElement) {
				row.parentElement.removeChild(row);
			}
			var tbody = document.querySelector('.history-table tbody');
			if (tbody && tbody.querySelectorAll('tr[data-session-id]').length === 0) {
				var emptyRow = document.createElement('tr');
				emptyRow.className = 'is-empty';
				emptyRow.innerHTML = '<td colspan="7"><div class="empty-state"><i class="fas fa-inbox"></i><p>Belum ada data riwayat.</p></div></td>';
				tbody.appendChild(emptyRow);
			}
			closeHistoryModals();
		})
		.catch(function() {
			alert('Gagal menghubungi server');
		});
	});
}

function watchHistoryState() {
	fetch('endpoints/device_state.php')
		.then(function(r) { return r.json(); })
		.then(function(state) {
			if (state.status !== 'ok') return;
			var changed = (Boolean(state.is_logged_in) !== historyIsLoggedIn) || (state.active_uid !== historyUid);
			if (changed) {
				location.reload();
			}
		})
		.catch(function() {});
}

setInterval(watchHistoryState, 2000);
</script>

</body>
</html>