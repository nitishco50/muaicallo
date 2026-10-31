package com.music.bitchord.allo

import io.ktor.client.HttpClient
import io.ktor.client.engine.okhttp.OkHttp
import io.ktor.client.plugins.HttpTimeout
import io.ktor.client.request.header
import io.ktor.client.request.request
import io.ktor.client.request.setBody
import io.ktor.client.statement.bodyAsText
import io.ktor.http.ContentType
import io.ktor.http.HttpMethod
import io.ktor.http.contentType
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonObjectBuilder
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put

/**
 * The allomusic account backend.
 *
 * A single PHP endpoint answers every action as `{ok, data}` or
 * `{ok, error:{code, message}}`, so the whole client is one request helper and
 * a set of thin wrappers. Tokens are bearer tokens issued at login.
 */
object AlloApi {

    /** Change this when the panel moves. No trailing slash. */
    const val BASE_URL = "https://allo.instadl.site"

    private const val ENDPOINT = "$BASE_URL/api/index.php"

    private val json = Json {
        isLenient = true
        ignoreUnknownKeys = true
        explicitNulls = false
    }

    private val client by lazy {
        HttpClient(OkHttp) {
            install(HttpTimeout) {
                requestTimeoutMillis = 20_000
                connectTimeoutMillis = 8_000
                socketTimeoutMillis = 20_000
            }
            expectSuccess = false
        }
    }

    sealed interface Result {
        data class Ok(val data: JsonObject) : Result
        data class Err(val code: String, val message: String) : Result
    }

    suspend fun call(
        action: String,
        method: HttpMethod = HttpMethod.Get,
        token: String? = null,
        body: JsonObject? = null,
        query: Map<String, String> = emptyMap(),
    ): Result = try {
        val suffix = query.entries.joinToString("") { (key, value) ->
            "&" + key + "=" + java.net.URLEncoder.encode(value, "UTF-8")
        }
        val text = client.request("$ENDPOINT?action=$action$suffix") {
            this.method = method
            if (token != null) {
                header("Authorization", "Bearer $token")
            }
            if (body != null) {
                contentType(ContentType.Application.Json)
                setBody(body.toString())
            }
        }.bodyAsText()

        val root = runCatching { json.parseToJsonElement(text).jsonObject }.getOrNull()
        if (root == null) {
            Result.Err("bad_response", "The server sent something unreadable.")
        } else if (root["ok"]?.jsonPrimitive?.booleanOrNull == true) {
            Result.Ok(root["data"]?.takeIf { it is JsonObject }?.jsonObject ?: JsonObject(emptyMap()))
        } else {
            val error = root["error"]?.takeIf { it is JsonObject }?.jsonObject
            Result.Err(
                error?.get("code")?.jsonPrimitive?.contentOrNull ?: "error",
                error?.get("message")?.jsonPrimitive?.contentOrNull ?: "Request failed.",
            )
        }
    } catch (e: Throwable) {
        Result.Err("network", e.message ?: "Could not reach allomusic servers.")
    }

    data class Device(
        val id: String,
        val model: String,
        val sdk: String,
        val appVersion: String,
    )

    private fun JsonObjectBuilder.device(device: Device) {
        put("device_id", device.id)
        put("device_name", device.model)
        put("sdk", device.sdk)
        put("app_version", device.appVersion)
    }

    suspend fun config(): Result = call("config")

    suspend fun register(
        email: String,
        password: String,
        name: String,
        device: Device,
        preferences: JsonObject? = null,
    ): Result =
        call("register", HttpMethod.Post, null, buildJsonObject {
            put("email", email)
            put("password", password)
            put("name", name)
            if (preferences != null) put("preferences", preferences)
            device(device)
        })

    suspend fun login(email: String, password: String, device: Device): Result =
        call("login", HttpMethod.Post, null, buildJsonObject {
            put("email", email)
            put("password", password)
            device(device)
        })

    suspend fun me(token: String): Result = call("me", token = token)

    suspend fun logout(token: String): Result = call("logout", HttpMethod.Post, token)

    suspend fun deleteAccount(token: String): Result =
        call("delete_account", HttpMethod.Post, token)

    suspend fun saveData(token: String, namespace: String, document: String): Result =
        call("data", HttpMethod.Post, token, buildJsonObject {
            put("ns", namespace)
            put("json", document)
        })

    suspend fun loadData(token: String, namespace: String): Result =
        call("data", token = token, query = mapOf("ns" to namespace))

    suspend fun notifications(token: String): Result = call("notifications", token = token)

    suspend fun sendEvents(token: String, events: List<JsonElement>): Result =
        call("event", HttpMethod.Post, token, buildJsonObject {
            put("events", JsonArray(events))
        })

    suspend fun signals(token: String): Result = call("signals", token = token)

    suspend fun preferences(token: String): Result = call("preferences", token = token)

    suspend fun savePreferences(token: String, preferences: JsonObject): Result =
        call("preferences", HttpMethod.Post, token, buildJsonObject {
            put("preferences", preferences)
        })

    suspend fun history(token: String, days: Int = 30, limit: Int = 200): Result =
        call("history", token = token, query = mapOf("days" to days.toString(), "limit" to limit.toString()))

    suspend fun clearHistory(token: String, days: Int = 30): Result =
        call("history_clear", HttpMethod.Post, token, buildJsonObject { put("days", days) })

    suspend fun profileStats(token: String): Result = call("profile_stats", token = token)

    suspend fun reaction(
        token: String,
        songKey: String,
        action: String,
        title: String = "",
        artist: String = "",
        artUrl: String = "",
    ): Result = call("reaction", HttpMethod.Post, token, buildJsonObject {
        put("song_key", songKey)
        put("action", action)
        put("title", title)
        put("artist", artist)
        put("art_url", artUrl)
    })

    suspend fun reactions(token: String, value: String = "like"): Result =
        call("reactions", token = token, query = mapOf("value" to value))

    suspend fun bookmark(
        token: String,
        songKey: String,
        action: String = "toggle",
        title: String = "",
        artist: String = "",
        artUrl: String = "",
    ): Result = call("bookmark", HttpMethod.Post, token, buildJsonObject {
        put("song_key", songKey)
        put("action", action)
        put("title", title)
        put("artist", artist)
        put("art_url", artUrl)
    })

    suspend fun bookmarks(token: String): Result = call("bookmarks", token = token)

    suspend fun song(token: String, songKey: String): Result =
        call("song", token = token, query = mapOf("song_key" to songKey))

    suspend fun comments(token: String, songKey: String, limit: Int = 50): Result =
        call("comments", token = token, query = mapOf("song_key" to songKey, "limit" to limit.toString()))

    suspend fun postComment(
        token: String,
        songKey: String,
        body: String,
        parentId: Long? = null,
        title: String = "",
        artist: String = "",
        artUrl: String = "",
    ): Result = call("comments", HttpMethod.Post, token, buildJsonObject {
        put("song_key", songKey)
        put("body", body)
        if (parentId != null) put("parent_id", parentId)
        put("title", title)
        put("artist", artist)
        put("art_url", artUrl)
    })

    suspend fun deleteComment(token: String, id: Long): Result =
        call("comment_delete", HttpMethod.Post, token, buildJsonObject { put("id", id) })

    suspend fun likeComment(token: String, id: Long, like: Boolean): Result =
        call("comment_like", HttpMethod.Post, token, buildJsonObject {
            put("id", id)
            put("action", if (like) "like" else "unlike")
        })

    suspend fun reportComment(token: String, id: Long, reason: String): Result =
        call("comment_report", HttpMethod.Post, token, buildJsonObject {
            put("id", id)
            put("reason", reason)
        })

    suspend fun myComments(token: String): Result = call("my_comments", token = token)

    suspend fun trending(token: String, days: Int = 7): Result =
        call("trending", token = token, query = mapOf("days" to days.toString()))

    suspend fun searchSongs(token: String, query: String): Result =
        call("search_songs", token = token, query = mapOf("q" to query))

    suspend fun publicProfile(token: String, userId: Long): Result =
        call("public_profile", token = token, query = mapOf("id" to userId.toString()))

    suspend fun follow(token: String, userId: Long, follow: Boolean): Result =
        call("follow", HttpMethod.Post, token, buildJsonObject {
            put("user_id", userId)
            put("action", if (follow) "follow" else "unfollow")
        })

    suspend fun following(token: String): Result = call("following", token = token)

    suspend fun followers(token: String): Result = call("followers", token = token)

    suspend fun followArtist(token: String, artist: String, follow: Boolean): Result =
        call("artist_follow", HttpMethod.Post, token, buildJsonObject {
            put("artist", artist)
            put("action", if (follow) "follow" else "unfollow")
        })

    suspend fun followedArtists(token: String): Result = call("artist_follows", token = token)

    suspend fun markNotificationsRead(token: String, id: Long? = null, all: Boolean = false): Result =
        call("notifications_read", HttpMethod.Post, token, buildJsonObject {
            if (all) put("all", true) else if (id != null) put("id", id)
        })

    suspend fun clearNotifications(token: String): Result =
        call("notifications_clear", HttpMethod.Post, token)

    suspend fun changePassword(token: String, current: String, next: String): Result =
        call("password", HttpMethod.Post, token, buildJsonObject {
            put("current", current)
            put("next", next)
        })
}
