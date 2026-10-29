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

    suspend fun register(email: String, password: String, name: String, device: Device): Result =
        call("register", HttpMethod.Post, null, buildJsonObject {
            put("email", email)
            put("password", password)
            put("name", name)
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

    suspend fun changePassword(token: String, current: String, next: String): Result =
        call("password", HttpMethod.Post, token, buildJsonObject {
            put("current", current)
            put("next", next)
        })
}
