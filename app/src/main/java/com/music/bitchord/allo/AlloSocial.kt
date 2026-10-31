package com.music.bitchord.allo

import com.music.bitchord.data.model.Song
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.longOrNull
import java.security.MessageDigest

/** One song as the community backend names it. */
data class SocialSong(
    val songKey: String,
    val title: String,
    val artist: String,
    val artUrl: String,
    val likes: Int = 0,
    val dislikes: Int = 0,
    val comments: Int = 0,
    val bookmarks: Int = 0,
    val myReaction: String = "",
    val bookmarked: Boolean = false,
    val score: Int = 0,
) {
    val subtitle: String
        get() = buildString {
            append(artist.ifBlank { "Unknown artist" })
            if (comments > 0) append(" · $comments comments")
            else if (likes + dislikes > 0) append(" · ${likes + dislikes} ratings")
        }
}

data class SocialComment(
    val id: Long,
    val songKey: String,
    val parentId: Long?,
    val body: String,
    val createdAt: String,
    val userId: Long,
    val userName: String,
    val likes: Int,
    val likedByMe: Boolean,
    val mine: Boolean,
)

/**
 * The allomusic community protocol, plus the small parsing layer the Compose
 * pages use. Kept apart from [AlloApi] so the request shape and the payload
 * shape can be read side by side.
 */
object AlloSocial {

    fun songRef(song: Song): SocialSong {
        val key = if (song.videoId.isNotBlank()) {
            song.videoId
        } else {
            "local:" + sha256("${song.title}\u0000${song.artist}").take(40)
        }
        return SocialSong(
            songKey = key,
            title = song.title,
            artist = song.artist,
            artUrl = song.thumbnailUrl.orEmpty(),
        )
    }

    fun songRef(songKey: String, title: String, artist: String, artUrl: String = ""): SocialSong =
        SocialSong(songKey, title, artist, artUrl)

    fun parseSong(obj: JsonObject): SocialSong {
        return SocialSong(
            songKey = obj.str("song_key"),
            title = obj.str("title"),
            artist = obj.str("artist"),
            artUrl = obj.str("art_url"),
            likes = obj.int("likes"),
            dislikes = obj.int("dislikes"),
            comments = obj.int("comments"),
            bookmarks = obj.int("bookmarks"),
            myReaction = obj.str("my_reaction"),
            bookmarked = obj.bool("bookmarked"),
            score = obj.int("score"),
        )
    }

    fun parseSongs(root: JsonObject, key: String): List<SocialSong> =
        root[key]?.let { runCatching { it.jsonArray }.getOrNull() }
            ?.mapNotNull { element -> runCatching { parseSong(element.jsonObject) }.getOrNull() }
            .orEmpty()

    fun parseComment(obj: JsonObject): SocialComment {
        val user = obj.obj("user")
        return SocialComment(
            id = obj.long("id"),
            songKey = obj.str("song_key"),
            parentId = obj.longOrNull("parent_id"),
            body = obj.str("body"),
            createdAt = obj.str("created_at"),
            userId = user.long("id"),
            userName = user.str("name").ifBlank { "Listener" },
            likes = obj.int("likes"),
            likedByMe = obj.bool("liked_by_me"),
            mine = obj.bool("mine"),
        )
    }

    fun parseComments(root: JsonObject, key: String = "comments"): List<SocialComment> =
        root[key]?.let { runCatching { it.jsonArray }.getOrNull() }
            ?.mapNotNull { element -> runCatching { parseComment(element.jsonObject) }.getOrNull() }
            .orEmpty()

    fun array(root: JsonObject, key: String): JsonArray =
        root[key]?.let { runCatching { it.jsonArray }.getOrNull() } ?: JsonArray(emptyList())

    fun objList(root: JsonObject, key: String): List<JsonObject> =
        array(root, key).mapNotNull { runCatching { it.jsonObject }.getOrNull() }

    fun sha256(value: String): String {
        val bytes = MessageDigest.getInstance("SHA-256").digest(value.toByteArray())
        return bytes.joinToString("") { "%02x".format(it) }
    }
}

fun JsonObject.str(key: String): String =
    this[key]?.jsonPrimitive?.contentOrNull.orEmpty()

fun JsonObject.int(key: String): Int =
    this[key]?.jsonPrimitive?.intOrNull ?: 0

fun JsonObject.long(key: String): Long =
    this[key]?.jsonPrimitive?.longOrNull ?: 0L

fun JsonObject.longOrNull(key: String): Long? =
    this[key]?.jsonPrimitive?.longOrNull

fun JsonObject.bool(key: String): Boolean =
    this[key]?.jsonPrimitive?.contentOrNull.equals("true", ignoreCase = true)

fun JsonObject.obj(key: String): JsonObject =
    this[key]?.let { runCatching { it.jsonObject }.getOrNull() } ?: JsonObject(emptyMap())
