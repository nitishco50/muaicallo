package com.music.bitchord.allo

import android.content.Context
import android.util.Log
import com.music.bitchord.data.settings.AppSettings
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonPrimitive
import org.json.JSONArray
import org.json.JSONObject

/**
 * Pushes this device's configuration to the account and pulls it back.
 *
 * The panel stores documents by namespace, so preferences are one document
 * today and the same call can carry playlists, history or downloads later
 * without a schema change. Settings are the part that is safe to merge whole:
 * [AppSettings.importPrefs] replaces the file and re-reads every flow, which is
 * what the existing backup import already does.
 */
object AlloSync {

    private const val TAG = "Allo"
    const val NAMESPACE_SETTINGS = "settings"

    fun settingsDocument(): String {
        val obj = JSONObject()
        AppSettings.exportPrefs().forEach { (key, value) ->
            when (value) {
                null -> obj.put(key, JSONObject.NULL)
                is Set<*> -> obj.put(key, JSONArray(value.filterIsInstance<String>()))
                else -> obj.put(key, value)
            }
        }
        return obj.toString()
    }

    suspend fun pushSettings(context: Context): Boolean = withContext(Dispatchers.IO) {
        val token = AlloSession.token ?: return@withContext false
        val document = runCatching { settingsDocument() }.getOrElse {
            Log.w(TAG, "Could not serialise settings", it)
            return@withContext false
        }
        when (val result = AlloApi.saveData(token, NAMESPACE_SETTINGS, document)) {
            is AlloApi.Result.Ok -> true
            is AlloApi.Result.Err -> {
                Log.w(TAG, "Settings push failed: ${result.code} ${result.message}")
                false
            }
        }
    }

    suspend fun pullSettings(context: Context): Boolean = withContext(Dispatchers.IO) {
        val token = AlloSession.token ?: return@withContext false
        when (val result = AlloApi.loadData(token, NAMESPACE_SETTINGS)) {
            is AlloApi.Result.Ok -> {
                val text = result.data["json"]?.jsonPrimitive?.contentOrNull
                if (text.isNullOrBlank() || text == "null") {
                    return@withContext false
                }
                runCatching {
                    AppSettings.importPrefs(JSONObject(text).toPrefs())
                }.onFailure { Log.w(TAG, "Settings pull failed", it) }.isSuccess
            }
            is AlloApi.Result.Err -> {
                Log.w(TAG, "Settings pull failed: ${result.code} ${result.message}")
                false
            }
        }
    }

    private fun JSONObject.toPrefs(): Map<String, Any?> {
        val out = HashMap<String, Any?>()
        keys().forEach { key ->
            when (val value = get(key)) {
                is JSONArray -> {
                    val set = HashSet<String>()
                    for (i in 0 until value.length()) {
                        value.optString(i).takeIf { it.isNotEmpty() }?.let(set::add)
                    }
                    out[key] = set
                }
                JSONObject.NULL -> out[key] = null
                is Double -> out[key] = value.toFloat()
                is Int, is Long, is Boolean, is String -> out[key] = value
            }
        }
        return out
    }
}
