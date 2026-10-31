package com.music.bitchord.allo

import android.content.Context
import android.content.SharedPreferences
import com.music.bitchord.data.model.Song
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import org.json.JSONArray
import org.json.JSONObject

/**
 * Listening and search events on their way to the allomusic account.
 *
 * Everything is queued on disk first and uploaded in batches, so a play made
 * offline is not lost and a burst of skips is not one request each. The queue
 * is capped: the oldest events are dropped rather than letting a device that
 * has been offline for a month grow an unbounded preferences blob.
 *
 * Events are the raw material for the recommendation signals the panel builds
 * server-side; nothing here decides what a listener likes.
 */
object AlloEvents {

    private const val PREFS = "allo_events"
    private const val KEY_QUEUE = "queue"
    private const val MAX_QUEUE = 400
    private const val BATCH = 100
    private const val FLUSH_AT = 20

    @Volatile private var prefs: SharedPreferences? = null
    @Volatile private var flushing = false

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val json = Json { isLenient = true; ignoreUnknownKeys = true }

    fun init(context: Context) {
        if (prefs != null) return
        synchronized(this) {
            if (prefs != null) return
            prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        }
        flushSoon()
    }

    fun play(song: Song, listenMs: Long, durationMs: Long) =
        trackEvent("play", song, listenMs, durationMs)

    fun complete(song: Song, listenMs: Long, durationMs: Long) =
        trackEvent("complete", song, listenMs, durationMs)

    fun skip(song: Song, listenMs: Long, durationMs: Long) =
        trackEvent("skip", song, listenMs, durationMs)

    /** [status] is a [com.music.bitchord.data.model.LikeStatus] name. */
    fun rating(videoId: String, status: String) {
        val type = when (status) {
            "LIKE" -> "like"
            "DISLIKE" -> "dislike"
            else -> "unlike"
        }
        val event = JSONObject()
        event.put("track_id", videoId)
        enqueue(type, event)
    }

    fun search(title: String, subtitle: String, entityType: String) {
        val event = JSONObject()
        event.put("title", title.take(255))
        event.put("subtitle", subtitle.take(255))
        event.put("entity_type", entityType)
        enqueue("search", event)
    }

    private fun trackEvent(type: String, song: Song, listenMs: Long, durationMs: Long) {
        val event = JSONObject()
        event.put("track_id", song.videoId)
        event.put("title", song.title.take(255))
        event.put("artist", song.artist.take(255))
        song.albumName?.let { event.put("album", it.take(255)) }
        song.thumbnailUrl?.let { event.put("art_url", it.take(512)) }
        event.put("duration_ms", durationMs.coerceAtLeast(0L))
        event.put("listen_ms", listenMs.coerceAtLeast(0L))
        event.put("source", song.queueTier.name)
        event.put("is_video", song.isVideo)
        enqueue(type, event)
    }

    private fun enqueue(type: String, event: JSONObject) {
        val store = prefs ?: return
        event.put("type", type)
        if (!event.has("ts")) event.put("ts", System.currentTimeMillis())
        var flushNow = false
        synchronized(this) {
            val queue = read(store)
            queue.put(event.toString())
            val trimmed = JSONArray()
            val start = (queue.length() - MAX_QUEUE).coerceAtLeast(0)
            for (i in start until queue.length()) trimmed.put(queue.get(i))
            store.edit().putString(KEY_QUEUE, trimmed.toString()).apply()
            flushNow = trimmed.length() >= FLUSH_AT
        }
        if (flushNow) flushSoon()
    }

    /** Drops anything not yet uploaded, so one account's plays cannot land on another. */
    fun clear() {
        prefs?.edit()?.remove(KEY_QUEUE)?.apply()
    }

    fun flushSoon() {
        scope.launch { flush() }
    }

    /** Uploads up to one batch; safe to call from anywhere, at any time. */
    suspend fun flush() {
        val store = prefs ?: return
        val token = AlloSession.token ?: return
        if (flushing) return
        flushing = true
        try {
            val batch: List<String> = synchronized(this) {
                val queue = read(store)
                val count = minOf(BATCH, queue.length())
                if (count == 0) return
                buildList(count) {
                    for (i in 0 until count) add(queue.getString(i))
                }
            }
            val elements: List<JsonElement> = batch.mapNotNull { raw ->
                runCatching { json.parseToJsonElement(raw) }.getOrNull()
            }
            if (elements.isEmpty()) return
            when (AlloApi.sendEvents(token, elements)) {
                is AlloApi.Result.Ok -> synchronized(this) {
                    val queue = read(store)
                    val rest = JSONArray()
                    for (i in batch.size until queue.length()) rest.put(queue.get(i))
                    store.edit().putString(KEY_QUEUE, rest.toString()).apply()
                }
                is AlloApi.Result.Err -> Unit
            }
        } finally {
            flushing = false
        }
    }

    private fun read(store: SharedPreferences): JSONArray =
        runCatching { JSONArray(store.getString(KEY_QUEUE, "[]")) }.getOrDefault(JSONArray())
}
