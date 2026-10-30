<?php
declare(strict_types=1);

/*
 * Derived listening signals.
 *
 * Events are the append-only record; these keys are the 100+ numbers and names
 * the panel and the recommendation query read. Rebuilding is cheap at the
 * volume one listener produces, so it runs after each batch instead of trying
 * to maintain counters incrementally.
 */

/** Store one signal: numeric in `num`, text in `txt`. */
function signal_set(array &$signals, string $key, float|int|null $num = null, ?string $text = null): void
{
    $signals[$key] = [$num === null ? null : (float) $num, $text];
}

function signals_recompute(int $userId): int
{
    $pdo = db();
    $userStmt = $pdo->prepare('SELECT created_at, last_seen_at, status, plan, email_verified FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        return 0;
    }

    $signals = [];
    $q = static function (string $sql, int $id) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    };

    $totals = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_events,
            COALESCE(SUM(type = 'play'), 0) AS total_plays,
            COALESCE(SUM(type = 'complete'), 0) AS total_completes,
            COALESCE(SUM(type = 'skip'), 0) AS total_skips,
            COALESCE(SUM(type = 'like'), 0) AS total_likes,
            COALESCE(SUM(type = 'dislike'), 0) AS total_dislikes,
            COALESCE(SUM(type = 'unlike'), 0) AS total_unlikes,
            COALESCE(SUM(type = 'search'), 0) AS total_searches,
            COALESCE(SUM(type = 'download'), 0) AS total_downloads,
            COALESCE(SUM(type = 'share'), 0) AS total_shares,
            COALESCE(SUM(type = 'playlist_add'), 0) AS total_playlist_adds,
            COALESCE(SUM(CASE WHEN type = 'play' THEN listen_ms ELSE 0 END), 0) AS listen_ms_total,
            COUNT(DISTINCT CASE WHEN type = 'play' AND track_id <> '' THEN track_id END) AS unique_tracks,
            COUNT(DISTINCT CASE WHEN type = 'play' AND artist <> '' THEN artist END) AS unique_artists,
            COUNT(DISTINCT CASE WHEN type = 'play' AND album <> '' THEN album END) AS unique_albums,
            COUNT(DISTINCT CASE WHEN type = 'play' AND genre <> '' THEN genre END) AS unique_genres,
            COUNT(DISTINCT DATE(created_at)) AS days_active
         FROM events WHERE user_id = ?"
    );
    $totals->execute([$userId]);
    $t = $totals->fetch() ?: [];

    $plays = (int) ($t['total_plays'] ?? 0);
    $completes = (int) ($t['total_completes'] ?? 0);
    $skips = (int) ($t['total_skips'] ?? 0);
    $likes = (int) ($t['total_likes'] ?? 0);
    $dislikes = (int) ($t['total_dislikes'] ?? 0);
    $searches = (int) ($t['total_searches'] ?? 0);
    $listenMs = (int) ($t['listen_ms_total'] ?? 0);
    $uniqueTracks = (int) ($t['unique_tracks'] ?? 0);
    $ratio = static fn (float|int $n, float|int $d): float => $d > 0 ? $n / $d : 0.0;

    signal_set($signals, 'total_events', (int) ($t['total_events'] ?? 0));
    signal_set($signals, 'total_plays', $plays);
    signal_set($signals, 'total_completes', $completes);
    signal_set($signals, 'total_skips', $skips);
    signal_set($signals, 'total_likes', $likes);
    signal_set($signals, 'total_dislikes', $dislikes);
    signal_set($signals, 'total_unlikes', (int) ($t['total_unlikes'] ?? 0));
    signal_set($signals, 'total_searches', $searches);
    signal_set($signals, 'total_downloads', (int) ($t['total_downloads'] ?? 0));
    signal_set($signals, 'total_shares', (int) ($t['total_shares'] ?? 0));
    signal_set($signals, 'total_playlist_adds', (int) ($t['total_playlist_adds'] ?? 0));
    signal_set($signals, 'total_ratings', $likes + $dislikes);

    signal_set($signals, 'listen_ms_total', $listenMs);
    signal_set($signals, 'listen_minutes_total', round($listenMs / 60000));
    signal_set($signals, 'avg_listen_ms', $plays > 0 ? (int) round($listenMs / $plays) : 0);
    signal_set($signals, 'avg_listen_minutes', $plays > 0 ? round($listenMs / $plays / 60000, 2) : 0);

    foreach ([['1d', 1], ['7d', 7], ['30d', 30]] as [$label, $days]) {
        $row = $q(
            "SELECT COALESCE(SUM(type = 'play'), 0) AS plays,
                    COALESCE(SUM(CASE WHEN type = 'play' THEN listen_ms ELSE 0 END), 0) AS ms
               FROM events WHERE user_id = ? AND created_at >= NOW() - INTERVAL {$days} DAY",
            $userId
        )[0] ?? [];
        signal_set($signals, 'plays_' . $label, (int) ($row['plays'] ?? 0));
        signal_set($signals, 'listen_ms_' . $label, (int) ($row['ms'] ?? 0));
        signal_set($signals, 'listen_minutes_' . $label, round((int) ($row['ms'] ?? 0) / 60000));
    }

    signal_set($signals, 'unique_tracks', $uniqueTracks);
    signal_set($signals, 'unique_artists', (int) ($t['unique_artists'] ?? 0));
    signal_set($signals, 'unique_albums', (int) ($t['unique_albums'] ?? 0));
    signal_set($signals, 'unique_genres', (int) ($t['unique_genres'] ?? 0));
    signal_set($signals, 'days_active', (int) ($t['days_active'] ?? 0));

    signal_set($signals, 'completion_rate', round($ratio($completes, $plays), 4));
    signal_set($signals, 'skip_rate', round($ratio($skips, $plays + $skips), 4));
    signal_set($signals, 'like_rate', round($ratio($likes, $plays), 4));
    signal_set($signals, 'dislike_rate', round($ratio($dislikes, $plays), 4));
    signal_set($signals, 'like_to_dislike_ratio', round($ratio($likes, max(1, $dislikes)), 4));
    signal_set($signals, 'repeat_play_ratio', round($ratio(max(0, $plays - $uniqueTracks), $plays), 4));
    signal_set($signals, 'likes_per_100_plays', round($ratio($likes * 100, $plays), 2));
    signal_set($signals, 'dislikes_per_100_plays', round($ratio($dislikes * 100, $plays), 2));
    signal_set($signals, 'searches_per_100_plays', round($ratio($searches * 100, $plays), 2));
    signal_set($signals, 'downloads_per_100_plays', round($ratio((int) ($t['total_downloads'] ?? 0) * 100, $plays), 2));
    signal_set($signals, 'shares_per_100_plays', round($ratio((int) ($t['total_shares'] ?? 0) * 100, $plays), 2));
    signal_set($signals, 'playlist_adds_per_100_plays', round($ratio((int) ($t['total_playlist_adds'] ?? 0) * 100, $plays), 2));
    signal_set($signals, 'engagement_score', round(
        $ratio($plays, max(1, $plays + $skips)) * 0.4
        + $ratio($likes, max(1, $likes + $dislikes)) * 0.35
        + $ratio($completes, max(1, $plays)) * 0.25,
        4
    ));

    // Hour and weekday distribution. WEEKDAY() is Monday = 0.
    $hours = array_fill(0, 24, 0);
    foreach ($q("SELECT HOUR(created_at) AS h, COUNT(*) AS c FROM events WHERE user_id = ? AND type = 'play' GROUP BY HOUR(created_at)", $userId) as $row) {
        $hours[(int) $row['h']] = (int) $row['c'];
    }
    $weekdays = array_fill(0, 7, 0);
    foreach ($q("SELECT WEEKDAY(created_at) AS d, COUNT(*) AS c FROM events WHERE user_id = ? AND type = 'play' GROUP BY WEEKDAY(created_at)", $userId) as $row) {
        $weekdays[(int) $row['d']] = (int) $row['c'];
    }
    $topHour = 0;
    $topWeekday = 0;
    for ($i = 0; $i < 24; $i++) {
        signal_set($signals, 'plays_hour_' . $i, $hours[$i]);
        if ($hours[$i] > $hours[$topHour]) {
            $topHour = $i;
        }
    }
    for ($i = 0; $i < 7; $i++) {
        signal_set($signals, 'plays_weekday_' . $i, $weekdays[$i]);
        if ($weekdays[$i] > $weekdays[$topWeekday]) {
            $topWeekday = $i;
        }
    }
    $hourSum = array_sum($hours) ?: 1;
    $weekSum = array_sum($weekdays) ?: 1;
    signal_set($signals, 'top_hour', $topHour);
    signal_set($signals, 'top_hour_plays', $hours[$topHour]);
    signal_set($signals, 'top_weekday', $topWeekday);
    signal_set($signals, 'top_weekday_plays', $weekdays[$topWeekday]);
    signal_set($signals, 'night_owl_ratio', round(($hours[0] + $hours[1] + $hours[2] + $hours[3] + $hours[4] + $hours[5]) / $hourSum, 4));
    signal_set($signals, 'morning_ratio', round(($hours[6] + $hours[7] + $hours[8] + $hours[9] + $hours[10] + $hours[11]) / $hourSum, 4));
    signal_set($signals, 'afternoon_ratio', round(($hours[12] + $hours[13] + $hours[14] + $hours[15] + $hours[16] + $hours[17]) / $hourSum, 4));
    signal_set($signals, 'evening_ratio', round(($hours[18] + $hours[19] + $hours[20] + $hours[21] + $hours[22] + $hours[23]) / $hourSum, 4));
    signal_set($signals, 'weekend_ratio', round(($weekdays[5] + $weekdays[6]) / $weekSum, 4));

    // Listening streaks, measured in days with at least one event.
    $dates = [];
    foreach ($q('SELECT DISTINCT DATE(created_at) AS d FROM events WHERE user_id = ? ORDER BY d DESC LIMIT 400', $userId) as $row) {
        $dates[] = (string) $row['d'];
    }
    $currentStreak = 0;
    $longestStreak = 0;
    $run = 0;
    $previous = null;
    foreach ($dates as $date) {
        $ts = strtotime($date);
        if ($previous !== null && $previous - $ts === 86400) {
            $run++;
        } else {
            $run = 1;
        }
        $longestStreak = max($longestStreak, $run);
        $previous = $ts;
    }
    if ($dates) {
        $today = strtotime(date('Y-m-d'));
        $first = strtotime($dates[0]);
        if ($first === $today || $first === $today - 86400) {
            $currentStreak = 1;
            $prev = $first;
            foreach (array_slice($dates, 1) as $date) {
                $ts = strtotime($date);
                if ($prev - $ts === 86400) {
                    $currentStreak++;
                    $prev = $ts;
                } else {
                    break;
                }
            }
        }
    }
    signal_set($signals, 'current_streak_days', $currentStreak);
    signal_set($signals, 'longest_streak_days', $longestStreak);

    // Top genres / artists / albums / tracks, ten each with their play counts.
    $tops = [
        'genre' => 'top_genre',
        'artist' => 'top_artist',
        'album' => 'top_album',
        'title' => 'top_track',
    ];
    foreach ($tops as $column => $prefix) {
        $rows = $q(
            "SELECT `{$column}` AS name, COUNT(*) AS plays
               FROM events
              WHERE user_id = ? AND type = 'play' AND `{$column}` <> ''
              GROUP BY `{$column}`
              ORDER BY plays DESC, name ASC
              LIMIT 10",
            $userId
        );
        for ($i = 1; $i <= 10; $i++) {
            $row = $rows[$i - 1] ?? null;
            signal_set($signals, $prefix . '_' . $i, null, $row ? (string) $row['name'] : null);
            signal_set($signals, $prefix . '_plays_' . $i, $row ? (int) $row['plays'] : 0);
        }
    }

    // Device and account context, so a profile row reads on its own.
    $devices = $q('SELECT model, app_version, sdk, last_seen FROM devices WHERE user_id = ? ORDER BY last_seen DESC', $userId);
    signal_set($signals, 'device_count', count($devices));
    signal_set($signals, 'app_version', null, $devices[0]['app_version'] ?? null);
    signal_set($signals, 'sdk', null, $devices[0]['sdk'] ?? null);
    signal_set($signals, 'device_models', null, implode(', ', array_slice(array_column($devices, 'model'), 0, 5)));

    $created = strtotime((string) $user['created_at']);
    $lastSeen = $user['last_seen_at'] ? strtotime((string) $user['last_seen_at']) : null;
    signal_set($signals, 'account_age_days', $created ? (int) floor((time() - $created) / 86400) : 0);
    signal_set($signals, 'days_since_last_seen', $lastSeen ? (int) floor((time() - $lastSeen) / 86400) : 0);
    signal_set($signals, 'email_verified', (int) ($user['email_verified'] ?? 0));
    signal_set($signals, 'plan', null, (string) ($user['plan'] ?? 'free'));
    signal_set($signals, 'status', null, (string) ($user['status'] ?? 'active'));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO user_signals (user_id, signal_key, num, txt, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE num = VALUES(num), txt = VALUES(txt), updated_at = VALUES(updated_at)'
        );
        $stamp = now();
        foreach ($signals as $key => [$num, $text]) {
            $stmt->execute([$userId, $key, $num, $text, $stamp]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return count($signals);
}

/** @return list<array{signal_key:string,num:?float,txt:?string}> */
function signals_fetch(int $userId): array
{
    $stmt = db()->prepare('SELECT signal_key, num, txt, updated_at FROM user_signals WHERE user_id = ? ORDER BY signal_key');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
