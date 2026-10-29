package com.music.bitchord.allo

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import com.music.bitchord.AppIcon
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonPrimitive

/**
 * The server-owned switches every install reads on launch.
 *
 * The launcher icon is an activity-alias, and only aliases compiled into the
 * APK can be enabled. [iconFor] therefore maps the panel's icon_set key onto a
 * bundled [AppIcon]; an upload cannot invent a new alias. A change is applied
 * only when the server value differs from the last one applied, so a user's own
 * pick in Settings survives until an admin actually changes the setting.
 */
object AlloRemoteConfig {

    private const val TAG = "Allo"
    private const val PREFS = "allo_remote"

    private fun prefs(context: Context): SharedPreferences =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    val announcement: String get() = cached?.announcement ?: ""
    val maintenance: Boolean get() = cached?.maintenance ?: false
    val maintenanceMessage: String get() = cached?.maintenanceMessage ?: ""
    val latestVersionCode: Int get() = cached?.latestVersionCode ?: 0
    val minVersionCode: Int get() = cached?.minVersionCode ?: 0
    val updateUrl: String get() = cached?.updateUrl ?: ""
    val forceUpdate: Boolean get() = cached?.forceUpdate ?: false
    val brandLogo: String get() = cached?.brandLogo ?: ""

    private data class Cached(
        val announcement: String,
        val maintenance: Boolean,
        val maintenanceMessage: String,
        val latestVersionCode: Int,
        val minVersionCode: Int,
        val updateUrl: String,
        val forceUpdate: Boolean,
        val brandLogo: String,
    )

    @Volatile private var cached: Cached? = null

    fun load(context: Context) {
        val p = prefs(context)
        cached = Cached(
            announcement = p.getString("announcement", "") ?: "",
            maintenance = p.getBoolean("maintenance", false),
            maintenanceMessage = p.getString("maintenance_message", "") ?: "",
            latestVersionCode = p.getInt("latest_version_code", 0),
            minVersionCode = p.getInt("min_version_code", 0),
            updateUrl = p.getString("update_url", "") ?: "",
            forceUpdate = p.getBoolean("force_update", false),
            brandLogo = p.getString("brand_logo", "") ?: "",
        )
    }

    fun refresh(context: Context) {
        val app = context.applicationContext
        load(app)
        CoroutineScope(Dispatchers.IO).launch {
            when (val result = AlloApi.config()) {
                is AlloApi.Result.Ok -> apply(app, result.data)
                is AlloApi.Result.Err -> Log.w(TAG, "Config fetch failed: ${result.message}")
            }
        }
    }

    private suspend fun apply(context: Context, data: JsonObject) {
        fun text(key: String): String =
            data[key]?.jsonPrimitive?.contentOrNull ?: ""

        val p = prefs(context)
        p.edit()
            .putString("announcement", text("announcement"))
            .putBoolean("maintenance", data["maintenance"]?.jsonPrimitive?.booleanOrNull == true)
            .putString("maintenance_message", text("maintenance_message"))
            .putInt("latest_version_code", data["latest_version_code"]?.jsonPrimitive?.intOrNull ?: 0)
            .putInt("min_version_code", data["min_version_code"]?.jsonPrimitive?.intOrNull ?: 0)
            .putString("update_url", text("update_url"))
            .putBoolean("force_update", data["force_update"]?.jsonPrimitive?.booleanOrNull == true)
            .putString("brand_logo", text("brand_logo"))
            .apply()
        load(context)

        val iconSet = text("icon_set")
        val lastApplied = p.getString("applied_icon_set", null)
        val icon = iconFor(iconSet)
        if (icon != null && iconSet != lastApplied) {
            withContext(Dispatchers.Main) {
                if (AppIcon.current(context) != icon) {
                    AppIcon.apply(context, icon)
                }
            }
            p.edit().putString("applied_icon_set", iconSet).apply()
        }
    }

    fun iconFor(key: String): AppIcon? = when (key) {
        "default" -> AppIcon.Default
        "user_custom" -> AppIcon.UserCustom
        "liquid_light" -> AppIcon.LiquidGlass
        "liquid_dark" -> AppIcon.LiquidGlassDark
        "liquid_clear" -> AppIcon.LiquidGlassClear
        "liquid_clear_dark" -> AppIcon.LiquidGlassClearDark
        "samsung_light" -> AppIcon.SamsungGlass
        "samsung_dark" -> AppIcon.SamsungGlassDark
        "samsung_clear" -> AppIcon.SamsungGlassClear
        "samsung_clear_dark" -> AppIcon.SamsungGlassClearDark
        "pixel_light" -> AppIcon.PixelGlass
        "pixel_dark" -> AppIcon.PixelGlassDark
        "pixel_clear" -> AppIcon.PixelGlassClear
        "pixel_clear_dark" -> AppIcon.PixelGlassClearDark
        else -> null
    }
}
