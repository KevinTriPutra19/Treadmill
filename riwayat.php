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
	$stmt = $conn->prepare("SELECT id, time_value, distance, calories, timestamp_api, edited, is_time_edited, is_distance_edited FROM treadmill_sessions WHERE rfid_uid = ? ORDER BY id DESC");
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
			'calories' => number_format($calories, 0, ',', '.'),
			'distance' => $distanceKm > 0 ? number_format($distanceKm, 1, ',', '.') . ' km' : '—',
			'edited' => (int) $row['edited'],
			'is_time_edited' => (int) ($row['is_time_edited'] ?? 0),
			'is_distance_edited' => (int) ($row['is_distance_edited'] ?? 0),
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
					</tr>
				</thead>
				<tbody>
					<?php if (count($sessions) > 0): ?>
						<?php foreach ($sessions as $session): ?>
							<tr>
								<td><?= htmlspecialchars($session['date']) ?></td>
								<td><?= htmlspecialchars($session['time']) ?></td>
								<td><?= htmlspecialchars($session['duration']) ?></td>
								<td><?= htmlspecialchars($session['calories']) ?> kcal</td>
								<td><?= htmlspecialchars($session['distance']) ?></td>
								<td>
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
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="6">
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

<script>
var historyIsLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
var historyUid = <?= json_encode($activeUid) ?>;

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