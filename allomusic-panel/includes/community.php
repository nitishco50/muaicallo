<?php
declare(strict_types=1);

/*
 * Community and listening-history helpers.
 *
 * History is read from the `events` table the app has been filling since 1.2:
 * a "play" row with listen_ms is a play. Community rows are keyed by song_key,
 * which is the YouTube video id when there is one and a hashed local id
 * otherwise, so likes, bookmarks and comments follow a track across devices.
 */

function community_key(mixed $raw): string
{
    $key = trim((string) $raw);
    if ($key === '') {
        return '';
    }
    return substr($key, 0, 191);
}

function community_clamp_int(mixed $value, int $min, int $max, int $default = 0): int
{
    if (!is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int) $value));
}

function community_notify(int $userId, string $title, string $body): void
{
    if ($userId <= 0) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO notifications (user_id, title, body, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, substr($title, 0, 160), substr($body, 0, 2000), now()]);
}

function community_counts(string $songKey): array
{
    $pdo = db();
    $reaction = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN value = 1 THEN 1 ELSE 0 END), 0) AS likes,
            COALESCE(SUM(CASE WHEN value = -1 THEN 1 ELSE 0 END), 0) AS dislikes
         FROM song_reactions WHERE song_key = ?'
    );
    $reaction->execute([$songKey]);
    $reactions = $reaction->fetch() ?: ['likes' => 0, 'dislikes' => 0];

    $bookmarks = $pdo->prepare('SELECT COUNT(*) FROM song_bookmarks WHERE song_key = ?');
    $bookmarks->execute([$songKey]);
    $comments = $pdo->prepare(
        "SELECT COUNT(*) FROM song_comments WHERE song_key = ? AND status = 'visible'"
    );
    $comments->execute([$songKey]);

    return [
        'likes' => (int) $reactions['likes'],
        'dislikes' => (int) $reactions['dislikes'],
        'bookmarks' => (int) $bookmarks->fetchColumn(),
        'comments' => (int) $comments->fetchColumn(),
    ];
}

function community_my_song(int $userId, string $songKey): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT value FROM song_reactions WHERE user_id = ? AND song_key = ? LIMIT 1');
    $stmt->execute([$userId, $songKey]);
    $value = $stmt->fetchColumn();
    $my = is_numeric($value) ? ((int) $value === 1 ? 'like' : ((int) $value === -1 ? 'dislike' : '')) : '';

    $stmt = $pdo->prepare('SELECT 1 FROM song_bookmarks WHERE user_id = ? AND song_key = ? LIMIT 1');
    $stmt->execute([$userId, $songKey]);

    return ['reaction' => $my, 'bookmarked' => (bool) $stmt->fetchColumn()];
}

/** Latest title/artist/art we know for a song, falling back to what the caller sent. */
function community_song_meta(string $songKey, array $fallback = []): array
{
    $pdo = db();
    $meta = [
        'song_key' => $songKey,
        'title' => substr(trim((string) ($fallback['title'] ?? '')), 0, 255),
        'artist' => substr(trim((string) ($fallback['artist'] ?? '')), 0, 255),
        'art_url' => substr(trim((string) ($fallback['art_url'] ?? '')), 0, 512),
    ];

    $sources = [
        'SELECT title, artist, art_url FROM song_reactions WHERE song_key = ? AND (title <> "" OR art_url <> "") ORDER BY updated_at DESC LIMIT 1',
        'SELECT title, artist, art_url FROM song_bookmarks WHERE song_key = ? AND (title <> "" OR art_url <> "") ORDER BY created_at DESC LIMIT 1',
        'SELECT title, artist, "" AS art_url FROM song_comments WHERE song_key = ? AND status = "visible" ORDER BY created_at DESC LIMIT 1',
        'SELECT title, artist, art_url FROM events WHERE track_id = ? AND (title <> "" OR art_url <> "") ORDER BY id DESC LIMIT 1',
    ];
    foreach ($sources as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$songKey]);
            $row = $stmt->fetch();
            if (!$row) {
                continue;
            }
            if ($meta['title'] === '' && (string) $row['title'] !== '') {
                $meta['title'] = (string) $row['title'];
            }
            if ($meta['artist'] === '' && (string) $row['artist'] !== '') {
                $meta['artist'] = (string) $row['artist'];
            }
            if ($meta['art_url'] === '' && (string) $row['art_url'] !== '') {
                $meta['art_url'] = (string) $row['art_url'];
            }
            if ($meta['title'] !== '' && $meta['art_url'] !== '') {
                break;
            }
        } catch (Throwable $e) {
            // An older schema without one of these columns must not take the
            // endpoint down; the next source usually still knows the song.
        }
    }

    return $meta;
}

function community_song(int $userId, string $songKey, array $fallback = []): array
{
    $meta = community_song_meta($songKey, $fallback);
    $mine = community_my_song($userId, $songKey);
    return $meta + community_counts($songKey) + [
        'my_reaction' => $mine['reaction'],
        'bookmarked' => $mine['bookmarked'],
    ];
}

function community_comment_rows(int $viewerId, array $rows): array
{
    if ($rows === []) {
        return [];
    }
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $likes = [];
    $likedByMe = [];
    $stmt = db()->prepare(
        "SELECT comment_id, COUNT(*) AS n FROM comment_likes WHERE comment_id IN ($placeholders) GROUP BY comment_id"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $likes[(int) $row['comment_id']] = (int) $row['n'];
    }
    $stmt = db()->prepare(
        "SELECT comment_id FROM comment_likes WHERE user_id = ? AND comment_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$viewerId], $ids));
    foreach ($stmt->fetchAll() as $row) {
        $likedByMe[(int) $row['comment_id']] = true;
    }

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $out[] = [
            'id' => $id,
            'song_key' => $row['song_key'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'body' => $row['body'],
            'created_at' => $row['created_at'],
            'user' => [
                'id' => (int) $row['user_id'],
                'name' => $row['user_name'] !== null && $row['user_name'] !== '' ? $row['user_name'] : 'Listener',
            ],
            'likes' => $likes[$id] ?? 0,
            'liked_by_me' => isset($likedByMe[$id]),
            'mine' => (int) $row['user_id'] === $viewerId,
        ];
    }
    return $out;
}

/**
 * Thirty-day listening history, its per-day buckets and the two charts the
 * profile needs. Built from `events` so it is already stored server-side.
 */
function community_history(int $userId, int $days, int $limit): array
{
    $days = community_clamp_int($days, 1, 180, 30);
    $limit = community_clamp_int($limit, 1, 500, 200);
    $pdo = db();
    $window = "created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";

    $statsStmt = $pdo->prepare(
        "SELECT COUNT(*) AS plays,
                COALESCE(SUM(listen_ms), 0) AS listen_ms,
                COUNT(DISTINCT track_id) AS unique_songs,
                COUNT(DISTINCT artist) AS unique_artists
           FROM events
          WHERE user_id = ? AND type = 'play' AND track_id <> '' AND $window"
    );
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch() ?: [];

    $dailyStmt = $pdo->prepare(
        "SELECT DATE(created_at) AS day, COUNT(*) AS plays, COALESCE(SUM(listen_ms), 0) AS listen_ms
           FROM events
          WHERE user_id = ? AND type = 'play' AND track_id <> '' AND $window
          GROUP BY DATE(created_at) ORDER BY day"
    );
    $dailyStmt->execute([$userId]);
    $daily = $dailyStmt->fetchAll();

    $playsStmt = $pdo->prepare(
        "SELECT track_id AS song_key, title, artist, album, art_url, type,
                listen_ms, duration_ms, created_at
           FROM events
          WHERE user_id = ? AND type IN ('play', 'complete') AND track_id <> '' AND $window
          ORDER BY id DESC LIMIT $limit"
    );
    $playsStmt->execute([$userId]);
    $plays = $playsStmt->fetchAll();
    foreach ($plays as &$play) {
        $play['song_key'] = (string) $play['song_key'];
        $play['listen_ms'] = (int) $play['listen_ms'];
        $play['duration_ms'] = (int) $play['duration_ms'];
    }
    unset($play);

    $topSongsStmt = $pdo->prepare(
        "SELECT track_id AS song_key, title, artist, art_url, COUNT(*) AS plays,
                COALESCE(SUM(listen_ms), 0) AS listen_ms
           FROM events
          WHERE user_id = ? AND type = 'play' AND track_id <> '' AND $window
          GROUP BY track_id, title, artist, art_url
          ORDER BY plays DESC LIMIT 10"
    );
    $topSongsStmt->execute([$userId]);

    $topArtistsStmt = $pdo->prepare(
        "SELECT artist, COUNT(*) AS plays, COALESCE(SUM(listen_ms), 0) AS listen_ms
           FROM events
          WHERE user_id = ? AND type = 'play' AND artist <> '' AND $window
          GROUP BY artist ORDER BY plays DESC LIMIT 10"
    );
    $topArtistsStmt->execute([$userId]);

    // Fill missing days so a profile chart has a bar for every day in the
    // window rather than silently compressing a quiet week to one pixel.
    $byDay = [];
    foreach ($daily as $row) {
        $byDay[(string) $row['day']] = [
            'plays' => (int) $row['plays'],
            'minutes' => (int) round(((int) $row['listen_ms']) / 60000),
        ];
    }
    $buckets = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $buckets[] = [
            'date' => $day,
            'plays' => $byDay[$day]['plays'] ?? 0,
            'minutes' => $byDay[$day]['minutes'] ?? 0,
        ];
    }

    $playsCount = (int) ($stats['plays'] ?? 0);
    $listenMs = (int) ($stats['listen_ms'] ?? 0);

    return [
        'days' => $days,
        'stats' => [
            'plays' => $playsCount,
            'minutes' => (int) round($listenMs / 60000),
            'hours' => round($listenMs / 3600000, 1),
            'unique_songs' => (int) ($stats['unique_songs'] ?? 0),
            'unique_artists' => (int) ($stats['unique_artists'] ?? 0),
            'average_minutes_per_day' => $days > 0 ? round($listenMs / 60000 / $days, 1) : 0,
        ],
        'daily' => $buckets,
        'plays' => $plays,
        'top_songs' => $topSongsStmt->fetchAll(),
        'top_artists' => $topArtistsStmt->fetchAll(),
    ];
}
